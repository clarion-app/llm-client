<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\CodingProject;
use ClarionApp\LlmClient\Models\McpSession;
use ClarionApp\LlmClient\Services\DockerCommandExecutor;
use ClarionApp\LlmClient\Services\LanguageRuntime;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 125-language-runtime-execution, US2, T021 (contracts/language-availability.md
 * §1, research.md D4). Drives the real, registered
 * `GET coding-project/{project}/languages` HTTP route through the real
 * CodingWorkspaceController, with DockerCommandExecutor swapped for a
 * Mockery double bound into the container -- mirroring
 * RunCodeJourneyTest.php's own shape exactly. This file never touches a
 * real `docker` binary or daemon. Genuine per-image probe behavior is
 * proven separately by tests/RealDocker/LanguageAvailabilityProbeTest.php
 * (T022).
 *
 * Written before CodingWorkspaceController::languages() (and the
 * `languages` route) exist -- expected to FAIL red (route-not-found)
 * until T023-T024 land.
 */
class LanguageAvailabilityJourneyTest extends TestCase
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

        $this->projectDir = sys_get_temp_dir().'/coding-agent-language-availability-'.Str::random(12);
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
        if (Schema::hasTable('coding_workspace_changes')) {
            DB::table('coding_workspace_changes')->delete();
        }
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
            'name' => 'language-availability project',
            'root_path' => $rootPath,
            'test_command' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function bindFakeExecutor(array $result): \Mockery\MockInterface
    {
        $fake = Mockery::mock(DockerCommandExecutor::class);
        $fake->shouldReceive('run')->andReturn($result);
        $this->app->instance(DockerCommandExecutor::class, $fake);

        return $fake;
    }

    private function languages(?string $projectId = null): \Illuminate\Testing\TestResponse
    {
        $id = $projectId ?? $this->registerProject($this->projectDir)->id;

        return $this->getJson($this->apiUrl("coding-project/{$id}/languages"));
    }

    // -----------------------------------------------------------------
    // (1) A mocked executor stdout of "python:available\njavascript:unavailable"
    // -> 200, {languages: [{name: python, available: true}, {name:
    // javascript, available: false}]} (contracts/language-availability.md §1).
    // -----------------------------------------------------------------

    #[Test]
    public function the_probes_parsed_output_is_returned_as_per_language_availability(): void
    {
        $project = $this->registerProject($this->projectDir);

        $this->bindFakeExecutor([
            'status' => 'completed',
            'exit_code' => 0,
            'timed_out' => false,
            'stdout' => "python:available\njavascript:unavailable",
            'stderr' => '',
            'output_truncated' => false,
            'duration_ms' => 120,
        ]);

        $response = $this->getJson($this->apiUrl("coding-project/{$project->id}/languages"));

        $response->assertStatus(200);
        $response->assertExactJson([
            'languages' => [
                ['name' => 'python', 'available' => true],
                ['name' => 'javascript', 'available' => false],
            ],
        ]);
    }

    // -----------------------------------------------------------------
    // (2) A mocked sandbox_unavailable executor result -> propagated as
    // {status: sandbox_unavailable, reason: ...}, the SAME shape
    // runCommand/runCode already use for this condition.
    // -----------------------------------------------------------------

    #[Test]
    public function a_sandbox_unavailable_executor_result_is_propagated_in_the_same_shape_run_code_uses(): void
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

        $response = $this->getJson($this->apiUrl("coding-project/{$project->id}/languages"));

        $response->assertStatus(200);
        $response->assertJson(['status' => 'sandbox_unavailable']);
        $this->assertNotEmpty($response->json('reason'));
        $this->assertNull($response->json('languages'), 'a sandbox_unavailable result must not also carry a languages key');
    }

    // -----------------------------------------------------------------
    // (3) 404 for an absent/foreign-owned project id.
    // -----------------------------------------------------------------

    #[Test]
    public function an_absent_project_id_returns_404_with_the_exact_error_shape(): void
    {
        $response = $this->getJson($this->apiUrl('coding-project/'.Str::uuid().'/languages'));

        $response->assertStatus(404);
        $response->assertJson(['error' => 'Coding project not found', 'code' => 'coding_project_not_found']);
    }

    #[Test]
    public function a_foreign_owned_project_returns_404(): void
    {
        $foreignProject = $this->registerProject($this->projectDir, $this->otherUser);

        $response = $this->getJson($this->apiUrl("coding-project/{$foreignProject->id}/languages"));

        $response->assertStatus(404);
        $response->assertJson(['error' => 'Coding project not found', 'code' => 'coding_project_not_found']);
    }

    // -----------------------------------------------------------------
    // (4) No CodingCommandExecution row is written by this endpoint under
    // any outcome -- unaudited, matching listFiles/gitStatus's own
    // existing precedent (data-model.md §3).
    // -----------------------------------------------------------------

    #[Test]
    public function no_coding_command_execution_row_is_written_regardless_of_outcome(): void
    {
        $availableProject = $this->registerProject($this->projectDir);
        $this->bindFakeExecutor([
            'status' => 'completed',
            'exit_code' => 0,
            'timed_out' => false,
            'stdout' => "python:available\njavascript:available",
            'stderr' => '',
            'output_truncated' => false,
            'duration_ms' => 90,
        ]);
        $this->getJson($this->apiUrl("coding-project/{$availableProject->id}/languages"))
            ->assertStatus(200);

        $this->assertSame(
            0,
            DB::table('coding_command_executions')->where('coding_project_id', $availableProject->id)->count(),
            'a successful languages probe must never write a CodingCommandExecution row'
        );

        Mockery::close();
        $unavailableDir = sys_get_temp_dir().'/coding-agent-language-availability-'.Str::random(12);
        mkdir($unavailableDir, 0777, true);
        $unavailableProject = $this->registerProject($unavailableDir);
        $this->bindFakeExecutor([
            'status' => 'sandbox_unavailable',
            'reason' => 'Docker is not reachable on this host',
            'exit_code' => null,
            'stdout' => null,
            'stderr' => null,
            'duration_ms' => null,
        ]);
        $this->getJson($this->apiUrl("coding-project/{$unavailableProject->id}/languages"))
            ->assertStatus(200);

        $this->assertSame(
            0,
            DB::table('coding_command_executions')->where('coding_project_id', $unavailableProject->id)->count(),
            'a sandbox_unavailable languages probe must never write a CodingCommandExecution row either'
        );

        $this->assertSame(
            0,
            DB::table('coding_command_executions')->count(),
            'no CodingCommandExecution row of any kind must exist after either languages() call'
        );

        $this->removeDirectory($unavailableDir);
    }

    // -----------------------------------------------------------------
    // (5) The mocked DockerCommandExecutor::run() call receives
    // LanguageRuntime::buildAvailabilityProbeCommand()'s exact output as
    // its command argument, with no $stdin passed (research.md D4).
    // -----------------------------------------------------------------

    #[Test]
    public function the_executor_is_invoked_with_the_exact_availability_probe_command_and_no_stdin(): void
    {
        $project = $this->registerProject($this->projectDir);

        $capturedCommand = null;
        $stdinSentinel = new \stdClass();
        $capturedStdin = $stdinSentinel;

        $fake = Mockery::mock(DockerCommandExecutor::class);
        $fake->shouldReceive('run')
            ->once()
            ->andReturnUsing(function (
                string $rootPath,
                string $command,
                ?string $codingProjectId = null,
                ?string $userId = null,
                bool $networkEnabled = false,
                ?int $timeLimitSeconds = null,
                ?int $memoryLimitMb = null,
                ?string $cpuLimit = null,
                ?int $pidsLimit = null,
                ?int $outputCapBytes = null,
                ?int $diskLimitMb = null,
                ?string $stdin = null,
            ) use (&$capturedCommand, &$capturedStdin) {
                $capturedCommand = $command;
                $capturedStdin = $stdin;

                return [
                    'status' => 'completed',
                    'exit_code' => 0,
                    'timed_out' => false,
                    'stdout' => "python:available\njavascript:available",
                    'stderr' => '',
                    'output_truncated' => false,
                    'duration_ms' => 90,
                ];
            });
        $this->app->instance(DockerCommandExecutor::class, $fake);

        $response = $this->getJson($this->apiUrl("coding-project/{$project->id}/languages"));

        $response->assertStatus(200);

        $expectedCommand = (new LanguageRuntime())->buildAvailabilityProbeCommand();
        $this->assertNotNull($capturedCommand, 'DockerCommandExecutor::run() must have been called');
        $this->assertSame($expectedCommand, $capturedCommand, "the exact LanguageRuntime::buildAvailabilityProbeCommand() output must be passed as the command argument");
        $this->assertNull($capturedStdin, 'no $stdin should be passed for an availability probe');
    }
}
