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
 * 126-git-operations-confirmation, US2 (P1) 🎯 MVP of the confirmable
 * half, T017 (contracts/git-commit.md, quickstart Scenario 2).
 *
 * Two combined techniques, mirroring two existing precedent files:
 *
 * - The confirmation-pause and decline halves (cases 1, 2, 5) drive the
 *   real, provisioned `coding` agent through
 *   AgentLoopService::run()/resumeSync() with a mocked LlmProvider
 *   producing a scripted gitCommit tool call —
 *   AgentLoopServiceRunCodeConfirmationTest.php's own "drive the confirm
 *   branch directly" heavy-fixture technique. No HTTP call is ever
 *   dispatched for these cases (Http::assertNothingSent()), since a
 *   pause or a pre-confirmation refusal both stop strictly before
 *   executeApiCall() ever runs.
 *
 * - The executed (approved) halves (cases 3, 4) and the ownership check
 *   (case 6) drive the real, registered
 *   `POST coding-project/{project}/git-commit` route directly via
 *   postJson() — RunCommandJourneyTest.php's own controller-level
 *   technique exactly, submitting the same {message, paths} body a real
 *   approval would resubmit and asserting on the real git repository
 *   state the controller produced.
 *
 * Every fixture repository is a real, throwaway `git init`'d temp
 * directory (Grounding note 7's convention, mirroring
 * GitOperationInspectorTest.php exactly) — never a mocked git
 * invocation, never Docker.
 *
 * Written before CodingWorkspaceController::gitCommit(),
 * AgentLoopService::gitOperationConfirmationPreview(), the git-commit
 * route, and coding.yaml's gitCommit entries all exist — expected to
 * FAIL red (a gitCommit tool call never pauses at all today, since
 * nothing recognizes CODING_WORKSPACE_GIT_COMMIT_OPERATION_ID as
 * confirmation-required or even as a known operation; POST
 * .../git-commit 404s as an unregistered route) until T018-T022 land.
 */
