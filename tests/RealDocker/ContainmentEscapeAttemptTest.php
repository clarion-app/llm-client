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
 * 123-sandboxed-shell-execution, US1, T010 (research.md D1a/D3/D5,
 * quickstart.md Scenario 1.3/1.4, FR-002/FR-016, SC-001). Genuine `docker`
 * calls throughout -- no mocking anywhere in this file. Cases 1-2 drive
 * the real, registered `POST coding-project/{project}/run-command` HTTP
 * route with the real (non-swapped) DockerCommandExecutor, so a genuine
 * container is created, mounted, and torn down for each. Proves what
 * tests/Unit/Services/DockerCommandExecutorTest.php's mocked-process-
 * boundary assertions cannot: that the constructed flag set actually
 * produces real containment against a real Docker daemon, not merely
 * that the right strings appear in a command array.
 *
 * `tests/RealDatabase/RealDatabaseTestCase.php` is deliberately NOT the
 * base class here (tasks.md Grounding note 11) -- this feature needs no
 * different database at all (ordinary SQLite in-memory, exactly like
 * every other Feature test), only a real Docker daemon.
 */
#[Group('real-docker')]
class ContainmentEscapeAttemptTest extends TestCase
{
    private User $user;

    private string $projectDir;

    private string $siblingDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user, 'api');

        $this->projectDir = sys_get_temp_dir().'/coding-agent-containment-project-'.Str::random(12);
        $this->siblingDir = sys_get_temp_dir().'/coding-agent-containment-sibling-'.Str::random(12);
        mkdir($this->projectDir, 0777, true);
        mkdir($this->siblingDir, 0777, true);
    }

    protected function tearDown(): void
    {
        DB::table('coding_command_executions')->delete();
        DB::table('coding_workspace_refusals')->delete();
        DB::table('coding_projects')->delete();
        DB::table('users')->delete();

        foreach ([$this->projectDir, $this->siblingDir] as $dir) {
            if (is_dir($dir)) {
                $this->removeDirectory($dir);
            }
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
            'name' => 'containment-escape project',
            'root_path' => $rootPath,
            'test_command' => null,
        ]);
    }

    // -----------------------------------------------------------------
    // 1. Ordinary in-container reads are not accidentally over-blocked
    // (quickstart Scenario 1.3).
    // -----------------------------------------------------------------

    #[Test]
    public function an_ordinary_in_container_read_of_the_containers_own_file_succeeds(): void
    {
        $project = $this->registerProject($this->projectDir);

        $response = $this->postJson($this->apiUrl("coding-project/{$project->id}/run-command"), [
            'command' => 'cat /etc/hostname',
        ]);

        $response->assertStatus(200);
        $this->assertSame('completed', $response->json('status'));
        $this->assertSame(0, $response->json('exit_code'));
        $this->assertNotEmpty($response->json('stdout'), 'the container must be able to read its own, ordinary filesystem');
    }

    // -----------------------------------------------------------------
    // 2. A real host-sibling path outside the mount genuinely does not
    // exist in the container's mount namespace (quickstart Scenario 1.4,
    // AS2, FR-002, SC-001).
    // -----------------------------------------------------------------

    #[Test]
    public function a_read_of_a_real_host_sibling_path_outside_the_mount_genuinely_fails_and_its_content_never_leaks(): void
    {
        $secretContent = 'TOP-SECRET-SIBLING-CONTENT-'.Str::random(24);
        file_put_contents($this->siblingDir.'/secret.txt', $secretContent);
        $this->assertFileExists($this->siblingDir.'/secret.txt', 'fixture sanity: the sibling file must genuinely exist on the host');

        $project = $this->registerProject($this->projectDir);

        $response = $this->postJson($this->apiUrl("coding-project/{$project->id}/run-command"), [
            'command' => 'cat '.escapeshellarg($this->siblingDir.'/secret.txt'),
        ]);

        $response->assertStatus(200);
        $this->assertSame('completed', $response->json('status'));
        $this->assertNotSame(0, $response->json('exit_code'), 'reading a path that does not exist inside the container must be a real, nonzero failure');

        // The genuinely-absent-from-namespace proof: "No such file or
        // directory" is what a missing path produces -- a permission
        // failure (the path DID exist but access was denied) would
        // instead print "Permission denied", which this must never be.
        $stderr = (string) $response->json('stderr');
        $this->assertStringContainsString('No such file or directory', $stderr);
        $this->assertStringNotContainsString('Permission denied', $stderr);

        // The sibling's known content must never appear anywhere in the
        // response.
        $this->assertStringNotContainsString($secretContent, (string) $response->json('stdout'));
        $this->assertStringNotContainsString($secretContent, $stderr);
    }

    // -----------------------------------------------------------------
    // 3. Concurrency isolation (FR-016, Edge Case 6, research.md D3):
    // two simultaneous real containers, mounted at two different
    // registered workspaces holding a same-named-but-different-content
    // file, must never see each other's content.
    //
    // PHPUnit's own test runner is single-threaded, so "two genuine
    // commands dispatched at the same time" through the real HTTP+
    // controller stack cannot be produced from a single test method
    // without forking the whole framework/database state mid-test (an
    // approach RealDatabase's own concurrency tests reject for
    // essentially the same reason, using separate OS processes instead --
    // see tests/RealDatabase/DelegationConcurrencyTest.php). Here, the
    // property under test is Docker's own per-container mount-namespace
    // isolation (D3: "Docker already gives each container its own PID
    // namespace, network namespace..., and cgroup -- with no additional
    // locking code required"), which is exactly as real when the two
    // `docker run` invocations are started directly, non-blocking, via
    // Symfony Process::start() -- the identical flag shape
    // DockerCommandExecutor itself constructs -- against two genuinely
    // registered CodingProject workspaces, as it would be through the
    // full HTTP round-trip. This still never touches a mock: both are
    // real containers, started concurrently, torn down independently.
    // -----------------------------------------------------------------

    #[Test]
    public function two_concurrent_containers_against_different_registered_workspaces_never_see_each_others_content(): void
    {
        $dirA = sys_get_temp_dir().'/coding-agent-concurrency-a-'.Str::random(12);
        $dirB = sys_get_temp_dir().'/coding-agent-concurrency-b-'.Str::random(12);
        mkdir($dirA, 0777, true);
        mkdir($dirB, 0777, true);

        file_put_contents($dirA.'/data.txt', 'WORKSPACE-A-CONTENT-'.Str::random(12));
        file_put_contents($dirB.'/data.txt', 'WORKSPACE-B-CONTENT-'.Str::random(12));
        $contentA = file_get_contents($dirA.'/data.txt');
        $contentB = file_get_contents($dirB.'/data.txt');

        $projectA = $this->registerProject($dirA);
        $projectB = $this->registerProject($dirB);

        $image = (string) config('llm-client.coding_agent.command_image', 'alpine:latest');

        $buildCommand = function (CodingProject $project, string $name) use ($image) {
            return [
                'docker', 'run',
                '--rm',
                '--name', $name,
                '-v', $project->root_path.':/workspace:rw',
                '--read-only',
                '--tmpfs', '/tmp',
                '--security-opt', 'no-new-privileges',
                '--workdir', '/workspace',
                $image,
                'sh', '-c', 'cat data.txt',
            ];
        };

        $nameA = 'coding-cmd-concurrency-test-'.Str::random(8);
        $nameB = 'coding-cmd-concurrency-test-'.Str::random(8);

        $processA = new Process($buildCommand($projectA, $nameA));
        $processB = new Process($buildCommand($projectB, $nameB));

        // Started back-to-back, neither blocking on the other -- both
        // containers are genuinely running at the same time on the host.
        $processA->start();
        $processB->start();

        $processA->wait();
        $processB->wait();

        $this->assertSame(0, $processA->getExitCode(), 'container A must have run to completion: '.$processA->getErrorOutput());
        $this->assertSame(0, $processB->getExitCode(), 'container B must have run to completion: '.$processB->getErrorOutput());

        $this->assertStringContainsString($contentA, $processA->getOutput());
        $this->assertStringNotContainsString($contentB, $processA->getOutput(), "workspace A's container must never see workspace B's content, even when run concurrently");

        $this->assertStringContainsString($contentB, $processB->getOutput());
        $this->assertStringNotContainsString($contentA, $processB->getOutput(), "workspace B's container must never see workspace A's content, even when run concurrently");

        foreach ([$dirA, $dirB] as $dir) {
            $this->removeDirectory($dir);
        }
    }
}
