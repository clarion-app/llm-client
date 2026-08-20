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
use ClarionApp\LlmClient\Services\GitOperationInspector;
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
 * 126-git-operations-confirmation, Polish, T044 (quickstart Scenario 6,
 * SC-005, research.md D8) -- the credential non-exposure guarantee.
 *
 * The fixture remote embeds a real, literal test credential
 * (`https://<user>:<credential>@127.0.0.1:<port>/repo.git`) directly in
 * the workspace's own git config -- exactly the leak vector D8 defends
 * against. The port is a genuinely unused loopback port (bound then
 * immediately released, so nothing is listening) rather than a live
 * server: every git invocation this file drives against it fails fast
 * with a connection refusal, never blocking on a real network wait, and
 * no traffic ever reaches an actual remote host -- matching the
 * Repository map's "no live network access anywhere in this feature's
 * test suite" constraint while still giving `git remote get-url`/
 * `git push`/`git ls-remote` a real, credential-bearing URL to read and
 * (for `git push`) a real subprocess failure whose stderr this file
 * inspects.
 *
 * Every scenario that can reach gitPush is driven here -- approved,
 * declined, refused-before-confirmation (network disabled), and a direct
 * HTTP call to the route itself -- capturing the confirmation marker,
 * the tool-result content fed back to the model, the raw HTTP response
 * body, and the persisted CodingCommandExecution row's command/stdout/
 * stderr alike; the literal test credential string must never appear in
 * any of them, and remote_url_sanitized/every persisted command value
 * must show the URL with its userinfo component stripped.
 *
 * Expected GREEN as written -- GitOperationInspector::sanitizeRemoteUrl()
 * (T006) and its call sites (T025-T027) already exist and are already
 * exercised indirectly by GitPublishConfirmationJourneyTest.php; this
 * file is this feature's first test to configure a remote that actually
 * embeds a credential rather than a bare file:// path, so a red result
 * here would point at a genuine, previously-unexercised leak rather than
 * a design gap.
 */
class GitCredentialNonExposureTest extends TestCase
{
    private const TEST_CREDENTIAL_USER = 'testuser';

    private const TEST_CREDENTIAL_SECRET = 's3cr3t-tok3n-9f8e7d';

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
    // Fixture: a real, throwaway git repo whose "origin" remote genuinely
    // embeds a test credential -- a loopback port bound then immediately
    // released (so nothing is listening there), never a live server.
    // -----------------------------------------------------------------

    private function createGitRepo(): string
    {
        $repoPath = sys_get_temp_dir().'/git_credential_test_'.Str::random(12);
        mkdir($repoPath, 0777, true);
        $this->tmpDirs[] = $repoPath;

        $this->runGit(['init'], $repoPath);
        $this->runGit(['config', 'user.name', 'Test Author'], $repoPath);
        $this->runGit(['config', 'user.email', 'test-author@example.test'], $repoPath);
        $this->runGit(['config', 'commit.gpgsign', 'false'], $repoPath);

        return $repoPath;
    }

    private function unusedLocalPort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($socket, "could not bind a loopback socket to discover a free port: {$errstr}");
        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private function credentialedRemoteUrl(int $port): string
    {
        return sprintf(
            'https://%s:%s@127.0.0.1:%d/repo.git',
            self::TEST_CREDENTIAL_USER,
            self::TEST_CREDENTIAL_SECRET,
            $port,
        );
    }

