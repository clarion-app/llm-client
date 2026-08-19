<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\CodingProject;
use ClarionApp\LlmClient\Models\CodingWorkspaceRefusal;
use ClarionApp\LlmClient\Models\McpSession;
use ClarionApp\LlmClient\Services\DockerCommandExecutor;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 123-sandboxed-shell-execution, US1, T009 (contracts/run-command.md).
 * Drives the real, registered `POST coding-project/{project}/run-command`
 * HTTP route through the real CodingWorkspaceController, with
 * DockerCommandExecutor swapped for a Mockery double bound into the
 * container -- this file never touches a real `docker` binary or daemon.
 * Genuine container behavior is proven separately by
 * tests/RealDocker/ContainmentEscapeAttemptTest.php and
 * tests/RealDocker/DockerUnavailableFallbackTest.php.
 *
 * Written before CodingWorkspaceController::runCommand() exists --
 * expected to FAIL red (404/501-shaped or route-not-found) until T014-T016
 * land.
 */
class RunCommandJourneyTest extends TestCase
{
    private User $user;

    private User $otherUser;

    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->otherUser = User::factory()->create();
        $this->actingAs($this->user, 'api');

        $this->projectDir = sys_get_temp_dir().'/coding-agent-run-command-'.Str::random(12);
        mkdir($this->projectDir, 0777, true);

