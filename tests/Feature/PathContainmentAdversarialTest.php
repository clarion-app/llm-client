<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\CodingProject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 112-coding-agent, US4 (P2), T038 (D4, FR-007, quickstart row 7,
 * mutation-checklist rows 4/5).
 *
 * Drives PathContainment (Foundational, T010) through the ACTUAL,
 * registered CodingWorkspaceController HTTP routes -- not the Phase 2
 * unit-level PathContainmentTest, which exercises the service directly --
 * against real attack shapes built on the real filesystem: a literal
 * traversal segment, a real symlink created inside the registered project
 * pointing outside it, a write whose parent directory does not exist, and
 * a project whose root directory has been physically removed from disk
 * after registration. Every case proves both that the request is refused
 * AND that nothing outside the project boundary was ever read or written.
 */
class PathContainmentAdversarialTest extends TestCase
{
    private User $user;

    private string $projectDir;

    private string $outsideDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user, 'api');

        $this->projectDir = sys_get_temp_dir().'/coding-agent-containment-project-'.Str::random(12);
        $this->outsideDir = sys_get_temp_dir().'/coding-agent-containment-outside-'.Str::random(12);
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
            'name' => 'adversarial project',
            'root_path' => $rootPath,
            'test_command' => null,
        ]);
    }

    private function apiUrl(string $suffix): string
    {
        return '/api/clarion-app/llm-client/'.$suffix;
    }

    // -----------------------------------------------------------------
    // A literal "../" traversal
    // -----------------------------------------------------------------

    #[Test]
    public function a_traversal_path_is_refused_and_the_outside_file_is_never_read(): void
    {
        file_put_contents($this->outsideDir.'/secret.txt', 'TOP SECRET CONTENT');
        file_put_contents($this->projectDir.'/inside.txt', 'inside content');

        $project = $this->registerProject($this->projectDir);

        // A real relative traversal reaching from the project root out to
        // a sibling directory's file.
        $traversal = '../'.basename($this->outsideDir).'/secret.txt';
        $query = http_build_query(['path' => $traversal]);

        $response = $this->getJson($this->apiUrl("coding-project/{$project->id}/file?{$query}"));

        $response->assertStatus(422);
        $this->assertSame('path traversal', $response->json('error'));
        $this->assertStringNotContainsString('TOP SECRET', $response->getContent(), 'the outside file\'s content must never reach the response');
    }

    // -----------------------------------------------------------------
    // A real symlink inside the project pointing outside it
    // -----------------------------------------------------------------

    #[Test]
    public function a_real_symlink_pointing_outside_the_project_is_refused_and_never_followed(): void
    {
        file_put_contents($this->outsideDir.'/secret.txt', 'TOP SECRET CONTENT');

        $project = $this->registerProject($this->projectDir);

        $linkPath = $this->projectDir.'/escape-link.txt';
        symlink($this->outsideDir.'/secret.txt', $linkPath);
        $this->assertTrue(is_link($linkPath), 'fixture sanity: the symlink must genuinely exist on disk before the request is made');

        $response = $this->getJson($this->apiUrl("coding-project/{$project->id}/file?path=escape-link.txt"));

        $response->assertStatus(422);
        $this->assertSame(
            'outside the registered project',
            $response->json('error'),
            'realpath() must resolve through the symlink and reject it exactly like a raw traversal, not string-match the literal path',
        );
        $this->assertStringNotContainsString('TOP SECRET', $response->getContent(), 'the symlink must never actually be followed to read its target');
    }

    // -----------------------------------------------------------------
    // A write whose parent directory does not exist
    // -----------------------------------------------------------------

    #[Test]
    public function a_write_whose_parent_directory_does_not_exist_is_refused_and_nothing_is_created(): void
    {
        $project = $this->registerProject($this->projectDir);

        $response = $this->postJson($this->apiUrl("coding-project/{$project->id}/file"), [
            'path' => 'no-such-subdir/new-file.txt',
            'content' => 'should never be written',
        ]);

        $response->assertStatus(422);
        $this->assertSame('not found', $response->json('error'));
        $this->assertFalse(is_dir($this->projectDir.'/no-such-subdir'), 'the missing parent directory must never be created as a side effect');
        $this->assertFalse(is_file($this->projectDir.'/no-such-subdir/new-file.txt'));
    }

    // -----------------------------------------------------------------
    // A project whose root directory has been removed from disk
    // -----------------------------------------------------------------

    #[Test]
    public function a_project_whose_root_directory_was_removed_from_disk_is_refused_with_a_distinct_not_reachable_reason(): void
    {
        $goneDir = sys_get_temp_dir().'/coding-agent-containment-gone-'.Str::random(12);
        mkdir($goneDir, 0777, true);

        $project = $this->registerProject($goneDir);

        // Removed AFTER registration -- proves the check is re-evaluated
        // live on every call, not cached or trusted from registration time
        // (data-model.md §3: "no time-of-check-to-time-of-use gap").
        rmdir($goneDir);
        $this->assertFalse(is_dir($goneDir), 'fixture sanity: the directory must genuinely be gone before the request is made');

        $response = $this->getJson($this->apiUrl("coding-project/{$project->id}/files"));

        $response->assertStatus(422);
        $this->assertSame(
            'project directory is not reachable',
            $response->json('error'),
            'a gone project directory must produce a reason distinct from an ordinary path-containment failure, checked before path resolution begins',
        );
    }
}
