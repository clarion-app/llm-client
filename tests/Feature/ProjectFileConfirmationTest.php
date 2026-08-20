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
 * A dedicated proof that a file creation, alteration, or deletion the
 * coding agent proposes is genuinely held for the user's explicit
 * confirmation before anything happens to it -- driven through the real,
 * unmodified confirmation pause/resume mechanism and the real,
 * provisioned coding agent, never a hand-simulated stand-in.
 *
 * Every mutation call is routed all the way through to the real
 * CodingWorkspaceController (see fakeCodingWorkspaceHttp() below), so a
 * "the file changed on disk" or "the file did not change on disk"
 * assertion reflects genuine filesystem state produced by the actual
 * production controller code, not a mocked interaction. Only the model
 * provider itself is scripted.
 */
class ProjectFileConfirmationTest extends TestCase
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

        $this->tmpDir = sys_get_temp_dir().'/coding-agent-confirmation-'.Str::random(12);
        mkdir($this->tmpDir, 0777, true);

        $this->project = CodingProject::create([
            'user_id' => $this->user->id,
            'name' => 'confirmation project',
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
     * straight into the real, unmodified CodingWorkspaceController, so an
     * approved write/delete reaches disk through the exact same
     * production code a live request would run. Only the last two path
     * segments are pattern-matched (project id + operation name), so the
     * exact URL prefix the operation catalog happens to use here does not
     * matter.
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

        // A stub token factory bypasses Passport's real OAuth token
        // minting, which is not bootable in this test environment. The
        // outbound HTTP call is routed straight into the real controller
        // by fakeCodingWorkspaceHttp() below, via Auth::actingAs() -- not
        // by verifying this bearer token -- so its exact value carries no
        // weight here.
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

    private function deleteFileCall(string $relativePath, string $callId): array
    {
        return $this->toolCall('execute_operation', [
            'operationId' => AgentLoopService::CODING_WORKSPACE_DELETE_FILE_OPERATION_ID,
            'parameters' => [
                'path' => ['project' => $this->project->id],
                'query' => ['path' => $relativePath],
            ],
        ], $callId);
    }

    private function runTestsCall(string $callId): array
    {
        return $this->toolCall('execute_operation', [
            'operationId' => 'clarionApp.llmClient.codingWorkspace.runTests',
            'parameters' => ['path' => ['project' => $this->project->id]],
        ], $callId);
    }

    private function readFileCall(string $relativePath, string $callId): array
    {
        return $this->toolCall('execute_operation', [
            'operationId' => 'clarionApp.llmClient.codingWorkspace.readFile',
            'parameters' => [
                'path' => ['project' => $this->project->id],
                'query' => ['path' => $relativePath],
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

    private function makeFile(string $relativePath, string $content): void
    {
        file_put_contents($this->tmpDir.'/'.$relativePath, $content);
    }

    private function fileContent(string $relativePath): string|false
    {
        return @file_get_contents($this->tmpDir.'/'.$relativePath);
    }

    private function projectFileExists(string $relativePath): bool
    {
        return is_file($this->tmpDir.'/'.$relativePath);
    }

    // -----------------------------------------------------------------
    // A write pauses, and the file is untouched at the pause point
    // -----------------------------------------------------------------

    #[Test]
    public function write_file_pauses_for_confirmation_with_the_target_file_verified_unchanged_on_disk_at_the_pause_point(): void
    {
        $this->makeFile('note.txt', "original content\n");

        $conversation = $this->makeConversation($this->agent());

        $service = $this->service([
            $this->toolCallReply([$this->writeFileCall('note.txt', 'new content', 'call_write_1')]),
        ]);

        $result = $service->run($conversation->fresh(), 'Please update note.txt.');

        $this->assertSame('confirmation_required', $result['status'], 'a write must pause, never execute immediately');

        $message = Message::find($result['message_id']);
        $this->assertNotNull($message->tool_data['pending_confirmation'] ?? null);
        $this->assertNull($message->tool_data['tool_results'], 'no tool result can exist yet -- the call was never dispatched');

        $this->assertSame(
            "original content\n",
            $this->fileContent('note.txt'),
            'the file must be byte-for-byte unchanged while its confirmation is pending',
        );

        Http::assertNothingSent();
    }

    // -----------------------------------------------------------------
    // Declining leaves the file unchanged and states no change applied
    // -----------------------------------------------------------------

    #[Test]
    public function declining_leaves_the_file_unchanged_and_the_report_states_no_change_was_applied(): void
    {
        $this->makeFile('note.txt', "original content\n");

        $conversation = $this->makeConversation($this->agent());

        $service = $this->service([
            $this->toolCallReply([$this->writeFileCall('note.txt', 'new content', 'call_write_1')]),
            $this->plainReply('Understood, note.txt was left unchanged.'),
        ]);

        $result = $service->run($conversation->fresh(), 'Please update note.txt.');
        $message = Message::find($result['message_id']);

        $final = $service->resumeSync($conversation->fresh(), $message, false);

        $this->assertSame('completed', $final['status']);
        $this->assertSame(
            "original content\n",
            $this->fileContent('note.txt'),
            'declining must leave the file exactly as it was',
        );
        Http::assertNothingSent();

        $message->refresh();
        $this->assertSame(
            'User cancelled this operation.',
            $message->tool_data['tool_results'][0]['content'] ?? null,
            'the tool result must plainly state the change was not applied, not a fabricated success',
        );
    }

    // -----------------------------------------------------------------
    // Approving applies the change, and only after approval
    // -----------------------------------------------------------------

    #[Test]
    public function approving_applies_the_change_and_only_after_approval(): void
    {
        $this->makeFile('note.txt', "original content\n");

        $conversation = $this->makeConversation($this->agent());

        $service = $this->service([
            $this->toolCallReply([$this->writeFileCall('note.txt', 'updated content', 'call_write_1')]),
            $this->plainReply('Done. note.txt was updated.'),
        ]);

        $result = $service->run($conversation->fresh(), 'Please update note.txt.');
        $this->assertSame(
            "original content\n",
            $this->fileContent('note.txt'),
            'still unchanged before approval',
        );
        Http::assertNothingSent();

        $message = Message::find($result['message_id']);
        $final = $service->resumeSync($conversation->fresh(), $message, true);

        $this->assertSame('completed', $final['status']);
        $this->assertSame(
            'updated content',
            $this->fileContent('note.txt'),
            'the approved change must be applied, byte-for-byte, only once approved',
        );

        Http::assertSent(fn ($req) => strtoupper($req->method()) === 'POST' && str_contains((string) parse_url($req->url(), PHP_URL_PATH), '/file'));
    }

    // -----------------------------------------------------------------
    // A delete pauses too, and is only removed once approved
    // -----------------------------------------------------------------

    #[Test]
    public function delete_file_pauses_for_confirmation_and_is_removed_only_after_approval(): void
    {
        $this->makeFile('deleteme.txt', "to be removed\n");

        $conversation = $this->makeConversation($this->agent());

        $service = $this->service([
            $this->toolCallReply([$this->deleteFileCall('deleteme.txt', 'call_delete_1')]),
            $this->plainReply('Deleted deleteme.txt.'),
        ]);

        $result = $service->run($conversation->fresh(), 'Delete deleteme.txt.');
        $this->assertSame('confirmation_required', $result['status']);
        $this->assertTrue($this->projectFileExists('deleteme.txt'), 'the file must still exist while its delete confirmation is pending');

        $message = Message::find($result['message_id']);
        $final = $service->resumeSync($conversation->fresh(), $message, true);

        $this->assertSame('completed', $final['status']);
        $this->assertFalse($this->projectFileExists('deleteme.txt'), 'the approved delete must remove the file only once approved');
    }

    // -----------------------------------------------------------------
    // Tests and reads are never held for confirmation
    // -----------------------------------------------------------------

    #[Test]
    public function running_the_projects_tests_executes_immediately_without_pausing(): void
    {
        $conversation = $this->makeConversation($this->agent());

        $service = $this->service([
            $this->toolCallReply([$this->runTestsCall('call_tests_1')]),
            $this->plainReply('No tests are configured for this project.'),
        ]);

        $result = $service->run($conversation->fresh(), 'Run the tests.');

        $this->assertSame('completed', $result['status'], 'running tests must never pause for confirmation');

        $toolMessage = Message::where('conversation_id', $conversation->id)
            ->whereNotNull('tool_data')
            ->orderBy('id')
            ->first();
        $this->assertNull($toolMessage->tool_data['pending_confirmation'] ?? null);
    }

    #[Test]
    public function reading_a_file_executes_immediately_without_pausing(): void
    {
        $this->makeFile('note.txt', "hello\n");

        $conversation = $this->makeConversation($this->agent());

        $service = $this->service([
            $this->toolCallReply([$this->readFileCall('note.txt', 'call_read_1')]),
            $this->plainReply('note.txt contains "hello".'),
        ]);

        $result = $service->run($conversation->fresh(), 'What does note.txt say?');

        $this->assertSame('completed', $result['status'], 'a read must never pause for confirmation');

        $toolMessage = Message::where('conversation_id', $conversation->id)
            ->whereNotNull('tool_data')
            ->orderBy('id')
            ->first();
        $this->assertNull($toolMessage->tool_data['pending_confirmation'] ?? null);
    }

    // -----------------------------------------------------------------
    // Multiple files are confirmed independently of one another
    // -----------------------------------------------------------------

    #[Test]
    public function declining_one_files_change_never_affects_a_later_independent_files_confirmation(): void
    {
        $this->makeFile('a.txt', "A original\n");
        $this->makeFile('b.txt', "B original\n");

        $conversation = $this->makeConversation($this->agent());

        $service = $this->service([
            $this->toolCallReply([$this->writeFileCall('a.txt', 'A changed', 'call_a')]),
            $this->plainReply('OK, a.txt was left unchanged.'),
            $this->toolCallReply([$this->writeFileCall('b.txt', 'B changed', 'call_b')]),
            $this->plainReply('Done. b.txt was updated.'),
        ]);

        // First, independent request: propose a change to a.txt, then
        // decline it.
        $resultA = $service->run($conversation->fresh(), 'Update a.txt.');
        $this->assertSame('confirmation_required', $resultA['status']);
        $messageA = Message::find($resultA['message_id']);
        $finalA = $service->resumeSync($conversation->fresh(), $messageA, false);
        $this->assertSame('completed', $finalA['status']);
        $this->assertSame("A original\n", $this->fileContent('a.txt'), 'declining a.txt must leave it unchanged');

        // Second, entirely independent request: propose a change to
        // b.txt and approve it. a.txt's earlier decline must have no
        // bearing on whether b.txt is offered or applied.
        $resultB = $service->run($conversation->fresh(), 'Update b.txt.');
        $this->assertSame('confirmation_required', $resultB['status']);
        $messageB = Message::find($resultB['message_id']);
        $this->assertSame("B original\n", $this->fileContent('b.txt'), 'b.txt must still be unchanged while its own confirmation is pending');

        $finalB = $service->resumeSync($conversation->fresh(), $messageB, true);
        $this->assertSame('completed', $finalB['status']);

        $this->assertSame("A original\n", $this->fileContent('a.txt'), 'a.txt must remain untouched throughout');
        $this->assertSame('B changed', $this->fileContent('b.txt'), 'b.txt must be updated only via its own, independent approval');
    }
}
