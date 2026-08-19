<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\CodingProject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 122-workspace-browser-ui, US3, T040 (contracts/workspace-change-
 * history-api.md, FR-008/FR-009/FR-010, Acceptance Scenarios 3-4).
 * GET coding-project/{project}/changes: most-recent-first ordering, the
 * flat {data, total, page, per_page} envelope (T006), unaffected-by-later-
 * deletion history entries (FR-010), and the uniform 404 shape for a
 * foreign or genuinely nonexistent project id (FR-013).
 */
class WorkspaceChangeHistoryEndpointTest extends TestCase
{
    private User $user;

    private User $otherUser;

    private CodingProject $project;

    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->otherUser = User::factory()->create();

        $this->projectDir = sys_get_temp_dir().'/coding-agent-change-history-'.Str::random(12);
        mkdir($this->projectDir, 0777, true);

        $this->project = CodingProject::create([
            'user_id' => $this->user->id,
            'name' => 'change history project',
            'root_path' => $this->projectDir,
            'test_command' => null,
        ]);
    }

    protected function tearDown(): void
    {
        DB::table('coding_workspace_changes')->delete();
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

    /**
     * Direct insert, bypassing WorkspaceChangeRecorder, so each row's
     * created_at is explicitly controlled -- necessary since the
     * database-level useCurrent() default has only second-level
     * resolution under SQLite, too coarse to prove ordering if rows were
     * instead produced by real, near-simultaneous calls in the same test.
     */
    private function insertChange(CodingProject $project, string $path, string $operation, string $createdAt): string
    {
        $id = (string) Str::uuid();

        DB::table('coding_workspace_changes')->insert([
            'id' => $id,
            'coding_project_id' => $project->id,
            'user_id' => $project->user_id,
            'root_path' => $project->root_path,
            'path' => $path,
            'operation' => $operation,
            'old_content' => null,
            'old_content_truncated' => false,
            'old_binary' => false,
            'old_size' => null,
            'new_content' => 'content for '.$path,
            'new_content_truncated' => false,
            'new_binary' => false,
            'new_size' => 10,
            'agent_id' => null,
            'agent_name' => null,
            'conversation_id' => null,
            'created_at' => $createdAt,
        ]);

        return $id;
    }

    #[Test]
    public function changes_are_ordered_most_recent_first(): void
    {
        $oldest = $this->insertChange($this->project, 'first.txt', 'created', '2026-08-01 00:00:00');
        $middle = $this->insertChange($this->project, 'second.txt', 'created', '2026-08-10 00:00:00');
        $newest = $this->insertChange($this->project, 'third.txt', 'created', '2026-08-15 00:00:00');

        $response = $this->actingAs($this->user)->getJson($this->apiUrl("coding-project/{$this->project->id}/changes"));

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$newest, $middle, $oldest], $ids, 'changes must be ordered most-recent-first, unconditionally');
    }

    #[Test]
    public function response_uses_the_flat_paginated_envelope_with_default_and_cap(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->insertChange($this->project, "file{$i}.txt", 'created', "2026-08-0{$i}");
        }

        $default = $this->actingAs($this->user)->getJson($this->apiUrl("coding-project/{$this->project->id}/changes"));
        $default->assertStatus(200);
        $default->assertJsonStructure(['data', 'total', 'page', 'per_page']);
        $this->assertSame(3, $default->json('total'));
        $this->assertSame(1, $default->json('page'));
        $this->assertSame(50, $default->json('per_page'));
        $this->assertCount(3, $default->json('data'));

        $capped = $this->actingAs($this->user)->getJson($this->apiUrl("coding-project/{$this->project->id}/changes?per_page=500"));
        $capped->assertStatus(200);
        $this->assertSame(100, $capped->json('per_page'));

        $floored = $this->actingAs($this->user)->getJson($this->apiUrl("coding-project/{$this->project->id}/changes?page=0&per_page=0"));
        $floored->assertStatus(200);
        $this->assertSame(1, $floored->json('page'));
        $this->assertSame(50, $floored->json('per_page'));
    }

    #[Test]
    public function zero_changes_returns_an_empty_data_array_with_zero_total(): void
    {
        $response = $this->actingAs($this->user)->getJson($this->apiUrl("coding-project/{$this->project->id}/changes"));

        $response->assertStatus(200);
        $response->assertJson(['data' => [], 'total' => 0]);
    }

    #[Test]
    public function a_change_to_a_file_that_was_later_deleted_from_disk_still_appears_unaffected(): void
    {
        file_put_contents($this->projectDir.'/will-be-removed.txt', 'content');
        $id = $this->insertChange($this->project, 'will-be-removed.txt', 'created', '2026-08-01 00:00:00');

        // The endpoint never consults the filesystem -- the file's later
        // removal from disk (not through the API, just directly, to prove
        // the endpoint truly never checks) must not affect the record.
        unlink($this->projectDir.'/will-be-removed.txt');

        $response = $this->actingAs($this->user)->getJson($this->apiUrl("coding-project/{$this->project->id}/changes"));

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($id, $ids, 'a change to a file that no longer exists on disk must remain in the history (FR-010)');
    }

    #[Test]
    public function a_foreign_owned_project_returns_the_same_404_shape_as_a_nonexistent_id(): void
    {
        $foreign = CodingProject::create([
            'user_id' => $this->otherUser->id,
            'name' => 'not yours',
            'root_path' => $this->projectDir,
            'test_command' => null,
        ]);

        $foreignResponse = $this->actingAs($this->user)->getJson($this->apiUrl("coding-project/{$foreign->id}/changes"));
        $absentResponse = $this->actingAs($this->user)->getJson($this->apiUrl('coding-project/'.((string) Str::uuid()).'/changes'));

        $foreignResponse->assertStatus(404);
        $foreignResponse->assertJson(['error' => 'Coding project not found', 'code' => 'coding_project_not_found']);
        $absentResponse->assertStatus(404);
        $this->assertSame($absentResponse->json(), $foreignResponse->json());
    }
}
