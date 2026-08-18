<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\CodingProject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 112-coding-agent — reconciliation (post-Phase-9). Phase 9's own T052
 * manual-walkthrough reconciliation flagged that `CodingProjectController`
 * (the actual `POST/GET/DELETE coding-project` HTTP routes — where a human
 * registers a project, resolves/validates `root_path`, lists their own
 * projects, and soft-deletes one) had ZERO automated test coverage: every
 * other test in the suite constructs `CodingProject` rows directly via
 * Eloquent (`CodingProject::create(...)`), bypassing the controller
 * entirely. This file drives the real, registered HTTP routes instead,
 * covering exactly the gap T052 named: `store()`'s `realpath()`
 * resolution/validation and request validation, `index()`'s ownership
 * scoping, and `destroy()`'s ownership check + soft-delete behavior.
 */
class CodingProjectControllerTest extends TestCase
{
    private User $user;

    private User $otherUser;

    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->otherUser = User::factory()->create();

        $this->projectDir = sys_get_temp_dir().'/coding-agent-controller-project-'.Str::random(12);
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

    // -----------------------------------------------------------------
    // POST coding-project (store)
    // -----------------------------------------------------------------

    #[Test]
    public function store_with_a_valid_root_path_succeeds_and_persists_the_resolved_real_path(): void
    {
        // Register with a path containing a redundant "./" segment so the
        // stored value is provably the REALPATH()-RESOLVED absolute path,
        // never the raw input echoed back unresolved (contracts §0,
        // data-model.md §1).
        $rawInput = $this->projectDir.'/./';

        $response = $this->actingAs($this->user)->postJson($this->apiUrl('coding-project'), [
            'name' => 'My Project',
            'root_path' => $rawInput,
            'test_command' => 'composer test',
        ]);

        $response->assertStatus(201);
        $data = $response->json();

        $this->assertSame(realpath($this->projectDir), $data['root_path']);
        $this->assertNotSame($rawInput, $data['root_path']);
        $this->assertSame('My Project', $data['name']);
        $this->assertSame('composer test', $data['test_command']);

        $project = CodingProject::find($data['id']);
        $this->assertNotNull($project);
        $this->assertSame($this->user->id, $project->user_id);
        $this->assertSame(realpath($this->projectDir), $project->root_path);
    }

    #[Test]
    public function store_without_a_test_command_persists_it_as_null(): void
    {
        $response = $this->actingAs($this->user)->postJson($this->apiUrl('coding-project'), [
            'name' => 'No Test Command',
            'root_path' => $this->projectDir,
        ]);

        $response->assertStatus(201);
        $this->assertNull($response->json('test_command'));

        $project = CodingProject::find($response->json('id'));
        $this->assertNull($project->test_command);
    }