        if (!Schema::hasTable('mcp_sessions')) {
            Schema::create('mcp_sessions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id')->nullable();
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
        Mockery::close();

        DB::table('coding_command_executions')->delete();
        DB::table('coding_workspace_refusals')->delete();
        DB::table('coding_projects')->delete();
        DB::table('users')->delete();

        if (is_dir($this->projectDir)) {
            $this->removeDirectory($this->projectDir);
        }

        parent::tearDown();
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

    private function registerProject(string $rootPath, ?User $owner = null): CodingProject
    {
        return CodingProject::create([
            'user_id' => ($owner ?? $this->user)->id,
            'name' => 'run-command project',
            'root_path' => $rootPath,
            'test_command' => null,
        ]);
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

    // -----------------------------------------------------------------
    // Happy path: exact response shape (contracts/run-command.md §1)
    // -----------------------------------------------------------------

    #[Test]
    public function a_completed_command_returns_the_exact_response_shape_the_contract_documents(): void
    {
        $project = $this->registerProject($this->projectDir);

        $this->bindFakeExecutor([
            'status' => 'completed',
            'exit_code' => 0,
            'timed_out' => false,
            'stdout' => "hello\n",
            'stderr' => '',
            'output_truncated' => false,
            'duration_ms' => 42,
        ]);

        $response = $this->postJson($this->apiUrl("coding-project/{$project->id}/run-command"), [
            'command' => 'echo hello',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'completed',
            'command' => 'echo hello',
            'exit_code' => 0,
            'timed_out' => false,
            'stdout' => "hello\n",
            'stderr' => '',
            'output_truncated' => false,
            'network_enabled' => false,
            'duration_ms' => 42,
        ]);
        $response->assertJsonStructure([
            'status', 'command', 'exit_code', 'timed_out', 'stdout', 'stderr',
            'output_truncated', 'network_enabled', 'duration_ms',
        ]);
    }

    #[Test]
    public function a_command_that_exits_nonzero_is_still_reported_as_completed(): void
    {
        $project = $this->registerProject($this->projectDir);

        $this->bindFakeExecutor([
            'status' => 'completed',
            'exit_code' => 7,
            'timed_out' => false,
            'stdout' => '',
            'stderr' => '',
            'output_truncated' => false,
            'duration_ms' => 10,
        ]);

        $response = $this->postJson($this->apiUrl("coding-project/{$project->id}/run-command"), [
            'command' => 'exit 7',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'completed', 'exit_code' => 7]);
    }

    #[Test]
    public function a_completed_run_persists_exactly_one_coding_command_execution_row(): void
    {
        $project = $this->registerProject($this->projectDir);

        $this->bindFakeExecutor([
            'status' => 'completed',
            'exit_code' => 0,
            'timed_out' => false,
            'stdout' => 'ok',
            'stderr' => '',
            'output_truncated' => false,
            'duration_ms' => 5,
        ]);

        $this->postJson($this->apiUrl("coding-project/{$project->id}/run-command"), [
            'command' => 'echo hello',
        ])->assertStatus(200);

        $this->assertSame(1, DB::table('coding_command_executions')->where('coding_project_id', $project->id)->count());
        $row = DB::table('coding_command_executions')->where('coding_project_id', $project->id)->first();
        $this->assertSame('completed', $row->status);
        $this->assertSame('echo hello', $row->command);
        $this->assertSame($this->user->id, $row->user_id);
    }

    // -----------------------------------------------------------------
    // sandbox_unavailable: 200, never a 5xx (FR-015)
    // -----------------------------------------------------------------

    #[Test]
    public function a_sandbox_unavailable_executor_result_is_reported_as_a_200_never_a_5xx(): void
    {
        $project = $this->registerProject($this->projectDir);

        $this->bindFakeExecutor([
            'status' => 'sandbox_unavailable',
            'reason' => 'Docker is not reachable on this host',
            'exit_code' => null,
            'stdout' => null,
            'stderr' => null,
            'duration_ms' => null,
        ]);

        $response = $this->postJson($this->apiUrl("coding-project/{$project->id}/run-command"), [
            'command' => 'npm test',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'sandbox_unavailable',
            'command' => 'npm test',
        ]);
        $this->assertNotEmpty($response->json('reason'));
    }

    // -----------------------------------------------------------------
    // Validation (422)
    // -----------------------------------------------------------------

    #[Test]
    public function a_missing_command_is_rejected_with_422(): void
    {
        $project = $this->registerProject($this->projectDir);

        $response = $this->postJson($this->apiUrl("coding-project/{$project->id}/run-command"), []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['command']);
    }

    #[Test]
    public function an_empty_command_is_rejected_with_422(): void
    {
        $project = $this->registerProject($this->projectDir);

        $response = $this->postJson($this->apiUrl("coding-project/{$project->id}/run-command"), [
            'command' => '',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['command']);
    }

    // -----------------------------------------------------------------
    // Ownership (404)
    // -----------------------------------------------------------------

    #[Test]
    public function an_absent_project_id_returns_404_with_the_exact_error_shape(): void
    {
        $response = $this->postJson($this->apiUrl('coding-project/'.Str::uuid().'/run-command'), [
            'command' => 'echo hello',
        ]);

        $response->assertStatus(404);
        $response->assertJson(['error' => 'Coding project not found', 'code' => 'coding_project_not_found']);
    }

    #[Test]
    public function a_foreign_owned_project_returns_404(): void
    {
        $foreignProject = $this->registerProject($this->projectDir, $this->otherUser);

        $response = $this->postJson($this->apiUrl("coding-project/{$foreignProject->id}/run-command"), [
            'command' => 'echo hello',
        ]);

        $response->assertStatus(404);
        $response->assertJson(['error' => 'Coding project not found', 'code' => 'coding_project_not_found']);
    }

    // -----------------------------------------------------------------
    // Workspace root unreachable (403, dedicated shape, Grounding note 6)
    // -----------------------------------------------------------------

    #[Test]
    public function a_project_whose_root_directory_has_vanished_is_refused_with_the_dedicated_403_shape_and_a_refusal_row(): void
    {
        $goneDir = sys_get_temp_dir().'/coding-agent-run-command-gone-'.Str::random(12);
        mkdir($goneDir, 0777, true);

        $project = $this->registerProject($goneDir);

        rmdir($goneDir);
        $this->assertFalse(is_dir($goneDir), 'fixture sanity: the directory must genuinely be gone before the request is made');

        $response = $this->postJson($this->apiUrl("coding-project/{$project->id}/run-command"), [
            'command' => 'echo hello',
        ]);

        $response->assertStatus(403);
        $response->assertExactJson([
            'error' => 'outside the registered project',
            'code' => 'workspace_boundary_refusal',
        ]);

        $refusal = CodingWorkspaceRefusal::where('coding_project_id', $project->id)->first();
        $this->assertNotNull($refusal, 'a durable refusal row must be written for the vanished-workspace case, not merely the response shape');
        $this->assertSame('run_command', $refusal->operation);
    }

    // -----------------------------------------------------------------
    // T013: the new internal HTTP call site (McpToolExecutor::
    // executeHttpCall(), as AgentLoopService dispatches it for the
    // runCommand operationId) must carry an explicit timeout -- research.md
    // D2's exact gap runTests()'s own call site still has, which this new
    // call site must not repeat. A seam-based assertion on the timeout
    // value actually passed to the underlying PendingRequest, never a real
    // 45-second sleep: the Http facade itself is swapped for a Mockery
    // double whose ->timeout() call is asserted directly.
    // -----------------------------------------------------------------

    #[Test]
    public function the_internal_http_call_site_for_run_command_carries_an_explicit_timeout_sized_from_config(): void
    {
        config(['llm-client.coding_agent.command_timeout_seconds' => 45]);

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('failed')->andReturn(false);
        $response->shouldReceive('body')->andReturn(json_encode(['status' => 'completed']));
        $response->shouldReceive('status')->andReturn(200);

        $pendingRequest = Mockery::mock(PendingRequest::class);
        $pendingRequest->shouldReceive('withoutVerifying')->once()->andReturnSelf();
        // The load-bearing assertion: ->timeout() must be called with
        // exactly the configured command_timeout_seconds value -- proving
        // the explicit timeout is genuinely wired into this call site,
        // not merely that the call eventually returns.
        $pendingRequest->shouldReceive('timeout')->once()->with(45)->andReturnSelf();
        $pendingRequest->shouldReceive('post')->once()->andReturn($response);

        Http::shouldReceive('withHeaders')->once()->andReturn($pendingRequest);

        $session = McpSession::create([
            'user_id' => $this->user->id,
            'protocol_version' => '2025-03-26',
        ]);

        $executor = new McpToolExecutor(
            app(McpToolRegistry::class),
            null,
            fn ($user) => 'test-token',
        );

        $result = $executor->executeHttpCall(
            'POST',
            '/coding-project/proj-1/run-command',
            [],
            ['command' => 'phpunit'],
            $session,
            [],
            45,
        );

        $this->assertSame(200, $result['status']);
    }

    #[Test]
    public function an_operation_with_no_explicit_timeout_leaves_the_http_client_at_its_default(): void
    {
        $response = Mockery::mock(Response::class);
        $response->shouldReceive('failed')->andReturn(false);
        $response->shouldReceive('body')->andReturn(json_encode(['status' => 'ok']));
        $response->shouldReceive('status')->andReturn(200);

        $pendingRequest = Mockery::mock(PendingRequest::class);
        $pendingRequest->shouldReceive('withoutVerifying')->once()->andReturnSelf();
        // No timeout() call is ever expected here -- every existing call
        // site's behavior must remain completely unchanged.
        $pendingRequest->shouldNotReceive('timeout');
        $pendingRequest->shouldReceive('get')->once()->andReturn($response);

        Http::shouldReceive('withHeaders')->once()->andReturn($pendingRequest);

        $session = McpSession::create([
            'user_id' => $this->user->id,
            'protocol_version' => '2025-03-26',
        ]);

        $executor = new McpToolExecutor(
            app(McpToolRegistry::class),
            null,
            fn ($user) => 'test-token',
        );

        $result = $executor->executeHttpCall('GET', '/coding-project/proj-1/files', [], [], $session);

        $this->assertSame(200, $result['status']);
    }
}
