<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Controllers\CodingWorkspaceController;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\CodingProject;
use ClarionApp\LlmClient\Models\CodingWorkspaceChange;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\CodingAgentProvisioner;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\MetricsRecorder;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\Services\RoleAssignmentService;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use Dedoc\Scramble\Generator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 122-workspace-browser-ui, US3, T033 (FR-006/FR-007, Acceptance
 * Scenarios 1-2, research.md D5/D6). A full request -> controller ->
 * service -> database journey: an agent's writeFile()/deleteFile(), routed
 * all the way through the real AgentLoopService::executeApiCall() (so the
 * X-Llm-Client-Conversation-Id header this test's attribution assertions
 * depend on is genuinely attached by production code, not hand-set by the
 * test), into the real, unmodified-by-this-test CodingWorkspaceController,
 * produces exactly one coding_workspace_changes row per mutation with the
 * correct operation, content, and attribution.
 *
 * Reuses ConfirmationRelaxationJourneyTest's real-pause/resume/real-
 * provisioned-coding-agent setup shape -- every mutation call is routed
 * all the way through to the real controller, so an assertion reflects
 * genuine filesystem and database state produced by actual production
 * code, never a hand-simulated stand-in.
 */
class WorkspaceChangeRecordingJourneyTest extends TestCase
{
    private User $user;

    private Server $server;

    private CodingProject $project;

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->server = Server::create([
            'name' => 'Test Server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'sk-test',
        ]);
        app(RoleAssignmentService::class)->set(ModelRole::Inference, $this->user->id, $this->server->id, 'test-model');

        config(['llm-client.confirm_methods' => []]);
        config(['llm-client.api_denylist' => []]);

        if (!Schema::hasTable('mcp_sessions')) {
            Schema::create('mcp_sessions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id');
                $table->string('protocol_version');
                $table->string('client_name')->nullable();
                $table->string('client_version')->nullable();
                $table->json('capabilities')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        $this->tmpDir = sys_get_temp_dir().'/coding-agent-change-recording-'.Str::random(12);
        mkdir($this->tmpDir, 0777, true);

        // Relaxed so every write/delete applies directly -- this journey
        // is about the change record, not the confirmation gate.
        $this->project = CodingProject::create([
            'user_id' => $this->user->id,
            'name' => 'change recording project',
            'root_path' => $this->tmpDir,
            'test_command' => null,
            'confirmation_relaxed' => true,
        ]);

        $this->seedOperationCatalog();
        $this->actingAs($this->user, 'api');
        $this->fakeCodingWorkspaceHttp();
    }

    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        if (is_dir($this->tmpDir)) {
            $this->removeDirectory($this->tmpDir);
        }

        DB::table('coding_workspace_changes')->delete();
        DB::table('agent_run_actions')->delete();
        DB::table('agent_run_steps')->delete();
        DB::table('agent_runs')->delete();
        DB::table('messages')->delete();
        DB::table('conversations')->delete();
        DB::table('coding_projects')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('llm_role_assignments')->delete();
        DB::table('llm_servers')->delete();
        if (Schema::hasTable('mcp_sessions')) {
            DB::table('mcp_sessions')->delete();
        }
        DB::table('users')->delete();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Fixture scaffolding (mirrors ConfirmationRelaxationJourneyTest.php)
    // -----------------------------------------------------------------

    private function seedOperationCatalog(): void
    {
        $operations = [
            'clarionApp.llmClient.codingWorkspace.listFiles' => ['path' => '/coding-project/{project}/files', 'method' => 'get'],
            'clarionApp.llmClient.codingWorkspace.readFile' => ['path' => '/coding-project/{project}/file', 'method' => 'get'],
            'clarionApp.llmClient.codingWorkspace.writeFile' => ['path' => '/coding-project/{project}/file', 'method' => 'post'],
            'clarionApp.llmClient.codingWorkspace.deleteFile' => ['path' => '/coding-project/{project}/file', 'method' => 'delete'],
            'clarionApp.llmClient.codingWorkspace.runTests' => ['path' => '/coding-project/{project}/run-tests', 'method' => 'post'],
            'clarionApp.llmClient.codingWorkspace.runCommand' => ['path' => '/coding-project/{project}/run-command', 'method' => 'post'],
            'clarionApp.llmClient.codingWorkspace.runCode' => ['path' => '/coding-project/{project}/run-code', 'method' => 'post'],
            'clarionApp.llmClient.codingWorkspace.gitStatus' => ['path' => '/coding-project/{project}/git-status', 'method' => 'get'],
            'clarionApp.llmClient.codingWorkspace.gitDiff' => ['path' => '/coding-project/{project}/git-diff', 'method' => 'get'],
        ];

        $paths = [];
        foreach ($operations as $operationId => $entry) {
            $paths[$entry['path']][$entry['method']] = [
                'operationId' => $operationId,
                'summary' => $operationId,
            ];
        }
        $doc = ['paths' => $paths];

        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, $doc);

