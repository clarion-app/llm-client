<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Controllers\CodingWorkspaceController;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\CodingProject;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
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
 * 121-workspace-boundary-hardening, US3, T024 (contracts/
 * confirmation-relaxation.md, spec.md Acceptance Scenarios 1-4, Edge Case).
 *
 * Reuses ProjectFileConfirmationTest's real-pause/resume/real-provisioned-
 * coding-agent setup shape -- every mutation call is routed all the way
 * through to the real CodingWorkspaceController, so a pause/apply
 * assertion reflects genuine filesystem state produced by the actual
 * production controller code, never a hand-simulated stand-in. Two
 * separately registered projects (A and B) prove the setting is scoped to
 * exactly the workspace it was changed on, never bleeding into a sibling
 * workspace.
 */
class ConfirmationRelaxationJourneyTest extends TestCase
{
    private User $user;

    private User $otherUser;

    private Server $server;

    private CodingProject $projectA;

    private CodingProject $projectB;

    private string $tmpDirA;

    private string $tmpDirB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->otherUser = User::factory()->create();

        $this->server = Server::create([
            'name' => 'Test Server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'sk-test',
        ]);
        app(RoleAssignmentService::class)->set(ModelRole::Inference, $this->user->id, $this->server->id, 'test-model');

        // Isolate every pause/allow decision to the coding agent's own
        // definition -- the installation-wide ceiling is not this
        // phase's concern.
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

        $this->tmpDirA = sys_get_temp_dir().'/coding-agent-relaxation-a-'.Str::random(12);
        mkdir($this->tmpDirA, 0777, true);
        $this->tmpDirB = sys_get_temp_dir().'/coding-agent-relaxation-b-'.Str::random(12);
        mkdir($this->tmpDirB, 0777, true);

        $this->projectA = CodingProject::create([
            'user_id' => $this->user->id,
            'name' => 'relaxation project A',
            'root_path' => $this->tmpDirA,
            'test_command' => null,
        ]);
        $this->projectB = CodingProject::create([
            'user_id' => $this->user->id,
            'name' => 'relaxation project B',
            'root_path' => $this->tmpDirB,
            'test_command' => null,
        ]);

