<?php

namespace Tests\RealDocker;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\CodingProject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * 125-language-runtime-execution, US1, T011 (contracts/run-code.md,
 * research.md D1/D2). Genuine `docker`/language-runtime calls throughout
 * -- no mocking anywhere in this file. Drives the real, registered
 * `POST coding-project/{project}/run-code` HTTP route with the real
 * (non-swapped) DockerCommandExecutor, exactly like
 * tests/RealDocker/ResourceLimitEnforcementTest.php and
 * tests/RealDocker/NetworkPolicyEnforcementTest.php.
 *
 * setUp() overrides `command_image` to a combined Python+Node.js image
 * (confirmed already pulled locally) for every test EXCEPT the
 * default-image case (9), which resets it back to the package's own
 * shipped default -- Testbench's fresh-application-per-test-method
 * guarantee (Grounding note 12) means that reset can never leak into any
 * other test in this file.
 *
 * Written before `run-code`/`languages` routes and
 * CodingWorkspaceController::runCode() exist -- expected to FAIL red
 * (404/route-not-found) until T012-T016 land.
 *
 * NOTE: this file involves genuine process timing (OOM/timeout/large
 * payload) -- it can take real wall-clock time to run. That is expected,
 * not a hang.
 */
#[Group('real-docker')]
class LanguageExecutionTest extends TestCase
{
    private const COMBINED_IMAGE = 'nikolaik/python-nodejs:latest';

