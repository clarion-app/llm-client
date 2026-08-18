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

    // -----------------------------------------------------------------
    // 120-workspace-file-tools, Phase 3 (US1), T015: search-specific
    // adversarial cases extending this file's own fixture shape (Grounding
    // note 10) rather than a new file.
    // -----------------------------------------------------------------

    #[Test]
    public function search_content_never_follows_a_symlink_to_a_file_outside_the_project(): void
    {
        file_put_contents($this->outsideDir.'/secret.txt', 'TOP SECRET CONTENT');

        $project = $this->registerProject($this->projectDir);

        $linkPath = $this->projectDir.'/escape-link.txt';
        symlink($this->outsideDir.'/secret.txt', $linkPath);
        $this->assertTrue(is_link($linkPath), 'fixture sanity: the symlink must genuinely exist on disk before the request is made');

        $response = $this->getJson($this->apiUrl("coding-project/{$project->id}/search-content?query=secret&pattern=*"));

        $response->assertStatus(200);
        $paths = array_column($response->json('matches'), 'path');
        $this->assertNotContains('escape-link.txt', $paths, 'the symlinked path must never appear as a match');
        $this->assertStringNotContainsString('TOP SECRET', $response->getContent(), 'the secret content must never reach the response, mirroring the existing symlink test\'s own assertion style');
    }

    #[Test]
    public function search_files_with_a_traversal_subpath_is_refused_with_the_identical_reason_string(): void
    {
        $project = $this->registerProject($this->projectDir);

        $response = $this->getJson($this->apiUrl("coding-project/{$project->id}/search-files?pattern=*&subpath=../"));

        $response->assertStatus(422);
        $this->assertSame(
            'path traversal',
            $response->json('error'),
            'search must return the identical reason string every existing operation already returns for this exact input'
        );
    }

    #[Test]
    public function search_files_against_a_vanished_project_directory_is_refused_with_the_identical_reason_string(): void
    {
        $goneDir = sys_get_temp_dir().'/coding-agent-containment-gone-search-'.Str::random(12);
        mkdir($goneDir, 0777, true);

        $project = $this->registerProject($goneDir);

        rmdir($goneDir);
        $this->assertFalse(is_dir($goneDir), 'fixture sanity: the directory must genuinely be gone before the request is made');

        $response = $this->getJson($this->apiUrl("coding-project/{$project->id}/search-files?pattern=*"));

        $response->assertStatus(422);
        $this->assertSame(
            'project directory is not reachable',
            $response->json('error'),
            'search must return the identical distinct reason every existing operation already returns for a vanished workspace'
        );
    }

    #[Test]
    public function workspace_search_service_calls_path_containment_validate_with_the_same_three_argument_shape_no_parallel_check(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/src/Services/WorkspaceSearchService.php');
        $this->assertNotFalse($source, 'fixture sanity: WorkspaceSearchService.php must be readable');

        $callSitePattern = '/PathContainment::validate\(\s*\$?[\w>\-\.]+(->\w+)?,\s*\$?\w+,\s*true\s*\)/';

        $this->assertMatchesRegularExpression(
            $callSitePattern,
            $source,
            'WorkspaceSearchService must call PathContainment::validate() with the same three-argument shape (rootPath, candidatePath, targetMustExist) every existing call site uses, never a second/parallel containment implementation'
        );

        preg_match_all($callSitePattern, $source, $matches);

        $this->assertCount(
            4,
            $matches[0],
            'exactly four real call sites — one subpath check and one per-result re-check in each of searchFiles()/searchContent() — no parallel or alternate containment implementation (a bare mention in a doc comment does not count, since it takes no arguments and cannot match this argument-shaped pattern)'
        );
    }
}
