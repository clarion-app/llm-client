<?php

namespace Tests\RealDocker;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\CodingProject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * 124-command-limit-controls, US2 (research.md R2, contracts/
 * run-command-limit-stops.md §1, quickstart.md Scenario 2). Genuine
 * `docker`/`du` calls throughout -- no mocking anywhere in this file. Every
 * case drives the real, registered `PATCH .../resource-limits` and
 * `POST .../run-command` HTTP routes against the real (non-swapped)
 * DockerCommandExecutor, mirroring
 * tests/RealDocker/ResourceLimitEnforcementTest.php's own shape.
 *
 * NOTE: case 1 genuinely writes tens of megabytes inside a container
 * before being stopped -- this file can take a little longer than a purely
 * unit-level suite. That is expected, not a hang.
 */
#[Group('real-docker')]
class DiskLimitEnforcementTest extends TestCase
{
    private User $user;

    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user, 'api');

        $this->projectDir = sys_get_temp_dir().'/coding-agent-disk-limit-'.Str::random(12);
        mkdir($this->projectDir, 0777, true);
    }

    protected function tearDown(): void
    {
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

    private function registerProject(string $rootPath): CodingProject
    {
        return CodingProject::create([
            'user_id' => $this->user->id,
            'name' => 'disk-limit project',
            'root_path' => $rootPath,
            'test_command' => null,
        ]);
    }

    /**
     * @return list<string>
     */
    private function dockerPsNames(string $namePrefix): array
    {
        $process = new Process(['docker', 'ps', '--filter', 'name='.$namePrefix, '--format', '{{.Names}}']);
        $process->run();

        $output = trim($process->getOutput());
        if ($output === '') {
            return [];
        }

        return array_values(array_filter(explode("\n", $output)));
    }

    private function setDiskLimitOverride(CodingProject $project, int $megabytes): void
    {
        $response = $this->patchJson($this->apiUrl("coding-project/{$project->id}/resource-limits"), [
            'disk_limit_override_mb' => $megabytes,
        ]);

        $response->assertStatus(200);
        $this->assertSame($megabytes, $response->json('disk_limit_override_mb'));
    }

    // -----------------------------------------------------------------
    // 1. FR-005/FR-006/FR-009/SC-002: a command that echoes a marker
    //    BEFORE writing well past a 5MB disk limit is stopped once that
    //    limit is reached -- not left to write the full amount -- and the
    //    marker survives in stdout, proving output already produced is
    //    never discarded.
    // -----------------------------------------------------------------

    /**
     * A slow, chunked write (1MB per iteration, a short sleep between each)
     * rather than one single fast `dd` -- the disk-usage-delta poll check
     * only re-measures on the same coarser cadence the progress-heartbeat
     * broadcast already uses (research.md R2/R3c, at least a whole second
     * per check by this class's existing accumulator), so the write must
     * genuinely span real wall-clock time for a poll tick to have any
     * chance of observing the breach before the command would otherwise
     * finish on its own. 20 x 1MB iterations at ~0.15s apart target a
     * ~3s total run if never stopped -- comfortably past the first ~1s
     * checkpoint, by which point roughly 6-7MB has already been written,
     * past the 5MB limit these tests configure.
     */
    private const SLOW_WRITE_LOOP = 'i=0; while [ $i -lt 20 ]; do head -c 1048576 /dev/zero >> %s; i=$((i+1)); sleep 0.15; done';

    #[Test]
    public function a_command_writing_far_past_the_configured_disk_limit_is_stopped_before_finishing_and_its_output_before_the_stop_survives(): void
    {
        config(['llm-client.coding_agent.command_progress_broadcast_after_seconds' => 1]);

        $project = $this->registerProject($this->projectDir);
        $this->setDiskLimitOverride($project, 5);

        $response = $this->postJson($this->apiUrl("coding-project/{$project->id}/run-command"), [
            'command' => 'echo before-the-write; '.sprintf(self::SLOW_WRITE_LOOP, 'big.bin'),
        ]);

        $response->assertStatus(200);
        $this->assertSame('stopped_disk_limit', $response->json('status'));
        $this->assertNull($response->json('exit_code'));
        $this->assertStringContainsString('before-the-write', $response->json('stdout'), 'output already produced before the disk-limit stop must never be discarded (FR-009)');

        // Stopped well before the full 20MB the loop was asked to write --
        // the workspace must not contain anywhere near the full amount.
        $writtenFile = $this->projectDir.'/big.bin';
        if (is_file($writtenFile)) {
            $this->assertLessThan(20 * 1024 * 1024, filesize($writtenFile), 'the command must have been stopped well before writing the full 20MB the loop was asked to write');
        }

        $stillRunning = $this->dockerPsNames('coding-cmd-');
        $this->assertEmpty($stillRunning, 'a container from this disk-limit-stopped invocation is still running after the kill sequence: '.implode(', ', $stillRunning));
    }

    // -----------------------------------------------------------------
    // 2. FR-006: the response specifically names stopped_disk_limit --
    //    never a generic failure and never `completed`.
    // -----------------------------------------------------------------

    #[Test]
    public function the_response_specifically_names_stopped_disk_limit_never_a_generic_failure_or_completed(): void
    {
        config(['llm-client.coding_agent.command_progress_broadcast_after_seconds' => 1]);

        $project = $this->registerProject($this->projectDir);
        $this->setDiskLimitOverride($project, 5);

        $response = $this->postJson($this->apiUrl("coding-project/{$project->id}/run-command"), [
            'command' => sprintf(self::SLOW_WRITE_LOOP, 'big2.bin'),
        ]);

        $response->assertStatus(200);
        $status = $response->json('status');
        $this->assertSame('stopped_disk_limit', $status);
        $this->assertNotSame('completed', $status);
    }

    // -----------------------------------------------------------------
    // 3. Ordinary commands well within the limit are unaffected.
    // -----------------------------------------------------------------

    #[Test]
    public function an_ordinary_command_writing_well_under_the_disk_limit_completes_unaffected(): void
    {
        $project = $this->registerProject($this->projectDir);
        $this->setDiskLimitOverride($project, 5);

        $response = $this->postJson($this->apiUrl("coding-project/{$project->id}/run-command"), [
            'command' => 'echo hi > small.txt',
        ]);

        $response->assertStatus(200);
        $this->assertSame('completed', $response->json('status'));
        $this->assertSame(0, $response->json('exit_code'));
    }

    // -----------------------------------------------------------------
    // 4. CRITICAL baseline-exclusion case (spec.md Edge Case): a
    //    workspace pre-seeded with 4MB of files by the test fixture
    //    itself (NOT by the command under test), a 5MB limit, and a
    //    command writing 2MB of NEW data -> completed. Wrongly including
    //    the pre-existing 4MB in the measured delta would incorrectly
    //    report stopped_disk_limit at 6MB against the 5MB limit.
    // -----------------------------------------------------------------

    #[Test]
    public function pre_existing_workspace_content_is_excluded_from_the_measured_disk_usage_delta(): void
    {
        // Pre-seed 4MB via the test fixture itself, before the command
        // under test ever runs -- this is the "already used by files that
        // existed in the workspace beforehand" the Edge Case names.
        file_put_contents($this->projectDir.'/pre-existing.bin', str_repeat("\0", 4 * 1024 * 1024));

        // A deliberately slow cadence (1s) PLUS a deliberately slow write
        // (2 x 1MB chunks with a real sleep between them) -- the whole
        // point of this case is that the disk-usage-delta check must
        // genuinely RUN mid-write (not merely be absent for the whole
        // invocation, which would let this case pass vacuously regardless
        // of whether baseline exclusion is implemented correctly at all)
        // and, even while running against a workspace that already
        // contains 4MB, must still resolve the correct ~1-2MB delta
        // (excluding the pre-existing baseline), never a false ~5-6MB
        // reading against the 5MB limit.
        config(['llm-client.coding_agent.command_progress_broadcast_after_seconds' => 1]);

        $project = $this->registerProject($this->projectDir);
        $this->setDiskLimitOverride($project, 5);

        $response = $this->postJson($this->apiUrl("coding-project/{$project->id}/run-command"), [
            'command' => 'head -c 1048576 /dev/zero >> new-data.bin; sleep 0.7; head -c 1048576 /dev/zero >> new-data.bin; sleep 0.7',
        ]);

        $response->assertStatus(200);
        $this->assertSame('completed', $response->json('status'), 'the pre-existing 4MB must be excluded from the measured delta -- only the 2MB this command itself wrote counts against the 5MB limit; wrongly including the baseline would report stopped_disk_limit at ~6MB');
        $this->assertSame(0, $response->json('exit_code'));
    }
}
