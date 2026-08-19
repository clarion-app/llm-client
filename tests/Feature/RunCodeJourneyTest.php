<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\CodingProject;
use ClarionApp\LlmClient\Models\CodingWorkspaceRefusal;
use ClarionApp\LlmClient\Models\McpSession;
use ClarionApp\LlmClient\Services\DockerCommandExecutor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 125-language-runtime-execution, US1, T009 (contracts/run-code.md §1).
 * Drives the real, registered `POST coding-project/{project}/run-code`
 * HTTP route through the real CodingWorkspaceController, with
 * DockerCommandExecutor swapped for a Mockery double bound into the
 * container -- mirroring RunCommandJourneyTest.php's own shape exactly.
 * This file never touches a real `docker` binary or daemon. Genuine
 * container/language behavior is proven separately by
 * tests/RealDocker/LanguageExecutionTest.php (T011).
 *
 * Written before CodingWorkspaceController::runCode() (and the
 * `run-code` route) exist -- expected to FAIL red (route-not-found or
 * missing-method shaped) until T012-T016 land.
 */
class RunCodeJourneyTest extends TestCase
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

        $this->projectDir = sys_get_temp_dir().'/coding-agent-run-code-'.Str::random(12);
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
            'name' => 'run-code project',
            'root_path' => $rootPath,
            'test_command' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function bindFakeExecutor(array $result, ?\Closure $duringRun = null): \Mockery\MockInterface
    {
        $fake = Mockery::mock(DockerCommandExecutor::class);
        if ($duringRun !== null) {
            $fake->shouldReceive('run')->andReturnUsing(function () use ($result, $duringRun) {
                $duringRun();

                return $result;
            });
        } else {
            $fake->shouldReceive('run')->andReturn($result);
        }
        $this->app->instance(DockerCommandExecutor::class, $fake);

        return $fake;
    }

    private function runCode(string $language, string $code, ?string $projectId = null): \Illuminate\Testing\TestResponse
    {
        $id = $projectId ?? $this->registerProject($this->projectDir)->id;

        return $this->postJson($this->apiUrl("coding-project/{$id}/run-code"), [
            'language' => $language,
            'code' => $code,
        ]);
    }

    // -----------------------------------------------------------------
    // (1) A completed result -- exact response shape (contracts/run-code.md
    // §1), stdout/stderr distinct, never conflated.
    // -----------------------------------------------------------------

    #[Test]
    public function a_completed_result_returns_the_exact_response_shape_the_contract_documents(): void
    {
        $project = $this->registerProject($this->projectDir);

        $this->bindFakeExecutor([
            'status' => 'completed',
            'exit_code' => 0,
            'timed_out' => false,
            'stdout' => "hello\n",
            'stderr' => "a warning\n",
            'output_truncated' => false,
            'duration_ms' => 812,
        ]);

        $response = $this->postJson($this->apiUrl("coding-project/{$project->id}/run-code"), [
            'language' => 'python',
            'code' => "print('hello')",
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'completed',
            'language' => 'python',
            'code' => "print('hello')",
            'exit_code' => 0,
            'timed_out' => false,
            'stdout' => "hello\n",
            'stderr' => "a warning\n",
            'output_truncated' => false,
            'network_enabled' => false,
            'duration_ms' => 812,
        ]);
        $response->assertJsonStructure([
            'status', 'language', 'code', 'exit_code', 'timed_out', 'stdout', 'stderr',
            'output_truncated', 'network_enabled', 'duration_ms',
        ]);
        $this->assertNotSame(
            $response->json('stdout'),
            $response->json('stderr'),
            'stdout and stderr must be returned distinctly, never conflated (FR-002)'
        );
    }

    // -----------------------------------------------------------------
    // (2) Missing/empty language or code -> 422, plain Laravel validation.
    // -----------------------------------------------------------------

    #[Test]
    public function a_missing_language_and_code_are_rejected_with_422(): void
    {
        $project = $this->registerProject($this->projectDir);

        $response = $this->postJson($this->apiUrl("coding-project/{$project->id}/run-code"), []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['language', 'code']);
    }

    #[Test]
    public function an_empty_language_is_rejected_with_422(): void
    {
        $project = $this->registerProject($this->projectDir);

        $response = $this->postJson($this->apiUrl("coding-project/{$project->id}/run-code"), [
            'language' => '',
            'code' => "print('hi')",
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['language']);
    }

    #[Test]
    public function an_empty_code_is_rejected_with_422(): void
    {
        $project = $this->registerProject($this->projectDir);

        $response = $this->postJson($this->apiUrl("coding-project/{$project->id}/run-code"), [
            'language' => 'python',
            'code' => '',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['code']);
    }

    // -----------------------------------------------------------------
    // (3) An unrecognized language name -> 422, dedicated shape, executor
    // never called, no audit row (FR-011, data-model.md §2).
    // -----------------------------------------------------------------

    #[Test]
    public function an_unrecognized_language_name_is_refused_with_422_and_the_executor_is_never_called(): void
    {
        $project = $this->registerProject($this->projectDir);

        $fake = Mockery::mock(DockerCommandExecutor::class);
        $fake->shouldReceive('run')->never();
        $this->app->instance(DockerCommandExecutor::class, $fake);

        $response = $this->postJson($this->apiUrl("coding-project/{$project->id}/run-code"), [
            'language' => 'ruby',
            'code' => "puts 'hi'",
        ]);

        $response->assertStatus(422);
        $response->assertExactJson([
            'error' => "unrecognized language 'ruby'",
            'code' => 'language_unrecognized',
            'language' => 'ruby',
        ]);

        $this->assertSame(
            0,
            DB::table('coding_command_executions')->where('coding_project_id', $project->id)->count(),
            'an unrecognized language name must never write a CodingCommandExecution row'
        );
    }

    // -----------------------------------------------------------------
    // (4) A language-unavailable raw executor result is translated by the
    // controller (Grounding note 8) and audited (quickstart row 9).
    // -----------------------------------------------------------------

    #[Test]
    public function a_language_unavailable_raw_executor_result_is_translated_and_audited(): void
    {
        $project = $this->registerProject($this->projectDir);

        $this->bindFakeExecutor([
            'status' => 'completed',
            'exit_code' => 127,
            'timed_out' => false,
            'stdout' => '',
            'stderr' => "__CLARION_LANGUAGE_UNAVAILABLE__\n",
            'output_truncated' => false,
            'duration_ms' => 340,
        ]);

        $response = $this->postJson($this->apiUrl("coding-project/{$project->id}/run-code"), [
            'language' => 'python',
            'code' => "print('hi')",
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'language_unavailable',
            'exit_code' => null,
            'stdout' => null,
            'stderr' => null,
            'reason' => "python is not available in this workspace's configured sandbox image",
        ]);

        $row = DB::table('coding_command_executions')->where('coding_project_id', $project->id)->first();
        $this->assertNotNull($row, 'a CodingCommandExecution row must be written for a language_unavailable outcome');
        $this->assertSame('language_unavailable', $row->status);
        $this->assertSame('python', $row->language);
        $this->assertSame("print('hi')", $row->command);
    }

    // -----------------------------------------------------------------
    // (5) sandbox_unavailable passed through as 200, never a 5xx (FR-015).
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

        $response = $this->postJson($this->apiUrl("coding-project/{$project->id}/run-code"), [
            'language' => 'python',
            'code' => "print('hi')",
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'sandbox_unavailable']);
        $this->assertNotEmpty($response->json('reason'));
    }

    // -----------------------------------------------------------------
    // (6) 404 for an absent/foreign-owned project id.
    // -----------------------------------------------------------------

    #[Test]
    public function an_absent_project_id_returns_404_with_the_exact_error_shape(): void
    {
        $response = $this->postJson($this->apiUrl('coding-project/'.Str::uuid().'/run-code'), [
            'language' => 'python',
            'code' => "print('hi')",
        ]);

        $response->assertStatus(404);
        $response->assertJson(['error' => 'Coding project not found', 'code' => 'coding_project_not_found']);
    }

    #[Test]
    public function a_foreign_owned_project_returns_404(): void
    {
        $foreignProject = $this->registerProject($this->projectDir, $this->otherUser);

        $response = $this->postJson($this->apiUrl("coding-project/{$foreignProject->id}/run-code"), [
            'language' => 'python',
            'code' => "print('hi')",
        ]);

        $response->assertStatus(404);
        $response->assertJson(['error' => 'Coding project not found', 'code' => 'coding_project_not_found']);
    }

    // -----------------------------------------------------------------
    // (7) A vanished workspace root -> dedicated 403 shape + refusal row,
    // operation: 'run_code' (distinct from runCommand's 'run_command').
    // -----------------------------------------------------------------

    #[Test]
    public function a_project_whose_root_directory_has_vanished_is_refused_with_the_dedicated_403_shape_and_a_run_code_refusal_row(): void
    {
        $goneDir = sys_get_temp_dir().'/coding-agent-run-code-gone-'.Str::random(12);
        mkdir($goneDir, 0777, true);

        $project = $this->registerProject($goneDir);

        rmdir($goneDir);
        $this->assertFalse(is_dir($goneDir), 'fixture sanity: the directory must genuinely be gone before the request is made');

        $response = $this->postJson($this->apiUrl("coding-project/{$project->id}/run-code"), [
            'language' => 'python',
            'code' => "print('hi')",
        ]);

        $response->assertStatus(403);
        $response->assertExactJson([
            'error' => 'outside the registered project',
            'code' => 'workspace_boundary_refusal',
        ]);

        $refusal = CodingWorkspaceRefusal::where('coding_project_id', $project->id)->first();
        $this->assertNotNull($refusal, 'a durable refusal row must be written for the vanished-workspace case, not merely the response shape');
        $this->assertSame('run_code', $refusal->operation);
    }

    // -----------------------------------------------------------------
    // (8) A completed run persists exactly one CodingCommandExecution row
    // with language set and command holding the submitted code.
    // -----------------------------------------------------------------

    #[Test]
    public function a_completed_run_persists_exactly_one_coding_command_execution_row_with_language_and_code(): void
    {
        $project = $this->registerProject($this->projectDir);

        $this->bindFakeExecutor([
            'status' => 'completed',
            'exit_code' => 0,
            'timed_out' => false,
            'stdout' => "hi\n",
            'stderr' => '',
            'output_truncated' => false,
            'duration_ms' => 5,
        ]);

        $this->postJson($this->apiUrl("coding-project/{$project->id}/run-code"), [
            'language' => 'javascript',
            'code' => "console.log('hi')",
        ])->assertStatus(200);

        $this->assertSame(1, DB::table('coding_command_executions')->where('coding_project_id', $project->id)->count());
        $row = DB::table('coding_command_executions')->where('coding_project_id', $project->id)->first();
        $this->assertSame('completed', $row->status);
        $this->assertSame('javascript', $row->language, 'the language column must hold the submitted language, not the code');
        $this->assertSame("console.log('hi')", $row->command, 'the command column must hold the submitted code text, not null and not the language name');
        $this->assertSame($this->user->id, $row->user_id);
    }

    // -----------------------------------------------------------------
    // (9) Oversized output reported by the (mocked) executor is passed
    // through as output_truncated: true, matching runCommand's own
    // existing handling, unchanged.
    // -----------------------------------------------------------------

    #[Test]
    public function oversized_output_reported_by_the_executor_is_passed_through_as_truncated_and_bounded_to_the_cap(): void
    {
        $project = $this->registerProject($this->projectDir);

        $cap = (int) config('llm-client.coding_agent.command_output_cap_bytes', 262144);
        $cappedStdout = str_repeat('x', $cap);

        $this->bindFakeExecutor([
            'status' => 'completed',
            'exit_code' => 0,
            'timed_out' => false,
            'stdout' => $cappedStdout,
            'stderr' => '',
            'output_truncated' => true,
            'duration_ms' => 500,
        ]);

        $response = $this->postJson($this->apiUrl("coding-project/{$project->id}/run-code"), [
            'language' => 'python',
            'code' => "print('x' * 999999999)",
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'completed',
            'output_truncated' => true,
        ]);
        $this->assertSame($cap, strlen((string) $response->json('stdout')), 'the reported stdout must be bounded to the configured cap');
        $this->assertLessThan(999999999, strlen((string) $response->json('stdout')), 'the reported stdout must never be the full oversized content');
    }

    // -----------------------------------------------------------------
    // (10) Change detection: a mocked executor result whose fixture writes
    // a file to the workspace root before returning -> that file's
    // `created` row appears in the workspace's existing change-history
    // endpoint, proving CommandChangeDetector/WorkspaceChangeRecorder are
    // genuinely bracketed around runCode()'s executor call, exactly as
    // runCommand()'s own (Grounding note 5).
    // -----------------------------------------------------------------

    #[Test]
    public function a_file_written_during_execution_appears_as_a_created_row_in_the_change_history_endpoint(): void
    {
        $project = $this->registerProject($this->projectDir);

        $this->bindFakeExecutor(
            [
                'status' => 'completed',
                'exit_code' => 0,
                'timed_out' => false,
                'stdout' => '',
                'stderr' => '',
                'output_truncated' => false,
                'duration_ms' => 5,
            ],
            function () {
                file_put_contents($this->projectDir.'/new-file-from-code.txt', 'written by the snippet');
            }
        );

        $this->postJson($this->apiUrl("coding-project/{$project->id}/run-code"), [
            'language' => 'python',
            'code' => "open('new-file-from-code.txt', 'w').write('written by the snippet')",
        ])->assertStatus(200);

        $changesResponse = $this->getJson($this->apiUrl("coding-project/{$project->id}/changes"));
        $changesResponse->assertStatus(200);

        $rows = $changesResponse->json('data');
        $this->assertCount(1, $rows, 'the change-history endpoint must show exactly one change from this run-code call');
        $this->assertSame('new-file-from-code.txt', $rows[0]['path']);
        $this->assertSame('created', $rows[0]['operation']);
    }
}
