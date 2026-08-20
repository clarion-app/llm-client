<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Controllers\CodingWorkspaceController;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\CodingProject;
use ClarionApp\LlmClient\Models\Conversation;
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
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * 126-git-operations-confirmation, Polish, T045 (quickstart Scenario 7,
 * research.md D10) -- the core non-bypass guarantee: a workspace's raw-
 * command escape hatches (`command_allowlist`, `confirmation_relaxed`)
 * govern the pre-existing, unmodified `runCommand` path exactly as they
 * always have, but are NEVER consulted for any of the four new structured
 * git operationIds -- the same logical action (e.g. `git push origin
 * main`), requested through the structured path instead of raw command
 * text, always pauses for its own separate confirmation regardless of
 * either escape hatch.
 *
 * All three of quickstart Scenario 7's cases:
 * (1) an allowlist matching `git push origin main` lets `runCommand`
 *     itself run unconfirmed -- the pre-existing, unmodified behavior
 *     this feature's own non-goal explicitly leaves alone.
 * (2) the SAME workspace, SAME allowlist entry, but the logically
 *     identical action requested via the structured `gitPush` operation
 *     -- still pauses for its own git_publish confirmation.
 * (3) `confirmation_relaxed: true` instead of an allowlist entry, tried
 *     against all four of gitBranch/gitCommit/gitRewriteHistory/gitPush
 *     in turn -- every one still pauses.
 *
 * Expected GREEN as written -- research.md D10 was deliberately never
 * touched across every phase of this feature (Grounding note 10, re-
 * confirmed directly against the live AgentLoopService source during this
 * phase: the confirmation_relaxed check at ~L4083 and the
 * command_allowlist check at ~L4112 both enumerate exactly
 * WRITE_FILE/DELETE_FILE/RUN_COMMAND, with no wildcard or prefix match
 * that could accidentally widen to the four new git operationIds). A red
 * result here would mean a genuine, security-relevant regression, not a
 * design gap -- investigate carefully rather than "fixing" the test.
 */
class GitRawCommandBypassTest extends TestCase
{
    private User $user;

    private Server $server;

    /** @var list<string> temp directories created by this test, removed in tearDown */
    private array $tmpDirs = [];

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

        foreach ($this->tmpDirs as $dir) {
            if (is_dir($dir)) {
                $this->removeDirectory($dir);
            }
        }

        DB::table('coding_command_executions')->delete();
        if (Schema::hasTable('agent_run_actions')) {
            DB::table('agent_run_actions')->delete();
        }
        if (Schema::hasTable('agent_run_steps')) {
            DB::table('agent_run_steps')->delete();
        }
        if (Schema::hasTable('agent_runs')) {
            DB::table('agent_runs')->delete();
        }
        DB::table('messages')->delete();
        DB::table('conversations')->delete();
        DB::table('coding_projects')->delete();
        if (Schema::hasTable('agent_versions')) {
            DB::table('agent_versions')->delete();
        }
        if (Schema::hasTable('agents')) {
            DB::table('agents')->delete();
        }
        DB::table('llm_role_assignments')->delete();
        DB::table('llm_servers')->delete();
        if (Schema::hasTable('mcp_sessions')) {
            DB::table('mcp_sessions')->delete();
        }
        DB::table('users')->delete();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Real, throwaway git repo + real local bare-repo remote fixtures,
    // mirroring GitPublishConfirmationJourneyTest.php's own dual-repo
    // convention exactly. Deterministically named "main" branch so the
    // allowlist pattern text can match quickstart's own literal example
    // ("git push origin main") regardless of this host's
    // init.defaultBranch configuration.
    // -----------------------------------------------------------------

    private function createGitRepo(): string
    {
        $repoPath = sys_get_temp_dir().'/git_raw_bypass_test_'.Str::random(12);
        mkdir($repoPath, 0777, true);
        $this->tmpDirs[] = $repoPath;

        $this->runGit(['init'], $repoPath);
        $this->runGit(['symbolic-ref', 'HEAD', 'refs/heads/main'], $repoPath);
        $this->runGit(['config', 'user.name', 'Test Author'], $repoPath);
        $this->runGit(['config', 'user.email', 'test-author@example.test'], $repoPath);
        $this->runGit(['config', 'commit.gpgsign', 'false'], $repoPath);

        return $repoPath;
    }

