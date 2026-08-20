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
 * 126-git-operations-confirmation, US3 (P1), T024 (contracts/git-publish.md,
 * quickstart Scenario 5).
 *
 * Same two combined techniques GitCommitConfirmationJourneyTest.php already
 * established:
 *
 * - The confirmation-pause/decline/approve halves drive the real,
 *   provisioned `coding` agent through
 *   AgentLoopService::run()/resumeSync() with a mocked LlmProvider
 *   producing scripted gitCommit/gitPush tool calls
 *   (AgentLoopServiceRunCodeConfirmationTest.php's own "drive the confirm
 *   branch directly" technique, and ScopeSurfaceTest.php's own
 *   "approve() returns the next pause raised by the very next tool call"
 *   pattern for chaining two independently-confirmed operations in one
 *   continuous run).
 * - The pre-confirmation-refusal and ownership-check halves also drive
 *   AgentLoopService::run()/the real `POST .../git-push` route directly
 *   (RunCommandJourneyTest.php's controller-level technique).
 *
 * Every fixture repository is a real, throwaway `git init`'d temp
 * directory, PLUS a real local `git init --bare` repository as a
 * `file://`-scheme remote stand-in (Grounding note 7's dual-repo
 * convention) -- no live network access anywhere in this file.
 *
 * Written before CodingWorkspaceController::gitPush(),
 * AgentLoopService::gitOperationConfirmationPreview()'s gitPush branch, the
 * git-push route, and coding.yaml's gitPush entries all exist -- expected
 * to FAIL red (a gitPush tool call never pauses at all today, since
 * nothing recognizes CODING_WORKSPACE_GIT_PUSH_OPERATION_ID as
 * confirmation-required or even as a known/permitted operation; POST
 * .../git-push 404s as an unregistered route) until T025-T029 land.
 */
class GitPublishConfirmationJourneyTest extends TestCase
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
    // Real, throwaway git repo + real local bare-repo remote fixtures --
    // Grounding note 7's dual-repo convention, mirroring
    // GitOperationInspectorTest.php exactly.
    // ---------------------------------------------------------------

    private function createGitRepo(): string
    {
        $repoPath = sys_get_temp_dir().'/git_publish_confirm_test_'.Str::random(12);
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
        $barePath = sys_get_temp_dir().'/git_publish_confirm_bare_'.Str::random(12);
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
     * fixture) and points the bare remote's own HEAD symref at that
     * branch, so `git rev-parse HEAD` against the bare repo resolves
     * deterministically regardless of git's configured default branch
     * name.
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

    // ---------------------------------------------------------------
    // Operation-catalog / provisioning scaffolding -- mirrors
    // GitCommitConfirmationJourneyTest.php exactly, extended with
    // gitPush.
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

    /**
     * ScopeSurfaceTest.php's own established technique: AgentLoopService::
     * executeApiCall() (via McpToolExecutor::executeHttpCall()) makes a
     * REAL outgoing HTTP call to $APP_URL/api/... for an approved
     * operation -- there is no test server listening at that URL in this
     * suite, so every service()-driven test that must observe an actually
     * EXECUTED (not merely paused/declined) git-commit or git-push needs
     * this fake in place, dispatching straight to the real controller
     * method instead. A pre-confirmation refusal or a decline never
     * reaches this at all (Http::assertNothingSent() still holds for
     * those), so installing this fake is harmless for every other case
     * too.
     */
    private function fakeCodingWorkspaceHttp(): void
    {
        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $method = strtoupper($request->method());

            if (!preg_match('#/([^/]+)/(git-commit|git-push)$#', $path, $m)) {
                return Http::response(['error' => 'unmapped test route: '.$path], 500);
            }
            [, $projectId, $suffix] = $m;

            $laravelRequest = in_array($method, ['POST', 'PUT', 'PATCH'], true)
                ? Request::create($request->url(), $method, $request->data())
                : Request::create($request->url(), $method);

            $controller = new CodingWorkspaceController();

            $response = match (true) {
                $suffix === 'git-commit' && $method === 'POST' => $controller->gitCommit($laravelRequest, $projectId),
                $suffix === 'git-push' && $method === 'POST' => $controller->gitPush($laravelRequest, $projectId),
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

    private function registerProject(string $rootPath, ?User $owner = null, bool $networkEnabled = true): CodingProject
    {
        return CodingProject::create([
            'user_id' => ($owner ?? $this->user)->id,
            'name' => 'git publish confirmation project',
            'root_path' => $rootPath,
            'test_command' => null,
            'network_enabled' => $networkEnabled,
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

    private function gitPushCall(CodingProject $project, ?string $remote, ?string $branch, string $callId): array
    {
        $body = [];
        if ($remote !== null) {
            $body['remote'] = $remote;
        }
        if ($branch !== null) {
            $body['branch'] = $branch;
        }

        return $this->toolCall('execute_operation', [
            'operationId' => AgentLoopService::CODING_WORKSPACE_GIT_PUSH_OPERATION_ID,
            'parameters' => [
                'path' => ['project' => $project->id],
                'body' => $body,
            ],
        ], $callId);
    }

    /**
     * Approves a pending confirmation and returns the continuation's own
     * result -- ScopeSurfaceTest.php's own established pattern: since the
     * approved call's own work happens inline inside resumeSync(), a
     * fresh pause the very next scripted tool call raises (here: the
     * gitPush call that follows an approved gitCommit) is returned
     * directly by this same call.
     */
    private function approve(AgentLoopService $service, Conversation $conversation, array $result): array
    {
        $message = Message::find($result['message_id']);

        return $service->resumeSync($conversation->fresh(), $message, true);
    }

    private function decline(AgentLoopService $service, Conversation $conversation, array $result): array
    {
        $message = Message::find($result['message_id']);

        return $service->resumeSync($conversation->fresh(), $message, false);
    }

    private function apiUrl(string $suffix): string
    {
        return '/api/clarion-app/llm-client/'.$suffix;
    }

    /**
     * Reads back the raw tool-result content the agent loop fed to the
     * model for a specific tool_call_id -- used to pin the SPECIFIC
     * refusal code (e.g. git_publish_disabled / git_no_remote_configured)
     * a pre-confirmation refusal must carry, distinguishing it from
     * today's generic "operation not permitted" rejection (gitPush is not
     * yet in coding.yaml's tools.allow at all) which would otherwise make
     * a bare status==='completed' assertion pass for the wrong reason.
     * Mirrors GitCommitConfirmationJourneyTest.php's own identical helper.
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

    /**
     * Shared fixture for both the decline and the approve journey below:
     * a local repo with an initial commit already published to a real,
     * local bare-repo remote, then one further local edit that has NOT
     * yet been committed -- the change the requested task will commit and
     * then propose to publish.
     *
     * @return array{repoPath: string, barePath: string, branch: string, initialRemoteHead: string, project: CodingProject}
     */
    private function setUpRepoAndBareRemoteWithPendingChange(): array
    {
        $repoPath = $this->createGitRepo();
        file_put_contents($repoPath.'/file.txt', "initial\n");
        $this->runGit(['add', 'file.txt'], $repoPath);
        $this->runGit(['commit', '-m', 'Initial commit'], $repoPath);
        $branch = $this->currentBranch($repoPath);

        $barePath = $this->createBareRemote();
        $this->runGit(['remote', 'add', 'origin', 'file://'.$barePath], $repoPath);
        $this->pushToBareRemote($repoPath, $barePath, $branch);

        $initialRemoteHead = trim($this->shellGit($barePath, ['rev-parse', 'HEAD']));

        // The change that will be committed and then (separately) proposed
        // for publish -- deliberately made AFTER the initial push, so the
        // remote already has a branch by the time the confirmable flow
        // below begins.
        file_put_contents($repoPath.'/file.txt', "initial\nchanged\n");

        $project = $this->registerProject($repoPath, null, true);

        return compact('repoPath', 'barePath', 'branch', 'initialRemoteHead', 'project');
    }

    // -----------------------------------------------------------------
    // (1)+(2)+(3): one requested task that both commits locally and
    // publishes -- two DISTINCT confirmation pauses appear in sequence;
    // approving the git_commit one does NOT also resolve the git_publish
    // one; the git_publish pause names the bare-repo remote and exactly
    // the just-committed change; declining the publish step only leaves
    // the bare remote's HEAD unchanged while the earlier, separately-
    // approved commit still stands locally (AS1, AS2, AS3, FR-007, FR-008).
    // -----------------------------------------------------------------

    #[Test]
    public function committing_then_declining_the_publish_leaves_the_remote_untouched_while_the_local_commit_stands(): void
    {
        $this->seedOperationCatalog();
        $this->actingAs($this->user, 'api');
        $this->fakeCodingWorkspaceHttp();

        ['repoPath' => $repoPath, 'barePath' => $barePath, 'branch' => $branch, 'initialRemoteHead' => $initialRemoteHead, 'project' => $project]
            = $this->setUpRepoAndBareRemoteWithPendingChange();

        $conversation = $this->makeConversation($this->agent(), $project);

        $service = $this->service([
            $this->toolCallReply([$this->gitCommitCall($project, 'Update file', null, 'call_commit')]),
            $this->toolCallReply([$this->gitPushCall($project, 'origin', $branch, 'call_push')]),
            $this->plainReply('Understood, the publish was not made.'),
        ]);

        $result = $service->run($conversation->fresh(), 'Please commit and push the change.');

        $this->assertSame('confirmation_required', $result['status'], 'a gitCommit operation must pause for its own confirmation');
        $this->assertSame('git_commit', $result['confirmation']['confirmation_type'] ?? null);

        // Approve the commit only.
        $afterCommit = $this->approve($service, $conversation, $result);
        $newCommitHash = trim($this->shellGit($repoPath, ['rev-parse', 'HEAD']));
        $this->assertNotSame($initialRemoteHead, $newCommitHash, 'a new local commit must actually have been made by the approved gitCommit');

        $this->assertSame(
            'confirmation_required',
            $afterCommit['status'],
            'approving the commit must not also resolve the still-pending publish -- git_push must raise its own separate pause (AS1, FR-007)'
        );
        $confirmation = $afterCommit['confirmation'] ?? [];
        $this->assertSame('git_publish', $confirmation['confirmation_type'] ?? null, 'the marker must carry the dedicated git_publish confirmation_type');
        $this->assertSame(
            'file://'.$barePath,
            $confirmation['remote_url_sanitized'] ?? null,
            'remote_url_sanitized must name the bare-repo fixture (AS2, FR-008)'
        );
        $this->assertSame(
            [$newCommitHash],
            array_column($confirmation['commits_ahead'] ?? [], 'hash'),
            'commits_ahead must name exactly the just-committed change, not the already-published initial commit (AS2)'
        );

        // Decline the publish step only.
        $afterDecline = $this->decline($service, $conversation, $afterCommit);
        $this->assertSame('completed', $afterDecline['status']);

        $remoteHeadAfterDecline = trim($this->shellGit($barePath, ['rev-parse', 'HEAD']));
        $this->assertSame(
            $initialRemoteHead,
            $remoteHeadAfterDecline,
            "declining the publish must leave the bare remote's HEAD completely unchanged (AS3)"
        );

        $localHeadAfterDecline = trim($this->shellGit($repoPath, ['rev-parse', 'HEAD']));
        $this->assertSame(
            $newCommitHash,
            $localHeadAfterDecline,
            'the earlier, separately-approved commit must still stand locally after the publish is declined (AS3)'
        );

        $pushRow = CodingCommandExecution::where('coding_project_id', $project->id)
            ->where('command', 'like', 'git push%')
            ->first();
        $this->assertNotNull($pushRow, 'a refused execution row must be written for the declined publish (research.md D9)');
        $this->assertSame('refused', $pushRow->status);
    }

    // -----------------------------------------------------------------
    // (4): approving the publish updates the bare remote's HEAD to the
    // pinned local commit that was described.
    // -----------------------------------------------------------------

    #[Test]
    public function committing_then_approving_the_publish_updates_the_remote_to_match_the_pinned_commit(): void
    {
        $this->seedOperationCatalog();
        $this->actingAs($this->user, 'api');
        $this->fakeCodingWorkspaceHttp();

        ['repoPath' => $repoPath, 'barePath' => $barePath, 'branch' => $branch, 'project' => $project]
            = $this->setUpRepoAndBareRemoteWithPendingChange();

        $conversation = $this->makeConversation($this->agent(), $project);

        $service = $this->service([
            $this->toolCallReply([$this->gitCommitCall($project, 'Update file', null, 'call_commit')]),
            $this->toolCallReply([$this->gitPushCall($project, 'origin', $branch, 'call_push')]),
            $this->plainReply('Published.'),
        ]);

        $result = $service->run($conversation->fresh(), 'Please commit and push the change.');
        $afterCommit = $this->approve($service, $conversation, $result);
        $newCommitHash = trim($this->shellGit($repoPath, ['rev-parse', 'HEAD']));

        $this->assertSame('confirmation_required', $afterCommit['status']);
        $this->assertSame('git_publish', $afterCommit['confirmation']['confirmation_type'] ?? null);

        $afterPush = $this->approve($service, $conversation, $afterCommit);
        $this->assertSame('completed', $afterPush['status']);

        $remoteHeadAfterApprove = trim($this->shellGit($barePath, ['rev-parse', 'HEAD']));
        $this->assertSame(
            $newCommitHash,
            $remoteHeadAfterApprove,
            "approving the publish must update the bare remote's HEAD to exactly the pinned local commit that was described"
        );

        $pushRow = CodingCommandExecution::where('coding_project_id', $project->id)
            ->where('command', 'like', 'git push%')
            ->first();
        $this->assertNotNull($pushRow);
        $this->assertSame('completed', $pushRow->status);
    }

    // -----------------------------------------------------------------
    // (5): network_enabled: false -- refused before any confirmation,
    // code: 'git_publish_disabled', no CodingCommandExecution row
    // (research.md D5).
    // -----------------------------------------------------------------

    #[Test]
    public function network_disabled_refuses_the_push_before_any_confirmation_with_no_row_written(): void
    {
        $this->seedOperationCatalog();
        $this->actingAs($this->user, 'api');
        $this->fakeCodingWorkspaceHttp();

        $repoPath = $this->createGitRepo();
        file_put_contents($repoPath.'/file.txt', "one\n");
        $this->runGit(['add', 'file.txt'], $repoPath);
        $this->runGit(['commit', '-m', 'Initial commit'], $repoPath);
        $branch = $this->currentBranch($repoPath);

        // Deliberately no remote configured either -- if the disabled
        // check were accidentally ordered after remote inspection, this
        // would still surface as git_no_remote_configured rather than
        // git_publish_disabled, which the assertion below would catch.
        $project = $this->registerProject($repoPath, null, false);
        $conversation = $this->makeConversation($this->agent(), $project);

        $service = $this->service([
            $this->toolCallReply([$this->gitPushCall($project, 'origin', $branch, 'call_push_disabled')]),
            $this->plainReply('Publishing is not enabled for this workspace.'),
        ]);
        $result = $service->run($conversation->fresh(), 'Please push the changes.');

        $this->assertSame('completed', $result['status'], 'a publish-disabled refusal must never pause for confirmation');
        $this->assertStringContainsString(
            'git_publish_disabled',
            $this->toolResultContentFor($conversation, 'call_push_disabled'),
            'the tool result fed back to the model must carry the specific git_publish_disabled refusal code, not a generic operation-rejected error'
        );
        Http::assertNothingSent();
        $this->assertSame(0, DB::table('coding_command_executions')->where('coding_project_id', $project->id)->count());
    }

    // -----------------------------------------------------------------
    // (6): network_enabled: true, no remote configured -- refused before
    // confirmation, code: 'git_no_remote_configured' -- a DIFFERENT code
    // from case 5, never a guessed destination (FR-012, AS4).
    // -----------------------------------------------------------------

    #[Test]
    public function no_remote_configured_refuses_the_push_before_confirmation_with_a_code_distinct_from_the_disabled_case(): void
    {
        $this->seedOperationCatalog();
        $this->actingAs($this->user, 'api');
        $this->fakeCodingWorkspaceHttp();

        $repoPath = $this->createGitRepo();
        file_put_contents($repoPath.'/file.txt', "one\n");
        $this->runGit(['add', 'file.txt'], $repoPath);
        $this->runGit(['commit', '-m', 'Initial commit'], $repoPath);
        $branch = $this->currentBranch($repoPath);

        $project = $this->registerProject($repoPath, null, true);
        $conversation = $this->makeConversation($this->agent(), $project);

        $service = $this->service([
            $this->toolCallReply([$this->gitPushCall($project, 'origin', $branch, 'call_push_no_remote')]),
            $this->plainReply('There is no shared location configured for this workspace.'),
        ]);
        $result = $service->run($conversation->fresh(), 'Please push the changes.');

        $this->assertSame('completed', $result['status'], 'a no-remote-configured refusal must never pause for confirmation');
        $content = $this->toolResultContentFor($conversation, 'call_push_no_remote');
        $this->assertStringContainsString(
            'git_no_remote_configured',
            $content,
            'the tool result fed back to the model must carry the specific git_no_remote_configured refusal code'
        );
        $this->assertStringNotContainsString(
            'git_publish_disabled',
            $content,
            'a configured-but-unreachable-remote refusal must never be conflated with the disabled-network refusal (FR-012)'
        );
        Http::assertNothingSent();
        $this->assertSame(0, DB::table('coding_command_executions')->where('coding_project_id', $project->id)->count());
    }

    // -----------------------------------------------------------------
    // (7): 404 for an absent/foreign-owned project id.
    // -----------------------------------------------------------------

    #[Test]
    public function git_push_404s_for_an_absent_or_foreign_owned_project(): void
    {
        $this->actingAs($this->user, 'api');

        $repoPath = $this->createGitRepo();
        $foreignProject = $this->registerProject($repoPath, $this->otherUser);

        $notFound = ['error' => 'Coding project not found', 'code' => 'coding_project_not_found'];
        $absentId = (string) Str::uuid();

        $this->postJson($this->apiUrl("coding-project/{$foreignProject->id}/git-push"), [
            'remote' => 'origin',
        ])->assertStatus(404)->assertJson($notFound);

        $this->postJson($this->apiUrl("coding-project/{$absentId}/git-push"), [
            'remote' => 'origin',
        ])->assertStatus(404)->assertJson($notFound);
    }
}
