<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\CodingProject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 122-workspace-browser-ui, US3, T041 (research.md D7, FR-011, mutation
 * checklist row 5). changes() is the one deliberate exception to
 * CodingWorkspaceController's usual findOwnedProject() ownership pattern:
 * it must keep resolving a soft-deleted (removed) project via
 * withTrashed(), so a workspace's change history stays reviewable after
 * the workspace itself is removed -- while every other method on the
 * controller correctly continues to 404 against that same removed
 * workspace, proving this test suite cannot be satisfied by accidentally
 * loosening the whole controller instead of just changes().
 */
class WorkspaceChangeHistoryAfterRemovalTest extends TestCase
{
    private User $user;

    private CodingProject $project;

    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->projectDir = sys_get_temp_dir().'/coding-agent-history-after-removal-'.Str::random(12);
        mkdir($this->projectDir, 0777, true);
        file_put_contents($this->projectDir.'/tracked.txt', 'content');

        $this->project = CodingProject::create([
            'user_id' => $this->user->id,
            'name' => 'removed-later project',
            'root_path' => $this->projectDir,
            'test_command' => null,
        ]);

        DB::table('coding_workspace_changes')->insert([
            'id' => (string) Str::uuid(),
            'coding_project_id' => $this->project->id,
            'user_id' => $this->project->user_id,
            'root_path' => $this->project->root_path,
            'path' => 'tracked.txt',
            'operation' => 'created',
            'old_content' => null,
            'old_content_truncated' => false,
            'old_binary' => false,
            'old_size' => null,
            'new_content' => 'content',
            'new_content_truncated' => false,
            'new_binary' => false,
            'new_size' => 7,
            'agent_id' => null,
            'agent_name' => null,
            'conversation_id' => null,
            'created_at' => now(),
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

    #[Test]
    public function changes_still_returns_the_full_history_after_the_workspace_is_removed(): void
    {
        $this->actingAs($this->user)->deleteJson($this->apiUrl("coding-project/{$this->project->id}"))->assertStatus(204);
        $this->assertNotNull(
            CodingProject::withTrashed()->find($this->project->id)->deleted_at,
            'precondition: the project must actually be soft-deleted',
        );

        $response = $this->actingAs($this->user)->getJson($this->apiUrl("coding-project/{$this->project->id}/changes"));

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('total'), 'the change history must remain fully reviewable after the workspace is removed (FR-011)');
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('tracked.txt', $response->json('data.0.path'));
    }

    #[Test]
    public function every_other_method_still_404s_against_the_same_removed_workspace(): void
    {
        $this->actingAs($this->user)->deleteJson($this->apiUrl("coding-project/{$this->project->id}"))->assertStatus(204);

        $notFound = ['error' => 'Coding project not found', 'code' => 'coding_project_not_found'];

        $this->actingAs($this->user)->getJson($this->apiUrl("coding-project/{$this->project->id}/files"))
            ->assertStatus(404)->assertJson($notFound);

        $this->actingAs($this->user)->getJson($this->apiUrl("coding-project/{$this->project->id}/file?path=tracked.txt"))
            ->assertStatus(404)->assertJson($notFound);

        $this->actingAs($this->user)->postJson($this->apiUrl("coding-project/{$this->project->id}/file"), ['path' => 'x.txt', 'content' => 'y'])
            ->assertStatus(404)->assertJson($notFound);

        $this->actingAs($this->user)->deleteJson($this->apiUrl("coding-project/{$this->project->id}/file?path=tracked.txt"))
            ->assertStatus(404)->assertJson($notFound);

        $this->actingAs($this->user)->getJson($this->apiUrl("coding-project/{$this->project->id}/search-files?pattern=*"))
            ->assertStatus(404)->assertJson($notFound);

        $this->actingAs($this->user)->getJson($this->apiUrl("coding-project/{$this->project->id}/search-content?query=x"))
            ->assertStatus(404)->assertJson($notFound);

        $this->actingAs($this->user)->postJson($this->apiUrl("coding-project/{$this->project->id}/run-tests"))
            ->assertStatus(404)->assertJson($notFound);

        $this->actingAs($this->user)->getJson($this->apiUrl("coding-project/{$this->project->id}/git-status"))
            ->assertStatus(404)->assertJson($notFound);

        $this->actingAs($this->user)->getJson($this->apiUrl("coding-project/{$this->project->id}/git-diff"))
            ->assertStatus(404)->assertJson($notFound);

        // The file on disk must never have been touched by any of the
        // refused calls above.
        $this->assertSame('content', file_get_contents($this->projectDir.'/tracked.txt'));
    }
}