        $generator = Mockery::mock(Generator::class);
        $generator->shouldReceive('__invoke')->andReturn($doc);
        $this->app->instance(Generator::class, $generator);
    }

    private function clearOperationCatalog(): void
    {
        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    /**
     * Routes the outbound HTTP call the agent loop's tool executor makes
     * straight into the real, unmodified CodingWorkspaceController --
     * including propagating whatever X-Llm-Client-Conversation-Id header
     * the real Http::withHeaders() call carried, onto the Laravel
     * $request the controller actually reads, so this test proves the
     * production header-attachment (AgentLoopService::executeApiCall())
     * and header-verification (CodingWorkspaceController) code, not a
     * stand-in for either.
     */
    private function fakeCodingWorkspaceHttp(): void
    {
        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $method = strtoupper($request->method());

            if (!preg_match('#/([^/]+)/(file)$#', $path, $m)) {
                return Http::response(['error' => 'unmapped test route: '.$path], 500);
            }
            [, $projectId, $suffix] = $m;

            $laravelRequest = in_array($method, ['POST', 'PUT', 'PATCH'], true)
                ? Request::create($request->url(), $method, $request->data())
                : Request::create($request->url(), $method);

            $conversationHeader = $request->header('X-Llm-Client-Conversation-Id');
            if (!empty($conversationHeader)) {
                $laravelRequest->headers->set('X-Llm-Client-Conversation-Id', $conversationHeader[0]);
            }

            $controller = new CodingWorkspaceController();

            $response = match (true) {
                $suffix === 'file' && $method === 'POST' => $controller->writeFile($laravelRequest, $projectId),
                $suffix === 'file' && $method === 'DELETE' => $controller->deleteFile($laravelRequest, $projectId),
                default => response()->json(['error' => 'unmapped test route: '.$suffix.' '.$method], 500),
            };

            return Http::response($response->getData(true), $response->getStatusCode());
        });
    }

    private function removeDirectory(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function service(array $responses): AgentLoopService
    {
        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturnUsing(function ($messages, $tools, $options = []) use (&$responses) {
            return array_shift($responses);
        });
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);

        $executor = new McpToolExecutor(
            app(McpToolRegistry::class),
            null,
            fn ($user) => 'test-token',
        );

        return new AgentLoopService(
            app(McpToolRegistry::class),
            $executor,
            app(OperationCache::class),
            $registry,
            presetRegistry: app(\ClarionApp\LlmClient\Services\StructuredOutputPresetRegistry::class),
            metricsRecorder: new MetricsRecorder(),
            runTraceRecorder: config('llm-client.run_trace.enabled', false) ? app(RunTraceRecorder::class) : null,
        );
    }

    private function plainReply(string $content): array
    {
        return ['choices' => [['message' => ['content' => $content, 'tool_calls' => []]]]];
    }

    private function toolCall(string $name, array $arguments, string $id): array
    {
        return [
            'id' => $id,
            'type' => 'function',
            'function' => ['name' => $name, 'arguments' => json_encode($arguments)],
        ];
    }

    private function toolCallReply(array $calls): array
    {
        return ['choices' => [['message' => ['content' => '', 'tool_calls' => $calls]]]];
    }

    private function writeFileCall(CodingProject $project, string $relativePath, string $content, string $callId): array
    {
        return $this->toolCall('execute_operation', [
            'operationId' => AgentLoopService::CODING_WORKSPACE_WRITE_FILE_OPERATION_ID,
            'parameters' => [
                'path' => ['project' => $project->id],
                'body' => ['path' => $relativePath, 'content' => $content],
            ],
        ], $callId);
    }

    private function deleteFileCall(CodingProject $project, string $relativePath, string $callId): array
    {
        return $this->toolCall('execute_operation', [
            'operationId' => AgentLoopService::CODING_WORKSPACE_DELETE_FILE_OPERATION_ID,
            'parameters' => [
                'path' => ['project' => $project->id],
                'query' => ['path' => $relativePath],
            ],
        ], $callId);
    }

    private function agent(): Agent
    {
        return app(CodingAgentProvisioner::class)->ensureForUser($this->user->id);
    }

    private function makeConversation(Agent $agent, CodingProject $project): Conversation
    {
        return Conversation::factory()->create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'title' => 'Coding conversation',
            'agent_id' => $agent->id,
            'agent_version_id' => $agent->current_version_id,
            'coding_project_id' => $project->id,
        ]);
    }

    private function makeFile(string $relativePath, string $content): void
    {
        file_put_contents($this->tmpDir.'/'.$relativePath, $content);
    }

    private function apiUrl(string $suffix): string
    {
        return '/api/clarion-app/llm-client/'.$suffix;
    }

    // -----------------------------------------------------------------
    // AS1/FR-006/FR-007 -- a new file's write is recorded as `created`
    // -----------------------------------------------------------------

    #[Test]
    public function writing_a_new_file_records_exactly_one_created_row_attributed_to_the_agent_and_conversation(): void
    {
        $agent = $this->agent();
        $conversation = $this->makeConversation($agent, $this->project);

        $service = $this->service([
            $this->toolCallReply([$this->writeFileCall($this->project, 'brand-new.txt', 'freshly written content', 'call_create_1')]),
            $this->plainReply('Created brand-new.txt.'),
        ]);

        $result = $service->run($conversation->fresh(), 'Please create brand-new.txt.');

        $this->assertSame('completed', $result['status']);
        $this->assertSame(1, DB::table('coding_workspace_changes')->count());

        $row = CodingWorkspaceChange::first();
        $this->assertNotNull($row);
        $this->assertSame($this->project->id, $row->coding_project_id);
        $this->assertSame('brand-new.txt', $row->path);
        $this->assertSame('created', $row->operation);
        $this->assertNull($row->old_content, 'a created row must have no old content');
        $this->assertNull($row->old_size);
        $this->assertSame('freshly written content', $row->new_content, 'the actual new content must be captured, not merely that a write happened');
        $this->assertSame(strlen('freshly written content'), $row->new_size);

        $this->assertSame($agent->id, $row->agent_id, 'agent_id must be populated from the verified header');
        $this->assertSame('coding', $row->agent_name);
        $this->assertSame($conversation->id, $row->conversation_id);
    }

    // -----------------------------------------------------------------
    // AS1/AS2/FR-006/FR-007 -- overwriting an existing file is `modified`,
    // with actual before/after content captured
    // -----------------------------------------------------------------

    #[Test]
    public function overwriting_an_existing_file_records_a_modified_row_with_actual_old_and_new_content(): void
    {
        $this->makeFile('existing.txt', 'the original content');

        $agent = $this->agent();
        $conversation = $this->makeConversation($agent, $this->project);

        $service = $this->service([
            $this->toolCallReply([$this->writeFileCall($this->project, 'existing.txt', 'the replacement content', 'call_modify_1')]),
            $this->plainReply('Updated existing.txt.'),
        ]);

        $result = $service->run($conversation->fresh(), 'Please update existing.txt.');

        $this->assertSame('completed', $result['status']);
        $this->assertSame(1, DB::table('coding_workspace_changes')->count());

        $row = CodingWorkspaceChange::first();
        $this->assertSame('modified', $row->operation);
        $this->assertSame('the original content', $row->old_content, 'the actual prior content must be captured');
        $this->assertSame(strlen('the original content'), $row->old_size);
        $this->assertSame('the replacement content', $row->new_content, 'the actual new content must be captured');
        $this->assertSame(strlen('the replacement content'), $row->new_size);

        $this->assertSame($agent->id, $row->agent_id);
        $this->assertSame($conversation->id, $row->conversation_id);
    }

    // -----------------------------------------------------------------
    // AS1/FR-007 Acceptance Scenario 4 -- a delete captures the
    // pre-deletion content as old_content
    // -----------------------------------------------------------------

    #[Test]
    public function deleting_a_file_records_a_deleted_row_with_the_pre_deletion_content_captured(): void
    {
        $this->makeFile('doomed.txt', 'content that is about to be deleted');

        $agent = $this->agent();
        $conversation = $this->makeConversation($agent, $this->project);

        $service = $this->service([
            $this->toolCallReply([$this->deleteFileCall($this->project, 'doomed.txt', 'call_delete_1')]),
            $this->plainReply('Deleted doomed.txt.'),
        ]);

        $result = $service->run($conversation->fresh(), 'Please delete doomed.txt.');

        $this->assertSame('completed', $result['status']);
        $this->assertSame(1, DB::table('coding_workspace_changes')->count());

        $row = CodingWorkspaceChange::first();
        $this->assertSame('deleted', $row->operation);
        $this->assertSame(
            'content that is about to be deleted',
            $row->old_content,
            'the deleted file\'s content must remain in the record -- not merely that a deletion happened',
        );
        $this->assertSame(strlen('content that is about to be deleted'), $row->old_size);
        $this->assertNull($row->new_content, 'a deleted row must have no new content');
        $this->assertNull($row->new_size);

        $this->assertSame($agent->id, $row->agent_id);
        $this->assertSame($conversation->id, $row->conversation_id);
    }

    // -----------------------------------------------------------------
    // research.md D5 -- absent header (a direct, non-agent caller) leaves
    // attribution entirely null, never blocking the write itself
    // -----------------------------------------------------------------

    #[Test]
    public function a_direct_write_call_with_no_conversation_header_records_a_row_with_null_attribution(): void
    {
        $response = $this->actingAs($this->user, 'api')->postJson($this->apiUrl("coding-project/{$this->project->id}/file"), [
            'path' => 'direct.txt',
            'content' => 'written directly, no agent involved',
        ]);

        $response->assertStatus(200);
        $this->assertSame(1, DB::table('coding_workspace_changes')->count());

        $row = CodingWorkspaceChange::first();
        $this->assertSame('created', $row->operation);
        $this->assertNull($row->agent_id);
        $this->assertNull($row->agent_name);
        $this->assertNull($row->conversation_id);
    }
}