class GitCommitConfirmationJourneyTest extends TestCase
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
    // Real, throwaway git repo fixture — Grounding note 7's convention,
    // mirroring GitOperationInspectorTest.php exactly.
    // ---------------------------------------------------------------

    private function createGitRepo(): string
    {
        $repoPath = sys_get_temp_dir().'/git_commit_confirm_test_'.Str::random(12);
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
    // Operation-catalog / provisioning scaffolding — mirrors
    // CommandExecutionConfirmationJourneyTest.php /
    // AgentLoopServiceRunCodeConfirmationTest.php exactly, extended with
    // gitCommit.
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
            'name' => 'git commit confirmation project',
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

    private function gitCommitCall(CodingProject $project, string $message, ?array $paths, string $callId): array
    {
        $body = ['message' => $message];
        if ($paths !== null) {
            $body['paths'] = $paths;
        }

        return $this->toolCall('execute_operation', [
            'operationId' => AgentLoopService::CODING_WORKSPACE_GIT_COMMIT_OPERATION_ID,
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

    // -----------------------------------------------------------------
    // (1) Proposing a commit with no paths against a repo with two
    // modified files -- confirmation_type: git_commit, files names
    // exactly those two files, diff_stat summarizes the real diff, and
    // nothing has happened yet (AS1, FR-004).
    // -----------------------------------------------------------------

    #[Test]
    public function proposing_a_commit_with_no_paths_names_the_two_modified_files_and_leaves_history_untouched(): void
    {
        $this->seedOperationCatalog();
        $this->actingAs($this->user, 'api');

        $repoPath = $this->createGitRepo();
        file_put_contents($repoPath.'/one.txt', "one\n");
        file_put_contents($repoPath.'/two.txt', "two\n");
        $this->runGit(['add', '.'], $repoPath);
        $this->runGit(['commit', '-m', 'Initial commit'], $repoPath);

        file_put_contents($repoPath.'/one.txt', "one changed\n");
        file_put_contents($repoPath.'/two.txt', "two changed\n");

        $expectedHash = trim($this->shellGit($repoPath, ['rev-parse', 'HEAD']));

        $project = $this->registerProject($repoPath);
        $conversation = $this->makeConversation($this->agent(), $project);

        $service = $this->service([
            $this->toolCallReply([$this->gitCommitCall($project, 'Update files', null, 'call_commit_1')]),
        ]);

        $result = $service->run($conversation->fresh(), 'Please commit the changes.');

        $this->assertSame('confirmation_required', $result['status'], 'a gitCommit operation must pause for confirmation, not run immediately');

        $confirmation = $result['confirmation'] ?? [];
        $this->assertSame('git_commit', $confirmation['confirmation_type'] ?? null, 'the marker must carry the dedicated git_commit confirmation_type, not the generic api_call');
        $this->assertEqualsCanonicalizing(
            ['one.txt', 'two.txt'],
            $confirmation['files'] ?? null,
            'the confirmation must name exactly the two changed files'
        );
        $this->assertNotEmpty($confirmation['diff_stat'] ?? null, 'the confirmation must carry a real diff_stat summary');

        $afterHash = trim($this->shellGit($repoPath, ['rev-parse', 'HEAD']));
        $this->assertSame($expectedHash, $afterHash, 'git log -1 (HEAD) must be completely unchanged -- nothing has happened yet');

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('coding_command_executions')->where('coding_project_id', $project->id)->count(), 'nothing must have run yet');
    }

    // -----------------------------------------------------------------
    // (2) Decline -- git status --porcelain still shows the same two
    // files changed, git log -1 unchanged, a status: 'refused'
    // CodingCommandExecution row exists with a reconstructed command
    // text (AS2, research.md D9).
    // -----------------------------------------------------------------

    #[Test]
    public function declining_a_pending_commit_leaves_git_history_untouched_and_writes_a_refused_row(): void
    {
        $this->seedOperationCatalog();
        $this->actingAs($this->user, 'api');

        $repoPath = $this->createGitRepo();
        file_put_contents($repoPath.'/one.txt', "one\n");
        file_put_contents($repoPath.'/two.txt', "two\n");
        $this->runGit(['add', '.'], $repoPath);
        $this->runGit(['commit', '-m', 'Initial commit'], $repoPath);

        file_put_contents($repoPath.'/one.txt', "one changed\n");
        file_put_contents($repoPath.'/two.txt', "two changed\n");

        $expectedHash = trim($this->shellGit($repoPath, ['rev-parse', 'HEAD']));
        $expectedStatus = $this->shellGit($repoPath, ['status', '--porcelain=v1']);

        $project = $this->registerProject($repoPath);
        $conversation = $this->makeConversation($this->agent(), $project);

        $service = $this->service([
            $this->toolCallReply([$this->gitCommitCall($project, 'Update files', ['one.txt', 'two.txt'], 'call_commit_decline')]),
            $this->plainReply('Understood, the commit was not made.'),
        ]);

        $result = $service->run($conversation->fresh(), 'Please commit the changes.');
        $message = Message::find($result['message_id']);

        $final = $service->resumeSync($conversation->fresh(), $message, false);

        $this->assertSame('completed', $final['status']);
        Http::assertNothingSent();

        $afterHash = trim($this->shellGit($repoPath, ['rev-parse', 'HEAD']));
        $afterStatus = $this->shellGit($repoPath, ['status', '--porcelain=v1']);
        $this->assertSame($expectedHash, $afterHash, 'declining must never advance HEAD');
        $this->assertSame($expectedStatus, $afterStatus, 'declining must never change git status --porcelain');

        $row = CodingCommandExecution::where('coding_project_id', $project->id)->first();
        $this->assertNotNull($row, 'a refused execution row must be written on decline');
        $this->assertSame('refused', $row->status);
        $this->assertStringStartsWith('git commit -m "Update files" --', $row->command, 'the reconstructed command text must name the commit message');
        $this->assertStringContainsString('one.txt', $row->command);
        $this->assertStringContainsString('two.txt', $row->command);
    }

    // -----------------------------------------------------------------
    // (3) Approve the same pending call -- git show --stat HEAD matches
    // the confirmation's files/diff_stat exactly; the two files no
    // longer appear in git status --porcelain (AS3).
    //
    // Driven directly against the real, registered git-commit route
    // (RunCommandJourneyTest.php's controller-level technique) with the
    // exact {message, paths} body a real approval resubmits.
    // -----------------------------------------------------------------

    #[Test]
    public function approving_the_pending_commit_produces_a_commit_matching_the_confirmation_and_clears_the_working_tree(): void
    {
        $this->actingAs($this->user, 'api');

        $repoPath = $this->createGitRepo();
        file_put_contents($repoPath.'/one.txt', "one\n");
        file_put_contents($repoPath.'/two.txt', "two\n");
        $this->runGit(['add', '.'], $repoPath);
        $this->runGit(['commit', '-m', 'Initial commit'], $repoPath);

        file_put_contents($repoPath.'/one.txt', "one changed\n");
        file_put_contents($repoPath.'/two.txt', "two changed\n");

        $project = $this->registerProject($repoPath);

        $response = $this->postJson($this->apiUrl("coding-project/{$project->id}/git-commit"), [
            'message' => 'Update files',
            'paths' => ['one.txt', 'two.txt'],
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'committed' => true,
            'message' => 'Update files',
        ]);
        $this->assertEqualsCanonicalizing(['one.txt', 'two.txt'], $response->json('files'));

        $newHash = trim($this->shellGit($repoPath, ['rev-parse', 'HEAD']));
        $this->assertSame($newHash, $response->json('hash'), 'the response hash must be the actual new HEAD');

        $showStat = $this->shellGit($repoPath, ['show', '--stat', 'HEAD']);
        $this->assertStringContainsString('one.txt', $showStat);
        $this->assertStringContainsString('two.txt', $showStat);

        $status = $this->shellGit($repoPath, ['status', '--porcelain=v1']);
        $this->assertStringNotContainsString('one.txt', $status, 'one.txt must no longer show as changed');
        $this->assertStringNotContainsString('two.txt', $status, 'two.txt must no longer show as changed');
    }

    // -----------------------------------------------------------------
    // (4) MUTATION-RELEVANT (pinning, quickstart Scenario 2.4): a THIRD,
    // unrelated file changes between confirmation and approval -- the
    // resulting commit still contains only the originally-described two
    // files; the third file remains uncommitted afterward.
    // -----------------------------------------------------------------

    #[Test]
    public function a_third_file_changed_between_confirmation_and_approval_is_never_included_in_the_resulting_commit(): void
    {
        $this->actingAs($this->user, 'api');

        $repoPath = $this->createGitRepo();
        file_put_contents($repoPath.'/one.txt', "one\n");
        file_put_contents($repoPath.'/two.txt', "two\n");
        file_put_contents($repoPath.'/three.txt', "three\n");
        $this->runGit(['add', '.'], $repoPath);
        $this->runGit(['commit', '-m', 'Initial commit'], $repoPath);

        file_put_contents($repoPath.'/one.txt', "one changed\n");
        file_put_contents($repoPath.'/two.txt', "two changed\n");

        // The confirmation was built (paths pinned) at this point,
        // describing only one.txt/two.txt -- three.txt was untouched and
        // never part of it.
        $pinnedPaths = ['one.txt', 'two.txt'];

        // A THIRD, unrelated file changes after the confirmation was
        // shown, before approval arrives.
        file_put_contents($repoPath.'/three.txt', "three changed\n");

        $project = $this->registerProject($repoPath);

        $response = $this->postJson($this->apiUrl("coding-project/{$project->id}/git-commit"), [
            'message' => 'Update files',
            'paths' => $pinnedPaths,
        ]);

        $response->assertStatus(200);
        $this->assertEqualsCanonicalizing($pinnedPaths, $response->json('files'));

        $showStat = $this->shellGit($repoPath, ['show', '--stat', 'HEAD']);
        $this->assertStringContainsString('one.txt', $showStat);
        $this->assertStringContainsString('two.txt', $showStat);
        $this->assertStringNotContainsString('three.txt', $showStat, 'the third, unrelated file must never be swept into the commit');

        $status = $this->shellGit($repoPath, ['status', '--porcelain=v1']);
        $this->assertStringContainsString('three.txt', $status, 'the third file must remain uncommitted afterward');
    }

    // -----------------------------------------------------------------
    // (5) Not-a-repo and nothing-to-commit -- both refused before any
    // confirmation is ever constructed, neither writes a
    // CodingCommandExecution row.
    // -----------------------------------------------------------------

    #[Test]
    public function a_not_a_repository_project_is_refused_before_any_confirmation_with_no_row_written(): void
    {
        $this->seedOperationCatalog();
        $this->actingAs($this->user, 'api');

        $plainDir = sys_get_temp_dir().'/git_commit_confirm_plain_'.Str::random(12);
        mkdir($plainDir, 0777, true);
        $this->tmpDirs[] = $plainDir;
        $notARepoProject = $this->registerProject($plainDir);

        $conversation = $this->makeConversation($this->agent(), $notARepoProject);
        $service = $this->service([
            $this->toolCallReply([$this->gitCommitCall($notARepoProject, 'Update files', null, 'call_not_a_repo')]),
            $this->plainReply('There is no git repository here.'),
        ]);
        $result = $service->run($conversation->fresh(), 'Please commit the changes.');

        $this->assertSame('completed', $result['status'], 'a not-a-repository refusal must never pause for confirmation');
        $this->assertStringContainsString(
            'git_not_a_repository',
            $this->toolResultContentFor($conversation, 'call_not_a_repo'),
            'the tool result fed back to the model must carry the specific git_not_a_repository refusal code, not a generic operation-rejected error'
        );
        Http::assertNothingSent();
        $this->assertSame(0, DB::table('coding_command_executions')->where('coding_project_id', $notARepoProject->id)->count());
    }

    #[Test]
    public function a_clean_tree_is_refused_as_nothing_to_commit_before_any_confirmation_with_no_row_written(): void
    {
        $this->seedOperationCatalog();
        $this->actingAs($this->user, 'api');

        $cleanRepo = $this->createGitRepo();
        file_put_contents($cleanRepo.'/only.txt', "only\n");
        $this->runGit(['add', 'only.txt'], $cleanRepo);
        $this->runGit(['commit', '-m', 'Initial commit'], $cleanRepo);

        $cleanProject = $this->registerProject($cleanRepo);
        $conversation = $this->makeConversation($this->agent(), $cleanProject);
        $service = $this->service([
            $this->toolCallReply([$this->gitCommitCall($cleanProject, 'Update files', null, 'call_nothing_to_commit')]),
            $this->plainReply('There is nothing to commit.'),
        ]);
        $result = $service->run($conversation->fresh(), 'Please commit the changes.');

        $this->assertSame('completed', $result['status'], 'a nothing-to-commit refusal must never pause for confirmation');
        $this->assertStringContainsString(
            'git_nothing_to_commit',
            $this->toolResultContentFor($conversation, 'call_nothing_to_commit'),
            'the tool result fed back to the model must carry the specific git_nothing_to_commit refusal code, not a generic operation-rejected error'
        );
        Http::assertNothingSent();
        $this->assertSame(0, DB::table('coding_command_executions')->where('coding_project_id', $cleanProject->id)->count());
    }

    /**
     * Reads back the raw tool-result content the agent loop fed to the
     * model for a specific tool_call_id -- used to pin the SPECIFIC
     * refusal code (e.g. git_not_a_repository) a pre-confirmation refusal
     * must carry, distinguishing it from today's generic "operation not
     * permitted" rejection (gitCommit is not yet in coding.yaml's
     * tools.allow at all) which would otherwise make a bare
     * status==='completed' assertion pass for the wrong reason.
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
    // (6) 404 for an absent/foreign-owned project id.
    // -----------------------------------------------------------------

    #[Test]
    public function git_commit_404s_for_an_absent_or_foreign_owned_project(): void
    {
        $this->actingAs($this->user, 'api');

        $repoPath = $this->createGitRepo();
        $foreignProject = $this->registerProject($repoPath, $this->otherUser);

        $notFound = ['error' => 'Coding project not found', 'code' => 'coding_project_not_found'];
        $absentId = (string) Str::uuid();

        $this->postJson($this->apiUrl("coding-project/{$foreignProject->id}/git-commit"), [
            'message' => 'Update files',
        ])->assertStatus(404)->assertJson($notFound);

        $this->postJson($this->apiUrl("coding-project/{$absentId}/git-commit"), [
            'message' => 'Update files',
        ])->assertStatus(404)->assertJson($notFound);
    }
}