    private User $user;

    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user, 'api');

        $this->projectDir = sys_get_temp_dir().'/coding-agent-run-code-'.Str::random(12);
        mkdir($this->projectDir, 0777, true);

        config(['llm-client.coding_agent.command_image' => self::COMBINED_IMAGE]);
    }

    protected function tearDown(): void
    {
        DB::table('coding_command_executions')->delete();
        if (Schema::hasTable('coding_workspace_refusals')) {
            DB::table('coding_workspace_refusals')->delete();
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

    private function registerProject(string $rootPath): CodingProject
    {
        return CodingProject::create([
            'user_id' => $this->user->id,
            'name' => 'run-code real-docker project',
            'root_path' => $rootPath,
            'test_command' => null,
        ]);
    }

    /**
     * @return list<string>
     */
    private function dockerPsNames(string $namePrefix): array
    {
        $process = new Process(['docker', 'ps', '-a', '--filter', 'name='.$namePrefix, '--format', '{{.Names}}']);
        $process->run();

        $output = trim($process->getOutput());
        if ($output === '') {
            return [];
        }

        return array_values(array_filter(explode("\n", $output)));
    }

    private function runCode(CodingProject $project, string $language, string $code): \Illuminate\Testing\TestResponse
    {
        return $this->postJson($this->apiUrl("coding-project/{$project->id}/run-code"), [
            'language' => $language,
            'code' => $code,
        ]);
    }

    // -----------------------------------------------------------------
    // (1) Python "hi from python" -- Scenario 1.1.
    // -----------------------------------------------------------------

    #[Test]
    public function a_python_snippet_runs_and_returns_its_printed_output(): void
    {
        $project = $this->registerProject($this->projectDir);

        $response = $this->runCode($project, 'python', "print('hi from python')");

        $response->assertStatus(200);
        $this->assertSame('completed', $response->json('status'));
        $this->assertStringContainsString("hi from python\n", (string) $response->json('stdout'));
    }

    // -----------------------------------------------------------------
    // (2) JavaScript "hi from js" -- Scenario 1.2.
    // -----------------------------------------------------------------

    #[Test]
    public function a_javascript_snippet_runs_and_returns_its_printed_output(): void
    {
        $project = $this->registerProject($this->projectDir);

        $response = $this->runCode($project, 'javascript', "console.log('hi from js')");

        $response->assertStatus(200);
        $this->assertSame('completed', $response->json('status'));
        $this->assertStringContainsString("hi from js\n", (string) $response->json('stdout'));
    }

    // -----------------------------------------------------------------
    // (3) stdout/stderr never conflated -- Scenario 1.3, FR-002.
    // -----------------------------------------------------------------

    #[Test]
    public function stdout_and_stderr_are_never_conflated(): void
    {
        $project = $this->registerProject($this->projectDir);

        $code = "import sys\nprint('on stdout')\nsys.stderr.write('on stderr\\n')\n";
        $response = $this->runCode($project, 'python', $code);

        $response->assertStatus(200);
        $this->assertSame('completed', $response->json('status'));

        $stdout = (string) $response->json('stdout');
        $stderr = (string) $response->json('stderr');

        $this->assertStringContainsString('on stdout', $stdout);
        $this->assertStringNotContainsString('on stderr', $stdout);
        $this->assertStringContainsString('on stderr', $stderr);
        $this->assertStringNotContainsString('on stdout', $stderr);
    }

    // -----------------------------------------------------------------
    // (4) Quote/backslash/$/multi-line hazard mix, small size -- Scenario
    // 1.4, research.md D2 tests A-C, output reflects true behavior
    // byte-for-byte, no shell-interpretation artifact.
    // -----------------------------------------------------------------

    #[Test]
    public function a_snippet_with_quotes_backslashes_dollar_tokens_and_multiple_lines_runs_with_no_shell_interpretation_artifact(): void
    {
        $project = $this->registerProject($this->projectDir);

        $code = <<<'PY'
s = 'single \' quote, "double" quote, backslash \\, dollar $HOME token'
multiline = """
line one
line two
"""
print(s)
print(multiline.strip())
print('hazard-ok')
PY;

        $response = $this->runCode($project, 'python', $code);

        $response->assertStatus(200);
        $this->assertSame('completed', $response->json('status'));
        $this->assertSame(0, $response->json('exit_code'));

        $stdout = (string) $response->json('stdout');
        $this->assertStringContainsString('single \' quote, "double" quote, backslash \\, dollar $HOME token', $stdout, 'the hazard characters must reach the interpreter byte-for-byte, never mangled by a shell');
        $this->assertStringContainsString("line one\nline two", $stdout);
        $this->assertStringContainsString('hazard-ok', $stdout);
    }

    // -----------------------------------------------------------------
    // (5) THE LOAD-BEARING LARGE-SNIPPET PROOF: a generated ~330-384 KB
    // Python snippet, past the real OS argv-length ceiling, containing
    // the same hazard mix -- submitted via run-code -> completed, exit 0,
    // a distinguishing marker present byte-correct in stdout.
    // -----------------------------------------------------------------

    #[Test]
    public function a_snippet_well_past_the_argv_length_ceiling_runs_correctly_via_stdin_not_argv(): void
    {
        $project = $this->registerProject($this->projectDir);

        $header = <<<'PY'
s = 'single \' quote, "double" quote, backslash \\, dollar $HOME token'
multiline = """
line one
line two
"""
print(s)
print(multiline.strip())
PY;

        $paddingLine = "# padding 'single' \"double\" backslash \\\\ dollar \$HOME token line of comment text\n";
        $lineLength = strlen($paddingLine);
        $targetPaddingBytes = 350_000;
        $repeat = (int) floor($targetPaddingBytes / $lineLength);
        $padding = str_repeat($paddingLine, $repeat);

        $code = $header."\n".$padding."\nprint('large-ok')\n";

        $this->assertGreaterThanOrEqual(330_000, strlen($code), 'fixture sanity: the generated snippet must genuinely be past the argv-length ceiling');
        $this->assertLessThanOrEqual(384_000, strlen($code), 'fixture sanity: the generated snippet must stay within the documented target window');

        $response = $this->runCode($project, 'python', $code);

        $response->assertStatus(200);
        $this->assertSame('completed', $response->json('status'), 'a large snippet submitted via stdin must complete normally, never an argv-length shell failure');
        $this->assertSame(0, $response->json('exit_code'));

        $stdout = (string) $response->json('stdout');
        $this->assertStringContainsString('single \' quote, "double" quote, backslash \\, dollar $HOME token', $stdout);
        $this->assertStringContainsString('large-ok', $stdout, 'the distinguishing final marker must be present byte-correct in stdout');
    }

    // -----------------------------------------------------------------
    // (6) Low memory_limit_override_mb + memory-exhausting snippet ->
    // stopped_oom -- Scenario 1.5, FR-003, proving runCode goes through
    // the identical DockerCommandExecutor::run() flag construction.
    // -----------------------------------------------------------------

    #[Test]
    public function a_memory_exhausting_python_snippet_past_the_overridden_memory_limit_resolves_stopped_oom(): void
    {
        config(['llm-client.coding_agent.command_timeout_seconds' => 30]);

        $project = $this->registerProject($this->projectDir);

        $limitsResponse = $this->patchJson($this->apiUrl("coding-project/{$project->id}/resource-limits"), [
            'memory_limit_override_mb' => 20,
        ]);
        $limitsResponse->assertStatus(200);
        $this->assertSame(20, $limitsResponse->json('memory_limit_override_mb'));

        $memoryBomb = <<<'PY'
print('before-oom')
data = []
chunk = 'A' * (1024 * 1024)
while True:
    data.append(chunk)
PY;

        $response = $this->runCode($project, 'python', $memoryBomb);

        $response->assertStatus(200);
        $this->assertSame('stopped_oom', $response->json('status'), 'runCode must resolve stopped_oom through the identical DockerCommandExecutor flow runCommand already uses');
        $this->assertNotSame(0, $response->json('exit_code'));

        $stillRunning = $this->dockerPsNames('coding-cmd-');
        $this->assertEmpty($stillRunning, 'no container from this OOM-killed run-code invocation may still be running');
    }

    // -----------------------------------------------------------------
    // (7) Low time_limit_override_seconds + non-terminating snippet ->
    // stopped_timeout, timed_out: true, before-marker preserved, no
    // lingering container -- Scenario 4, FR-007/FR-008.
    // -----------------------------------------------------------------

    #[Test]
    public function a_non_terminating_python_snippet_is_stopped_at_the_overridden_time_limit_with_no_container_left_running(): void
    {
        $project = $this->registerProject($this->projectDir);

        $limitsResponse = $this->patchJson($this->apiUrl("coding-project/{$project->id}/resource-limits"), [
            'time_limit_override_seconds' => 3,
        ]);
        $limitsResponse->assertStatus(200);

        $loop = <<<'PY'
print('before')
while True:
    pass
PY;

        $before = microtime(true);
        $response = $this->runCode($project, 'python', $loop);
        $elapsed = microtime(true) - $before;

        $response->assertStatus(200);
        $this->assertSame('stopped_timeout', $response->json('status'));
        $this->assertTrue($response->json('timed_out'));
        $this->assertStringContainsString('before', (string) $response->json('stdout'), 'output already produced before the timeout-kill must never be discarded');
        $this->assertLessThan(60.0, $elapsed, 'the request must not have blocked anywhere near a genuine hang');

        $stillRunning = $this->dockerPsNames('coding-cmd-');
        $this->assertEmpty($stillRunning, 'a container from this timed-out run-code invocation is still running: '.implode(', ', $stillRunning));
    }

    // -----------------------------------------------------------------
    // (8) No-state-leakage: one call writes a file and sets a
    // module-level variable; an immediately-following second call reports
    // the file absent and the variable unset -- Scenario 5, FR-010/SC-005.
    // -----------------------------------------------------------------

    #[Test]
    public function two_sequential_calls_against_the_same_workspace_share_no_state(): void
    {
        $project = $this->registerProject($this->projectDir);

        $firstCall = <<<'PY'
with open('/tmp/leak-marker.txt', 'w') as f:
    f.write('leaked')
leak_variable = 'should not survive'
print('first-call-done')
PY;

        $firstResponse = $this->runCode($project, 'python', $firstCall);
        $firstResponse->assertStatus(200);
        $this->assertSame('completed', $firstResponse->json('status'));

        $secondCall = <<<'PY'
import os
print('file-present' if os.path.exists('/tmp/leak-marker.txt') else 'file-absent')
try:
    leak_variable
    print('variable-set')
except NameError:
    print('variable-unset')
PY;

        $secondResponse = $this->runCode($project, 'python', $secondCall);
        $secondResponse->assertStatus(200);
        $this->assertSame('completed', $secondResponse->json('status'));

        $stdout = (string) $secondResponse->json('stdout');
        $this->assertStringContainsString('file-absent', $stdout, 'a file written during the first call must not survive into the second, fresh container');
        $this->assertStringContainsString('variable-unset', $stdout, 'a module-level variable set during the first call must not survive into the second, fresh container');
    }

    // -----------------------------------------------------------------
    // (9) Default shipped image (alpine:latest, unmodified) -> python is
    // genuinely unavailable -- Scenario 2.4, FR-006, SC-002. The ONE case
    // that does NOT use the combined-image override.
    // -----------------------------------------------------------------

    #[Test]
    public function against_the_default_shipped_image_python_is_reported_as_genuinely_unavailable(): void
    {
        config(['llm-client.coding_agent.command_image' => 'alpine:latest']);

        $project = $this->registerProject($this->projectDir);

        $response = $this->runCode($project, 'python', "print('hi')");

        $response->assertStatus(200);
        $this->assertSame('language_unavailable', $response->json('status'), 'the shipped default sandbox image has no python runtime at all -- this must be reported specifically, never a raw shell error');
        $this->assertStringContainsString('python', (string) $response->json('reason'), 'the reason must name python specifically');
    }

    // -----------------------------------------------------------------
    // (10) NETWORK POLICY PARITY: default network_enabled: false blocks a
    // genuine egress attempt; enabling network for the workspace lets the
    // identical snippet succeed -- FR-009, SC-004, Scenario 6.
    // -----------------------------------------------------------------

    #[Test]
    public function network_policy_parity_default_blocks_egress_and_enabling_it_lets_the_identical_snippet_succeed(): void
    {
        $project = $this->registerProject($this->projectDir);
        $this->assertFalse((bool) $project->network_enabled, 'fixture sanity: network access is off by default');

        $egressSnippet = <<<'PY'
import urllib.request
try:
    urllib.request.urlopen('http://example.com', timeout=2)
    print('egress-succeeded')
except Exception as e:
    print('egress-failed: ' + str(e))
PY;

        $blockedResponse = $this->runCode($project, 'python', $egressSnippet);
        $blockedResponse->assertStatus(200);
        $this->assertSame('completed', $blockedResponse->json('status'), 'the container itself must have run -- the network attempt fails from inside it, this is not sandbox_unavailable');
        $blockedStdout = (string) $blockedResponse->json('stdout');
        $blockedStderr = (string) $blockedResponse->json('stderr');
        $this->assertTrue(
            str_contains($blockedStdout, 'egress-failed')
                || (int) $blockedResponse->json('exit_code') !== 0
                || $blockedStderr !== '',
            'a genuine egress attempt must fail against the default, no-network policy'
        );

        $this->patchJson($this->apiUrl("coding-project/{$project->id}/network-policy"), [
            'network_enabled' => true,
        ])->assertStatus(200);
        $this->assertTrue((bool) $project->fresh()->network_enabled, 'fixture sanity: network access is now enabled for this workspace');

        $allowedResponse = $this->runCode($project, 'python', $egressSnippet);
        $allowedResponse->assertStatus(200);
        $this->assertSame('completed', $allowedResponse->json('status'));
        $this->assertStringContainsString('egress-succeeded', (string) $allowedResponse->json('stdout'), 'the identical egress attempt must succeed once network_enabled is true for this workspace');
    }
}
