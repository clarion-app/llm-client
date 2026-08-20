<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Controllers\CodingWorkspaceController;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\CodingCommandExecution;
use ClarionApp\LlmClient\Models\CodingProject;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\CodingAgentProvisioner;
use ClarionApp\LlmClient\Services\DockerCommandExecutor;
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
 * 123-sandboxed-shell-execution, US2, T024 (spec.md Acceptance Scenarios
 * 1-4, contracts/run-command.md §1, contracts/command-allowlist.md §2).
 *
 * Reuses ProjectFileConfirmationTest.php's real-provisioned-coding-agent
 * setup shape -- a real pause/resume through the real `coding` agent
 * definition, never a hand-simulated stand-in. Every runCommand call is
 * routed all the way through to the real CodingWorkspaceController (see
 * fakeCodingWorkspaceHttp() below), with DockerCommandExecutor swapped
 * for a Mockery double bound into the container so this file never
 * touches a real `docker` binary -- genuine container behavior is proven
 * separately by tests/RealDocker/*.
 *
 * Written before AgentLoopService::handleExecuteOperation()'s allowlist
 * check and coding.yaml's confirmation_required entry exist -- expected
 * to FAIL red (every command running immediately instead of pausing)
 * until T026-T028 land.
 */
class CommandExecutionConfirmationJourneyTest extends TestCase
{
    private User $user;

    private Server $server;

    private CodingProject $projectA;

    private CodingProject $projectB;

    private string $tmpDirA;

    private string $tmpDirB;

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

        $this->tmpDirA = sys_get_temp_dir().'/coding-agent-cmd-confirm-a-'.Str::random(12);
        mkdir($this->tmpDirA, 0777, true);
        $this->tmpDirB = sys_get_temp_dir().'/coding-agent-cmd-confirm-b-'.Str::random(12);
        mkdir($this->tmpDirB, 0777, true);

        $this->projectA = CodingProject::create([
            'user_id' => $this->user->id,
            'name' => 'confirmation project A',
            'root_path' => $this->tmpDirA,
            'test_command' => null,
        ]);
        $this->projectB = CodingProject::create([
            'user_id' => $this->user->id,
            'name' => 'confirmation project B',
            'root_path' => $this->tmpDirB,
            'test_command' => null,
        ]);

        $this->seedOperationCatalog();
        $this->actingAs($this->user, 'api');
        $this->fakeCodingWorkspaceHttp();
        $this->bindFakeExecutor([
            'status' => 'completed',
            'exit_code' => 0,
            'timed_out' => false,
            'stdout' => 'ok',
            'stderr' => '',
            'output_truncated' => false,
            'duration_ms' => 5,
        ]);
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

        DB::table('coding_command_executions')->delete();
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