    private function createBareRemote(): string
    {
        $barePath = sys_get_temp_dir().'/git_raw_bypass_bare_'.Str::random(12);
        mkdir($barePath, 0777, true);
        $this->tmpDirs[] = $barePath;

        $this->runGit(['init', '--bare'], $barePath);

        return $barePath;
    }

    private function runGit(array $args, string $cwd): Process
    {
        $process = new Process(array_merge(['git'], $args), $cwd);
        $process->mustRun();

        return $process;
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

    /**
     * A repo with two real commits, both already pushed to a real, local
     * bare-repo remote (so gitPush's own preview resolves successfully),
     * plus one further uncommitted edit to a third file (so gitCommit's
     * own preview finds something to commit) -- one fixture shape that
     * lets every one of the four mutating git operations reach a genuine,
     * successful confirmation preview (never an incidental refusal that
     * would make "it paused" ambiguous with "it was refused before ever
     * reaching the allowlist/relaxation check at all").
     *
     * @return array{repoPath: string, project: CodingProject}
     */
    private function setUpFullyPreviewableRepo(bool $confirmationRelaxed = false): array
    {
        $repoPath = $this->createGitRepo();

        file_put_contents($repoPath.'/first.txt', "one\n");
        $this->runGit(['add', 'first.txt'], $repoPath);
        $this->runGit(['commit', '-m', 'First commit'], $repoPath);

        file_put_contents($repoPath.'/second.txt', "two\n");
        $this->runGit(['add', 'second.txt'], $repoPath);
        $this->runGit(['commit', '-m', 'Second commit'], $repoPath);

        $barePath = $this->createBareRemote();
        $this->runGit(['remote', 'add', 'origin', 'file://'.$barePath], $repoPath);
        $this->runGit(['push', 'origin', 'HEAD:refs/heads/main'], $repoPath);

        // An uncommitted third file -- gitCommit's own preview needs
        // something to describe.
        file_put_contents($repoPath.'/third.txt', "three\n");

        $project = CodingProject::create([
            'user_id' => $this->user->id,
            'name' => 'raw command bypass project',
            'root_path' => $repoPath,
            'test_command' => null,
            'network_enabled' => true,
            'confirmation_relaxed' => $confirmationRelaxed,
        ]);

        return compact('repoPath', 'project');
    }

    // -----------------------------------------------------------------
    // Operation-catalog / provisioning scaffolding.
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
            'clarionApp.llmClient.codingWorkspace.gitLog' => ['path' => '/coding-project/{project}/git-log', 'method' => 'get'],
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
     * @param  array<string, mixed>  $result
     */
    private function bindFakeExecutor(array $result): void
    {
        $fake = Mockery::mock(DockerCommandExecutor::class);
        $fake->shouldReceive('run')->andReturn($result);
        $this->app->instance(DockerCommandExecutor::class, $fake);
    }

    /**
     * Routes the outbound HTTP call the agent loop's tool executor makes
     * straight into the real, unmodified CodingWorkspaceController via
     * the container (so bindFakeExecutor()'s DockerCommandExecutor
     * binding is actually honored for run-command).
     */
    private function fakeCodingWorkspaceHttp(): void
    {
        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $method = strtoupper($request->method());

            if (!preg_match('#/([^/]+)/(run-command|git-commit|git-push|git-branch|git-rewrite-history)$#', $path, $m)) {
                return Http::response(['error' => 'unmapped test route: '.$path], 500);
            }
            [, $projectId, $suffix] = $m;

            $laravelRequest = in_array($method, ['POST', 'PUT', 'PATCH'], true)
                ? Request::create($request->url(), $method, $request->data())
                : Request::create($request->url(), $method);

            $controller = app(CodingWorkspaceController::class);

            $response = match (true) {
                $suffix === 'run-command' && $method === 'POST' => $controller->runCommand($laravelRequest, $projectId),
                $suffix === 'git-commit' && $method === 'POST' => $controller->gitCommit($laravelRequest, $projectId),
                $suffix === 'git-push' && $method === 'POST' => $controller->gitPush($laravelRequest, $projectId),
                $suffix === 'git-branch' && $method === 'POST' => $controller->gitBranch($laravelRequest, $projectId),
                $suffix === 'git-rewrite-history' && $method === 'POST' => $controller->gitRewriteHistory($laravelRequest, $projectId),
                default => response()->json(['error' => 'unmapped test route: '.$suffix.' '.$method], 500),
            };

            return Http::response($response->getData(true), $response->getStatusCode());
        });
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

