<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\CodingProject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * 126-git-operations-confirmation, US1 (P1), T010 (contracts/git-inspection.md,
 * Grounding note 7's real-temp-git-repo convention).
 *
 * `git-status` and `git-diff` already exist and are exercised here only for
 * completeness/regression-pinning (mirrors ChangeReportDerivationTest's own
 * fixture style exactly). `git-log` is new for this feature and does not
 * exist yet at the time this file is written -- every git-log assertion
 * below is expected to fail against the current tree (no route registered),
 * which is the point: this file drives Phase 3's T011-T013 implementation.
 *
 * Never mocked -- every assertion here is checked against a real, throwaway
 * `git init`'d temp repository (or a real plain non-git temp directory), the
 * same convention GitOperationInspectorTest/ChangeReportDerivationTest both
 * already established.
 */
class GitInspectionJourneyTest extends TestCase
{
    private User $user;

    /** @var list<string> temp directories created by this test, removed in tearDown */
    private array $tmpDirs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            if (is_dir($dir)) {
                $this->removeDirectory($dir);
            }
        }

        DB::table('coding_projects')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // (1) All three endpoints: 200, correct content, and never mutate
    // the repository (AS1-AS4, FR-001, FR-002).
    // ---------------------------------------------------------------

    #[Test]
    public function status_diff_and_log_all_return_200_with_correct_content_and_never_mutate_the_repository(): void
    {
        $dir = $this->makeTempDir();

        $this->runGit($dir, ['init']);
        $this->runGit($dir, ['config', 'user.email', 'test@example.com']);
        $this->runGit($dir, ['config', 'user.name', 'Test Author']);
        $this->runGit($dir, ['config', 'commit.gpgsign', 'false']);

        file_put_contents($dir.'/existing.txt', "line one\n");
        $this->runGit($dir, ['add', '.']);
        $this->runGit($dir, ['commit', '-m', 'initial commit']);

        // One further, real, uncommitted edit on top of the single commit.
        file_put_contents($dir.'/existing.txt', "line one\nline two\n");

        $expectedHash = trim($this->shellGit($dir, ['rev-parse', 'HEAD']));
        $expectedShortHash = trim($this->shellGit($dir, ['rev-parse', '--short', 'HEAD']));
        $expectedAuthor = trim($this->shellGit($dir, ['log', '-1', '--format=%an']));
        $expectedDate = trim($this->shellGit($dir, ['log', '-1', '--format=%ad', '--date=iso-strict']));
        $expectedSubject = trim($this->shellGit($dir, ['log', '-1', '--format=%s']));
        $expectedStatus = $this->shellGit($dir, ['status', '--porcelain=v1']);
        $expectedDiff = $this->shellGit($dir, ['diff']);

        $this->assertStringContainsString('existing.txt', $expectedStatus, 'sanity: the real git status must show the uncommitted edit');
        $this->assertStringContainsString('line two', $expectedDiff, 'sanity: the real git diff must show the modified line');

        $project = CodingProject::create([
            'user_id' => $this->user->id,
            'name' => 'git project',
            'root_path' => $dir,
            'test_command' => null,
        ]);

        foreach (['git-status', 'git-diff', 'git-log'] as $endpoint) {
            $beforeHead = trim($this->shellGit($dir, ['rev-parse', 'HEAD']));
            $beforeStatus = $this->shellGit($dir, ['status', '--porcelain=v1']);

            $response = $this->actingAs($this->user, 'api')
                ->getJson("/api/clarion-app/llm-client/coding-project/{$project->id}/{$endpoint}");

            $afterHead = trim($this->shellGit($dir, ['rev-parse', 'HEAD']));
            $afterStatus = $this->shellGit($dir, ['status', '--porcelain=v1']);

            $this->assertSame($beforeHead, $afterHead, "{$endpoint} must never mutate HEAD");
            $this->assertSame($beforeStatus, $afterStatus, "{$endpoint} must never mutate the working tree");

            $response->assertStatus(200, "{$endpoint} must return 200 and never pause for confirmation");

            match ($endpoint) {
                'git-status' => $this->assertStatusContentMatches($response, $expectedStatus),
                'git-diff' => $this->assertDiffContentMatches($response, $expectedDiff),
                'git-log' => $this->assertLogContentMatches($response, $expectedHash, $expectedShortHash, $expectedAuthor, $expectedDate, $expectedSubject),
            };
        }
    }

    private function assertStatusContentMatches($response, string $expectedStatus): void
    {
        $response->assertJson(['is_git_repo' => true]);
        $this->assertSame($expectedStatus, $response->json('porcelain'));
    }

    private function assertDiffContentMatches($response, string $expectedDiff): void
    {
        $response->assertJson(['is_git_repo' => true]);
        $this->assertSame($expectedDiff, $response->json('diff'));
    }

    private function assertLogContentMatches($response, string $expectedHash, string $expectedShortHash, string $expectedAuthor, string $expectedDate, string $expectedSubject): void
    {
        $response->assertJson(['is_git_repo' => true]);

        $entries = $response->json('entries');
        $this->assertIsArray($entries);
        $this->assertCount(1, $entries, 'exactly one commit exists in this fixture');
        $this->assertSame($expectedHash, $entries[0]['hash'] ?? null);
        $this->assertSame($expectedShortHash, $entries[0]['short_hash'] ?? null);
        $this->assertSame($expectedAuthor, $entries[0]['author'] ?? null);
        $this->assertSame($expectedDate, $entries[0]['date'] ?? null);
        $this->assertSame($expectedSubject, $entries[0]['subject'] ?? null);
    }

    // ---------------------------------------------------------------
    // (2) git-log against a freshly git init'd, zero-commit repo --
    // never an error, never is_git_repo:false (Edge case 6).
    // ---------------------------------------------------------------

    #[Test]
    public function git_log_against_a_fresh_zero_commit_repository_returns_is_git_repo_true_with_empty_entries(): void
    {
        $dir = $this->makeTempDir();
        $this->runGit($dir, ['init']);

        $project = CodingProject::create([
            'user_id' => $this->user->id,
            'name' => 'zero-commit project',
            'root_path' => $dir,
            'test_command' => null,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/coding-project/{$project->id}/git-log");

        $response->assertStatus(200);
        $response->assertExactJson(['is_git_repo' => true, 'entries' => []]);
    }

    // ---------------------------------------------------------------
    // (3) All three endpoints against a plain, non-git directory --
    // is_git_repo:false, 200, distinguishable from case (2)'s result
    // (Edge case 1, FR-010, SC-006).
    // ---------------------------------------------------------------

    #[Test]
    public function all_three_endpoints_report_is_git_repo_false_for_a_plain_non_git_directory(): void
    {
        $dir = $this->makeTempDir();

        $project = CodingProject::create([
            'user_id' => $this->user->id,
            'name' => 'plain directory project',
            'root_path' => $dir,
            'test_command' => null,
        ]);

        foreach (['git-status', 'git-diff', 'git-log'] as $endpoint) {
            $response = $this->actingAs($this->user, 'api')
                ->getJson("/api/clarion-app/llm-client/coding-project/{$project->id}/{$endpoint}");

            $response->assertStatus(200, "{$endpoint} must report is_git_repo:false via 200, never an error");
            // Deliberately NOT is_git_repo:true/entries:[] -- that shape is
            // reserved for a real, empty repository (case 2). This is a
            // directory with no `.git` at all.
            $response->assertExactJson(['is_git_repo' => false]);
        }
    }

    // ---------------------------------------------------------------
    // (4) git-log's `limit` query param: default / clamp / degrade.
    // Config overridden per-test to small values so the fixture only
    // needs a handful of commits to prove both the default-count and
    // clamp-to-max behaviors, rather than depending on production
    // defaults (50/200).
    // ---------------------------------------------------------------

    #[Test]
    public function git_log_limit_defaults_clamps_to_max_and_degrades_gracefully_never_a_5xx(): void
    {
        $this->app['config']->set('llm-client.coding_agent.git.log_default_limit', 5);
        $this->app['config']->set('llm-client.coding_agent.git.log_max_limit', 8);

        $dir = $this->makeTempDir();
        $this->runGit($dir, ['init']);
        $this->runGit($dir, ['config', 'user.email', 'test@example.com']);
        $this->runGit($dir, ['config', 'user.name', 'Test Author']);
        $this->runGit($dir, ['config', 'commit.gpgsign', 'false']);

        // More commits than either the configured default or the
        // configured max, so both the default-count and the
        // clamp-to-max behaviors are actually observable.
        for ($i = 1; $i <= 12; $i++) {
            $this->runGit($dir, ['commit', '--allow-empty', '-m', "commit {$i}"]);
        }

        $project = CodingProject::create([
            'user_id' => $this->user->id,
            'name' => 'many-commits project',
            'root_path' => $dir,
            'test_command' => null,
        ]);

        $baseUrl = "/api/clarion-app/llm-client/coding-project/{$project->id}/git-log";

        $default = $this->actingAs($this->user, 'api')->getJson($baseUrl);
        $default->assertStatus(200);
        $this->assertLessThan(500, $default->getStatusCode());
        $this->assertCount(5, $default->json('entries'), 'omitted limit must fall back to the configured default (5), not all 12 commits');

        $oversized = $this->actingAs($this->user, 'api')->getJson($baseUrl.'?limit=9999');
        $oversized->assertStatus(200);
        $this->assertLessThan(500, $oversized->getStatusCode());
        $this->assertCount(8, $oversized->json('entries'), 'an oversized limit must clamp to the configured max (8), never all 12 commits');

        $negative = $this->actingAs($this->user, 'api')->getJson($baseUrl.'?limit=-1');
        $this->assertLessThan(500, $negative->getStatusCode(), 'a negative limit must never produce a 5xx');
        $negative->assertStatus(200);
        $this->assertCount(5, $negative->json('entries'), 'a negative limit must degrade to the configured default');

        $nonNumeric = $this->actingAs($this->user, 'api')->getJson($baseUrl.'?limit=not-a-number');
        $this->assertLessThan(500, $nonNumeric->getStatusCode(), 'a non-numeric limit must never produce a 5xx');
        $nonNumeric->assertStatus(200);
        $this->assertCount(5, $nonNumeric->json('entries'), 'a non-numeric limit must degrade to the configured default');
    }

    // ---------------------------------------------------------------
    // (5) 404 for an absent or foreign-owned project id, on all three
    // endpoints.
    // ---------------------------------------------------------------

    #[Test]
    public function all_three_endpoints_404_for_an_absent_or_foreign_owned_project(): void
    {
        $otherUser = User::factory()->create();

        $dir = $this->makeTempDir();
        $this->runGit($dir, ['init']);

        $foreignProject = CodingProject::create([
            'user_id' => $otherUser->id,
            'name' => 'foreign project',
            'root_path' => $dir,
            'test_command' => null,
        ]);

        $notFound = ['error' => 'Coding project not found', 'code' => 'coding_project_not_found'];
        $absentId = (string) Str::uuid();

        foreach (['git-status', 'git-diff', 'git-log'] as $endpoint) {
            $this->actingAs($this->user, 'api')
                ->getJson("/api/clarion-app/llm-client/coding-project/{$foreignProject->id}/{$endpoint}")
                ->assertStatus(404)
                ->assertJson($notFound);

            $this->actingAs($this->user, 'api')
                ->getJson("/api/clarion-app/llm-client/coding-project/{$absentId}/{$endpoint}")
                ->assertStatus(404)
                ->assertJson($notFound);
        }
    }

    // ---------------------------------------------------------------
    // (6) None of gitStatus/gitDiff/gitLog's operationIds appear in
    // coding.yaml's safety.confirmation_required list -- the structural
    // proof underlying "inspection never pauses" (SC-001, research.md
    // D2). This is a pure config-file check, independent of the gitLog
    // route/method existing yet.
    // ---------------------------------------------------------------

    #[Test]
    public function none_of_the_inspection_operation_ids_are_listed_in_confirmation_required(): void
    {
        $yaml = Yaml::parseFile(__DIR__.'/../../src/Templates/coding.yaml');
        $confirmationRequired = $yaml['safety']['confirmation_required'] ?? [];

        $this->assertIsArray($confirmationRequired);
        $this->assertNotContains('clarionApp.llmClient.codingWorkspace.gitStatus', $confirmationRequired);
        $this->assertNotContains('clarionApp.llmClient.codingWorkspace.gitDiff', $confirmationRequired);
        $this->assertNotContains('clarionApp.llmClient.codingWorkspace.gitLog', $confirmationRequired);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir().'/coding-agent-git-inspection-'.Str::random(12);
        mkdir($dir, 0777, true);
        $this->tmpDirs[] = $dir;

        return $dir;
    }

    private function runGit(string $dir, array $args): void
    {
        (new Process(array_merge(['git'], $args), $dir))->mustRun();
    }

    private function shellGit(string $dir, array $args): string
    {
        $process = new Process(array_merge(['git'], $args), $dir);
        $process->run();

        return $process->getOutput();
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
