<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Controllers\CodingWorkspaceController;
use ClarionApp\LlmClient\Models\CodingProject;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 112-coding-agent, US5 (P2), T042 (D5, FR-013, quickstart steps 8-9,
 * mutation-checklist rows 7/8).
 *
 * Confirm-or-fix: codingWorkspace.runTests's three-way split
 * (no_tests_configured / could_not_run / completed) was already built in
 * US1 (T026). This file proves the two non-completed states are reachable
 * on their own real, distinct triggers and never collapse into one
 * another or into a real pass/fail completed result.
 *
 * A note on the could_not_run trigger actually used here: Symfony's
 * Process::fromShellCommandline() always runs the given command through
 * "sh -c", so a command naming a nonexistent interpreter/binary never
 * throws when started -- the shell itself starts fine and the "command
 * not found" surfaces as a real, ordinary nonzero exit code (127) from a
 * process that did start. This was confirmed directly against the real
 * Symfony\Component\Process\Process class before writing this file (not
 * assumed from its docs). The one condition that genuinely makes the
 * process itself fail to start -- and the one the controller's
 * catch (\Throwable) block was actually built for -- is an unusable
 * working directory (e.g. the project's root_path having been removed
 * from disk after registration while a test_command is still set): that
 * is a real, thrown Symfony\Component\Process\Exception\RuntimeException,
 * caught before any test process ever runs. This distinction also lines
 * up with the spec's own Edge Cases guidance: a broken build or failing
 * test process is reported as a real (completed) failure, never folded
 * into an ambiguous "could not run" bucket -- so a shell command that
 * merely names a missing binary correctly lands under completed/failed
 * here, not could_not_run.
 */
