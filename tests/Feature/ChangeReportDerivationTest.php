<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\CodingProject;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\RunTraceQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * 112-coding-agent, US1 (P1), T023 (D6, data-model.md §6, FR-005,
 * quickstart row 2).
 *
 * Two derivations, tried in order, never merged into a single ambiguous
 * value (data-model.md §6): a git-backed project's report is checked
 * against real `git status --porcelain=v1`/`git diff` output for a real
 * temp git repository (never mocked — D8), and a non-git project's report
 * falls back to the run trace's own recorded writeFile/deleteFile
 * AgentRunAction rows for that run, ownership-checked via
 * RunTraceQuery::changedFilesFromRunTrace() (delegates to
 * actionsForRun()'s existing agent_runs.user_id comparison). A declined
 * confirmation never reaches executeApiCall(), so it is never recorded
 * with a decodable content payload and never appears in either report.
 */
class ChangeReportDerivationTest extends TestCase
{
    private User $user;

    /** @var list<string> temp directories created by this test, removed in tearDown */
    private array $tmpDirs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            if (is_dir($dir)) {
                $this->removeDirectory($dir);
            }
        }

        DB::table('agent_run_actions')->delete();
        DB::table('agent_run_steps')->delete();
        DB::table('agent_runs')->delete();
        DB::table('coding_projects')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Git-backed derivation
    // ---------------------------------------------------------------

    #[Test]
    public function a_git_backed_projects_change_report_matches_real_git_status_and_diff(): void
    {
        $dir = $this->makeTempDir();

        $this->runGit($dir, ['init']);
        $this->runGit($dir, ['config', 'user.email', 'test@example.com']);
        $this->runGit($dir, ['config', 'user.name', 'Test']);

        file_put_contents($dir.'/existing.txt', "line one\n");
        $this->runGit($dir, ['add', '.']);
        $this->runGit($dir, ['commit', '-m', 'initial']);

        // Real, independent working-tree changes — a modification and a
        // brand new untracked file.
        file_put_contents($dir.'/existing.txt', "line one\nline two\n");
        file_put_contents($dir.'/new-file.txt', "brand new\n");

        $project = CodingProject::create([
            'user_id' => $this->user->id,
            'name' => 'git project',
            'root_path' => $dir,
            'test_command' => null,
        ]);

        // The independently-obtained real signal this test checks the
        // controller's output against.
        $expectedStatus = $this->shellGit($dir, ['status', '--porcelain=v1']);
        $expectedDiff = $this->shellGit($dir, ['diff']);

        $this->assertStringContainsString('new-file.txt', $expectedStatus, 'sanity: the real git status must show the new untracked file');
        $this->assertStringContainsString('line two', $expectedDiff, 'sanity: the real git diff must show the modified line');

        $statusResponse = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/coding-project/{$project->id}/git-status");
        $statusResponse->assertStatus(200);
        $statusResponse->assertJson(['is_git_repo' => true]);
        $this->assertSame($expectedStatus, $statusResponse->json('porcelain'));

        $diffResponse = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/coding-project/{$project->id}/git-diff");
        $diffResponse->assertStatus(200);
        $diffResponse->assertJson(['is_git_repo' => true]);
        $this->assertSame($expectedDiff, $diffResponse->json('diff'));
    }

    #[Test]
    public function a_non_git_directory_reports_is_git_repo_false_not_an_error(): void
    {
        $dir = $this->makeTempDir();

        $project = CodingProject::create([
            'user_id' => $this->user->id,
            'name' => 'plain directory',
            'root_path' => $dir,
            'test_command' => null,
        ]);

        $statusResponse = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/coding-project/{$project->id}/git-status");
        $statusResponse->assertStatus(200);
        $statusResponse->assertExactJson(['is_git_repo' => false]);

        $diffResponse = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/coding-project/{$project->id}/git-diff");
        $diffResponse->assertStatus(200);
        $diffResponse->assertExactJson(['is_git_repo' => false]);
    }

    // ---------------------------------------------------------------
    // Run-trace fallback derivation (non-git)
    // ---------------------------------------------------------------

    #[Test]
    public function a_non_git_projects_change_report_falls_back_to_the_run_trace_most_recent_wins(): void
    {
        $runId = $this->insertRun($this->user->id);
        $stepId = $this->insertStep($runId);

        // Two distinct files, confirmed-and-executed.
        $this->insertCodingWorkspaceAction($runId, $stepId, AgentLoopService::CODING_WORKSPACE_WRITE_FILE_OPERATION_ID, 'src/A.php', '2026-01-01 10:00:00');
        $this->insertCodingWorkspaceAction($runId, $stepId, AgentLoopService::CODING_WORKSPACE_WRITE_FILE_OPERATION_ID, 'src/B.php', '2026-01-01 10:01:00');
        // A later delete of the SAME path as the first write -- the final
        // report must reflect the LAST operation on that path, never both.
        $this->insertCodingWorkspaceAction($runId, $stepId, AgentLoopService::CODING_WORKSPACE_DELETE_FILE_OPERATION_ID, 'src/A.php', '2026-01-01 10:02:00');

        $report = (new RunTraceQuery())->changedFilesFromRunTrace($this->user->id, $runId);

        $this->assertCount(2, $report, 'distinct by path -- the repeated src/A.php path must collapse to one entry');

        $byPath = collect($report)->keyBy('path');
        $this->assertSame('deleteFile', $byPath['src/A.php']['operation'], 'most-recent-wins: the later delete must override the earlier write');
        $this->assertSame('writeFile', $byPath['src/B.php']['operation']);
    }

    #[Test]
    public function the_run_trace_fallback_is_ownership_checked(): void
    {
        $otherUser = User::factory()->create();

        $runId = $this->insertRun($this->user->id);
        $stepId = $this->insertStep($runId);
        $this->insertCodingWorkspaceAction($runId, $stepId, AgentLoopService::CODING_WORKSPACE_WRITE_FILE_OPERATION_ID, 'src/A.php', '2026-01-01 10:00:00');

        $query = new RunTraceQuery();

        $this->assertCount(1, $query->changedFilesFromRunTrace($this->user->id, $runId));
        $this->assertSame([], $query->changedFilesFromRunTrace($otherUser->id, $runId), 'a run id alone must never grant access to another user\'s change report');
    }

    #[Test]
    public function a_declined_confirmation_never_appears_in_the_run_trace_report(): void
    {
        $runId = $this->insertRun($this->user->id);
        $stepId = $this->insertStep($runId);

        // A declined confirmation never reaches executeApiCall(), so
        // AgentLoopService::resume()/resumeSync() close the paused action
        // as Failure/'User declined' with no content at all -- mirrored
        // directly here rather than a successful write.
        DB::table('agent_run_actions')->insert([
            'id' => (string) Str::uuid(),
            'run_id' => $runId,
            'step_id' => $stepId,
            'action_type' => 'tool_invocation',
            'target' => 'execute_operation',
            'outcome' => 'failure',
            'failure_reason' => 'User declined',
            'content' => null,
            'started_at' => '2026-01-01 10:00:00',
        ]);

        $report = (new RunTraceQuery())->changedFilesFromRunTrace($this->user->id, $runId);

        $this->assertSame([], $report, 'a declined confirmation must never appear in the change report');
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir().'/coding-agent-change-report-'.Str::random(12);
        mkdir($dir, 0777, true);
        $this->tmpDirs[] = $dir;

        return $dir;
    }

    private function runGit(string $dir, array $args): void
    {
        (new Process(array_merge(['git'], $args), $dir))->run();
    }

    private function shellGit(string $dir, array $args): string
    {
        $process = new Process(array_merge(['git'], $args), $dir);
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

    private function insertRun(string $userId): string
    {
        $runId = (string) Str::uuid();
        DB::table('agent_runs')->insert([
            'id' => $runId,
            'kind' => 'interactive',
            'user_id' => $userId,
            'conversation_id' => null,
            'source' => null,
            'end_state' => 'in_progress',
            'end_reason' => null,
            'started_at' => now()->toDateTimeString(),
            'ended_at' => null,
            'duration_ms' => null,
            'step_count' => 0,
            'created_at' => now()->toDateTimeString(),
        ]);

        return $runId;
    }

    private function insertStep(string $runId): string
    {
        $stepId = (string) Str::uuid();
        DB::table('agent_run_steps')->insert([
            'id' => $stepId,
            'run_id' => $runId,
            'position' => 1,
            'attempt_group_id' => null,
            'end_state' => 'in_progress',
            'end_reason' => null,
            'started_at' => now()->toDateTimeString(),
            'ended_at' => null,
            'duration_ms' => null,
            'wait_ms' => null,
        ]);

        return $stepId;
    }

    private function insertCodingWorkspaceAction(string $runId, string $stepId, string $operationId, string $path, string $startedAt): void
    {
        DB::table('agent_run_actions')->insert([
            'id' => (string) Str::uuid(),
            'run_id' => $runId,
            'step_id' => $stepId,
            'action_type' => 'tool_invocation',
            'target' => 'execute_operation',
            'outcome' => 'success',
            'content' => json_encode(['operationId' => $operationId, 'path' => $path]),
            'started_at' => $startedAt,
        ]);
    }
}
