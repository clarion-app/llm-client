<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\CodingCommandExecution;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * 126-git-operations-confirmation, US4 (P2), T031 (contracts/git-branch.md,
 * quickstart Scenario 3).
 *
 * Same two combined techniques GitCommitConfirmationJourneyTest.php and
 * GitPublishConfirmationJourneyTest.php already established:
 *
 * - The confirmation-pause, decline, and pre-confirmation-refusal halves
 *   (cases 1, 2, 4, 5) drive the real, provisioned `coding` agent through
 *   AgentLoopService::run()/resumeSync() with a mocked LlmProvider
 *   producing a scripted gitBranch tool call --
 *   AgentLoopServiceRunCodeConfirmationTest.php's own "drive the confirm
 *   branch directly" heavy-fixture technique. No HTTP call is ever
 *   dispatched for these cases (Http::assertNothingSent()), since a
 *   pause or a pre-confirmation refusal both stop strictly before
 *   executeApiCall() ever runs.
 *
 * - The executed (approved) half (case 3) and the ownership check
 *   (case 6) drive the real, registered
 *   `POST coding-project/{project}/git-branch` route directly via
 *   postJson() -- RunCommandJourneyTest.php's own controller-level
 *   technique exactly, submitting the same {name, start_point} body a
 *   real approval would resubmit (start_point already the pinned,
 *   resolved hash -- research.md D6) and asserting on the real git
 *   repository state the controller produced.
 *
 * Every fixture repository is a real, throwaway `git init`'d temp
 * directory (Grounding note 7's convention, mirroring
 * GitOperationInspectorTest.php exactly) -- never a mocked git
 * invocation, never Docker.
 *
 * Written before CodingWorkspaceController::gitBranch(),
 * AgentLoopService::gitOperationConfirmationPreview()'s gitBranch branch,
 * the git-branch route, and coding.yaml's gitBranch entries all exist --
 * expected to FAIL red (a gitBranch tool call never pauses at all today,
 * since nothing recognizes CODING_WORKSPACE_GIT_BRANCH_OPERATION_ID as
 * confirmation-required or even as a known/permitted operation; POST
 * .../git-branch 404s as an unregistered route) until T032-T034 land.
 */
class GitBranchConfirmationJourneyTest extends TestCase
{
    private User $user;

    private User $otherUser;

    private Server $server;

    /** @var list<string> temp directories created by this test, removed in tearDown */
    private array $tmpDirs = [];

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

    // ---------------------------------------------------------------
    // Real, throwaway git repo fixture -- Grounding note 7's convention,
    // mirroring GitOperationInspectorTest.php exactly.
    // ---------------------------------------------------------------

    private function createGitRepo(): string
    {
        $repoPath = sys_get_temp_dir().'/git_branch_confirm_test_'.Str::random(12);
        mkdir($repoPath, 0777, true);
        $this->tmpDirs[] = $repoPath;

        $this->runGit(['init'], $repoPath);
        $this->runGit(['config', 'user.name', 'Test Author'], $repoPath);
        $this->runGit(['config', 'user.email', 'test-author@example.test'], $repoPath);
        $this->runGit(['config', 'commit.gpgsign', 'false'], $repoPath);

        return $repoPath;
    }

    private function runGit(array $args, string $cwd): Process
    {
        $process = new Process(array_merge(['git'], $args), $cwd);
        $process->mustRun();

        return $process;
    }

    private function shellGit(string $cwd, array $args): string
    {
        $process = new Process(array_merge(['git'], $args), $cwd);
        $process->run();

        return $process->getOutput();
    }

