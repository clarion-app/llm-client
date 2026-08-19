<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\CodingProject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 123-sandboxed-shell-execution, US2, T023 (contracts/command-allowlist.md
 * §1). Drives the real, registered `PATCH coding-project/{id}/command-
 * allowlist` HTTP route through the real CodingProjectController -- no
 * agent loop, no Docker, no mocking; this contract's own surface is
 * entirely CRUD-shaped, ownership-checked identically to
 * updateConfirmationSetting().
 *
 * Written before CodingProjectController::updateCommandAllowlist() exists
 * -- expected to FAIL red (route not found) until T025/T027 land.
 */
class CommandAllowlistJourneyTest extends TestCase
{
    private User $user;

    private User $otherUser;

    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->otherUser = User::factory()->create();
        $this->actingAs($this->user, 'api');

        $this->projectDir = sys_get_temp_dir().'/coding-agent-allowlist-'.Str::random(12);
        mkdir($this->projectDir, 0777, true);
    }

    protected function tearDown(): void
    {
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

    private function registerProject(string $rootPath, ?User $owner = null): CodingProject
    {
        return CodingProject::create([
            'user_id' => ($owner ?? $this->user)->id,
            'name' => 'allowlist project',
            'root_path' => $rootPath,
            'test_command' => null,
        ]);
    }

    // -----------------------------------------------------------------
    // Replacing the whole list (FR-004/FR-005, contracts §1)
    // -----------------------------------------------------------------

    #[Test]
    public function patching_the_allowlist_returns_200_with_the_full_updated_project(): void
    {
        $project = $this->registerProject($this->projectDir);

        $response = $this->patchJson($this->apiUrl("coding-project/{$project->id}/command-allowlist"), [
            'patterns' => ['git status', 'git diff', 'phpunit', 'npm test *'],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('id', $project->id);
        $response->assertJsonPath('command_allowlist', ['git status', 'git diff', 'phpunit', 'npm test *']);

        $this->assertSame(
            ['git status', 'git diff', 'phpunit', 'npm test *'],
            $project->fresh()->command_allowlist,
        );
    }

    #[Test]
    public function patching_replaces_the_entire_list_rather_than_adding_incrementally(): void
    {
        $project = $this->registerProject($this->projectDir);

        $this->patchJson($this->apiUrl("coding-project/{$project->id}/command-allowlist"), [
            'patterns' => ['git status', 'git diff'],
        ])->assertStatus(200);

        $this->patchJson($this->apiUrl("coding-project/{$project->id}/command-allowlist"), [
            'patterns' => ['phpunit'],
        ])->assertStatus(200);

        $this->assertSame(
            ['phpunit'],
            $project->fresh()->command_allowlist,
            'a second PATCH must replace the whole list, never merge with the first',
        );
    }

    #[Test]
    public function duplicate_patterns_in_the_submitted_array_are_deduplicated_on_write(): void
    {
        $project = $this->registerProject($this->projectDir);

        $response = $this->patchJson($this->apiUrl("coding-project/{$project->id}/command-allowlist"), [
            'patterns' => ['git status', 'git status', 'phpunit', 'phpunit'],
        ]);

        $response->assertStatus(200);
        $stored = $project->fresh()->command_allowlist;
        $this->assertCount(2, $stored, 'duplicates must be silently deduplicated, not rejected as an error');
        $this->assertEqualsCanonicalizing(['git status', 'phpunit'], $stored);
    }

    #[Test]
    public function an_empty_patterns_array_is_accepted_and_clears_the_allowlist(): void
    {
        $project = $this->registerProject($this->projectDir);
        $project->update(['command_allowlist' => ['git status']]);

        $response = $this->patchJson($this->apiUrl("coding-project/{$project->id}/command-allowlist"), [
            'patterns' => [],
        ]);

        $response->assertStatus(200);
        $this->assertSame([], $project->fresh()->command_allowlist);
    }

    // -----------------------------------------------------------------
    // Ownership (404)
    // -----------------------------------------------------------------

    #[Test]
    public function patching_an_absent_project_id_returns_404(): void
    {
        $response = $this->patchJson($this->apiUrl('coding-project/'.Str::uuid().'/command-allowlist'), [
            'patterns' => ['git status'],
        ]);

        $response->assertStatus(404);
        $response->assertJson(['error' => 'Coding project not found', 'code' => 'coding_project_not_found']);
    }

    #[Test]
    public function patching_a_foreign_owned_project_returns_404(): void
    {
        $foreignProject = $this->registerProject($this->projectDir, $this->otherUser);

        $response = $this->patchJson($this->apiUrl("coding-project/{$foreignProject->id}/command-allowlist"), [
            'patterns' => ['git status'],
        ]);

        $response->assertStatus(404);
        $response->assertJson(['error' => 'Coding project not found', 'code' => 'coding_project_not_found']);
        $this->assertNull($foreignProject->fresh()->command_allowlist, 'a foreign-owned project must never be modified');
    }

    // -----------------------------------------------------------------
    // Validation (422)
    // -----------------------------------------------------------------

    #[Test]
    public function a_missing_patterns_field_is_rejected_with_422(): void
    {
        $project = $this->registerProject($this->projectDir);

        $response = $this->patchJson($this->apiUrl("coding-project/{$project->id}/command-allowlist"), []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['patterns']);
    }

    #[Test]
    public function a_non_array_patterns_field_is_rejected_with_422(): void
    {
        $project = $this->registerProject($this->projectDir);

        $response = $this->patchJson($this->apiUrl("coding-project/{$project->id}/command-allowlist"), [
            'patterns' => 'git status',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['patterns']);
    }

    #[Test]
    public function a_non_string_element_is_rejected_with_422(): void
    {
        $project = $this->registerProject($this->projectDir);

        $response = $this->patchJson($this->apiUrl("coding-project/{$project->id}/command-allowlist"), [
            'patterns' => ['git status', 123],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['patterns.1']);
    }

    #[Test]
    public function an_empty_string_element_is_rejected_with_422(): void
    {
        $project = $this->registerProject($this->projectDir);

        $response = $this->patchJson($this->apiUrl("coding-project/{$project->id}/command-allowlist"), [
            'patterns' => ['git status', ''],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['patterns.1']);
    }

    // -----------------------------------------------------------------
    // Cross-workspace isolation (SC-007)
    // -----------------------------------------------------------------

    #[Test]
    public function setting_one_workspaces_allowlist_has_zero_effect_on_a_second_workspaces_own_allowlist(): void
    {
        $projectA = $this->registerProject($this->projectDir);
        $projectBDir = sys_get_temp_dir().'/coding-agent-allowlist-b-'.Str::random(12);
        mkdir($projectBDir, 0777, true);
        $projectB = $this->registerProject($projectBDir);
        $projectB->update(['command_allowlist' => ['phpunit']]);

        $this->patchJson($this->apiUrl("coding-project/{$projectA->id}/command-allowlist"), [
            'patterns' => ['git status'],
        ])->assertStatus(200);

        $this->assertSame(['git status'], $projectA->fresh()->command_allowlist);
        $this->assertSame(
            ['phpunit'],
            $projectB->fresh()->command_allowlist,
            'workspace B\'s own allowlist must be completely unaffected by workspace A\'s PATCH',
        );

        $this->removeDirectory($projectBDir);
    }
}
