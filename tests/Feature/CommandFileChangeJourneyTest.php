<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\CodingProject;
use ClarionApp\LlmClient\Services\DockerCommandExecutor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 124-command-limit-controls, US4, T035 (contracts/command-file-changes.md,
 * FR-010/FR-011, Acceptance Scenarios 1-2).
 *
 * DockerCommandExecutor is swapped for a single Mockery double bound into
 * the container once per test (mirroring RunCommandJourneyTest.php's own
 * fake-executor style) -- no real Docker required. The double's `run()`
 * consumes a queue of {result, duringRun} pairs pushed via
 * queueExecutorResult() -- a test that makes several sequential
 * `run-command` calls queues one entry per call, consumed in order.
 *
 * Deliberately ONE mock per test, bound once, rather than rebinding a
 * fresh mock before each request: Laravel's Route::getController() caches
 * the resolved controller instance (and therefore its constructor-injected
 * DockerCommandExecutor) on the Route object itself the first time it is
 * dispatched, and that cache persists across every subsequent request
 * within the same test method -- a second `$this->app->instance()` call
 * later in the same test has no effect on a route already dispatched once.
 * A queued single mock sidesteps this entirely.
 *
 * Each `duringRun` closure performs a real filesystem write/delete
 * synchronously when `run()` is invoked, standing in for "the command
 * ran": since CodingWorkspaceController::runCommand() snapshots the
 * workspace immediately before and immediately after this call, a
 * filesystem mutation performed from inside the closure is genuinely
 * bracketed by those two snapshots, exactly as a real command's own writes
 * would be.
 */
class CommandFileChangeJourneyTest extends TestCase
{
    private User $user;

    private CodingProject $project;

    private string $projectDir;