    private function setAllowlist(CodingProject $project, array $patterns): void
    {
        $this->patchJson($this->apiUrl("coding-project/{$project->id}/command-allowlist"), [
            'patterns' => $patterns,
        ])->assertStatus(200);
    }

    private function apiUrl(string $suffix): string
    {
        return '/api/clarion-app/llm-client/'.$suffix;
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

    private function gitPushCall(CodingProject $project, string $callId): array
    {
        return $this->toolCall('execute_operation', [
            'operationId' => AgentLoopService::CODING_WORKSPACE_GIT_PUSH_OPERATION_ID,
            'parameters' => [
                'path' => ['project' => $project->id],
                'body' => ['remote' => 'origin', 'branch' => 'main'],
            ],
        ], $callId);
    }

    private function gitCommitCall(CodingProject $project, string $callId): array
    {
        return $this->toolCall('execute_operation', [
            'operationId' => AgentLoopService::CODING_WORKSPACE_GIT_COMMIT_OPERATION_ID,
            'parameters' => [
                'path' => ['project' => $project->id],
                'body' => ['message' => 'Add third file'],
            ],
        ], $callId);
    }

    private function gitBranchCall(CodingProject $project, string $callId): array
    {
        return $this->toolCall('execute_operation', [
            'operationId' => AgentLoopService::CODING_WORKSPACE_GIT_BRANCH_OPERATION_ID,
            'parameters' => [
                'path' => ['project' => $project->id],
                'body' => ['name' => 'feature/relaxation-bypass-check'],
            ],
        ], $callId);
    }

    private function gitRewriteHistoryCall(CodingProject $project, string $callId): array
    {
        return $this->toolCall('execute_operation', [
            'operationId' => AgentLoopService::CODING_WORKSPACE_GIT_REWRITE_HISTORY_OPERATION_ID,
            'parameters' => [
                'path' => ['project' => $project->id],
                'body' => ['mode' => 'reset_soft', 'target' => 'HEAD~1'],
            ],
        ], $callId);
    }

    // -----------------------------------------------------------------
    // (1) The pre-existing, unmodified runCommand allowlist behavior
    // itself, entirely unaffected by this feature -- spec.md's own
    // non-goal.
    // -----------------------------------------------------------------

    #[Test]
    public function an_allowlisted_raw_command_runs_without_confirmation(): void
    {
        $this->seedOperationCatalog();
        $this->actingAs($this->user, 'api');
        $this->fakeCodingWorkspaceHttp();

        ['project' => $project] = $this->setUpFullyPreviewableRepo();
        $this->setAllowlist($project, ['git push origin main']);

        $conversation = $this->makeConversation($this->agent(), $project);
        $service = $this->service([
            $this->toolCallReply([$this->runCommandCall($project, 'git push origin main', 'call_raw')]),
            $this->plainReply('Pushed via the raw command path.'),
        ]);

        $result = $service->run($conversation->fresh(), 'Push the repo.');

        $this->assertSame('completed', $result['status'], 'a command exactly matching the allowlist must run without pausing -- the pre-existing runCommand behavior, unaffected by this feature');
        Http::assertSent(fn ($req) => strtoupper($req->method()) === 'POST' && str_contains((string) parse_url($req->url(), PHP_URL_PATH), '/run-command'));
    }

    // -----------------------------------------------------------------
    // (2) The core non-bypass guarantee -- the SAME workspace, SAME
    // allowlist entry, but the structured gitPush operation for the
    // logically identical action still pauses, unaffected by the
    // allowlist.
    // -----------------------------------------------------------------

    #[Test]
    public function the_structured_git_push_operation_still_pauses_despite_a_matching_command_allowlist_entry(): void
    {
        $this->seedOperationCatalog();
        $this->actingAs($this->user, 'api');
        $this->fakeCodingWorkspaceHttp();

        ['project' => $project] = $this->setUpFullyPreviewableRepo();
        $this->setAllowlist($project, ['git push origin main']);

        $conversation = $this->makeConversation($this->agent(), $project);
        $service = $this->service([
            $this->toolCallReply([$this->gitPushCall($project, 'call_structured_push')]),
        ]);

        $result = $service->run($conversation->fresh(), 'Publish the repo.');

        $this->assertSame(
            'confirmation_required',
            $result['status'],
            'the structured gitPush operation must still pause for its own confirmation -- CommandAllowlistMatcher must never be consulted for gitPush\'s operationId'
        );
        $this->assertSame('git_publish', $result['confirmation']['confirmation_type'] ?? null);
        Http::assertNothingSent();
    }

    // -----------------------------------------------------------------
    // (3) confirmation_relaxed: true instead of an allowlist entry,
    // tried against all four mutating git operations in turn -- every
    // one still pauses; relaxation only ever applies to
    // writeFile/deleteFile/runCommand.
    // -----------------------------------------------------------------

    #[Test]
    public function confirmation_relaxed_never_bypasses_any_of_the_four_mutating_git_operations(): void
    {
        $this->seedOperationCatalog();
        $this->actingAs($this->user, 'api');
        $this->fakeCodingWorkspaceHttp();

        ['project' => $project] = $this->setUpFullyPreviewableRepo(confirmationRelaxed: true);
        $this->assertTrue((bool) $project->fresh()->confirmation_relaxed, 'fixture sanity: relaxation must genuinely be set for this workspace');

        $cases = [
            'gitBranch' => fn (CodingProject $p, string $id) => $this->gitBranchCall($p, $id),
            'gitCommit' => fn (CodingProject $p, string $id) => $this->gitCommitCall($p, $id),
            'gitRewriteHistory' => fn (CodingProject $p, string $id) => $this->gitRewriteHistoryCall($p, $id),
            'gitPush' => fn (CodingProject $p, string $id) => $this->gitPushCall($p, $id),
        ];

        foreach ($cases as $label => $callBuilder) {
            $conversation = $this->makeConversation($this->agent(), $project);
            $service = $this->service([
                $this->toolCallReply([$callBuilder($project, "call_relaxed_{$label}")]),
            ]);

            $result = $service->run($conversation->fresh(), "Please perform the {$label} action.");

            $this->assertSame(
                'confirmation_required',
                $result['status'],
                "confirmation_relaxed must never bypass {$label} -- it must still pause even though this workspace has relaxation enabled"
            );
        }

        Http::assertNothingSent();
    }

    // -----------------------------------------------------------------
    // Sanity companion: relaxation DOES still bypass runCommand itself
    // (the pre-existing behavior this feature must not have broken while
    // ensuring it never extends to the four git operations above).
    // -----------------------------------------------------------------

    #[Test]
    public function confirmation_relaxed_still_bypasses_plain_run_command_as_before(): void
    {
        $this->seedOperationCatalog();
        $this->actingAs($this->user, 'api');
        $this->fakeCodingWorkspaceHttp();

        ['project' => $project] = $this->setUpFullyPreviewableRepo(confirmationRelaxed: true);

        $conversation = $this->makeConversation($this->agent(), $project);
        $service = $this->service([
            $this->toolCallReply([$this->runCommandCall($project, 'echo hello', 'call_relaxed_raw')]),
            $this->plainReply('Done.'),
        ]);

        $result = $service->run($conversation->fresh(), 'Run echo hello.');

        $this->assertSame('completed', $result['status'], 'confirmation_relaxed must still bypass plain runCommand, exactly as before this feature');
        Http::assertSent(fn ($req) => strtoupper($req->method()) === 'POST' && str_contains((string) parse_url($req->url(), PHP_URL_PATH), '/run-command'));
    }
}
