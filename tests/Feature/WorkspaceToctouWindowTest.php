<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Controllers\CodingWorkspaceController;
use ClarionApp\LlmClient\Models\CodingProject;
use ClarionApp\LlmClient\Services\WorkspaceFilePolicy;
use ClarionApp\LlmClient\Services\WorkspaceSearchService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Proves the genuine race window is closed, not merely a pre-placed
 * adversarial shape: a location approved by PathContainment::validate()
 * is swapped for a symlink to a secret in the narrow window between that
 * approval and the controller's own fopen(), and the operation must
 * still refuse.
 *
 * The production code re-derives nothing from a cached path -- there is
 * no artificial delay in-process to race against -- so simulating the
 * interleaving requires a minimal, explicit seam:
 * CodingWorkspaceController::beforeResolvedPathOpen(), a no-op hook every
 * real caller runs straight through, that only a partial Mockery mock
 * intercepts here. Everything downstream of that one hook -- the real
 * fopen()/fstat() call and its comparison against the identity captured
 * at approval time -- runs unmocked, so this test proves the actual
 * production identity check, not a stubbed outcome.
 */
class WorkspaceToctouWindowTest extends TestCase
{
    private User $user;

    private string $projectDir;

    private string $outsideDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user, 'api');

        $this->projectDir = sys_get_temp_dir().'/coding-agent-toctou-project-'.Str::random(12);
        $this->outsideDir = sys_get_temp_dir().'/coding-agent-toctou-outside-'.Str::random(12);
        mkdir($this->projectDir, 0777, true);
        mkdir($this->outsideDir, 0777, true);
    }

    protected function tearDown(): void
    {
        DB::table('coding_projects')->delete();
        DB::table('users')->delete();

        foreach ([$this->projectDir, $this->outsideDir] as $dir) {
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

    private function registerProject(string $rootPath): CodingProject
    {
        return CodingProject::create([
            'user_id' => $this->user->id,
            'name' => 'toctou project',
            'root_path' => $rootPath,
            'test_command' => null,
        ]);
    }

    private function apiUrl(string $suffix): string
    {
        return '/api/clarion-app/llm-client/'.$suffix;
    }

    /**
     * Binds a partial mock of CodingWorkspaceController into the
     * container, overriding only beforeResolvedPathOpen() -- every other
     * method, including readFile()'s/writeFile()'s own real fopen()/
     * fstat() logic, runs for real, unmocked.
     */
    private function installRaceSimulatingController(\Closure $swap): void
    {
        $controller = Mockery::mock(CodingWorkspaceController::class, [
            new WorkspaceSearchService(),
            new WorkspaceFilePolicy(),
        ])->makePartial();
        $controller->shouldAllowMockingProtectedMethods();
        $controller->shouldReceive('beforeResolvedPathOpen')
            ->once()
            ->andReturnUsing($swap);

        $this->app->instance(CodingWorkspaceController::class, $controller);
    }

    #[Test]
    public function a_read_target_swapped_for_a_symlink_between_validate_and_open_is_refused_not_read(): void
    {
        $secretPath = $this->outsideDir.'/secret-read.txt';
        file_put_contents($secretPath, 'TOCTOU SECRET READ CONTENT');

        file_put_contents($this->projectDir.'/race-target.txt', 'original, harmless content');

        $project = $this->registerProject($this->projectDir);

        $this->installRaceSimulatingController(function (string $resolvedPath) use ($secretPath) {
            // Simulates the exact race: swap the already-approved,
            // ordinary location for a symlink to a secret, in the window
            // between PathContainment::validate()'s approval and the
            // controller's own fopen().
            unlink($resolvedPath);
            symlink($secretPath, $resolvedPath);
        });

        $response = $this->getJson($this->apiUrl("coding-project/{$project->id}/file?path=race-target.txt"));

        $response->assertStatus(422);
        $this->assertSame('outside the registered project', $response->json('error'));
        $this->assertStringNotContainsString(
            'TOCTOU SECRET READ CONTENT',
            $response->getContent(),
            'the swapped-in secret must never reach the response',
        );
    }

    #[Test]
    public function a_write_target_swapped_for_a_symlink_between_validate_and_open_is_refused_not_overwritten(): void
    {
        $secretPath = $this->outsideDir.'/secret-write.txt';
        file_put_contents($secretPath, 'ORIGINAL OUTSIDE CONTENT');

        file_put_contents($this->projectDir.'/race-write-target.txt', 'original, harmless content');

        $project = $this->registerProject($this->projectDir);

        $this->installRaceSimulatingController(function (string $resolvedPath) use ($secretPath) {
            unlink($resolvedPath);
            symlink($secretPath, $resolvedPath);
        });

        $response = $this->postJson($this->apiUrl("coding-project/{$project->id}/file"), [
            'path' => 'race-write-target.txt',
            'content' => 'OVERWRITTEN BY RACE',
        ]);

        $response->assertStatus(422);
        $this->assertSame('outside the registered project', $response->json('error'));
        $this->assertSame(
            'ORIGINAL OUTSIDE CONTENT',
            file_get_contents($secretPath),
            'the swapped-in location must never actually be written through',
        );
    }

    #[Test]
    public function a_delete_target_swapped_for_a_symlink_between_validate_and_open_is_refused_not_deleted(): void
    {
        $secretPath = $this->outsideDir.'/secret-delete.txt';
        file_put_contents($secretPath, 'ORIGINAL OUTSIDE DELETE CONTENT');

        file_put_contents($this->projectDir.'/race-delete-target.txt', 'original, harmless content');

        $project = $this->registerProject($this->projectDir);

        $this->installRaceSimulatingController(function (string $resolvedPath) use ($secretPath) {
            unlink($resolvedPath);
            symlink($secretPath, $resolvedPath);
        });

        $response = $this->deleteJson($this->apiUrl("coding-project/{$project->id}/file?path=race-delete-target.txt"));

        $response->assertStatus(422);
        $this->assertSame('outside the registered project', $response->json('error'));
        $this->assertTrue(is_file($secretPath), 'the outside file must still exist after the refused delete');
        $this->assertSame(
            'ORIGINAL OUTSIDE DELETE CONTENT',
            file_get_contents($secretPath),
            'the outside file\'s content must be unchanged after the refused delete',
        );
    }
}
