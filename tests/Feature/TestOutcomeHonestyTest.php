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
 * 112-coding-agent, US3 (P1), T034 (D5, FR-006/FR-011, quickstart row 5,
 * mutation-checklist row 6).
 *
 * Proves codingWorkspace.runTests's pass/fail outcome is read exclusively
 * from Process::getExitCode() -- never inferred from stdout/stderr text.
 * Every case here drives a REAL subprocess (Symfony\Component\Process,
 * unmocked) whose stdout prints an incidental, "OK"-shaped string while
 * genuinely exiting nonzero. A stdout-sniffing implementation would
 * misreport this as a pass; the exit-code-derived implementation must not.
 */
class TestOutcomeHonestyTest extends TestCase
{
    private User $user;

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user, 'api');

        $this->tmpDir = sys_get_temp_dir().'/coding-agent-test-outcome-'.Str::random(12);
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
    public function a_genuinely_failing_subprocess_is_reported_as_failed_even_though_stdout_prints_an_ok_shaped_string(): void
    {
        // A shell command that prints something a naive stdout-sniffing
        // implementation might mistake for a pass, then genuinely exits 1.
        $project = CodingProject::create([
            'user_id' => $this->user->id,
            'name' => 'genuinely failing project',
            'root_path' => $this->tmpDir,
            'test_command' => 'echo "Tests ran OK, 3 passed"; exit 1',
        ]);

        $data = $this->runTests($project);

        $this->assertSame(
            'completed',
            $data['status'],
            'the process genuinely started and exited -- this is a completed result, never no_tests_configured/could_not_run',
        );
        $this->assertSame(1, $data['exit_code'], 'exit_code must be the real, nonzero exit code from Process::getExitCode()');
        $this->assertFalse($data['passed'], 'passed must be derived from exit_code, never from the OK-shaped stdout text');
        $this->assertStringContainsString(
            'Tests ran OK',
            $data['stdout'],
            'the incidental OK-shaped stdout text is captured verbatim, but must never drive passed',
        );
    }

    #[Test]
    public function the_report_names_which_tests_failed_from_real_output_without_the_task_being_describable_as_successfully_completed(): void
    {
        $project = CodingProject::create([
            'user_id' => $this->user->id,
            'name' => 'project with named failures',
            'root_path' => $this->tmpDir,
            'test_command' => 'echo "FAIL: test_alpha"; echo "FAIL: test_beta"; echo "status OK overall"; exit 1',
        ]);

        $data = $this->runTests($project);

        $this->assertSame('completed', $data['status']);
        $this->assertFalse($data['passed'], 'a run exiting nonzero must never be describable as a successful completion');
        $this->assertSame(1, $data['exit_code']);
        $this->assertStringContainsString('FAIL: test_alpha', $data['stdout'], 'the real output naming which tests failed must survive so the report can name them');
        $this->assertStringContainsString('FAIL: test_beta', $data['stdout']);
    }

    #[Test]
    public function a_genuinely_passing_subprocess_is_reported_as_passed(): void
    {
        // Contrast case: the same exit-code-only derivation must also
        // correctly report a real pass, proving this is a two-sided
        // honesty guarantee, not a hard-coded false.
        $project = CodingProject::create([
            'user_id' => $this->user->id,
            'name' => 'genuinely passing project',
            'root_path' => $this->tmpDir,
            'test_command' => 'echo "all good"; exit 0',
        ]);

        $data = $this->runTests($project);

        $this->assertSame('completed', $data['status']);
        $this->assertSame(0, $data['exit_code']);
        $this->assertTrue($data['passed']);
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
