<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\CodingProject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 122-workspace-browser-ui, US2, T020 (FR-013, Acceptance Scenario 4,
 * mutation checklist row 6's initial half). Extended again in Phase 5/T043
 * with a `changes()` case.
 *
 * Every read or write action reachable through the workspace browser must
 * refuse a request against another user's workspace exactly as it would
 * refuse a genuinely nonexistent id -- the same 404 `coding_project_not_found`
 * shape, never a distinguishing 403 or any other response that would let a
 * caller infer "this id exists, it's just not yours."
 *
 * `updateConfirmationSetting()` and `destroy()` already scope their lookup
 * with `->where('user_id', Auth::id())` (confirmed by direct read), so
 * those two cases are expected to pass on first run -- this file proves it
 * rather than assuming it. `listFiles()` (a workspace-id-scoped GET,
 * `CodingWorkspaceController::findOwnedProject()`) is included as the GET
 * case since `CodingProjectController` itself exposes no single-workspace
 * GET-by-id route -- only the list (`index()`), which is covered
 * separately below. The `index()` sweep case is genuinely new coverage of
 * Phase 3's paginated response shape.
 */
class WorkspaceBrowserCrossUserIsolationTest extends TestCase
{
    private User $user;

    private User $otherUser;

    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->otherUser = User::factory()->create();

        $this->projectDir = sys_get_temp_dir().'/coding-agent-cross-user-isolation-'.Str::random(12);
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

    private function assertUniformNotFound($response): void
    {
        $response->assertStatus(404);
        $response->assertJson(['error' => 'Coding project not found', 'code' => 'coding_project_not_found']);
    }

    // -----------------------------------------------------------------
    // A genuinely nonexistent id -- the baseline every foreign-owned case
    // below must be indistinguishable from.
    // -----------------------------------------------------------------

    private function nonexistentId(): string
    {
        return (string) Str::uuid();
    }

    #[Test]
    public function get_files_against_a_foreign_owned_workspace_returns_the_same_404_shape_as_a_nonexistent_id(): void
    {
        $foreign = CodingProject::create([
            'user_id' => $this->otherUser->id,
            'name' => 'Not Yours',
            'root_path' => $this->projectDir,
            'test_command' => null,
        ]);

        $foreignResponse = $this->actingAs($this->user)->getJson($this->apiUrl("coding-project/{$foreign->id}/files"));
        $absentResponse = $this->actingAs($this->user)->getJson($this->apiUrl('coding-project/'.$this->nonexistentId().'/files'));

        $this->assertUniformNotFound($foreignResponse);
        $this->assertUniformNotFound($absentResponse);
        $this->assertSame($absentResponse->getStatusCode(), $foreignResponse->getStatusCode());
        $this->assertSame($absentResponse->json(), $foreignResponse->json());
    }

    #[Test]
    public function patch_confirmation_setting_against_a_foreign_owned_workspace_returns_the_same_404_shape_as_a_nonexistent_id(): void
    {
        $foreign = CodingProject::create([
            'user_id' => $this->otherUser->id,
            'name' => 'Not Yours',
            'root_path' => $this->projectDir,
            'test_command' => null,
        ]);

        $foreignResponse = $this->actingAs($this->user)->patchJson($this->apiUrl("coding-project/{$foreign->id}/confirmation-setting"), ['relaxed' => true]);
        $absentResponse = $this->actingAs($this->user)->patchJson($this->apiUrl('coding-project/'.$this->nonexistentId().'/confirmation-setting'), ['relaxed' => true]);

        $this->assertUniformNotFound($foreignResponse);
        $this->assertUniformNotFound($absentResponse);
        $this->assertSame($absentResponse->json(), $foreignResponse->json());
        $this->assertFalse((bool) $foreign->fresh()->confirmation_relaxed, 'a foreign-owned workspace must never actually be modified');
    }

    #[Test]
    public function delete_against_a_foreign_owned_workspace_returns_the_same_404_shape_as_a_nonexistent_id(): void
    {
        $foreign = CodingProject::create([
            'user_id' => $this->otherUser->id,
            'name' => 'Not Yours',
            'root_path' => $this->projectDir,
            'test_command' => null,
        ]);

        $foreignResponse = $this->actingAs($this->user)->deleteJson($this->apiUrl("coding-project/{$foreign->id}"));
        $absentResponse = $this->actingAs($this->user)->deleteJson($this->apiUrl('coding-project/'.$this->nonexistentId()));

        $this->assertUniformNotFound($foreignResponse);
        $this->assertUniformNotFound($absentResponse);
        $this->assertSame($absentResponse->json(), $foreignResponse->json());
        $this->assertNotNull(CodingProject::find($foreign->id), 'a foreign-owned workspace must never actually be deleted');
    }

    #[Test]
    public function index_never_includes_another_users_workspace_row_even_when_both_users_have_registered_workspaces(): void
    {
        $mine = CodingProject::create([
            'user_id' => $this->user->id,
            'name' => 'Mine',
            'root_path' => $this->projectDir,
            'test_command' => null,
        ]);

        $theirs = CodingProject::create([
            'user_id' => $this->otherUser->id,
            'name' => 'Theirs',
            'root_path' => $this->projectDir,
            'test_command' => null,
        ]);

        $myResponse = $this->actingAs($this->user)->getJson($this->apiUrl('coding-project'));
        $myResponse->assertStatus(200);
        $myIds = collect($myResponse->json('data'))->pluck('id');
        $this->assertTrue($myIds->contains($mine->id));
        $this->assertFalse($myIds->contains($theirs->id), 'another user\'s workspace must never appear in my list');

        $theirResponse = $this->actingAs($this->otherUser)->getJson($this->apiUrl('coding-project'));
        $theirResponse->assertStatus(200);
        $theirIds = collect($theirResponse->json('data'))->pluck('id');
        $this->assertTrue($theirIds->contains($theirs->id));
        $this->assertFalse($theirIds->contains($mine->id), 'my workspace must never appear in another user\'s list');
    }

    // -----------------------------------------------------------------
    // 122-workspace-browser-ui, US3, T043 (mutation checklist row 6's
    // second half): changes() against a foreign-owned workspace -- both
    // still-registered and removed -- must be indistinguishable from a
    // genuinely nonexistent id.
    // -----------------------------------------------------------------

    #[Test]
    public function get_changes_against_a_foreign_owned_workspace_returns_the_same_404_shape_as_a_nonexistent_id(): void
    {
        $foreign = CodingProject::create([
            'user_id' => $this->otherUser->id,
            'name' => 'Not Yours',
            'root_path' => $this->projectDir,
            'test_command' => null,
        ]);

        $foreignResponse = $this->actingAs($this->user)->getJson($this->apiUrl("coding-project/{$foreign->id}/changes"));
        $absentResponse = $this->actingAs($this->user)->getJson($this->apiUrl('coding-project/'.$this->nonexistentId().'/changes'));

        $this->assertUniformNotFound($foreignResponse);
        $this->assertUniformNotFound($absentResponse);
        $this->assertSame($absentResponse->json(), $foreignResponse->json());
    }

    #[Test]
    public function get_changes_against_a_foreign_owned_and_since_removed_workspace_still_returns_the_same_404_shape(): void
    {
        $foreign = CodingProject::create([
            'user_id' => $this->otherUser->id,
            'name' => 'Not Yours, And Removed',
            'root_path' => $this->projectDir,
            'test_command' => null,
        ]);
        $foreign->delete();

        // withTrashed() (research.md D7) is scoped by user_id too -- a
        // removed workspace belonging to a different user must still
        // never resolve for this caller, exactly like the still-registered
        // case above.
        $foreignResponse = $this->actingAs($this->user)->getJson($this->apiUrl("coding-project/{$foreign->id}/changes"));

        $this->assertUniformNotFound($foreignResponse);
    }
}
