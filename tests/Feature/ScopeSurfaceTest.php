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
use ClarionApp\LlmClient\Services\StructuredOutputPresetRegistry;
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
 * A dedicated proof that a task discovered to touch far more files than
 * scope_surface_threshold_files is surfaced to the user as its own,
 * distinct decision point before being applied in full -- and that this
 * aggregate acknowledgment never substitutes for, or bypasses, the
 * ordinary per-file confirmation every write/delete already carries.
 *
 * Structurally mirrors ProjectFileConfirmationTest: every mutation call is
 * routed through the real, unmodified CodingWorkspaceController via
 * fakeCodingWorkspaceHttp(), so a "the file did/did not change on disk"
 * assertion reflects genuine filesystem state. Only the model provider is
 * scripted.
 */
class ScopeSurfaceTest extends TestCase
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

        // A small threshold keeps each test's fixture short-lived while
        // still exercising a genuine crossing (3 already-touched files,
        // a 4th newly crossing).
        config(['llm-client.coding_agent.scope_surface_threshold_files' => 3]);

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

        $this->tmpDir = sys_get_temp_dir().'/coding-agent-scope-surface-'.Str::random(12);
        mkdir($this->tmpDir, 0777, true);

        $this->project = CodingProject::create([
            'user_id' => $this->user->id,
            'name' => 'scope surface project',
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
    // Fixture scaffolding (mirrors ProjectFileConfirmationTest)
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
            'clarionApp.llmClient.codingWorkspace.gitRewriteHistory' => ['path' => '/coding-project/{project}/git-rewrite-history', 'method' => 'post'],
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
            presetRegistry: app(StructuredOutputPresetRegistry::class),
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

    private function writeFileCall(string $relativePath, string $content, string $callId): array
    {
        return $this->toolCall('execute_operation', [
            'operationId' => AgentLoopService::CODING_WORKSPACE_WRITE_FILE_OPERATION_ID,
            'parameters' => [
                'path' => ['project' => $this->project->id],
                'body' => ['path' => $relativePath, 'content' => $content],
            ],
        ], $callId);
    }

    private function agent(): Agent
    {
        return app(CodingAgentProvisioner::class)->ensureForUser($this->user->id);
    }

    private function makeConversation(Agent $agent): Conversation
    {
        return Conversation::factory()->create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'title' => 'Coding conversation',
            'agent_id' => $agent->id,
            'agent_version_id' => $agent->current_version_id,
            'coding_project_id' => $this->project->id,
        ]);
    }

    private function fileContent(string $relativePath): string|false
    {
        return @file_get_contents($this->tmpDir.'/'.$relativePath);
    }

    private function projectFileExists(string $relativePath): bool
    {
        return is_file($this->tmpDir.'/'.$relativePath);
    }

    /**
     * Approves a pending confirmation and returns the continuation's own
     * result -- since the confirmed run() work happens inline inside
     * resumeSync(), a fresh pause the very next tool call raises is
     * returned directly by this same call, never requiring a second
     * top-level run() (which would mint an unrelated, fresh run and reset
     * this run's own touched-file count to zero).
     */
    private function approve(AgentLoopService $service, Conversation $conversation, array $result): array
    {
        $message = Message::find($result['message_id']);

        return $service->resumeSync($conversation->fresh(), $message, true);
    }

    // -----------------------------------------------------------------
    // Crossing the threshold produces a distinct scope_surface marker
    // -----------------------------------------------------------------

    #[Test]
    public function a_file_that_newly_crosses_the_threshold_produces_a_scope_surface_marker_distinct_from_the_ordinary_marker(): void
    {
        $conversation = $this->makeConversation($this->agent());

        // One continuous run: a.txt/b.txt/c.txt approved in turn (each
        // still under the threshold of 3), then d.txt's own attempt --
        // which must pause as scope_surface rather than the ordinary
        // api_call marker every one of the first three received.
        $service = $this->service([
            $this->toolCallReply([$this->writeFileCall('a.txt', 'content of a.txt', 'call_a')]),
            $this->toolCallReply([$this->writeFileCall('b.txt', 'content of b.txt', 'call_b')]),
            $this->toolCallReply([$this->writeFileCall('c.txt', 'content of c.txt', 'call_c')]),
            $this->toolCallReply([$this->writeFileCall('d.txt', 'content of d.txt', 'call_d1')]),
        ]);

        $result = $service->run($conversation->fresh(), 'Please create a.txt, b.txt, c.txt, and d.txt.');
        foreach (['a.txt', 'b.txt', 'c.txt'] as $name) {
            $this->assertSame('confirmation_required', $result['status'], "{$name} must pause for its own confirmation");
            $this->assertSame(
                'api_call',
                $result['confirmation']['confirmation_type'] ?? null,
                "{$name} is at or under the threshold — it must never itself trigger scope-surfacing",
            );
            $result = $this->approve($service, $conversation, $result);
        }

        $this->assertSame('confirmation_required', $result['status'], 'the crossing file must still pause');
        $this->assertSame(
            'scope_surface',
            $result['confirmation']['confirmation_type'] ?? null,
            'the file that newly crosses the threshold must produce a scope_surface marker, distinct from the ordinary api_call marker',
        );
        $this->assertSame(3, $result['confirmation']['threshold'] ?? null);
        $this->assertSame('d.txt', $result['confirmation']['would_add'] ?? null);
        $this->assertEqualsCanonicalizing(
            ['a.txt', 'b.txt', 'c.txt'],
            $result['confirmation']['files_touched_so_far'] ?? [],
        );

        // Nothing about d.txt has happened yet -- scope-surfacing pauses
        // before the write, exactly like an ordinary confirmation does.
        $this->assertFalse($this->projectFileExists('d.txt'));
        $this->assertSame('content of a.txt', $this->fileContent('a.txt'));
        $this->assertSame('content of b.txt', $this->fileContent('b.txt'));
        $this->assertSame('content of c.txt', $this->fileContent('c.txt'));
    }

    // -----------------------------------------------------------------
    // A request whose actual scope stays under the threshold never
    // produces the marker
    // -----------------------------------------------------------------

    #[Test]
    public function a_request_whose_actual_scope_stays_under_the_threshold_never_produces_the_marker(): void
    {
        $conversation = $this->makeConversation($this->agent());

        // Threshold is 3; only two files are ever touched -- the
        // interruption must never fire.
        $service = $this->service([
            $this->toolCallReply([$this->writeFileCall('a.txt', 'content of a.txt', 'call_a')]),
            $this->toolCallReply([$this->writeFileCall('b.txt', 'content of b.txt', 'call_b')]),
            $this->plainReply('a.txt and b.txt created.'),
        ]);

        $result = $service->run($conversation->fresh(), 'Please create a.txt and b.txt.');
        foreach (['a.txt', 'b.txt'] as $name) {
            $this->assertSame('confirmation_required', $result['status']);
            $this->assertSame(
                'api_call',
                $result['confirmation']['confirmation_type'] ?? null,
                'a request whose actual scope never crosses the threshold must never produce a scope_surface marker',
            );
            $result = $this->approve($service, $conversation, $result);
        }

        $this->assertSame('completed', $result['status']);
        $this->assertSame('content of a.txt', $this->fileContent('a.txt'));
        $this->assertSame('content of b.txt', $this->fileContent('b.txt'));
    }

    // -----------------------------------------------------------------
    // Approving the scope_surface marker never bypasses the crossing
    // file's own ordinary per-file confirmation, nor any later file's
    // -----------------------------------------------------------------

    #[Test]
    public function approval_does_not_bypass_per_file_confirmation(): void
    {
        $conversation = $this->makeConversation($this->agent());

        $service = $this->service([
            // a.txt, b.txt, c.txt: reach the threshold (3), each its own
            // ordinary confirmation.
            $this->toolCallReply([$this->writeFileCall('a.txt', 'content of a.txt', 'call_a')]),
            $this->toolCallReply([$this->writeFileCall('b.txt', 'content of b.txt', 'call_b')]),
            $this->toolCallReply([$this->writeFileCall('c.txt', 'content of c.txt', 'call_c')]),
            // d.txt: crosses the threshold -- pauses as scope_surface.
            $this->toolCallReply([$this->writeFileCall('d.txt', 'content of d.txt', 'call_d1')]),
            // After the scope_surface marker is approved, the model
            // reissues d.txt's own write -- this time it must reach its
            // ordinary per-file confirmation, never execute directly.
            $this->toolCallReply([$this->writeFileCall('d.txt', 'content of d.txt', 'call_d2')]),
            // e.txt: a later file, still past the threshold. Since this
            // run has already had one approved scope_surface confirmation,
            // e.txt must reach only its own ordinary confirmation, never
            // a second scope_surface interruption and never an
            // auto-approval.
            $this->toolCallReply([$this->writeFileCall('e.txt', 'content of e.txt', 'call_e')]),
            $this->plainReply('a.txt through e.txt created.'),
        ]);

        $result = $service->run($conversation->fresh(), 'Please create a.txt, b.txt, c.txt, d.txt, and e.txt.');
        foreach (['a.txt', 'b.txt', 'c.txt'] as $name) {
            $this->assertSame('api_call', $result['confirmation']['confirmation_type'] ?? null, "{$name} must be an ordinary confirmation");
            $result = $this->approve($service, $conversation, $result);
        }

        $this->assertSame('scope_surface', $result['confirmation']['confirmation_type'] ?? null);

        // Approve the aggregate scope acknowledgment.
        $afterScope = $this->approve($service, $conversation, $result);

        // d.txt must NOT have been written -- approving scope_surface is
        // not itself a per-file approval.
        $this->assertFalse($this->projectFileExists('d.txt'), 'approving scope_surface must never itself write the crossing file');

        // The loop must have continued straight to d.txt's own, ordinary
        // per-file confirmation -- not skipped it, not executed silently.
        $this->assertSame('confirmation_required', $afterScope['status']);
        $this->assertSame(
            'api_call',
            $afterScope['confirmation']['confirmation_type'] ?? null,
            'd.txt must still receive its own ordinary api_call confirmation after scope_surface is approved',
        );

        $afterOrdinary = $this->approve($service, $conversation, $afterScope);
        $this->assertSame(
            'content of d.txt',
            $this->fileContent('d.txt'),
            'd.txt is written only once its own per-file confirmation is separately approved',
        );

        // A later file (e.txt), still past the threshold, must receive
        // its own ordinary confirmation too -- not a second scope_surface
        // interruption (already surfaced this run) and not a silent
        // auto-approval.
        $this->assertSame('confirmation_required', $afterOrdinary['status']);
        $this->assertSame(
            'api_call',
            $afterOrdinary['confirmation']['confirmation_type'] ?? null,
            'a later file past an already-surfaced threshold must still receive its own ordinary confirmation, never a second scope_surface prompt and never an auto-approval',
        );
        $this->assertFalse($this->projectFileExists('e.txt'));

        $final = $this->approve($service, $conversation, $afterOrdinary);
        $this->assertSame('completed', $final['status']);
        $this->assertSame('content of e.txt', $this->fileContent('e.txt'));
    }
}