    /**
     * Runs a git command WITHOUT requiring success -- used for
     * `git show-ref --verify --quiet`, whose whole point is a non-zero
     * exit code when the ref does not exist.
     */
    private function gitExitCode(string $cwd, array $args): int
    {
        $process = new Process(array_merge(['git'], $args), $cwd);
        $process->run();

        return $process->getExitCode();
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

    // ---------------------------------------------------------------
    // Operation-catalog / provisioning scaffolding -- mirrors
    // GitCommitConfirmationJourneyTest.php exactly, extended with
    // gitBranch.
    // ---------------------------------------------------------------

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

    private function registerProject(string $rootPath, ?User $owner = null): CodingProject
    {
        return CodingProject::create([
            'user_id' => ($owner ?? $this->user)->id,
            'name' => 'git branch confirmation project',
            'root_path' => $rootPath,
            'test_command' => null,
        ]);
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

    private function gitBranchCall(CodingProject $project, string $name, ?string $startPoint, string $callId): array
    {
        $body = ['name' => $name];
        if ($startPoint !== null) {
            $body['start_point'] = $startPoint;
        }

        return $this->toolCall('execute_operation', [
            'operationId' => AgentLoopService::CODING_WORKSPACE_GIT_BRANCH_OPERATION_ID,
            'parameters' => [
                'path' => ['project' => $project->id],
                'body' => $body,
            ],
        ], $callId);
    }

    private function apiUrl(string $suffix): string
    {
        return '/api/clarion-app/llm-client/'.$suffix;
    }

    /**
     * Reads back the raw tool-result content the agent loop fed to the
     * model for a specific tool_call_id -- used to pin the SPECIFIC
     * refusal code (e.g. git_branch_already_exists) a pre-confirmation
     * refusal must carry, distinguishing it from today's generic
     * "operation not permitted" rejection (gitBranch is not yet in
     * coding.yaml's tools.allow at all) which would otherwise make a
     * bare status==='completed' assertion pass for the wrong reason.
     * Mirrors GitCommitConfirmationJourneyTest.php's own identical
     * helper.
     */
    private function toolResultContentFor(Conversation $conversation, string $toolCallId): string
    {
        $messages = Message::where('conversation_id', $conversation->id)->get();

        foreach ($messages as $message) {
            $results = $message->tool_data['tool_results'] ?? null;
            if (!is_array($results)) {
                continue;
            }
            foreach ($results as $result) {
                if (($result['tool_call_id'] ?? null) === $toolCallId) {
                    return (string) ($result['content'] ?? '');
                }
            }
        }

        return '';
    }

    // -----------------------------------------------------------------
    // (1) Proposing {name: "feature/x"} with no start_point -- the
    // marker names feature/x and start_point_resolved.hash equal to the
    // repo's current HEAD (AS1).
    // -----------------------------------------------------------------

    #[Test]
    public function proposing_a_branch_with_no_start_point_names_it_and_pins_the_current_head(): void
    {
        $this->seedOperationCatalog();
        $this->actingAs($this->user, 'api');

        $repoPath = $this->createGitRepo();
        file_put_contents($repoPath.'/file.txt', "one\n");
        $this->runGit(['add', 'file.txt'], $repoPath);
        $this->runGit(['commit', '-m', 'Initial commit'], $repoPath);
        $headHash = trim($this->shellGit($repoPath, ['rev-parse', 'HEAD']));

        $project = $this->registerProject($repoPath);
        $conversation = $this->makeConversation($this->agent(), $project);

        $service = $this->service([
            $this->toolCallReply([$this->gitBranchCall($project, 'feature/x', null, 'call_branch_1')]),
        ]);

        $result = $service->run($conversation->fresh(), 'Please create a branch called feature/x.');

        $this->assertSame('confirmation_required', $result['status'], 'a gitBranch operation must pause for confirmation, not run immediately');

        $confirmation = $result['confirmation'] ?? [];
        $this->assertSame('git_branch', $confirmation['confirmation_type'] ?? null, 'the marker must carry the dedicated git_branch confirmation_type, not the generic api_call');
        $this->assertSame('feature/x', $confirmation['branch_name'] ?? null, 'the marker must name the proposed branch');
        $this->assertSame(
            $headHash,
            $confirmation['start_point_resolved']['hash'] ?? null,
            'omitting start_point must resolve to and pin the repo\'s current HEAD (AS1, D6)'
        );

        // Nothing has happened yet -- the branch must not exist at all.
        $exitCode = $this->gitExitCode($repoPath, ['show-ref', '--verify', '--quiet', 'refs/heads/feature/x']);
        $this->assertNotSame(0, $exitCode, 'proposing the branch must never create it before approval');
        Http::assertNothingSent();
        $this->assertSame(0, DB::table('coding_command_executions')->where('coding_project_id', $project->id)->count(), 'nothing must have run yet');
    }

    // -----------------------------------------------------------------
    // (2) Decline -- git show-ref --verify --quiet refs/heads/feature/x
    // still fails afterward (AS2).
    // -----------------------------------------------------------------

    #[Test]
    public function declining_a_pending_branch_leaves_it_uncreated_and_writes_a_refused_row(): void
    {
        $this->seedOperationCatalog();
        $this->actingAs($this->user, 'api');

        $repoPath = $this->createGitRepo();
        file_put_contents($repoPath.'/file.txt', "one\n");
        $this->runGit(['add', 'file.txt'], $repoPath);
        $this->runGit(['commit', '-m', 'Initial commit'], $repoPath);

        $project = $this->registerProject($repoPath);
        $conversation = $this->makeConversation($this->agent(), $project);

        $service = $this->service([
            $this->toolCallReply([$this->gitBranchCall($project, 'feature/decline', null, 'call_branch_decline')]),
            $this->plainReply('Understood, the branch was not created.'),
        ]);

        $result = $service->run($conversation->fresh(), 'Please create a branch called feature/decline.');
        $message = Message::find($result['message_id']);

        $final = $service->resumeSync($conversation->fresh(), $message, false);

        $this->assertSame('completed', $final['status']);
        Http::assertNothingSent();

        $exitCode = $this->gitExitCode($repoPath, ['show-ref', '--verify', '--quiet', 'refs/heads/feature/decline']);
        $this->assertNotSame(0, $exitCode, 'declining must never create the branch -- show-ref must still fail');

        $row = CodingCommandExecution::where('coding_project_id', $project->id)->first();
        $this->assertNotNull($row, 'a refused execution row must be written on decline');
        $this->assertSame('refused', $row->status);
        $this->assertStringContainsString('feature/decline', $row->command);
    }

    // -----------------------------------------------------------------
    // (3) Approve -- git rev-parse feature/x equals the hash shown in
    // the confirmation (AS3).
    //
    // Driven directly against the real, registered git-branch route
    // (RunCommandJourneyTest.php's controller-level technique) with the
    // exact {name, start_point} body a real approval resubmits --
    // start_point already the pinned, resolved hash (D6).
    // -----------------------------------------------------------------

    #[Test]
    public function approving_the_pending_branch_creates_it_at_exactly_the_pinned_start_point(): void
    {
        $this->actingAs($this->user, 'api');

        $repoPath = $this->createGitRepo();
        file_put_contents($repoPath.'/file.txt', "one\n");
        $this->runGit(['add', 'file.txt'], $repoPath);
        $this->runGit(['commit', '-m', 'Initial commit'], $repoPath);
        $headHash = trim($this->shellGit($repoPath, ['rev-parse', 'HEAD']));

        $project = $this->registerProject($repoPath);

        $response = $this->postJson($this->apiUrl("coding-project/{$project->id}/git-branch"), [
            'name' => 'feature/approve',
            'start_point' => $headHash,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'branch' => 'feature/approve',
            'created' => true,
            'start_point' => $headHash,
        ]);

        $actualHash = trim($this->shellGit($repoPath, ['rev-parse', 'feature/approve']));
        $this->assertSame($headHash, $actualHash, 'the created branch must point at exactly the hash shown in the confirmation');
    }

    // -----------------------------------------------------------------
    // (4) A name that already exists -- refused before any confirmation
    // is ever shown, code: 'git_branch_already_exists'.
    // -----------------------------------------------------------------

    #[Test]
    public function a_branch_name_that_already_exists_is_refused_before_any_confirmation_with_no_row_written(): void
    {
        $this->seedOperationCatalog();
        $this->actingAs($this->user, 'api');

        $repoPath = $this->createGitRepo();
        file_put_contents($repoPath.'/file.txt', "one\n");
        $this->runGit(['add', 'file.txt'], $repoPath);
        $this->runGit(['commit', '-m', 'Initial commit'], $repoPath);
        $this->runGit(['branch', 'already-exists'], $repoPath);

        $project = $this->registerProject($repoPath);
        $conversation = $this->makeConversation($this->agent(), $project);

        $service = $this->service([
            $this->toolCallReply([$this->gitBranchCall($project, 'already-exists', null, 'call_branch_exists')]),
            $this->plainReply('That branch already exists.'),
        ]);
        $result = $service->run($conversation->fresh(), 'Please create a branch called already-exists.');

        $this->assertSame('completed', $result['status'], 'an already-exists refusal must never pause for confirmation');
        $this->assertStringContainsString(
            'git_branch_already_exists',
            $this->toolResultContentFor($conversation, 'call_branch_exists'),
            'the tool result fed back to the model must carry the specific git_branch_already_exists refusal code, not a generic operation-rejected error'
        );
        Http::assertNothingSent();
        $this->assertSame(0, DB::table('coding_command_executions')->where('coding_project_id', $project->id)->count());
    }

    // -----------------------------------------------------------------
    // (5) Not-a-repo, and an unresolvable start_point -- each refused
    // before confirmation with its own distinct code.
    // -----------------------------------------------------------------

    #[Test]
    public function a_not_a_repository_project_is_refused_before_any_confirmation_with_no_row_written(): void
    {
        $this->seedOperationCatalog();
        $this->actingAs($this->user, 'api');

        $plainDir = sys_get_temp_dir().'/git_branch_confirm_plain_'.Str::random(12);
        mkdir($plainDir, 0777, true);
        $this->tmpDirs[] = $plainDir;
        $notARepoProject = $this->registerProject($plainDir);

        $conversation = $this->makeConversation($this->agent(), $notARepoProject);
        $service = $this->service([
            $this->toolCallReply([$this->gitBranchCall($notARepoProject, 'feature/not-a-repo', null, 'call_branch_not_a_repo')]),
            $this->plainReply('There is no git repository here.'),
        ]);
        $result = $service->run($conversation->fresh(), 'Please create a branch called feature/not-a-repo.');

        $this->assertSame('completed', $result['status'], 'a not-a-repository refusal must never pause for confirmation');
        $content = $this->toolResultContentFor($conversation, 'call_branch_not_a_repo');
        $this->assertStringContainsString(
            'git_not_a_repository',
            $content,
            'the tool result fed back to the model must carry the specific git_not_a_repository refusal code, not a generic operation-rejected error'
        );
        Http::assertNothingSent();
        $this->assertSame(0, DB::table('coding_command_executions')->where('coding_project_id', $notARepoProject->id)->count());
    }

    #[Test]
    public function an_unresolvable_start_point_is_refused_as_an_invalid_reference_before_any_confirmation_with_no_row_written(): void
    {
        $this->seedOperationCatalog();
        $this->actingAs($this->user, 'api');

        $repoPath = $this->createGitRepo();
        file_put_contents($repoPath.'/file.txt', "one\n");
        $this->runGit(['add', 'file.txt'], $repoPath);
        $this->runGit(['commit', '-m', 'Initial commit'], $repoPath);

        $project = $this->registerProject($repoPath);
        $conversation = $this->makeConversation($this->agent(), $project);

        $service = $this->service([
            $this->toolCallReply([$this->gitBranchCall($project, 'feature/bad-ref', 'no-such-ref-at-all', 'call_branch_bad_ref')]),
            $this->plainReply('That starting point does not exist.'),
        ]);
        $result = $service->run($conversation->fresh(), 'Please create a branch called feature/bad-ref from no-such-ref-at-all.');

        $this->assertSame('completed', $result['status'], 'an invalid-reference refusal must never pause for confirmation');
        $content = $this->toolResultContentFor($conversation, 'call_branch_bad_ref');
        $this->assertStringContainsString(
            'git_invalid_reference',
            $content,
            'the tool result fed back to the model must carry the specific git_invalid_reference refusal code'
        );
        $this->assertStringNotContainsString(
            'git_not_a_repository',
            $content,
            'an unresolvable start_point must never be conflated with the not-a-repository refusal'
        );
        Http::assertNothingSent();
        $this->assertSame(0, DB::table('coding_command_executions')->where('coding_project_id', $project->id)->count());
    }

    // -----------------------------------------------------------------
    // (6) 404 for an absent/foreign-owned project id.
    // -----------------------------------------------------------------

    #[Test]
    public function git_branch_404s_for_an_absent_or_foreign_owned_project(): void
    {
        $this->actingAs($this->user, 'api');

        $repoPath = $this->createGitRepo();
        $foreignProject = $this->registerProject($repoPath, $this->otherUser);

        $notFound = ['error' => 'Coding project not found', 'code' => 'coding_project_not_found'];
        $absentId = (string) Str::uuid();

        $this->postJson($this->apiUrl("coding-project/{$foreignProject->id}/git-branch"), [
            'name' => 'feature/foreign',
        ])->assertStatus(404)->assertJson($notFound);

        $this->postJson($this->apiUrl("coding-project/{$absentId}/git-branch"), [
            'name' => 'feature/absent',
        ])->assertStatus(404)->assertJson($notFound);
    }
}