class UnrunnableSuiteTest extends TestCase
{
    private User $user;

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user, 'api');

        $this->tmpDir = sys_get_temp_dir().'/coding-agent-unrunnable-'.Str::random(12);
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            $this->removeDirectory($this->tmpDir);
        }

        parent::tearDown();
    }

    #[Test]
    public function a_null_test_command_produces_no_tests_configured_and_no_process_is_ever_spawned(): void
    {
        // root_path deliberately points at a subpath that does not exist
        // on disk. This is the verification, not an inference from
        // reading the source: the "cannot start" case below proves that
        // Process::run() genuinely throws when its cwd does not exist, so
        // if runTests() fell through to constructing/running a Process
        // here despite test_command being null, this call would fail
        // loudly (a 500/exception) instead of returning the clean
        // no_tests_configured response asserted below.
        $project = CodingProject::create([
            'user_id' => $this->user->id,
            'name' => 'no test command, unreachable root',
            'root_path' => $this->tmpDir.'/does-not-exist-on-disk',
            'test_command' => null,
        ]);

        $data = $this->runTests($project);

        $this->assertSame(
            ['status' => 'no_tests_configured', 'command' => null],
            $data,
            'no_tests_configured must be the entire response -- no exit_code/passed/reason key, proving no Process was ever constructed or run',
        );
    }

    #[Test]
    public function a_test_command_whose_working_directory_has_been_removed_produces_could_not_run(): void
    {
        // A project registered against a real, existing directory whose
        // root_path is then removed from disk -- mirroring the still-
        // registered-but-gone-on-disk scenario T038 already covers for
        // the other coding-workspace endpoints. runTests() does not
        // consult PathContainment at all (contracts §3); this exercises
        // its own, independent catch (\Throwable) path around
        // Process::run() instead.
        $goneDir = $this->tmpDir.'/will-be-removed';
        mkdir($goneDir, 0777, true);

        $project = CodingProject::create([
            'user_id' => $this->user->id,
            'name' => 'root removed after registration',
            'root_path' => $goneDir,
            'test_command' => 'echo hi',
        ]);

        rmdir($goneDir);
        $this->assertDirectoryDoesNotExist($goneDir, 'the precondition for this case: root_path must genuinely be gone before runTests() is called');

        $data = $this->runTests($project);

        $this->assertSame('could_not_run', $data['status']);
        $this->assertSame('echo hi', $data['command']);
        $this->assertArrayHasKey('reason', $data);
        $this->assertNotEmpty($data['reason'], 'a could_not_run result must name why the process could not start');
        $this->assertArrayNotHasKey('exit_code', $data, 'could_not_run must never carry a completed-shaped exit_code/passed pair');
        $this->assertArrayNotHasKey('passed', $data);
    }

    #[Test]
    public function a_command_naming_a_nonexistent_binary_is_a_real_completed_failure_not_could_not_run(): void
    {
        // The shell itself starts fine against a valid root_path; only
        // the named binary is missing. This must not be misclassified as
        // could_not_run (see class docblock) -- it is a genuine,
        // structurally distinct completed/failed result.
        $project = CodingProject::create([
            'user_id' => $this->user->id,
            'name' => 'valid root, missing binary',
            'root_path' => $this->tmpDir,
            'test_command' => '/nonexistent/interpreter/does-not-exist-xyz --run',
        ]);

        $data = $this->runTests($project);

        $this->assertSame('completed', $data['status']);
        $this->assertSame(127, $data['exit_code'], 'a real, started-then-failed shell exits 127 for "command not found" -- read from Process::getExitCode(), not inferred from stderr text');
        $this->assertFalse($data['passed']);
        $this->assertArrayNotHasKey('reason', $data, 'a completed result never carries could_not_run\'s reason key');
    }

    #[Test]
    public function all_three_outcomes_are_structurally_distinct_shapes(): void
    {
        $noCommandProject = CodingProject::create([
            'user_id' => $this->user->id,
            'name' => 'no command',
            'root_path' => $this->tmpDir.'/also-does-not-exist',
            'test_command' => null,
        ]);

        $goneDir = $this->tmpDir.'/also-removed';
        mkdir($goneDir, 0777, true);
        $unstartableProject = CodingProject::create([
            'user_id' => $this->user->id,
            'name' => 'unstartable',
            'root_path' => $goneDir,
            'test_command' => 'echo hi',
        ]);
        rmdir($goneDir);

        $passingProject = CodingProject::create([
            'user_id' => $this->user->id,
            'name' => 'passing',
            'root_path' => $this->tmpDir,
            'test_command' => 'exit 0',
        ]);

        $noTestsConfigured = $this->runTests($noCommandProject);
        $couldNotRun = $this->runTests($unstartableProject);
        $completed = $this->runTests($passingProject);

        $this->assertSame('no_tests_configured', $noTestsConfigured['status']);
        $this->assertSame('could_not_run', $couldNotRun['status']);
        $this->assertSame('completed', $completed['status']);

        // The three status strings are themselves already distinct
        // (asserted above); this additionally locks in that the three
        // envelopes are shaped differently, so nothing downstream could
        // treat two of them as interchangeable.
        $statuses = [$noTestsConfigured['status'], $couldNotRun['status'], $completed['status']];
        $this->assertSame(3, count(array_unique($statuses)), 'no two of the three outcomes may share a status string');

        $this->assertArrayNotHasKey('reason', $noTestsConfigured);
        $this->assertArrayNotHasKey('exit_code', $noTestsConfigured);
        $this->assertArrayNotHasKey('exit_code', $couldNotRun);
        $this->assertArrayNotHasKey('reason', $completed);
        $this->assertTrue($completed['passed']);
        $this->assertSame(0, $completed['exit_code']);
    }

    /**
     * @return array<string, mixed>
     */
    private function runTests(CodingProject $project): array
    {
        $controller = new CodingWorkspaceController();
        $request = Request::create('/coding-project/'.$project->id.'/run-tests', 'POST');

        return $controller->runTests($request, $project->id)->getData(true);
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
}