    /**
     * Routes the outbound HTTP call the agent loop's tool executor makes
     * straight into the real, unmodified CodingWorkspaceController via
     * the container (app(CodingWorkspaceController::class), not `new`)
     * so bindFakeExecutor()'s DockerCommandExecutor instance binding is
     * actually honored -- the controller's own constructor default
     * (`new DockerCommandExecutor()`) would otherwise bypass it.
     */
    private function fakeCodingWorkspaceHttp(): void
    {
        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $method = strtoupper($request->method());

            if (!preg_match('#/([^/]+)/(files|file|run-tests|run-command|git-status|git-diff)$#', $path, $m)) {
                return Http::response(['error' => 'unmapped test route: '.$path], 500);
            }
            [, $projectId, $suffix] = $m;

            $laravelRequest = in_array($method, ['POST', 'PUT', 'PATCH'], true)
                ? Request::create($request->url(), $method, $request->data())
                : Request::create($request->url(), $method);

            $controller = app(CodingWorkspaceController::class);

            $response = match (true) {
                $suffix === 'files' && $method === 'GET' => $controller->listFiles($laravelRequest, $projectId),
                $suffix === 'file' && $method === 'GET' => $controller->readFile($laravelRequest, $projectId),
                $suffix === 'file' && $method === 'POST' => $controller->writeFile($laravelRequest, $projectId),
                $suffix === 'file' && $method === 'DELETE' => $controller->deleteFile($laravelRequest, $projectId),
                $suffix === 'run-tests' && $method === 'POST' => $controller->runTests($laravelRequest, $projectId),
                $suffix === 'run-command' && $method === 'POST' => $controller->runCommand($laravelRequest, $projectId),
                $suffix === 'git-status' && $method === 'GET' => $controller->gitStatus($laravelRequest, $projectId),
                $suffix === 'git-diff' && $method === 'GET' => $controller->gitDiff($laravelRequest, $projectId),
                default => response()->json(['error' => 'unmapped test route: '.$suffix.' '.$method], 500),
            };

            return Http::response($response->getData(true), $response->getStatusCode());
        });
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function bindFakeExecutor(array $result): void
    {
        $fake = Mockery::mock(DockerCommandExecutor::class);
        $fake->shouldReceive('run')->andReturn($result);
        $this->app->instance(DockerCommandExecutor::class, $fake);
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

    private function apiUrl(string $suffix): string
    {
        return '/api/clarion-app/llm-client/'.$suffix;
    }

    private function setAllowlist(CodingProject $project, array $patterns): void
    {
        $this->patchJson($this->apiUrl("coding-project/{$project->id}/command-allowlist"), [
            'patterns' => $patterns,
        ])->assertStatus(200);
    }

    private function relaxConfirmation(CodingProject $project, bool $relaxed): void
    {
        $this->patchJson($this->apiUrl("coding-project/{$project->id}/confirmation-setting"), [
            'relaxed' => $relaxed,
        ])->assertStatus(200);
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

    private function runCommandCall(CodingProject $project, string $command, string $callId): array
    {
        return $this->toolCall('execute_operation', [
            'operationId' => AgentLoopService::CODING_WORKSPACE_RUN_COMMAND_OPERATION_ID,
            'parameters' => [
                'path' => ['project' => $project->id],
                'body' => ['command' => $command],
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

    // -----------------------------------------------------------------
    // AS1/SC-002 -- empty allowlist pauses, showing the actual command
    // -----------------------------------------------------------------

    #[Test]
    public function a_command_against_an_empty_allowlist_pauses_for_confirmation_showing_the_exact_command_text(): void
    {
        $conversation = $this->makeConversation($this->agent(), $this->projectA);

        $service = $this->service([
            $this->toolCallReply([$this->runCommandCall($this->projectA, 'phpunit', 'call_cmd_1')]),
        ]);

        $result = $service->run($conversation->fresh(), 'Run phpunit.');

        $this->assertSame('confirmation_required', $result['status'], 'a command not matching the (empty) allowlist must pause');

        $message = Message::find($result['message_id']);
        $pending = $message->tool_data['pending_confirmation'] ?? null;
        $this->assertNotNull($pending);
        $this->assertSame('coding_workspace_command', $pending['confirmation_type'] ?? null);
        $this->assertSame(
            'phpunit',
            $pending['arguments']['body']['command'] ?? null,
            'the confirmation must show the actual, verbatim command text',
        );

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('coding_command_executions')->where('coding_project_id', $this->projectA->id)->count(), 'nothing must have run yet');
    }

    // -----------------------------------------------------------------
    // Checklist row 4's target -- confirmation-by-default is real, not
    // vacuous: a command must genuinely stay paused rather than
    // resolving STATUS_ALLOW unconditionally.
    // -----------------------------------------------------------------

    #[Test]
    public function the_confirmation_pause_never_appears_for_a_command_that_should_have_been_auto_allowed_only_when_a_real_path_grants_it(): void
    {
        // Sanity companion to the AS1 case above: with nothing granting a
        // bypass, the pause is the only reachable outcome. Documented
        // here as the exact assertion the mutation-testing checklist
        // (quickstart row 4: "make the runCommand operation always
        // resolve STATUS_ALLOW") is meant to falsify.
        $conversation = $this->makeConversation($this->agent(), $this->projectA);

        $service = $this->service([
            $this->toolCallReply([$this->runCommandCall($this->projectA, 'rm -rf /', 'call_cmd_danger')]),
        ]);

        $result = $service->run($conversation->fresh(), 'Run rm -rf /.');

        $this->assertSame('confirmation_required', $result['status']);
        Http::assertNothingSent();
    }

    // -----------------------------------------------------------------
    // AS2/SC-003 -- adding the exact pattern lets it run without a prompt
    // -----------------------------------------------------------------

    #[Test]
    public function adding_the_exact_pattern_to_the_allowlist_lets_the_identical_command_run_without_a_prompt(): void
    {
        $this->setAllowlist($this->projectA, ['phpunit']);

        $conversation = $this->makeConversation($this->agent(), $this->projectA);

        $service = $this->service([
            $this->toolCallReply([$this->runCommandCall($this->projectA, 'phpunit', 'call_cmd_2')]),
            $this->plainReply('phpunit ran.'),
        ]);

        $result = $service->run($conversation->fresh(), 'Run phpunit.');

        $this->assertSame('completed', $result['status'], 'an allowlisted command must run immediately, never pausing');

        $message = Message::find($result['message_id']);
        $this->assertNull($message->tool_data['pending_confirmation'] ?? null);

        Http::assertSent(fn ($req) => strtoupper($req->method()) === 'POST' && str_contains((string) parse_url($req->url(), PHP_URL_PATH), '/run-command'));
        $this->assertSame(1, DB::table('coding_command_executions')->where('coding_project_id', $this->projectA->id)->count());
    }

    // -----------------------------------------------------------------
    // AS4/FR-006/Edge Case (checklist row 5's target) -- a non-wildcard
    // allowlist entry does not match a command with extra arguments; the
    // wildcard form is opt-in.
    // -----------------------------------------------------------------

    #[Test]
    public function a_non_wildcard_allowlist_entry_does_not_match_a_command_with_extra_arguments(): void
    {
        $this->setAllowlist($this->projectA, ['phpunit']);

        $conversation = $this->makeConversation($this->agent(), $this->projectA);

        $service = $this->service([
            $this->toolCallReply([$this->runCommandCall($this->projectA, 'phpunit --coverage-html=/tmp/out', 'call_cmd_3')]),
        ]);

        $result = $service->run($conversation->fresh(), 'Run phpunit with coverage.');

        $this->assertSame(
            'confirmation_required',
            $result['status'],
            'a bare "phpunit" pattern must not match "phpunit --coverage-html=/tmp/out" -- proving the real matcher governs, not a prefix check',
        );
        Http::assertNothingSent();
    }

    #[Test]
    public function switching_the_allowlist_to_the_wildcard_form_lets_the_same_command_with_arguments_run(): void
    {
        $this->setAllowlist($this->projectA, ['phpunit *']);

        $conversation = $this->makeConversation($this->agent(), $this->projectA);

        $service = $this->service([
            $this->toolCallReply([$this->runCommandCall($this->projectA, 'phpunit --coverage-html=/tmp/out', 'call_cmd_4')]),
            $this->plainReply('phpunit ran with coverage.'),
        ]);

        $result = $service->run($conversation->fresh(), 'Run phpunit with coverage.');

        $this->assertSame(
            'completed',
            $result['status'],
            'the wildcard form is opt-in -- once set, it must let the identical command with arguments run without a prompt',
        );
        Http::assertSent(fn ($req) => strtoupper($req->method()) === 'POST' && str_contains((string) parse_url($req->url(), PHP_URL_PATH), '/run-command'));
    }

    // -----------------------------------------------------------------
    // Independent path (command-allowlist.md §2) -- confirmation_relaxed
    // alone (empty allowlist) also lets it run.
    // -----------------------------------------------------------------

    #[Test]
    public function confirmation_relaxed_alone_with_an_empty_allowlist_also_lets_the_command_run_without_a_prompt(): void
    {
        $this->relaxConfirmation($this->projectA, true);
        $this->assertSame([], $this->projectA->fresh()->command_allowlist ?? [], 'fixture sanity: the allowlist must genuinely be empty for this case');

        $conversation = $this->makeConversation($this->agent(), $this->projectA);

        $service = $this->service([
            $this->toolCallReply([$this->runCommandCall($this->projectA, 'phpunit', 'call_cmd_5')]),
            $this->plainReply('phpunit ran.'),
        ]);

        $result = $service->run($conversation->fresh(), 'Run phpunit.');

        $this->assertSame('completed', $result['status'], 'confirmation_relaxed alone must be sufficient, with no allowlist entry at all');
        Http::assertSent(fn ($req) => strtoupper($req->method()) === 'POST' && str_contains((string) parse_url($req->url(), PHP_URL_PATH), '/run-command'));
    }

    /**
     * The two paths are independent -- neither is required when the
     * other alone is sufficient. A project with a matching allowlist
     * entry but confirmation_relaxed left at its default (false) must
     * still run without a prompt, proving the allowlist path does not
     * secretly also require relaxation.
     */
    #[Test]
    public function an_allowlist_match_alone_is_sufficient_even_though_confirmation_relaxed_was_never_set(): void
    {
        $this->setAllowlist($this->projectA, ['phpunit']);
        $this->assertFalse((bool) $this->projectA->fresh()->confirmation_relaxed, 'fixture sanity: relaxation must genuinely be untouched for this case');

        $conversation = $this->makeConversation($this->agent(), $this->projectA);

        $service = $this->service([
            $this->toolCallReply([$this->runCommandCall($this->projectA, 'phpunit', 'call_cmd_6')]),
            $this->plainReply('phpunit ran.'),
        ]);

        $result = $service->run($conversation->fresh(), 'Run phpunit.');

        $this->assertSame('completed', $result['status']);
    }

    // -----------------------------------------------------------------
    // AS3/SC-007 -- adding a pattern to workspace A has no effect on
    // workspace B, which still pauses for the identical command.
    // -----------------------------------------------------------------

    #[Test]
    public function adding_a_pattern_to_workspace_a_has_no_effect_on_workspace_b(): void
    {
        $this->setAllowlist($this->projectA, ['phpunit']);

        $conversationB = $this->makeConversation($this->agent(), $this->projectB);

        $service = $this->service([
            $this->toolCallReply([$this->runCommandCall($this->projectB, 'phpunit', 'call_cmd_7')]),
        ]);

        $result = $service->run($conversationB->fresh(), 'Run phpunit.');

        $this->assertSame(
            'confirmation_required',
            $result['status'],
            'workspace B must still pause for the identical command -- workspace A\'s allowlist change must never leak across workspaces',
        );
        Http::assertNothingSent();
        $this->assertNull($this->projectB->fresh()->command_allowlist, 'workspace B\'s own allowlist must be completely untouched');
    }

    // -----------------------------------------------------------------
    // T029 -- declining a runCommand confirmation writes exactly one
    // CodingCommandExecution row with status: 'refused'.
    // -----------------------------------------------------------------

    #[Test]
    public function declining_a_paused_command_writes_a_refused_execution_row_with_the_declined_command_text(): void
    {
        $conversation = $this->makeConversation($this->agent(), $this->projectA);

        $service = $this->service([
            $this->toolCallReply([$this->runCommandCall($this->projectA, 'phpunit', 'call_cmd_decline')]),
            $this->plainReply('Understood, phpunit was not run.'),
        ]);

        $result = $service->run($conversation->fresh(), 'Run phpunit.');
        $message = Message::find($result['message_id']);

        $final = $service->resumeSync($conversation->fresh(), $message, false);

        $this->assertSame('completed', $final['status']);
        Http::assertNothingSent();

        $row = CodingCommandExecution::where('coding_project_id', $this->projectA->id)->first();
        $this->assertNotNull($row, 'a refused execution row must be written on decline');
        $this->assertSame('refused', $row->status);
        $this->assertSame('phpunit', $row->command);
        $this->assertNull($row->exit_code);
        $this->assertNull($row->duration_ms);
        $this->assertFalse((bool) $row->timed_out);
    }

    #[Test]
    public function declining_a_write_file_confirmation_never_writes_a_coding_command_execution_row(): void
    {
        // Scoped correctly: only runCommand declines write to
        // coding_command_executions. A declined writeFile must never
        // produce a row here -- that operation has no command-execution
        // concept at all.
        $conversation = $this->makeConversation($this->agent(), $this->projectA);

        $service = $this->service([
            $this->toolCallReply([$this->toolCall('execute_operation', [
                'operationId' => AgentLoopService::CODING_WORKSPACE_WRITE_FILE_OPERATION_ID,
                'parameters' => [
                    'path' => ['project' => $this->projectA->id],
                    'body' => ['path' => 'note.txt', 'content' => 'new content'],
                ],
            ], 'call_write_decline')]),
            $this->plainReply('Understood, note.txt was left unchanged.'),
        ]);

        $result = $service->run($conversation->fresh(), 'Please update note.txt.');
        $message = Message::find($result['message_id']);

        $final = $service->resumeSync($conversation->fresh(), $message, false);

        $this->assertSame('completed', $final['status']);
        $this->assertSame(0, DB::table('coding_command_executions')->count(), 'a declined writeFile must never write a coding_command_executions row');
    }
}