    private function currentBranch(string $repoPath): string
    {
        return trim($this->shellGit($repoPath, ['rev-parse', '--abbrev-ref', 'HEAD']));
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
     * @return array{repoPath: string, branch: string, port: int, project: CodingProject}
     */
    private function setUpRepoWithCredentialedRemote(bool $networkEnabled = true): array
    {
        $repoPath = $this->createGitRepo();
        file_put_contents($repoPath.'/file.txt', "initial\n");
        $this->runGit(['add', 'file.txt'], $repoPath);
        $this->runGit(['commit', '-m', 'Initial commit'], $repoPath);
        $branch = $this->currentBranch($repoPath);

        $port = $this->unusedLocalPort();
        $this->runGit(['remote', 'add', 'origin', $this->credentialedRemoteUrl($port)], $repoPath);

        $project = CodingProject::create([
            'user_id' => $this->user->id,
            'name' => 'credential non-exposure project',
            'root_path' => $repoPath,
            'test_command' => null,
            'network_enabled' => $networkEnabled,
        ]);

        return compact('repoPath', 'branch', 'port', 'project');
    }

    // -----------------------------------------------------------------
    // Operation-catalog / provisioning scaffolding -- mirrors
    // GitPublishConfirmationJourneyTest.php exactly.
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

    private function assertCredentialAbsent(string $haystack, string $context): void
    {
        $this->assertStringNotContainsString(
            self::TEST_CREDENTIAL_SECRET,
            $haystack,
            "the configured test credential must never appear in {$context}",
        );
        $this->assertStringNotContainsString(
            self::TEST_CREDENTIAL_USER.':'.self::TEST_CREDENTIAL_SECRET,
            $haystack,
            "the credential-bearing userinfo component must never appear in {$context}",
        );
    }

    // -----------------------------------------------------------------
    // Approved: the confirmation marker, the executed (and, against this
    // fixture's unreachable remote, failing) push's tool-result content,
    // and the persisted CodingCommandExecution row's command/stdout/
    // stderr all stay credential-free -- mutation checklist rows 7 and 8.
    // -----------------------------------------------------------------

    #[Test]
    public function proposing_and_approving_a_push_never_exposes_the_configured_credential(): void
    {
        $this->seedOperationCatalog();
        $this->actingAs($this->user, 'api');
        $this->fakeCodingWorkspaceHttp();

        ['branch' => $branch, 'port' => $port, 'project' => $project] = $this->setUpRepoWithCredentialedRemote();
        $conversation = $this->makeConversation($this->agent(), $project);

        $service = $this->service([
            $this->toolCallReply([$this->gitPushCall($project, 'origin', $branch, 'call_push')]),
            $this->plainReply('Understood.'),
        ]);

        $result = $service->run($conversation->fresh(), 'Please push the changes.');

        $this->assertSame('confirmation_required', $result['status']);
        $confirmation = $result['confirmation'] ?? [];
        $this->assertSame('git_publish', $confirmation['confirmation_type'] ?? null);

        $markerJson = json_encode($confirmation, JSON_UNESCAPED_SLASHES);
        $this->assertCredentialAbsent($markerJson, 'the git_publish confirmation marker');
        $this->assertSame(
            "https://127.0.0.1:{$port}/repo.git",
            $confirmation['remote_url_sanitized'] ?? null,
            'remote_url_sanitized must strip the userinfo component while keeping the rest of the URL intact',
        );

        $afterApprove = $this->approve($service, $conversation, $result);
        $this->assertSame('completed', $afterApprove['status'], 'the loop must complete once the follow-up plain reply is consumed, even though the push itself failed against the unreachable fixture remote');

        $toolResultContent = $this->toolResultContentFor($conversation, 'call_push');
        $this->assertNotSame('', $toolResultContent, 'the executed push must have produced a tool result');
        $this->assertCredentialAbsent($toolResultContent, 'the tool result content fed back to the model');

        $row = CodingCommandExecution::where('coding_project_id', $project->id)->first();
        $this->assertNotNull($row, 'an executed (even if failed) push must still write an audit row');
        $this->assertSame('failed', $row->status, 'fixture sanity: the push must genuinely have failed against the unreachable remote, not silently no-opped');
        $this->assertCredentialAbsent((string) $row->command, "the persisted CodingCommandExecution.command ({$row->command})");
        $this->assertCredentialAbsent((string) $row->stdout, 'the persisted CodingCommandExecution.stdout');
        $this->assertCredentialAbsent((string) $row->stderr, 'the persisted CodingCommandExecution.stderr');
    }

    // -----------------------------------------------------------------
    // Declined: the refused audit row's reconstructed command text stays
    // credential-free too.
    // -----------------------------------------------------------------

    #[Test]
    public function declining_a_push_confirmation_never_exposes_the_configured_credential(): void
    {
        $this->seedOperationCatalog();
        $this->actingAs($this->user, 'api');
        $this->fakeCodingWorkspaceHttp();

        ['branch' => $branch, 'project' => $project] = $this->setUpRepoWithCredentialedRemote();
        $conversation = $this->makeConversation($this->agent(), $project);

        $service = $this->service([
            $this->toolCallReply([$this->gitPushCall($project, 'origin', $branch, 'call_push_decline')]),
            $this->plainReply('Understood, the push was not made.'),
        ]);

        $result = $service->run($conversation->fresh(), 'Please push the changes.');
        $this->assertSame('confirmation_required', $result['status']);

        $afterDecline = $this->decline($service, $conversation, $result);
        $this->assertSame('completed', $afterDecline['status']);

        $toolResultContent = $this->toolResultContentFor($conversation, 'call_push_decline');
        $this->assertCredentialAbsent($toolResultContent, 'the declined push\'s tool result content');

        $row = CodingCommandExecution::where('coding_project_id', $project->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('refused', $row->status);
        $this->assertCredentialAbsent((string) $row->command, "the refused row's reconstructed command ({$row->command})");
    }

    // -----------------------------------------------------------------
    // Refused before confirmation (network_enabled: false): the workspace
    // still has the credentialed remote configured, but the disabled
    // check fires before any remote is even inspected -- never touching,
    // let alone exposing, the credential.
    // -----------------------------------------------------------------

    #[Test]
    public function a_publish_disabled_refusal_never_exposes_the_configured_credential(): void
    {
        $this->seedOperationCatalog();
        $this->actingAs($this->user, 'api');
        $this->fakeCodingWorkspaceHttp();

        ['branch' => $branch, 'project' => $project] = $this->setUpRepoWithCredentialedRemote(networkEnabled: false);
        $conversation = $this->makeConversation($this->agent(), $project);

        $service = $this->service([
            $this->toolCallReply([$this->gitPushCall($project, 'origin', $branch, 'call_push_disabled')]),
            $this->plainReply('Publishing is not enabled for this workspace.'),
        ]);

        $result = $service->run($conversation->fresh(), 'Please push the changes.');
        $this->assertSame('completed', $result['status'], 'a publish-disabled refusal must never pause for confirmation');

        $content = $this->toolResultContentFor($conversation, 'call_push_disabled');
        $this->assertStringContainsString('git_publish_disabled', $content);
        $this->assertCredentialAbsent($content, 'the publish-disabled refusal\'s tool result content');

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('coding_command_executions')->where('coding_project_id', $project->id)->count());
    }

    // -----------------------------------------------------------------
    // A direct HTTP call to the real git-push route (no agent loop at
    // all) -- the raw JSON response body itself must never carry the
    // credential either.
    // -----------------------------------------------------------------

    #[Test]
    public function the_git_push_route_never_returns_the_configured_credential_in_its_response_body(): void
    {
        $this->actingAs($this->user, 'api');

        ['branch' => $branch, 'port' => $port, 'project' => $project] = $this->setUpRepoWithCredentialedRemote();

        $response = $this->postJson($this->apiUrl("coding-project/{$project->id}/git-push"), [
            'remote' => 'origin',
            'branch' => $branch,
        ]);

        // Unreachable fixture remote -> a genuine execution-time failure
        // (422 git_push_rejected), exercising exactly the response shape
        // whose `stderr` field D8 requires sanitized.
        $response->assertStatus(422);
        $body = $response->json();
        $this->assertSame('git_push_rejected', $body['code'] ?? null);

        $rawBody = $response->getContent();
        $this->assertCredentialAbsent($rawBody, 'the raw git-push HTTP response body');
    }

    // -----------------------------------------------------------------
    // Wiring proof that gitPush() actually calls
    // GitOperationInspector::sanitizeRemoteUrl() on both the audit row's
    // `stderr` and its `command` -- added during Polish (T046, mutation
    // checklist row 8) after discovering that a real, unreachable
    // https:// remote's own git/curl-produced stderr is ALREADY
    // credential-free by construction (git's own curl-based redaction
    // already strips userinfo from "unable to access '<url>'" messages
    // before this feature's own sanitizer ever sees it -- confirmed by
    // direct experimentation during this phase), which made every case
    // above pass identically whether or not
    // CodingWorkspaceController::gitPush() actually called
    // sanitizeRemoteUrl() at all on that specific field. A real,
    // unauthenticated `git://` remote's DNS-failure error text is the one
    // case found to leak a literal, unredacted "user:pass@host" substring
    // (git's own git:// transport does not route through curl's URL
    // parser at all) -- but research.md D8's sanitizer is deliberately
    // scoped to the "scheme://user:pass@host" shape only (matching
    // GitOperationInspectorTest.php's own SCP-shorthand exclusion), so
    // even the correct, unmutated sanitizer does not clean that specific
    // schemeless substring either; this is a narrow, pre-existing gap
    // outside the scope quickstart.md's own credential fixture describes
    // (an http(s)-shaped URL), recorded in this feature's Progress Log
    // rather than silently patched over by broadening the sanitizer's
    // contract mid-Polish.
    //
    // This test instead proves the WIRING directly: a partial mock of
    // GitOperationInspector runs every other method for real (a genuine
    // git repository, a genuine failing push against the unreachable
    // fixture remote) but intercepts sanitizeRemoteUrl() to tag its
    // input, so the persisted row's stderr/command carrying that tag is
    // proof the call happened -- independent of whether git's own
    // redaction would have masked the same mutation.
    // -----------------------------------------------------------------

    #[Test]
    public function git_push_actually_invokes_sanitize_remote_url_on_the_audit_rows_stderr_and_command(): void
    {
        $this->actingAs($this->user, 'api');

        ['branch' => $branch, 'project' => $project] = $this->setUpRepoWithCredentialedRemote();

        $spyingInspector = Mockery::mock(GitOperationInspector::class)->makePartial();
        $spyingInspector->shouldReceive('sanitizeRemoteUrl')
            ->atLeast()->once()
            ->andReturnUsing(fn (string $value) => 'SANITIZE_CALLED('.$value.')');

        $controller = new CodingWorkspaceController(gitOperationInspector: $spyingInspector);

        $request = Request::create('/', 'POST', ['remote' => 'origin', 'branch' => $branch]);
        $response = $controller->gitPush($request, $project->id);

        $this->assertSame(422, $response->getStatusCode(), 'fixture sanity: the push must genuinely fail against the unreachable remote');
        $body = $response->getData(true);
        $this->assertStringStartsWith(
            'SANITIZE_CALLED(',
            (string) ($body['stderr'] ?? ''),
            'gitPush() must route the real process stderr through sanitizeRemoteUrl() before it ever leaves the controller'
        );

        $row = CodingCommandExecution::where('coding_project_id', $project->id)->first();
        $this->assertNotNull($row);
        $this->assertStringStartsWith(
            'SANITIZE_CALLED(',
            (string) $row->stderr,
            'the persisted CodingCommandExecution.stderr must be the sanitizer\'s own output, not the raw Process error output'
        );
        $this->assertStringContainsString(
            'SANITIZE_CALLED(',
            (string) $row->command,
            'the persisted CodingCommandExecution.command must also route its remote value through sanitizeRemoteUrl() (buildPushCommandString()\'s own defensive pass)'
        );
    }
}