    /** @var list<array{0: array<string, mixed>, 1: ?\Closure}> */
    private array $executorQueue = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user, 'api');

        $this->projectDir = sys_get_temp_dir().'/coding-agent-command-file-change-'.Str::random(12);
        mkdir($this->projectDir, 0777, true);

        $this->project = CodingProject::create([
            'user_id' => $this->user->id,
            'name' => 'command file change project',
            'root_path' => $this->projectDir,
            'test_command' => null,
        ]);

        $fake = Mockery::mock(DockerCommandExecutor::class);
        $fake->shouldReceive('run')->andReturnUsing(function () {
            [$result, $duringRun] = array_shift($this->executorQueue)
                ?? [$this->completedResult(), null];

            if ($duringRun !== null) {
                $duringRun();
            }

            return $result;
        });
        $this->app->instance(DockerCommandExecutor::class, $fake);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        DB::table('coding_workspace_changes')->delete();
        DB::table('coding_command_executions')->delete();
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

    /**
     * @param  array<string, mixed>  $result
     */
    private function queueExecutorResult(array $result, ?\Closure $duringRun = null): void
    {
        $this->executorQueue[] = [$result, $duringRun];
    }

    private function runCommand(string $command = 'irrelevant, executor is mocked'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson($this->apiUrl("coding-project/{$this->project->id}/run-command"), [
            'command' => $command,
        ]);
    }

    private function changeHistory(): array
    {
        $response = $this->getJson($this->apiUrl("coding-project/{$this->project->id}/changes"));
        $response->assertStatus(200);

        return $response->json('data');
    }

    private function completedResult(array $overrides = []): array
    {
        return array_merge([
            'status' => 'completed',
            'exit_code' => 0,
            'timed_out' => false,
            'stdout' => '',
            'stderr' => '',
            'output_truncated' => false,
            'duration_ms' => 5,
        ], $overrides);
    }

    // -----------------------------------------------------------------
    // (1) created/modified/deleted each produce a row on the same terms
    // writeFile()/deleteFile()'s own rows already use (contracts §1-2).
    // -----------------------------------------------------------------

    #[Test]
    public function a_created_file_produces_a_row_matching_the_writeFile_shape_with_old_side_null(): void
    {
        $this->queueExecutorResult($this->completedResult(), function () {
            file_put_contents($this->projectDir.'/new-file.txt', 'brand new content');
        });

        $this->runCommand('touch new-file.txt')->assertStatus(200);

        $rows = $this->changeHistory();
        $this->assertCount(1, $rows);

        $row = $rows[0];
        $this->assertSame('new-file.txt', $row['path']);
        $this->assertSame('created', $row['operation']);
        $this->assertNull($row['old_content'], 'old_content must be null for a command-detected change (research.md R4 §4)');
        $this->assertNull($row['old_size']);
        $this->assertFalse($row['old_content_truncated']);
        $this->assertFalse($row['old_binary']);
        $this->assertSame('brand new content', $row['new_content']);
        $this->assertSame(strlen('brand new content'), $row['new_size']);
        $this->assertFalse($row['new_content_truncated']);
        $this->assertFalse($row['new_binary']);
    }

    #[Test]
    public function a_modified_file_produces_a_row_with_new_content_read_fresh_from_disk(): void
    {
        file_put_contents($this->projectDir.'/existing.txt', 'old content');

        $this->queueExecutorResult($this->completedResult(), function () {
            file_put_contents($this->projectDir.'/existing.txt', 'updated content, deliberately a different length');
        });

        $this->runCommand('echo updated > existing.txt')->assertStatus(200);

        $rows = $this->changeHistory();
        $this->assertCount(1, $rows);

        $row = $rows[0];
        $this->assertSame('existing.txt', $row['path']);
        $this->assertSame('modified', $row['operation']);
        $this->assertNull($row['old_content']);
        $this->assertNull($row['old_size']);
        $this->assertSame('updated content, deliberately a different length', $row['new_content']);
        $this->assertSame(strlen('updated content, deliberately a different length'), $row['new_size']);
    }

    #[Test]
    public function a_deleted_file_produces_a_row_with_both_new_content_and_new_size_null(): void
    {
        file_put_contents($this->projectDir.'/to-remove.txt', 'will be removed');

        $this->queueExecutorResult($this->completedResult(), function () {
            unlink($this->projectDir.'/to-remove.txt');
        });

        $this->runCommand('rm to-remove.txt')->assertStatus(200);

        $rows = $this->changeHistory();
        $this->assertCount(1, $rows);

        $row = $rows[0];
        $this->assertSame('to-remove.txt', $row['path']);
        $this->assertSame('deleted', $row['operation']);
        $this->assertNull($row['old_content']);
        $this->assertNull($row['old_size']);
        $this->assertNull($row['new_content'], 'a deleted path must report new_content: null, exactly like deleteFile()\'s own shape');
        $this->assertNull($row['new_size']);
    }

    #[Test]
    public function a_command_that_touches_nothing_produces_no_change_history_rows_at_all(): void
    {
        file_put_contents($this->projectDir.'/untouched.txt', 'never touched');

        $this->queueExecutorResult($this->completedResult());

        $this->runCommand('echo hi')->assertStatus(200);

        $this->assertSame([], $this->changeHistory());
    }

    // -----------------------------------------------------------------
    // (2) LOAD-BEARING FR-011 case: a stopped-partway command's
    // already-made change is still captured, not omitted.
    // -----------------------------------------------------------------

    #[Test]
    public function a_file_already_written_before_a_stopped_timeout_result_still_appears_in_change_history(): void
    {
        $this->queueExecutorResult([
            'status' => 'stopped_timeout',
            'exit_code' => null,
            'timed_out' => true,
            'stdout' => 'partial',
            'stderr' => '',
            'output_truncated' => false,
            'duration_ms' => 3000,
        ], function () {
            file_put_contents($this->projectDir.'/partial-output.txt', 'partial content written before the stop');
        });

        $response = $this->runCommand('echo partial > partial-output.txt; sleep 300');
        $response->assertStatus(200);
        $response->assertJson(['status' => 'stopped_timeout']);

        $rows = $this->changeHistory();
        $this->assertCount(1, $rows, 'a command stopped partway must still record the change it had already made (FR-011)');

        $row = $rows[0];
        $this->assertSame('partial-output.txt', $row['path']);
        $this->assertSame('created', $row['operation']);
        $this->assertSame('partial content written before the stop', $row['new_content']);
    }

    /**
     * The same guarantee proven once more against every other stop
     * reason this feature and its siblings introduce -- the diff must run
     * unconditionally, never special-cased on $result['status'].
     */
    #[Test]
    public function a_file_already_written_before_any_limit_stop_status_still_appears_in_change_history(): void
    {
        foreach (['stopped_disk_limit', 'stopped_oom', 'stopped_pids_limit'] as $status) {
            DB::table('coding_workspace_changes')->delete();

            $marker = 'marker-for-'.$status.'.txt';

            $this->queueExecutorResult([
                'status' => $status,
                'exit_code' => $status === 'stopped_oom' ? 137 : null,
                'timed_out' => false,
                'stdout' => 'partial',
                'stderr' => '',
                'output_truncated' => false,
                'duration_ms' => 1000,
            ], function () use ($marker) {
                file_put_contents($this->projectDir.'/'.$marker, 'content written before '.$marker);
            });

            $response = $this->runCommand('some command');
            $response->assertStatus(200);
            $response->assertJson(['status' => $status]);

            $rows = $this->changeHistory();
            $this->assertCount(1, $rows, "a change already made before a {$status} stop must still be recorded");
            $this->assertSame($marker, $rows[0]['path']);
            $this->assertSame('created', $rows[0]['operation']);
        }
    }

    // -----------------------------------------------------------------
    // (3) Exactly-once across separate invocations (Edge Case).
    // -----------------------------------------------------------------

    #[Test]
    public function a_second_identical_invocation_that_does_not_touch_an_already_created_file_produces_no_duplicate_row(): void
    {
        $this->queueExecutorResult($this->completedResult(), function () {
            file_put_contents($this->projectDir.'/output.txt', 'first pass content');
        });
        $this->runCommand()->assertStatus(200);

        $afterFirst = $this->changeHistory();
        $this->assertCount(1, $afterFirst);
        $this->assertSame('created', $afterFirst[0]['operation']);

        // A second, separate invocation of the identical command -- this
        // time it does not touch output.txt at all (e.g. it stopped
        // before reaching that step, or the write was already done and
        // this run's own effect is on something else entirely). The
        // point under test: output.txt's content on disk is genuinely
        // unchanged between this invocation's own before/after snapshot
        // pair, so no new row for it may appear.
        $this->queueExecutorResult($this->completedResult());
        $this->runCommand()->assertStatus(200);

        $afterSecond = $this->changeHistory();
        $this->assertCount(1, $afterSecond, 'an unchanged file on a second invocation must not produce a duplicate row');
        $this->assertSame('output.txt', $afterSecond[0]['path']);
    }

    #[Test]
    public function a_second_invocation_that_genuinely_further_modifies_the_file_produces_a_distinct_new_row(): void
    {
        $this->queueExecutorResult($this->completedResult(), function () {
            file_put_contents($this->projectDir.'/output.txt', 'first pass content');
        });
        $this->runCommand()->assertStatus(200);

        $afterFirst = $this->changeHistory();
        $this->assertCount(1, $afterFirst);
        $this->assertSame('created', $afterFirst[0]['operation']);

        // A second, separate invocation that genuinely writes further
        // content -- a different, larger size guarantees a distinguishable
        // (mtime, size) pair regardless of filesystem timestamp
        // resolution.
        $this->queueExecutorResult($this->completedResult(), function () {
            file_put_contents($this->projectDir.'/output.txt', 'first pass content, now with substantially more appended data');
        });
        $this->runCommand()->assertStatus(200);

        $afterSecond = $this->changeHistory();
        $this->assertCount(2, $afterSecond, 'a genuinely further-modified file must produce a second, distinct row');

        $second = $afterSecond[0]; // most-recent-first ordering
        $this->assertSame('output.txt', $second['path']);
        $this->assertSame('modified', $second['operation']);
        $this->assertSame('first pass content, now with substantially more appended data', $second['new_content']);
    }
}