        $this->seedOperationCatalog();
        $this->actingAs($this->user, 'api');
        $this->fakeCodingWorkspaceHttp();
    }

    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        if (is_dir($this->tmpDirA)) {
            $this->removeDirectory($this->tmpDirA);
        }
        if (is_dir($this->tmpDirB)) {
            $this->removeDirectory($this->tmpDirB);
        }

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
    // Fixture scaffolding
    // -----------------------------------------------------------------

    private function seedOperationCatalog(): void
    {
        $operations = [
            'clarionApp.llmClient.codingWorkspace.listFiles' => ['path' => '/coding-project/{project}/files', 'method' => 'get'],
            'clarionApp.llmClient.codingWorkspace.readFile' => ['path' => '/coding-project/{project}/file', 'method' => 'get'],
            'clarionApp.llmClient.codingWorkspace.writeFile' => ['path' => '/coding-project/{project}/file', 'method' => 'post'],
            'clarionApp.llmClient.codingWorkspace.deleteFile' => ['path' => '/coding-project/{project}/file', 'method' => 'delete'],
            'clarionApp.llmClient.codingWorkspace.runTests' => ['path' => '/coding-project/{project}/run-tests', 'method' => 'post'],
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
     * straight into the real, unmodified CodingWorkspaceController, so an
     * approved (or relaxed) write/delete reaches disk through the exact
     * same production code a live request would run. Only the last two
     * path segments are pattern-matched (project id + operation name), so
     * the exact URL prefix the operation catalog happens to use here does
     * not matter.
     */
    private function fakeCodingWorkspaceHttp(): void
    {
        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $method = strtoupper($request->method());

            if (!preg_match('#/([^/]+)/(files|file|run-tests|git-status|git-diff)$#', $path, $m)) {
                return Http::response(['error' => 'unmapped test route: '.$path], 500);
            }
            [, $projectId, $suffix] = $m;

            $laravelRequest = in_array($method, ['POST', 'PUT', 'PATCH'], true)
                ? Request::create($request->url(), $method, $request->data())
                : Request::create($request->url(), $method);

            $controller = new CodingWorkspaceController();

            $response = match (true) {
                $suffix === 'files' && $method === 'GET' => $controller->listFiles($laravelRequest, $projectId),
                $suffix === 'file' && $method === 'GET' => $controller->readFile($laravelRequest, $projectId),
                $suffix === 'file' && $method === 'POST' => $controller->writeFile($laravelRequest, $projectId),
                $suffix === 'file' && $method === 'DELETE' => $controller->deleteFile($laravelRequest, $projectId),
                $suffix === 'run-tests' && $method === 'POST' => $controller->runTests($laravelRequest, $projectId),
                $suffix === 'git-status' && $method === 'GET' => $controller->gitStatus($laravelRequest, $projectId),
                $suffix === 'git-diff' && $method === 'GET' => $controller->gitDiff($laravelRequest, $projectId),
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

        // A stub token factory bypasses Passport's real OAuth token
        // minting, which is not bootable in this test environment. The
        // outbound HTTP call is routed straight into the real controller
        // by fakeCodingWorkspaceHttp() below, via Auth::actingAs() -- not
        // by verifying this bearer token -- so its exact value carries no
        // weight here.
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

    private function makeFile(string $dir, string $relativePath, string $content): void
    {
        file_put_contents($dir.'/'.$relativePath, $content);
    }

    private function fileContent(string $dir, string $relativePath): string|false
    {
        return @file_get_contents($dir.'/'.$relativePath);
    }

    private function projectFileExists(string $dir, string $relativePath): bool
    {
        return is_file($dir.'/'.$relativePath);
    }

    private function apiUrl(string $suffix): string
    {
        return '/api/clarion-app/llm-client/'.$suffix;
    }

    private function relaxProject(CodingProject $project, bool $relaxed): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user, 'api')
            ->patchJson($this->apiUrl("coding-project/{$project->id}/confirmation-setting"), ['relaxed' => $relaxed]);
    }

    // -----------------------------------------------------------------
    // Step 1 (regression baseline) -- neither project relaxed
    // -----------------------------------------------------------------

    #[Test]
    public function write_file_on_an_unrelaxed_project_still_pauses_for_confirmation_exactly_as_before(): void
    {
        $this->makeFile($this->tmpDirA, 'note.txt', "original content\n");

        $conversation = $this->makeConversation($this->agent(), $this->projectA);

        $service = $this->service([
            $this->toolCallReply([$this->writeFileCall($this->projectA, 'note.txt', 'new content', 'call_write_1')]),
        ]);

        $result = $service->run($conversation->fresh(), 'Please update note.txt.');

        $this->assertSame('confirmation_required', $result['status'], 'a write on an unrelaxed project must pause, exactly as before this feature');

        $message = Message::find($result['message_id']);
        $this->assertNotNull($message->tool_data['pending_confirmation'] ?? null);
        $this->assertSame(
            "original content\n",
            $this->fileContent($this->tmpDirA, 'note.txt'),
            'the file must be unchanged while confirmation is pending',
        );
    }

    // -----------------------------------------------------------------
    // Step 2 -- relaxing via PATCH returns 200 with confirmation_relaxed true
    // -----------------------------------------------------------------

    #[Test]
    public function patching_relaxed_true_returns_200_with_the_updated_project(): void
    {
        $response = $this->relaxProject($this->projectA, true);

        $response->assertStatus(200);
        $response->assertJsonPath('id', $this->projectA->id);
        $response->assertJsonPath('confirmation_relaxed', true);

        $this->assertTrue((bool) $this->projectA->fresh()->confirmation_relaxed);
    }

    // -----------------------------------------------------------------
    // Step 3 / AS1 / SC-003 -- a relaxed project applies a write directly
    // -----------------------------------------------------------------

    #[Test]
    public function write_file_on_a_relaxed_project_applies_directly_without_a_confirmation_step(): void
    {
        $this->makeFile($this->tmpDirA, 'note.txt', "original content\n");
        $this->relaxProject($this->projectA, true)->assertStatus(200);

        $conversation = $this->makeConversation($this->agent(), $this->projectA);

        $service = $this->service([
            $this->toolCallReply([$this->writeFileCall($this->projectA, 'note.txt', 'relaxed content', 'call_write_2')]),
            $this->plainReply('Done. note.txt was updated.'),
        ]);

        $result = $service->run($conversation->fresh(), 'Please update note.txt.');

        $this->assertSame('completed', $result['status'], 'a relaxed write must apply immediately, never pausing');

        $message = Message::find($result['message_id']);
        $this->assertNull($message->tool_data['pending_confirmation'] ?? null, 'no confirmation marker should ever appear for a relaxed write');
        $this->assertSame(
            'relaxed content',
            $this->fileContent($this->tmpDirA, 'note.txt'),
            'the relaxed write must be applied to disk without a confirmation round-trip',
        );
    }

    // -----------------------------------------------------------------
    // Step 4 / AS2 / AS3 / SC-005 -- project B is unaffected by A's relaxation
    // -----------------------------------------------------------------

    #[Test]
    public function a_second_untouched_project_still_requires_confirmation_after_the_first_is_relaxed(): void
    {
        $this->makeFile($this->tmpDirB, 'note.txt', "B original\n");
        $this->relaxProject($this->projectA, true)->assertStatus(200);

        $this->assertFalse((bool) $this->projectB->fresh()->confirmation_relaxed, 'project B must never be relaxed by project A\'s own setting change');

        $conversation = $this->makeConversation($this->agent(), $this->projectB);

        $service = $this->service([
            $this->toolCallReply([$this->writeFileCall($this->projectB, 'note.txt', 'B changed', 'call_write_b')]),
        ]);

        $result = $service->run($conversation->fresh(), 'Please update note.txt.');

        $this->assertSame('confirmation_required', $result['status'], 'project B must still pause for confirmation, unaffected by project A\'s relaxation');

        $message = Message::find($result['message_id']);
        $this->assertNotNull($message->tool_data['pending_confirmation'] ?? null);
        $this->assertSame("B original\n", $this->fileContent($this->tmpDirB, 'note.txt'));
    }

    // -----------------------------------------------------------------
    // Step 5 / AS4 / FR-009 -- restoring the default requires confirmation again
    // -----------------------------------------------------------------

    #[Test]
    public function restoring_confirmation_after_relaxation_requires_confirmation_again(): void
    {
        $this->makeFile($this->tmpDirA, 'note.txt', "original content\n");

        $this->relaxProject($this->projectA, true)->assertStatus(200);
        $restoreResponse = $this->relaxProject($this->projectA, false);

        $restoreResponse->assertStatus(200);
        $restoreResponse->assertJsonPath('confirmation_relaxed', false);
        $this->assertFalse((bool) $this->projectA->fresh()->confirmation_relaxed);

        $conversation = $this->makeConversation($this->agent(), $this->projectA);

        $service = $this->service([
            $this->toolCallReply([$this->writeFileCall($this->projectA, 'note.txt', 'new content', 'call_write_3')]),
        ]);

        $result = $service->run($conversation->fresh(), 'Please update note.txt.');

        $this->assertSame('confirmation_required', $result['status'], 'restoring the default must require confirmation again');
        $this->assertSame("original content\n", $this->fileContent($this->tmpDirA, 'note.txt'));
    }

    // -----------------------------------------------------------------
    // Step 6 / Edge Case / FR-010 -- relaxation never touches the boundary check
    // -----------------------------------------------------------------

    #[Test]
    public function a_relaxed_project_still_refuses_a_traversal_escape_with_the_ordinary_containment_reason(): void
    {
        $this->relaxProject($this->projectA, true)->assertStatus(200);

        $conversation = $this->makeConversation($this->agent(), $this->projectA);

        $service = $this->service([
            $this->toolCallReply([$this->writeFileCall($this->projectA, '../escape.txt', 'malicious content', 'call_escape')]),
            $this->plainReply('That path is not permitted.'),
        ]);

        $result = $service->run($conversation->fresh(), 'Please update ../escape.txt.');

        $this->assertSame('completed', $result['status'], 'a boundary refusal is not a confirmation pause -- it resolves immediately as a tool error');

        $toolMessage = Message::where('conversation_id', $conversation->id)
            ->whereNotNull('tool_data')
            ->orderBy('id')
            ->first();
        $toolResultContent = $toolMessage->tool_data['tool_results'][0]['content'] ?? '';
        $this->assertStringContainsString('path traversal', (string) $toolResultContent, 'relaxation must never bypass the ordinary containment refusal reason');

        $this->assertFalse(
            $this->projectFileExists(dirname($this->tmpDirA), 'escape.txt'),
            'the traversal write must never actually land outside the workspace, relaxed or not',
        );
    }

    // -----------------------------------------------------------------
    // Step 7 / FR-008 -- deleteFile is also covered by relaxation
    // -----------------------------------------------------------------

    #[Test]
    public function delete_file_on_a_relaxed_project_also_applies_without_a_confirmation_step(): void
    {
        $this->makeFile($this->tmpDirA, 'deleteme.txt', "to be removed\n");
        $this->relaxProject($this->projectA, true)->assertStatus(200);

        $conversation = $this->makeConversation($this->agent(), $this->projectA);

        $service = $this->service([
            $this->toolCallReply([$this->deleteFileCall($this->projectA, 'deleteme.txt', 'call_delete_1')]),
            $this->plainReply('Deleted deleteme.txt.'),
        ]);

        $result = $service->run($conversation->fresh(), 'Delete deleteme.txt.');

        $this->assertSame('completed', $result['status'], 'a relaxed delete must apply immediately, never pausing');

        $message = Message::find($result['message_id']);
        $this->assertNull($message->tool_data['pending_confirmation'] ?? null);
        $this->assertFalse($this->projectFileExists($this->tmpDirA, 'deleteme.txt'), 'the relaxed delete must actually remove the file');
    }

    // -----------------------------------------------------------------
    // Contract error shapes
    // -----------------------------------------------------------------

    #[Test]
    public function patching_a_foreign_owned_project_returns_404_matching_destroys_shape(): void
    {
        $foreignProject = CodingProject::create([
            'user_id' => $this->otherUser->id,
            'name' => 'not yours',
            'root_path' => $this->tmpDirB,
            'test_command' => null,
        ]);

        $response = $this->relaxProject($foreignProject, true);

        $response->assertStatus(404);
        $response->assertJson(['error' => 'Coding project not found', 'code' => 'coding_project_not_found']);
        $this->assertFalse((bool) $foreignProject->fresh()->confirmation_relaxed, 'a foreign-owned project must never be modified');
    }

    #[Test]
    public function patching_an_absent_project_id_returns_404(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->patchJson($this->apiUrl('coding-project/'.(string) Str::uuid().'/confirmation-setting'), ['relaxed' => true]);

        $response->assertStatus(404);
        $response->assertJson(['error' => 'Coding project not found', 'code' => 'coding_project_not_found']);
    }

    #[Test]
    public function patching_with_a_missing_relaxed_field_returns_422(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->patchJson($this->apiUrl("coding-project/{$this->projectA->id}/confirmation-setting"), []);

        $response->assertStatus(422);
    }

    #[Test]
    public function patching_with_a_non_boolean_relaxed_field_returns_422(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->patchJson($this->apiUrl("coding-project/{$this->projectA->id}/confirmation-setting"), ['relaxed' => 'sort-of']);

        $response->assertStatus(422);
    }
}
