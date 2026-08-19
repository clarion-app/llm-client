<?php

namespace Tests\RealDocker;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\CodingProject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 125-language-runtime-execution, US2, T022 (contracts/language-availability.md
 * §1, research.md D4). Genuine `docker`/`command -v` calls throughout -- no
 * mocking anywhere in this file. Drives the real, registered
 * `GET coding-project/{project}/languages` HTTP route with the real
 * (non-swapped) DockerCommandExecutor, exactly like
 * tests/RealDocker/LanguageExecutionTest.php.
 *
 * setUp() overrides `command_image` to a combined Python+Node.js image
 * (confirmed already pulled locally) for every test EXCEPT the
 * default-image case (2), which resets it back to the package's own
 * shipped default -- Testbench's fresh-application-per-test-method
 * guarantee means that reset can never leak into any other test in this
 * file.
 *
 * Written before the `languages` route and
 * CodingWorkspaceController::languages() exist -- expected to FAIL red
 * (404/route-not-found) until T023-T024 land.
 *
 * NOTE: this file pulls/launches genuine ephemeral containers -- it can
 * take real wall-clock time to run. That is expected, not a hang.
 */
#[Group('real-docker')]
class LanguageAvailabilityProbeTest extends TestCase
{
    private const COMBINED_IMAGE = 'nikolaik/python-nodejs:latest';

    private const PYTHON_ONLY_IMAGE = 'python:3-alpine';

    private User $user;

    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user, 'api');

        $this->projectDir = sys_get_temp_dir().'/coding-agent-language-availability-'.Str::random(12);
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
            'name' => 'language-availability real-docker project',
            'root_path' => $rootPath,
            'test_command' => null,
        ]);
    }

    private function languages(CodingProject $project): \Illuminate\Testing\TestResponse
    {
        return $this->getJson($this->apiUrl("coding-project/{$project->id}/languages"));
    }

    private function runCode(CodingProject $project, string $language, string $code): \Illuminate\Testing\TestResponse
    {
        return $this->postJson($this->apiUrl("coding-project/{$project->id}/run-code"), [
            'language' => $language,
            'code' => $code,
        ]);
    }

    // -----------------------------------------------------------------
    // (1) Combined Python+Node.js image -> both python and javascript
    // report available: true -- Scenario 2.1, FR-004/FR-005.
    // -----------------------------------------------------------------

    #[Test]
    public function against_the_combined_image_both_python_and_javascript_are_reported_available(): void
    {
        $project = $this->registerProject($this->projectDir);

        $response = $this->languages($project);

        $response->assertStatus(200);
        $response->assertExactJson([
            'languages' => [
                ['name' => 'python', 'available' => true],
                ['name' => 'javascript', 'available' => true],
            ],
        ]);
    }

    // -----------------------------------------------------------------
    // (2) Package's own shipped default image (alpine:latest, unmodified)
    // -> both python and javascript report available: false -- Scenario
    // 2.2, FR-005 (a real, honest answer, not a hardcoded assumption). The
    // ONE test method in this file that does NOT use the combined-image
    // override.
    // -----------------------------------------------------------------

    #[Test]
    public function against_the_package_shipped_default_image_both_languages_are_reported_unavailable(): void
    {
        config(['llm-client.coding_agent.command_image' => 'alpine:latest']);

        $project = $this->registerProject($this->projectDir);

        $response = $this->languages($project);

        $response->assertStatus(200);
        $response->assertExactJson([
            'languages' => [
                ['name' => 'python', 'available' => false],
                ['name' => 'javascript', 'available' => false],
            ],
        ]);
    }

    // -----------------------------------------------------------------
    // (3) A single-language image (python:3-alpine) -> a genuinely mixed
    // result, proving the probe reads REAL per-binary presence, not an
    // all-or-nothing image-level guess.
    // -----------------------------------------------------------------

    #[Test]
    public function against_a_single_language_image_the_result_is_genuinely_mixed_not_all_or_nothing(): void
    {
        config(['llm-client.coding_agent.command_image' => self::PYTHON_ONLY_IMAGE]);

        $project = $this->registerProject($this->projectDir);

        $response = $this->languages($project);

        $response->assertStatus(200);
        $response->assertExactJson([
            'languages' => [
                ['name' => 'python', 'available' => true],
                ['name' => 'javascript', 'available' => false],
            ],
        ]);
    }

    // -----------------------------------------------------------------
    // (4) Immediately after a true/true result against the combined image,
    // running the Scenario 1 snippets against the same workspace succeeds
    // -- the availability answer matches what actually runs (Scenario 2.3,
    // SC-001).
    // -----------------------------------------------------------------

    #[Test]
    public function a_true_true_availability_result_is_consistent_with_both_languages_actually_running(): void
    {
        $project = $this->registerProject($this->projectDir);

        $availability = $this->languages($project);
        $availability->assertStatus(200);
        $availability->assertExactJson([
            'languages' => [
                ['name' => 'python', 'available' => true],
                ['name' => 'javascript', 'available' => true],
            ],
        ]);

        $pythonRun = $this->runCode($project, 'python', "print('hi from python')");
        $pythonRun->assertStatus(200);
        $this->assertSame('completed', $pythonRun->json('status'), 'python was reported available -- it must actually run successfully');
        $this->assertStringContainsString("hi from python\n", (string) $pythonRun->json('stdout'));

        $jsRun = $this->runCode($project, 'javascript', "console.log('hi from js')");
        $jsRun->assertStatus(200);
        $this->assertSame('completed', $jsRun->json('status'), 'javascript was reported available -- it must actually run successfully');
        $this->assertStringContainsString("hi from js\n", (string) $jsRun->json('stdout'));
    }
}
