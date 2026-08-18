<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\CodingProject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 120-workspace-file-tools, Phase 3 User Story 1, T013
 * (contracts/workspace-search-operations.md §1-§2, spec.md Acceptance
 * Scenarios 1-5, quickstart Scenario 1). Drives the two new search
 * operations through real HTTP routes on the real, registered
 * CodingWorkspaceController -- not the Unit-level WorkspaceSearchServiceTest,
 * which exercises the service directly -- mirroring
 * PathContainmentAdversarialTest's own real-filesystem fixture shape.
 */
class WorkspaceSearchJourneyTest extends TestCase
{
    private User $user;

    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user, 'api');

        $this->projectDir = sys_get_temp_dir().'/workspace-search-journey-'.Str::random(12);
        mkdir($this->projectDir, 0777, true);
    }

    protected function tearDown(): void
    {
        DB::table('coding_projects')->delete();
        DB::table('users')->delete();

        $this->removeDirectory($this->projectDir);

        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

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

    private function registerProject(string $rootPath, ?User $owner = null): CodingProject
    {
        return CodingProject::create([
            'user_id' => ($owner ?? $this->user)->id,
            'name' => 'search journey project',
            'root_path' => $rootPath,
            'test_command' => null,
        ]);
    }

    private function write(string $relativePath, string $content): void
    {
        $full = $this->projectDir.'/'.$relativePath;
        @mkdir(dirname($full), 0777, true);
        file_put_contents($full, $content);
    }

    private function apiUrl(string $suffix): string
    {
        return '/api/clarion-app/llm-client/'.$suffix;
    }

    // -----------------------------------------------------------------
    // AS1 -- filename-pattern search
    // -----------------------------------------------------------------

    #[Test]
    public function as1_a_filename_pattern_search_returns_only_the_matching_files_within_the_workspace(): void
    {
        $this->write('src/A.php', '<?php // A');
        $this->write('src/B.php', '<?php // B');
        $this->write('docs/readme.md', '# readme');
        $this->write('notes.txt', 'not php');

        $project = $this->registerProject($this->projectDir);

        $response = $this->getJson($this->apiUrl("coding-project/{$project->id}/search-files?pattern=*.php"));

        $response->assertStatus(200);
        $paths = array_column($response->json('matches'), 'path');
        sort($paths);

        $this->assertSame(['src/A.php', 'src/B.php'], $paths);
        $this->assertStringNotContainsString('.txt', $response->getContent());
        $this->assertStringNotContainsString('readme.md', $response->getContent());
    }

    // -----------------------------------------------------------------
    // AS2 -- content search with line/snippet
    // -----------------------------------------------------------------

    #[Test]
    public function as2_a_content_search_returns_the_matching_file_line_and_snippet(): void
    {
        $term = 'UNIQUE_SEARCH_TERM_XYZ';
        $this->write('docs/readme.md', "line one\nline two contains {$term} here\nline three");

        $project = $this->registerProject($this->projectDir);

        $response = $this->getJson($this->apiUrl("coding-project/{$project->id}/search-content?query={$term}"));

        $response->assertStatus(200);
        $matches = $response->json('matches');
        $this->assertCount(1, $matches);
        $this->assertSame('docs/readme.md', $matches[0]['path']);
        $this->assertSame(2, $matches[0]['line']);
        $this->assertStringContainsString($term, $matches[0]['snippet']);
    }

    // -----------------------------------------------------------------
    // AS3/SC-002 -- bounded, truncated result against a large workspace
    // -----------------------------------------------------------------

    #[Test]
    public function as3_a_search_against_thousands_of_files_completes_bounded_and_truncated(): void
    {
        for ($i = 0; $i < 1200; $i++) {
            $this->write("filler{$i}.txt", "some text with the letter e in it\n");
        }

        $project = $this->registerProject($this->projectDir);

        $start = microtime(true);
        $response = $this->getJson($this->apiUrl("coding-project/{$project->id}/search-content?query=e"));
        $elapsed = microtime(true) - $start;

        $response->assertStatus(200);
        $response->assertJsonPath('truncated', true);

        $maxResults = (int) config('llm-client.coding_agent.search.max_results', 100);
        $this->assertLessThanOrEqual($maxResults, count($response->json('matches')));
        $this->assertLessThan(30.0, $elapsed, 'a search against a large workspace must complete in a reasonable time, not time out');
    }

    // -----------------------------------------------------------------
    // AS4/FR-005 -- empty match is a clean 200, never an error
    // -----------------------------------------------------------------

    #[Test]
    public function as4_a_query_matching_nothing_returns_a_clean_empty_result(): void
    {
        $this->write('a.txt', 'nothing relevant here');

        $project = $this->registerProject($this->projectDir);

        $response = $this->getJson($this->apiUrl("coding-project/{$project->id}/search-content?query=NO_SUCH_TERM_ANYWHERE_XYZ"));

        $response->assertStatus(200);
        $response->assertExactJson([
            'matches' => [],
            'truncated' => false,
            'files_scanned' => 1,
            'skipped_binary_count' => 0,
        ]);
    }

    // -----------------------------------------------------------------
    // AS5/FR-004 -- cross-user isolation, both endpoints independently
    // -----------------------------------------------------------------

    #[Test]
    public function as5_search_files_on_another_users_project_returns_404_and_leaks_nothing(): void
    {
        $otherUser = User::factory()->create();
        $this->write('secret-plan.php', '<?php // TOP SECRET PLAN');
        $otherProject = $this->registerProject($this->projectDir, $otherUser);

        $response = $this->getJson($this->apiUrl("coding-project/{$otherProject->id}/search-files?pattern=*.php"));

        $response->assertStatus(404);
        $this->assertSame('Coding project not found', $response->json('error'));
        $this->assertStringNotContainsString('secret-plan', $response->getContent());
    }

    #[Test]
    public function as5_search_content_on_another_users_project_returns_404_and_leaks_nothing(): void
    {
        $otherUser = User::factory()->create();
        $this->write('secret.txt', 'TOP SECRET CONTENT MARKER');
        $otherProject = $this->registerProject($this->projectDir, $otherUser);

        $response = $this->getJson($this->apiUrl("coding-project/{$otherProject->id}/search-content?query=SECRET"));

        $response->assertStatus(404);
        $this->assertSame('Coding project not found', $response->json('error'));
        $this->assertStringNotContainsString('MARKER', $response->getContent());
    }
}
