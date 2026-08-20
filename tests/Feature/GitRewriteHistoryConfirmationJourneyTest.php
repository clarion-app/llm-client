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
 * 126-git-operations-confirmation, US5 (P2), T038 (contracts/git-rewrite-
 * history.md, quickstart Scenario 4).
 *
 * Same two combined techniques GitBranchConfirmationJourneyTest.php and
 * GitPublishConfirmationJourneyTest.php already established:
 *
 * - The confirmation-pause, decline, and pre-confirmation-refusal halves
 *   (propose/decline/refusal/ownership cases) drive the real, provisioned
 *   `coding` agent through AgentLoopService::run()/resumeSync() with a
 *   mocked LlmProvider producing a scripted gitRewriteHistory tool call
 *   (AgentLoopServiceRunCodeConfirmationTest.php's own "drive the confirm
 *   branch directly" heavy-fixture technique). No HTTP call is ever
 *   dispatched for these cases (Http::assertNothingSent()), since a pause
 *   or a pre-confirmation refusal both stop strictly before
 *   executeApiCall() ever runs.
 *
 * - The executed (approved) half and the ownership check drive the real,
 *   registered `POST coding-project/{project}/git-rewrite-history` route
 *   directly via postJson() -- RunCommandJourneyTest.php's own
 *   controller-level technique exactly (GitBranchConfirmationJourneyTest.
 *   php's own case-3 precedent), submitting the same {mode, target} body a
 *   real approval would resubmit (`target` already the pinned, resolved
 *   hash -- research.md D6) and asserting on the real git repository state
 *   the controller produced. No AgentLoopService/Http::fake plumbing is
 *   needed for this, since gitRewriteHistory never chains into a second,
 *   separately-confirmed operation the way gitCommit->gitPush does.
 *
 * Every fixture repository is a real, throwaway `git init`'d temp
 * directory (Grounding note 7's convention, mirroring
 * GitOperationInspectorTest.php exactly), plus a real local
 * `git init --bare` repository as a `file://`-scheme remote stand-in for
 * the published-flag case -- never a mocked git invocation, never Docker.
 *
 * Written before CodingWorkspaceController::gitRewriteHistory(),
 * AgentLoopService::gitOperationConfirmationPreview()'s gitRewriteHistory
 * branch, the git-rewrite-history route, and coding.yaml's
 * gitRewriteHistory entries all exist -- expected to FAIL red (a
 * gitRewriteHistory tool call never pauses at all today, since nothing
 * recognizes CODING_WORKSPACE_GIT_REWRITE_HISTORY_OPERATION_ID as
 * confirmation-required or even as a known/permitted operation; POST
 * .../git-rewrite-history 404s as an unregistered route) until T039-T041
 * land.
 */
class GitRewriteHistoryConfirmationJourneyTest extends TestCase
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
    // Real, throwaway git repo (+ real local bare-repo remote for the
    // published-flag case) fixtures -- Grounding note 7's convention,
    // mirroring GitOperationInspectorTest.php exactly.
    // ---------------------------------------------------------------

    private function createGitRepo(): string
    {
        $repoPath = sys_get_temp_dir().'/git_rewrite_history_confirm_test_'.Str::random(12);
        mkdir($repoPath, 0777, true);
        $this->tmpDirs[] = $repoPath;

        $this->runGit(['init'], $repoPath);
        $this->runGit(['config', 'user.name', 'Test Author'], $repoPath);
        $this->runGit(['config', 'user.email', 'test-author@example.test'], $repoPath);
        $this->runGit(['config', 'commit.gpgsign', 'false'], $repoPath);

        return $repoPath;
    }

    private function createBareRemote(): string
    {
        $barePath = sys_get_temp_dir().'/git_rewrite_history_confirm_bare_'.Str::random(12);
        mkdir($barePath, 0777, true);
        $this->tmpDirs[] = $barePath;

        $this->runGit(['init', '--bare'], $barePath);

        return $barePath;
    }

    private function currentBranch(string $repoPath): string
    {
        return trim($this->shellGit($repoPath, ['rev-parse', '--abbrev-ref', 'HEAD']));
    }

    /**
     * Pushes $repoPath's current HEAD to $barePath directly (never through
     * the app -- a real, independent git invocation used only to seed the
     * fixture) -- this also updates the local repo's own remote-tracking
     * ref (refs/remotes/origin/<branch>), which the `published` flag under
     * test is computed against.
     */
    private function pushToBareRemote(string $repoPath, string $barePath, string $branch): void
    {
        $this->runGit(['push', 'origin', "HEAD:refs/heads/{$branch}"], $repoPath);
        $this->runGit(['symbolic-ref', 'HEAD', "refs/heads/{$branch}"], $barePath);
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
     * Builds a repo with a base commit plus two further commits ahead of
     * it (so `target: "HEAD~2"` resolves to the base), then an uncommitted
     * edit to the same already-tracked file -- the shared fixture every
     * case below that needs "two commits ahead of a base, plus a dirty
     * tree" starts from. Mirrors GitOperationInspectorTest.php's own
     * identical builder.
     *
     * @return array{repoPath: string, baseHash: string, secondHash: string, thirdHash: string, editedContent: string}
     */
    private function createRepoWithTwoCommitsAheadAndADirtyTree(): array
    {
        $repoPath = $this->createGitRepo();

        file_put_contents($repoPath.'/file.txt', "one\n");
        $this->runGit(['add', 'file.txt'], $repoPath);
        $this->runGit(['commit', '-m', 'Base commit'], $repoPath);
        $baseHash = trim($this->shellGit($repoPath, ['rev-parse', 'HEAD']));

        file_put_contents($repoPath.'/file.txt', "one\ntwo\n");
        $this->runGit(['add', 'file.txt'], $repoPath);
        $this->runGit(['commit', '-m', 'Second commit'], $repoPath);
        $secondHash = trim($this->shellGit($repoPath, ['rev-parse', 'HEAD']));

        file_put_contents($repoPath.'/file.txt', "one\ntwo\nthree\n");
        $this->runGit(['add', 'file.txt'], $repoPath);
        $this->runGit(['commit', '-m', 'Third commit'], $repoPath);
        $thirdHash = trim($this->shellGit($repoPath, ['rev-parse', 'HEAD']));

        // An uncommitted edit to the same already-tracked file -- the
        // dirty-tree state the reset_hard-discard case needs.
        $editedContent = "one\ntwo\nthree\nuncommitted\n";
        file_put_contents($repoPath.'/file.txt', $editedContent);

        return compact('repoPath', 'baseHash', 'secondHash', 'thirdHash', 'editedContent');
    }

    // ---------------------------------------------------------------
    // Operation-catalog / provisioning scaffolding -- mirrors
    // GitBranchConfirmationJourneyTest.php exactly, extended with
    // gitRewriteHistory.
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
            'name' => 'git rewrite history confirmation project',
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

    private function gitRewriteHistoryCall(CodingProject $project, string $mode, string $target, string $callId): array
    {
        return $this->toolCall('execute_operation', [
            'operationId' => AgentLoopService::CODING_WORKSPACE_GIT_REWRITE_HISTORY_OPERATION_ID,
            'parameters' => [
                'path' => ['project' => $project->id],
                'body' => ['mode' => $mode, 'target' => $target],
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
     * refusal code (e.g. git_invalid_reference) a pre-confirmation refusal
     * must carry, distinguishing it from today's generic "operation not
     * permitted" rejection (gitRewriteHistory is not yet in coding.yaml's
     * tools.allow at all) which would otherwise make a bare
     * status==='completed' assertion pass for the wrong reason. Mirrors
     * GitBranchConfirmationJourneyTest.php's own identical helper.
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
    // (1) Two commits ahead of a base, plus an uncommitted edit to a
    // tracked file, {mode: "reset_hard", target: "HEAD~2"} -> the
    // marker's commits_removed_from_branch lists both removed commits,
    // uncommitted_changes_would_be_discarded: true with the edited file
    // named in discarded_paths -- all before anything happens (AS1, AS2,
    // FR-009).
    // -----------------------------------------------------------------

    #[Test]
    public function proposing_reset_hard_names_removed_commits_and_the_discard_warning_before_anything_happens(): void
    {
        $this->seedOperationCatalog();
        $this->actingAs($this->user, 'api');

        ['repoPath' => $repoPath, 'secondHash' => $secondHash, 'thirdHash' => $thirdHash]
            = $this->createRepoWithTwoCommitsAheadAndADirtyTree();
        $headBeforeRequest = trim($this->shellGit($repoPath, ['rev-parse', 'HEAD']));
        $statusBeforeRequest = $this->shellGit($repoPath, ['status', '--porcelain']);

        $project = $this->registerProject($repoPath);
        $conversation = $this->makeConversation($this->agent(), $project);

        $service = $this->service([
            $this->toolCallReply([$this->gitRewriteHistoryCall($project, 'reset_hard', 'HEAD~2', 'call_rewrite_1')]),
            // Only consumed if this operation does not yet pause today (pre-
            // implementation red state) -- harmless once T039-T041 land,
            // since run() returns before a second chat() call is ever made.
            $this->plainReply('Understood.'),
        ]);

        $result = $service->run($conversation->fresh(), 'Please reset the branch back two commits, discarding everything since.');

        $this->assertSame('confirmation_required', $result['status'], 'a gitRewriteHistory operation must pause for confirmation, not run immediately');

        $confirmation = $result['confirmation'] ?? [];
        $this->assertSame('git_rewrite_history', $confirmation['confirmation_type'] ?? null, 'the marker must carry the dedicated git_rewrite_history confirmation_type, not the generic api_call');

        $removedHashes = array_column($confirmation['commits_removed_from_branch'] ?? [], 'hash');
        $this->assertEqualsCanonicalizing(
            [$secondHash, $thirdHash],
            $removedHashes,
            'the marker must list exactly the two commits that would be removed from the branch (AS1)'
        );

        $this->assertTrue(
            $confirmation['uncommitted_changes_would_be_discarded'] ?? null,
            'a reset_hard against a genuinely dirty tree must explicitly warn of discard (AS2, FR-009)'
        );
        $this->assertSame(['file.txt'], $confirmation['discarded_paths'] ?? null);

        // Nothing has happened yet.
        $this->assertSame($headBeforeRequest, trim($this->shellGit($repoPath, ['rev-parse', 'HEAD'])), 'proposing the reset must never move HEAD before approval');
        $this->assertSame($statusBeforeRequest, $this->shellGit($repoPath, ['status', '--porcelain']), 'proposing the reset must never touch the working tree before approval');
        Http::assertNothingSent();
        $this->assertSame(0, DB::table('coding_command_executions')->where('coding_project_id', $project->id)->count(), 'nothing must have run yet');
    }

    // -----------------------------------------------------------------
    // (2) Decline -> git rev-parse HEAD and git status --porcelain both
    // unchanged from before the request (AS3).
    // -----------------------------------------------------------------

    #[Test]
    public function declining_a_pending_reset_hard_leaves_everything_unchanged_and_writes_a_refused_row(): void
    {
        $this->seedOperationCatalog();
        $this->actingAs($this->user, 'api');

        ['repoPath' => $repoPath] = $this->createRepoWithTwoCommitsAheadAndADirtyTree();
        $headBeforeRequest = trim($this->shellGit($repoPath, ['rev-parse', 'HEAD']));
        $statusBeforeRequest = $this->shellGit($repoPath, ['status', '--porcelain']);

        $project = $this->registerProject($repoPath);
        $conversation = $this->makeConversation($this->agent(), $project);

        $service = $this->service([
            $this->toolCallReply([$this->gitRewriteHistoryCall($project, 'reset_hard', 'HEAD~2', 'call_rewrite_decline')]),
            $this->plainReply('Understood, the reset was not performed.'),
        ]);

        $result = $service->run($conversation->fresh(), 'Please reset the branch back two commits.');
        $message = Message::find($result['message_id']);

        $final = $service->resumeSync($conversation->fresh(), $message, false);

        $this->assertSame('completed', $final['status']);
        Http::assertNothingSent();

        $this->assertSame($headBeforeRequest, trim($this->shellGit($repoPath, ['rev-parse', 'HEAD'])), 'declining must never move HEAD (AS3)');
        $this->assertSame($statusBeforeRequest, $this->shellGit($repoPath, ['status', '--porcelain']), 'declining must never touch the working tree (AS3)');

        $row = CodingCommandExecution::where('coding_project_id', $project->id)->first();
        $this->assertNotNull($row, 'a refused execution row must be written on decline');
        $this->assertSame('refused', $row->status);
        $this->assertStringContainsString('reset', $row->command);
    }

    // -----------------------------------------------------------------
    // (3) The identical setup with mode: "reset_soft" -> commits_removed_
    // from_branch still lists the same two commits, but uncommitted_
    // changes_would_be_discarded: false and discarded_paths: [] (research.
    // md D7).
    // -----------------------------------------------------------------

    #[Test]
    public function proposing_reset_soft_reports_the_same_removed_commits_but_no_discard_warning(): void
    {
        $this->seedOperationCatalog();
        $this->actingAs($this->user, 'api');

        ['repoPath' => $repoPath, 'secondHash' => $secondHash, 'thirdHash' => $thirdHash]
            = $this->createRepoWithTwoCommitsAheadAndADirtyTree();

        $project = $this->registerProject($repoPath);
        $conversation = $this->makeConversation($this->agent(), $project);

        $service = $this->service([
            $this->toolCallReply([$this->gitRewriteHistoryCall($project, 'reset_soft', 'HEAD~2', 'call_rewrite_soft')]),
            // Only consumed if this operation does not yet pause today (pre-
            // implementation red state) -- harmless once T039-T041 land,
            // since run() returns before a second chat() call is ever made.
            $this->plainReply('Understood.'),
        ]);

        $result = $service->run($conversation->fresh(), 'Please soft-reset the branch back two commits.');

        $this->assertSame('confirmation_required', $result['status']);
        $confirmation = $result['confirmation'] ?? [];
        $this->assertSame('git_rewrite_history', $confirmation['confirmation_type'] ?? null);

        $removedHashes = array_column($confirmation['commits_removed_from_branch'] ?? [], 'hash');
        $this->assertEqualsCanonicalizing(
            [$secondHash, $thirdHash],
            $removedHashes,
            'reset_soft must report the same removed commits as reset_hard against the identical target (D7)'
        );

        $this->assertFalse($confirmation['uncommitted_changes_would_be_discarded'] ?? null, 'reset_soft never touches working-tree content, so no discard warning applies');
        $this->assertSame([], $confirmation['discarded_paths'] ?? null);

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('coding_command_executions')->where('coding_project_id', $project->id)->count());
    }

    // -----------------------------------------------------------------
    // Approving the reset_soft confirmation leaves the working tree's
    // edited file exactly as it was (research.md D7). Driven directly
    // against the real, registered git-rewrite-history route
    // (RunCommandJourneyTest.php's controller-level technique, mirroring
    // GitBranchConfirmationJourneyTest.php's own case-3 precedent) with
    // the exact {mode, target} body a real approval resubmits -- target
    // already the pinned, resolved hash (D6).
    // -----------------------------------------------------------------

    #[Test]
    public function approving_reset_soft_leaves_the_working_trees_edited_file_exactly_as_it_was(): void
    {
        $this->actingAs($this->user, 'api');

        ['repoPath' => $repoPath, 'baseHash' => $baseHash, 'thirdHash' => $thirdHash, 'editedContent' => $editedContent]
            = $this->createRepoWithTwoCommitsAheadAndADirtyTree();

        $project = $this->registerProject($repoPath);

        $response = $this->postJson($this->apiUrl("coding-project/{$project->id}/git-rewrite-history"), [
            'mode' => 'reset_soft',
            'target' => $baseHash,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'mode' => 'reset_soft',
            'target' => $baseHash,
            'previous_head' => $thirdHash,
            'new_head' => $baseHash,
        ]);

        $this->assertSame(
            $editedContent,
            file_get_contents($repoPath.'/file.txt'),
            'reset_soft must leave the working tree\'s edited file exactly as it was before the reset'
        );
        $this->assertSame($baseHash, trim($this->shellGit($repoPath, ['rev-parse', 'HEAD'])), 'HEAD must now point at the resolved target');
    }

    // -----------------------------------------------------------------
    // (4) One of the two removed commits already pushed to the bare-repo
    // remote fixture beforehand -> its entry carries published: true; the
    // other, never-pushed commit carries published: false.
    // -----------------------------------------------------------------

    #[Test]
    public function an_already_published_removed_commit_is_flagged_published_distinctly_from_a_never_pushed_one(): void
    {
        $this->seedOperationCatalog();
        $this->actingAs($this->user, 'api');

        $repoPath = $this->createGitRepo();
        file_put_contents($repoPath.'/file.txt', "one\n");
        $this->runGit(['add', 'file.txt'], $repoPath);
        $this->runGit(['commit', '-m', 'Base commit'], $repoPath);
        $baseHash = trim($this->shellGit($repoPath, ['rev-parse', 'HEAD']));

        $barePath = $this->createBareRemote();
        $this->runGit(['remote', 'add', 'origin', 'file://'.$barePath], $repoPath);

        file_put_contents($repoPath.'/file.txt', "one\ntwo\n");
        $this->runGit(['add', 'file.txt'], $repoPath);
        $this->runGit(['commit', '-m', 'Published commit'], $repoPath);
        $publishedHash = trim($this->shellGit($repoPath, ['rev-parse', 'HEAD']));

        $branch = $this->currentBranch($repoPath);
        $this->pushToBareRemote($repoPath, $barePath, $branch);

        file_put_contents($repoPath.'/file.txt', "one\ntwo\nthree\n");
        $this->runGit(['add', 'file.txt'], $repoPath);
        $this->runGit(['commit', '-m', 'Unpublished commit'], $repoPath);
        $unpublishedHash = trim($this->shellGit($repoPath, ['rev-parse', 'HEAD']));

        $project = $this->registerProject($repoPath);
        $conversation = $this->makeConversation($this->agent(), $project);

        $service = $this->service([
            $this->toolCallReply([$this->gitRewriteHistoryCall($project, 'reset_soft', $baseHash, 'call_rewrite_published')]),
            // Only consumed if this operation does not yet pause today (pre-
            // implementation red state) -- harmless once T039-T041 land,
            // since run() returns before a second chat() call is ever made.
            $this->plainReply('Understood.'),
        ]);

        $result = $service->run($conversation->fresh(), 'Please soft-reset the branch back to the base commit.');

        $this->assertSame('confirmation_required', $result['status']);
        $confirmation = $result['confirmation'] ?? [];

        $byHash = [];
        foreach ($confirmation['commits_removed_from_branch'] ?? [] as $entry) {
            $byHash[$entry['hash']] = $entry;
        }

        $this->assertArrayHasKey($publishedHash, $byHash);
        $this->assertArrayHasKey($unpublishedHash, $byHash);
        $this->assertTrue($byHash[$publishedHash]['published'] ?? null, 'a removed commit already on the remote must be flagged published');
        $this->assertFalse($byHash[$unpublishedHash]['published'] ?? null, 'a removed commit never pushed anywhere must be flagged unpublished, in the same result set');
    }

    // -----------------------------------------------------------------
    // (5) An unresolvable target, and a not-a-repo workspace -> each
    // refused before any confirmation.
    // -----------------------------------------------------------------

    #[Test]
    public function an_unresolvable_target_is_refused_before_any_confirmation_with_no_row_written(): void
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
            $this->toolCallReply([$this->gitRewriteHistoryCall($project, 'reset_hard', 'no-such-ref-at-all', 'call_rewrite_bad_ref')]),
            $this->plainReply('That target does not exist.'),
        ]);
        $result = $service->run($conversation->fresh(), 'Please reset the branch to no-such-ref-at-all.');

        $this->assertSame('completed', $result['status'], 'an invalid-reference refusal must never pause for confirmation');
        $content = $this->toolResultContentFor($conversation, 'call_rewrite_bad_ref');
        $this->assertStringContainsString(
            'git_invalid_reference',
            $content,
            'the tool result fed back to the model must carry the specific git_invalid_reference refusal code'
        );
        $this->assertStringNotContainsString(
            'git_not_a_repository',
            $content,
            'an unresolvable target must never be conflated with the not-a-repository refusal'
        );
        Http::assertNothingSent();
        $this->assertSame(0, DB::table('coding_command_executions')->where('coding_project_id', $project->id)->count());
    }

    #[Test]
    public function a_not_a_repository_project_is_refused_before_any_confirmation_with_no_row_written(): void
    {
        $this->seedOperationCatalog();
        $this->actingAs($this->user, 'api');

        $plainDir = sys_get_temp_dir().'/git_rewrite_history_confirm_plain_'.Str::random(12);
        mkdir($plainDir, 0777, true);
        $this->tmpDirs[] = $plainDir;
        $notARepoProject = $this->registerProject($plainDir);

        $conversation = $this->makeConversation($this->agent(), $notARepoProject);
        $service = $this->service([
            $this->toolCallReply([$this->gitRewriteHistoryCall($notARepoProject, 'reset_hard', 'HEAD~1', 'call_rewrite_not_a_repo')]),
            $this->plainReply('There is no git repository here.'),
        ]);
        $result = $service->run($conversation->fresh(), 'Please reset the branch back one commit.');

        $this->assertSame('completed', $result['status'], 'a not-a-repository refusal must never pause for confirmation');
        $content = $this->toolResultContentFor($conversation, 'call_rewrite_not_a_repo');
        $this->assertStringContainsString(
            'git_not_a_repository',
            $content,
            'the tool result fed back to the model must carry the specific git_not_a_repository refusal code, not a generic operation-rejected error'
        );
        Http::assertNothingSent();
        $this->assertSame(0, DB::table('coding_command_executions')->where('coding_project_id', $notARepoProject->id)->count());
    }

    // -----------------------------------------------------------------
    // (6) 404 for an absent/foreign-owned project id.
    // -----------------------------------------------------------------

    #[Test]
    public function git_rewrite_history_404s_for_an_absent_or_foreign_owned_project(): void
    {
        $this->actingAs($this->user, 'api');

        $repoPath = $this->createGitRepo();
        $foreignProject = $this->registerProject($repoPath, $this->otherUser);

        $notFound = ['error' => 'Coding project not found', 'code' => 'coding_project_not_found'];
        $absentId = (string) Str::uuid();

        $this->postJson($this->apiUrl("coding-project/{$foreignProject->id}/git-rewrite-history"), [
            'mode' => 'reset_hard',
            'target' => 'HEAD~1',
        ])->assertStatus(404)->assertJson($notFound);

        $this->postJson($this->apiUrl("coding-project/{$absentId}/git-rewrite-history"), [
            'mode' => 'reset_hard',
            'target' => 'HEAD~1',
        ])->assertStatus(404)->assertJson($notFound);
    }
}
