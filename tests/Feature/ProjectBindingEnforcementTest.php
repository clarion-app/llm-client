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
 * 112-coding-agent, US4 (P2), T039 (D2, FR-007/FR-008, quickstart row 7,
 * mutation-checklist rows 3/14).
 *
 * Proves the loop-level project-binding guard (AgentLoopService::
 * enforceCodingProjectBinding(), Foundational T018) actually holds under
 * adversarial and edge-case conditions, not merely the controller's own
 * second-layer ownership check:
 *
 *  - a request naming a project other than the one the conversation is
 *    bound to -- even one the requesting user legitimately owns -- is
 *    rejected at the AgentLoopService seam, BEFORE any HTTP call is ever
 *    dispatched (Http::assertNothingSent());
 *  - a conversation with no bound project at all is rejected the same way;
 *  - binding a project owned by another user at conversation creation is
 *    refused with a 422, never persisted;
 *  - a conversation bound to a coding_project_id whose CodingProject row
 *    has since been soft-deleted -- the "not actually registered" edge
 *    case, distinct from a still-registered project whose on-disk
 *    directory was removed (PathContainmentAdversarialTest) -- is refused
 *    with a clear, structurally distinct statement, not the same generic
 *    "not bound" wording the first two cases produce.
 */
class ProjectBindingEnforcementTest extends TestCase
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

        $this->tmpDirA = sys_get_temp_dir().'/coding-agent-binding-a-'.Str::random(12);
        $this->tmpDirB = sys_get_temp_dir().'/coding-agent-binding-b-'.Str::random(12);
        mkdir($this->tmpDirA, 0777, true);
        mkdir($this->tmpDirB, 0777, true);

        // Both owned by the SAME user -- the adversarial case this phase
        // exists to prove is that legitimate ownership of project B is
        // still not enough to reach it from a conversation bound to A.
        $this->projectA = CodingProject::create([
            'user_id' => $this->user->id,
            'name' => 'project A',
            'root_path' => $this->tmpDirA,
            'test_command' => null,
        ]);
        $this->projectB = CodingProject::create([
            'user_id' => $this->user->id,
            'name' => 'project B',
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

        foreach ([$this->tmpDirA, $this->tmpDirB] as $dir) {
            if (is_dir($dir)) {
                $this->removeDirectory($dir);
            }
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
    // Fixture scaffolding (mirrors ProjectFileConfirmationTest exactly --
    // a real, unmodified coding agent and pause/resume mechanism, only the
    // model provider itself scripted)
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

    /**
     * Routes the outbound HTTP call straight into the real, unmodified
     * CodingWorkspaceController -- so if a request under test DOES reach
     * this far (the soft-deleted-project case, unlike the two loop-level
     * rejections), the 404 it produces is the actual production
     * controller's own response, not a hand-simulated stand-in.
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

    private function listFilesCall(string $projectId, string $callId): array
    {
        return $this->toolCall('execute_operation', [
            'operationId' => 'clarionApp.llmClient.codingWorkspace.listFiles',
            'parameters' => ['path' => ['project' => $projectId]],
        ], $callId);
    }

    private function agent(): Agent
    {
        return app(CodingAgentProvisioner::class)->ensureForUser($this->user->id);
    }

    private function makeConversation(Agent $agent, ?string $codingProjectId): Conversation
    {
        return Conversation::factory()->create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'title' => 'Binding-enforcement conversation',
            'agent_id' => $agent->id,
            'agent_version_id' => $agent->current_version_id,
            'coding_project_id' => $codingProjectId,
        ]);
    }

    /**
     * Looked up by conversation, not by $result['message_id'] -- a run
     * whose sole tool result is mistaken for a success by
     * allExecuteOperationsSucceeded() (which only recognizes a plain
     * {"error": ...} json shape, not McpToolExecutor's "Error: <body>"
     * prefix on an HTTP-failure result -- exactly what the soft-deleted-
     * project case below produces) short-circuits with 'message_id' =>
     * null, even though the tool-call message itself was still stored.
     */
    private function toolResultContent(Conversation $conversation): string
    {
        $toolMessage = Message::where('conversation_id', $conversation->id)
            ->whereNotNull('tool_data')
            ->orderBy('id')
            ->first();
        $this->assertNotNull($toolMessage, 'fixture sanity: the run must have actually attempted execute_operation');

        return (string) ($toolMessage->tool_data['tool_results'][0]['content'] ?? '');
    }

    // -----------------------------------------------------------------
    // A different project id -- even one the user legitimately owns --
    // is rejected at the AgentLoopService seam, before any HTTP call
    // -----------------------------------------------------------------

    #[Test]
    public function a_request_naming_a_different_even_legitimately_owned_project_is_rejected_at_the_agent_loop_seam_before_any_http_call(): void
    {
        // Bound to A, but the tool call names B -- a project this very
        // user does legitimately own.
        $conversation = $this->makeConversation($this->agent(), $this->projectA->id);

        $service = $this->service([
            $this->toolCallReply([$this->listFilesCall($this->projectB->id, 'call_cross_project')]),
            $this->plainReply('I cannot access that project from this conversation.'),
        ]);

        $result = $service->run($conversation->fresh(), 'List the files in the other project.');

        $this->assertSame('completed', $result['status'], 'a rejection is fed back to the model, not a pause -- the turn still completes');

        $content = $this->toolResultContent($conversation);
        $decoded = json_decode($content, true);
        $this->assertIsArray($decoded, 'the loop-level rejection is plain json, never wrapped in an "Error: " HTTP-failure prefix');
        $this->assertSame(
            'Operation rejected: this conversation is not bound to the requested project.',
            $decoded['error'] ?? null,
        );

        Http::assertNothingSent();
    }

    // -----------------------------------------------------------------
    // No bound project at all is rejected the same way
    // -----------------------------------------------------------------

    #[Test]
    public function a_conversation_with_no_bound_project_is_rejected_the_same_way(): void
    {
        $conversation = $this->makeConversation($this->agent(), null);

        $service = $this->service([
            $this->toolCallReply([$this->listFilesCall($this->projectA->id, 'call_unbound')]),
            $this->plainReply('I have no project to work in.'),
        ]);

        $result = $service->run($conversation->fresh(), 'List the project files.');

        $this->assertSame('completed', $result['status']);

        $content = $this->toolResultContent($conversation);
        $decoded = json_decode($content, true);
        $this->assertIsArray($decoded);
        $this->assertSame(
            'Operation rejected: this conversation is not bound to the requested project.',
            $decoded['error'] ?? null,
            'an unbound conversation must be refused with the exact same wording as a wrong-project request -- both are "not this conversation\'s project"',
        );

        Http::assertNothingSent();
    }

    // -----------------------------------------------------------------
    // Binding a project owned by another user is refused with a 422
    // -----------------------------------------------------------------

    #[Test]
    public function binding_a_project_owned_by_another_user_is_refused(): void
    {
        $foreignProject = CodingProject::create([
            'user_id' => $this->otherUser->id,
            'name' => 'not yours',
            'root_path' => sys_get_temp_dir(),
            'test_command' => null,
        ]);

        $response = $this->postJson('/api/clarion-app/llm-client/conversation', [
            'coding_project_id' => $foreignProject->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['coding_project_id']);

        $this->assertSame(
            0,
            Conversation::where('coding_project_id', $foreignProject->id)->count(),
            'no conversation may ever be persisted bound to a project the requesting user does not own',
        );
    }

    // -----------------------------------------------------------------
    // The "not actually registered" edge case: bound to a since
    // soft-deleted CodingProject row -- distinct from both the loop-level
    // rejections above and from PathContainmentAdversarialTest's
    // still-registered-but-gone-directory case
    // -----------------------------------------------------------------

    #[Test]
    public function a_conversation_bound_to_a_since_soft_deleted_project_is_refused_with_a_clear_distinct_statement(): void
    {
        $tmpDirC = sys_get_temp_dir().'/coding-agent-binding-c-'.Str::random(12);
        mkdir($tmpDirC, 0777, true);

        $projectC = CodingProject::create([
            'user_id' => $this->user->id,
            'name' => 'project C, about to be deleted',
            'root_path' => $tmpDirC,
            'test_command' => null,
        ]);

        $conversation = $this->makeConversation($this->agent(), $projectC->id);

        // The row is soft-deleted AFTER the conversation is already bound
        // to it -- coding_project_id is immutable, so the conversation
        // still names this id.
        $projectC->delete();
        $this->assertSoftDeleted('coding_projects', ['id' => $projectC->id]);

        $service = $this->service([
            // The tool call requests the SAME id the conversation is
            // bound to -- the loop-level guard's string comparison
            // matches, so unlike the two cases above, this one is NOT
            // caught before the HTTP call.
            $this->toolCallReply([$this->listFilesCall($projectC->id, 'call_deleted_project')]),
            $this->plainReply('That project is no longer registered.'),
        ]);

        $result = $service->run($conversation->fresh(), 'List the project files.');

        $this->assertSame('completed', $result['status']);

        // Unlike the loop-level rejection cases, this DOES reach the real
        // controller -- CodingProject's default query excludes a
        // soft-deleted row, so CodingWorkspaceController::findOwnedProject()
        // genuinely returns null and the controller's own 404 fires.
        Http::assertSent(fn ($req) => str_contains((string) parse_url($req->url(), PHP_URL_PATH), '/files'));

        $content = $this->toolResultContent($conversation);

        $this->assertStringContainsString(
            'Coding project not found',
            $content,
            'the soft-deleted-project case must be refused with a clear statement naming the project as not found',
        );
        $this->assertStringContainsString(
            'coding_project_not_found',
            $content,
            'the rejection must carry a distinct machine-readable code, not just prose',
        );

        // Structurally distinct from the two loop-level rejections above:
        // those are plain, un-prefixed json ({"error": "..."}); this one
        // is an HTTP-failure result, wrapped with an "Error: " prefix
        // around the controller's real response body -- never the same
        // "not bound to the requested project" wording.
        $this->assertStringNotContainsString(
            'not bound to the requested project',
            $content,
            'a since-deleted project must not be reported with the same generic wording as a wrong/unbound project -- the two failure modes are different and must read differently',
        );
    }
}