    #[Test]
    public function store_with_a_nonexistent_root_path_is_refused_with_a_clear_reason_and_nothing_is_persisted(): void
    {
        $nonexistent = sys_get_temp_dir().'/coding-agent-does-not-exist-'.Str::random(12);
        $this->assertFalse(is_dir($nonexistent), 'fixture sanity: the directory must genuinely not exist');

        $countBefore = CodingProject::count();

        $response = $this->actingAs($this->user)->postJson($this->apiUrl('coding-project'), [
            'name' => 'Ghost Project',
            'root_path' => $nonexistent,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['root_path']);
        $this->assertSame($countBefore, CodingProject::count(), 'a refused registration must persist nothing');
    }

    #[Test]
    public function store_with_a_file_instead_of_a_directory_as_root_path_is_refused(): void
    {
        $filePath = $this->projectDir.'/not-a-directory.txt';
        file_put_contents($filePath, 'this is a file, not a directory');

        $countBefore = CodingProject::count();

        $response = $this->actingAs($this->user)->postJson($this->apiUrl('coding-project'), [
            'name' => 'File Not Dir',
            'root_path' => $filePath,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['root_path']);
        $this->assertSame($countBefore, CodingProject::count());
    }

    #[Test]
    public function store_requires_name_and_root_path(): void
    {
        $response = $this->actingAs($this->user)->postJson($this->apiUrl('coding-project'), []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'root_path']);
        $this->assertSame(0, CodingProject::count());
    }

    // -----------------------------------------------------------------
    // GET coding-project (index)
    // -----------------------------------------------------------------

    #[Test]
    public function index_returns_only_the_requesting_users_own_projects(): void
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

        $response = $this->actingAs($this->user)->getJson($this->apiUrl('coding-project'));

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($theirs->id), 'another user\'s project must never be listed');
    }

    #[Test]
    public function index_returns_an_empty_list_for_a_user_with_no_registered_projects(): void
    {
        CodingProject::create([
            'user_id' => $this->otherUser->id,
            'name' => 'Not Mine',
            'root_path' => $this->projectDir,
            'test_command' => null,
        ]);

        $response = $this->actingAs($this->user)->getJson($this->apiUrl('coding-project'));

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'));
    }

    // -----------------------------------------------------------------
    // GET coding-project (index) -- reachability + pagination
    // (122-workspace-browser-ui, US1, contracts/workspace-list-api.md,
    // research.md D3/D4, T006's flat-envelope decision)
    // -----------------------------------------------------------------

    #[Test]
    public function index_reports_reachable_true_for_a_real_readable_directory_and_false_for_one_that_no_longer_exists(): void
    {
        $missingDir = sys_get_temp_dir().'/coding-agent-controller-missing-'.Str::random(12);
        mkdir($missingDir, 0777, true);

        $healthy = CodingProject::create([
            'user_id' => $this->user->id,
            'name' => 'Healthy',
            'root_path' => $this->projectDir,
            'test_command' => null,
        ]);

        $broken = CodingProject::create([
            'user_id' => $this->user->id,
            'name' => 'Broken',
            'root_path' => $missingDir,
            'test_command' => null,
        ]);

        // Directory removed AFTER registration -- reachable must reflect the
        // CURRENT state of the filesystem, not whatever was true at
        // registration time (FR-002, research.md D3).
        $this->removeDirectory($missingDir);

        $response = $this->actingAs($this->user)->getJson($this->apiUrl('coding-project'));

        $response->assertStatus(200);
        $rows = collect($response->json('data'))->keyBy('id');

        $this->assertTrue($rows[$healthy->id]['reachable']);
        $this->assertFalse($rows[$broken->id]['reachable']);
    }

    #[Test]
    public function index_rechecks_reachability_live_on_every_call_not_only_at_registration(): void
    {
        $dir = sys_get_temp_dir().'/coding-agent-controller-live-recheck-'.Str::random(12);
        mkdir($dir, 0777, true);

        $project = CodingProject::create([
            'user_id' => $this->user->id,
            'name' => 'Live Recheck',
            'root_path' => $dir,
            'test_command' => null,
        ]);

        $first = $this->actingAs($this->user)->getJson($this->apiUrl('coding-project'));
        $first->assertStatus(200);
        $firstRow = collect($first->json('data'))->firstWhere('id', $project->id);
        $this->assertTrue($firstRow['reachable'], 'must be reachable while the directory still exists');

        // Directory removed BETWEEN the two GET calls, within the same
        // test -- proving live per-call recomputation, not a value cached
        // or stored from the first call (research.md D3, mutation
        // checklist rows 3-4).
        $this->removeDirectory($dir);

        $second = $this->actingAs($this->user)->getJson($this->apiUrl('coding-project'));
        $second->assertStatus(200);
        $secondRow = collect($second->json('data'))->firstWhere('id', $project->id);
        $this->assertFalse($secondRow['reachable'], 'must reflect the directory now being gone, not a value cached from the first GET');
    }

    #[Test]
    public function index_returns_the_flat_paginated_envelope_with_default_and_cap(): void
    {
        for ($i = 0; $i < 3; $i++) {
            CodingProject::create([
                'user_id' => $this->user->id,
                'name' => "Project {$i}",
                'root_path' => $this->projectDir,
                'test_command' => null,
            ]);
        }

        // Default: no page/per_page supplied.
        $default = $this->actingAs($this->user)->getJson($this->apiUrl('coding-project'));
        $default->assertStatus(200);
        $default->assertJsonStructure(['data', 'total', 'page', 'per_page']);
        $this->assertSame(3, $default->json('total'));
        $this->assertSame(1, $default->json('page'));
        $this->assertSame(50, $default->json('per_page'));
        $this->assertCount(3, $default->json('data'));

        // per_page above the cap (100) is clamped down to it.
        $capped = $this->actingAs($this->user)->getJson($this->apiUrl('coding-project?per_page=500'));
        $capped->assertStatus(200);
        $this->assertSame(100, $capped->json('per_page'));

        // page/per_page below 1 fall back to their floor/default.
        $floored = $this->actingAs($this->user)->getJson($this->apiUrl('coding-project?page=0&per_page=0'));
        $floored->assertStatus(200);
        $this->assertSame(1, $floored->json('page'));
        $this->assertSame(50, $floored->json('per_page'));
    }

    // -----------------------------------------------------------------
    // DELETE coding-project/{id} (destroy)
    // -----------------------------------------------------------------

    #[Test]
    public function destroy_soft_deletes_an_owned_project_leaving_the_row_intact_with_deleted_at_set(): void
    {
        $project = CodingProject::create([
            'user_id' => $this->user->id,
            'name' => 'To Delete',
            'root_path' => $this->projectDir,
            'test_command' => null,
        ]);

        $response = $this->actingAs($this->user)->deleteJson($this->apiUrl("coding-project/{$project->id}"));

        $response->assertStatus(204);

        // Soft delete, not hard delete: the row still exists with
        // deleted_at set, invisible to the default (non-trashed) scope.
        $this->assertNull(CodingProject::find($project->id));
        $trashed = CodingProject::withTrashed()->find($project->id);
        $this->assertNotNull($trashed, 'the row must still exist in the database after a soft delete');
        $this->assertNotNull($trashed->deleted_at);

        $raw = DB::table('coding_projects')->where('id', $project->id)->first();
        $this->assertNotNull($raw, 'a hard delete would remove the row from the table entirely');
        $this->assertNotNull($raw->deleted_at);
    }

    #[Test]
    public function destroy_refuses_a_foreign_owned_project_and_does_not_delete_it(): void
    {
        $project = CodingProject::create([
            'user_id' => $this->otherUser->id,
            'name' => 'Not Yours',
            'root_path' => $this->projectDir,
            'test_command' => null,
        ]);

        $response = $this->actingAs($this->user)->deleteJson($this->apiUrl("coding-project/{$project->id}"));

        $response->assertStatus(404);
        $this->assertNotNull(CodingProject::find($project->id), 'a foreign-owned project must not be deleted');
    }

    #[Test]
    public function destroy_refuses_an_absent_project_id(): void
    {
        $response = $this->actingAs($this->user)->deleteJson($this->apiUrl('coding-project/'.(string) Str::uuid()));

        $response->assertStatus(404);
    }
}
