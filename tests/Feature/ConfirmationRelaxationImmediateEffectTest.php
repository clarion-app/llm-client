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
 * 122-workspace-browser-ui, US2, T021 (FR-004/SC-002, Acceptance Scenarios
 * 1-2, mutation checklist row 11).
 *
 * `ConfirmationRelaxationJourneyTest` (spec 121) already covers
 * relax-then-act and restore-then-act extensively, but every one of its
 * cases toggles the setting BEFORE the conversation is ever created. Spec
 * 122's own Acceptance Scenario 1 wording is specifically about a
 * conversation "already in progress" -- one that existed, and already
 * made an attempt, before the toggle happened. This file establishes that
 * exact ordering: conversation created -> first write reaches (or
 * resolves at) the confirmation-pending state under the ORIGINAL setting
 * -> the setting is toggled via PATCH -> the SAME, already-open
 * conversation's next write reflects the new setting immediately, with no
 * separate propagation step.
 *
 * Per plan.md's Constraints (AgentLoopService::handleExecuteOperation()
 * already re-reads confirmation_relaxed fresh on every call), both cases
 * below are expected to pass on first run -- run honestly, not assumed.
 */
class ConfirmationRelaxationImmediateEffectTest extends TestCase
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

        $this->tmpDir = sys_get_temp_dir().'/coding-agent-relaxation-immediate-'.Str::random(12);
        mkdir($this->tmpDir, 0777, true);

        $this->project = CodingProject::create([
            'user_id' => $this->user->id,
            'name' => 'immediate effect project',
            'root_path' => $this->tmpDir,
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

        if (is_dir($this->tmpDir)) {
            $this->removeDirectory($this->tmpDir);
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
            'clarionApp.llmClient.codingWorkspace.gitCommit' => ['path' => '/coding-project/{project}/git-commit', 'method' => 'post'],
            'clarionApp.llmClient.codingWorkspace.gitPush' => ['path' => '/coding-project/{project}/git-push', 'method' => 'post'],
            'clarionApp.llmClient.codingWorkspace.gitBranch' => ['path' => '/coding-project/{project}/git-branch', 'method' => 'post'],
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

    private function plainReply(string $content): array
    {
        return ['choices' => [['message' => ['content' => $content, 'tool_calls' => []]]]];
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
    // AS1 -- relaxing mid-conversation applies to that conversation's
    // very next attempt, with no restart and no separate propagation step.
    // -----------------------------------------------------------------

    #[Test]
    public function relaxing_mid_conversation_applies_to_the_same_already_open_conversations_next_write_with_no_confirmation_step(): void
    {
        $this->makeFile($this->tmpDir, 'note.txt', "original content\n");

        // The conversation exists, and already makes one attempt, WHILE
        // the workspace still requires confirmation -- this is what
        // "already in progress" actually means for this scenario.
        $conversation = $this->makeConversation($this->agent(), $this->project);

        $firstAttempt = $this->service([
            $this->toolCallReply([$this->writeFileCall($this->project, 'note.txt', 'first attempt', 'call_first')]),
        ]);
        $firstResult = $firstAttempt->run($conversation->fresh(), 'Please update note.txt.');

        $this->assertSame('confirmation_required', $firstResult['status'], 'the first attempt must pause -- the workspace still requires confirmation at this point');
        $firstMessage = Message::find($firstResult['message_id']);
        $this->assertNotNull($firstMessage->tool_data['pending_confirmation'] ?? null);
        $this->assertSame("original content\n", $this->fileContent($this->tmpDir, 'note.txt'), 'the paused write must not have applied');

        // Now the workspace is relaxed, via the same PATCH endpoint this
        // feature's browser reuses unmodified.
        $this->relaxProject($this->project, true)->assertStatus(200);

        // The SAME, already-open conversation's NEXT write must reflect
        // the new setting immediately -- no restart, no separate
        // propagation step (SC-002).
        $secondAttempt = $this->service([
            $this->toolCallReply([$this->writeFileCall($this->project, 'note.txt', 'second attempt, relaxed', 'call_second')]),
            $this->plainReply('Done. note.txt was updated.'),
        ]);
        $secondResult = $secondAttempt->run($conversation->fresh(), 'Please update note.txt again.');

        $this->assertSame('completed', $secondResult['status'], 'the second attempt, made after relaxation, must apply directly with no confirmation pause');
        $secondMessage = Message::find($secondResult['message_id']);
        $this->assertNull($secondMessage->tool_data['pending_confirmation'] ?? null, 'no confirmation marker should appear once the workspace is relaxed');
        $this->assertSame(
            'second attempt, relaxed',
            $this->fileContent($this->tmpDir, 'note.txt'),
            'the relaxed write must be applied to disk without a confirmation round-trip, for the same conversation that was already in progress at relaxation time',
        );
    }

    // -----------------------------------------------------------------
    // AS2 -- restoring mid-conversation requires confirmation again for
    // that same conversation's very next attempt.
    // -----------------------------------------------------------------

    #[Test]
    public function restoring_mid_conversation_requires_confirmation_again_for_the_same_already_open_conversations_next_write(): void
    {
        $this->makeFile($this->tmpDir, 'note.txt', "original content\n");

        // Relaxed from the start this time, so the first attempt applies
        // directly.
        $this->relaxProject($this->project, true)->assertStatus(200);

        $conversation = $this->makeConversation($this->agent(), $this->project);

        $firstAttempt = $this->service([
            $this->toolCallReply([$this->writeFileCall($this->project, 'note.txt', 'first attempt, relaxed', 'call_first')]),
            $this->plainReply('Done. note.txt was updated.'),
        ]);
        $firstResult = $firstAttempt->run($conversation->fresh(), 'Please update note.txt.');

        $this->assertSame('completed', $firstResult['status'], 'the first attempt must apply directly -- the workspace is relaxed at this point');
        $this->assertSame('first attempt, relaxed', $this->fileContent($this->tmpDir, 'note.txt'));

        // Now the default confirmation requirement is restored, via the
        // same already-open conversation's own workspace.
        $this->relaxProject($this->project, false)->assertStatus(200);

        $secondAttempt = $this->service([
            $this->toolCallReply([$this->writeFileCall($this->project, 'note.txt', 'second attempt, should pause', 'call_second')]),
        ]);
        $secondResult = $secondAttempt->run($conversation->fresh(), 'Please update note.txt again.');

        $this->assertSame('confirmation_required', $secondResult['status'], 'the second attempt, made after restoring confirmation, must pause again for the same already-open conversation');
        $secondMessage = Message::find($secondResult['message_id']);
        $this->assertNotNull($secondMessage->tool_data['pending_confirmation'] ?? null);
        $this->assertSame(
            'first attempt, relaxed',
            $this->fileContent($this->tmpDir, 'note.txt'),
            'the paused second write must not have applied -- the file must still hold whatever the first (relaxed) write left it as',
        );
    }
}
