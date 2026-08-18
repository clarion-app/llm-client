<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\CodingProject;
use ClarionApp\LlmClient\Models\CodingWorkspaceRefusal;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 121-workspace-boundary-hardening, US2, T016 (contracts/refusal-recording.md
 * §1/§3, spec.md Acceptance Scenarios 1-3, Edge Case). Drives real HTTP
 * routes through the real CodingWorkspaceController -- fixture shape
 * mirrors PathContainmentAdversarialTest.php's own established shape -- and
 * proves the new refusal-recording seam: every containment failure leaves
 * a retrievable CodingWorkspaceRefusal row naming the workspace, roughly
 * when, and the specific reason, independent of whether an agent or a
 * direct caller triggered it, and degrading gracefully rather than
 * breaking the caller's response on a write failure.
 */
class WorkspaceRefusalRecordingJourneyTest extends TestCase
{
    private User $user;

    private string $projectDir;

    private string $outsideDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user, 'api');

        $this->projectDir = sys_get_temp_dir().'/coding-agent-refusal-project-'.Str::random(12);
        $this->outsideDir = sys_get_temp_dir().'/coding-agent-refusal-outside-'.Str::random(12);
        mkdir($this->projectDir, 0777, true);
        mkdir($this->outsideDir, 0777, true);
    }

    protected function tearDown(): void
    {
        DB::table('coding_workspace_refusals')->delete();
        DB::table('coding_projects')->delete();
        DB::table('users')->delete();

        // Safety net for the graceful-degradation test below, which
        // deliberately drops this table to force a write failure.
        if (!Schema::hasTable('coding_workspace_refusals')) {
            $this->recreateCodingWorkspaceRefusalsTable();
        }

        foreach ([$this->projectDir, $this->outsideDir] as $dir) {
            if (is_dir($dir)) {
                $this->removeDirectory($dir);
            }
        }

        parent::tearDown();
    }

    private function recreateCodingWorkspaceRefusalsTable(): void
    {
        Schema::create('coding_workspace_refusals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('coding_project_id');
            $table->string('operation');
            $table->string('reason');
            $table->timestamp('created_at')->useCurrent();

            $table->index('coding_project_id');
        });
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
            'name' => 'refusal-recording project',
            'root_path' => $rootPath,
            'test_command' => null,
        ]);
    }

    private function apiUrl(string $suffix): string
    {
        return '/api/clarion-app/llm-client/'.$suffix;
    }

    // -----------------------------------------------------------------
    // AS1: a refusal leaves a record naming the project/operation/reason
    // -----------------------------------------------------------------

    #[Test]
    public function a_traversal_refusal_returns_422_and_creates_a_matching_refusal_row(): void
    {
        file_put_contents($this->outsideDir.'/secret.txt', 'TOP SECRET CONTENT');

        $project = $this->registerProject($this->projectDir);

        $traversal = '../'.basename($this->outsideDir).'/secret.txt';
        $query = http_build_query(['path' => $traversal]);

        $response = $this->getJson($this->apiUrl("coding-project/{$project->id}/file?{$query}"));

        $response->assertStatus(422);
        $this->assertSame('path traversal', $response->json('error'));

        $this->assertSame(1, DB::table('coding_workspace_refusals')->count(), 'exactly one refusal row must be created for this refused attempt');

        $row = CodingWorkspaceRefusal::first();
        $this->assertNotNull($row);
        $this->assertSame($project->id, $row->coding_project_id, 'the row must name this exact project');
        $this->assertSame('read_file', $row->operation, 'readFile()\'s refusal must be recorded under the read_file operation label');
        $this->assertSame('path traversal', $row->reason, 'the recorded reason must match the response reason exactly');
        $this->assertNotEmpty($row->created_at, 'created_at must be populated (roughly when the refusal happened)');
    }

    // -----------------------------------------------------------------
    // AS3: several refusals against the same project are distinguishable
    // -----------------------------------------------------------------

    #[Test]
    public function two_distinct_refusals_against_the_same_project_are_both_recorded_independently(): void
    {
        $secretPath = $this->outsideDir.'/secret-write.txt';
        file_put_contents($secretPath, 'ORIGINAL OUTSIDE CONTENT');

        $project = $this->registerProject($this->projectDir);

        // First refusal: a literal traversal on a read.
        $traversalQuery = http_build_query(['path' => '../'.basename($this->outsideDir).'/secret-write.txt']);
        $first = $this->getJson($this->apiUrl("coding-project/{$project->id}/file?{$traversalQuery}"));
        $first->assertStatus(422);

        // Second, distinct refusal: a symlink escape on a write, against
        // the same project.
        $linkPath = $this->projectDir.'/write-target.txt';
        symlink($secretPath, $linkPath);
        $second = $this->postJson($this->apiUrl("coding-project/{$project->id}/file"), [
            'path' => 'write-target.txt',
            'content' => 'OVERWRITTEN',
        ]);
        $second->assertStatus(422);

        $this->assertSame(
            2,
            DB::table('coding_workspace_refusals')->count(),
            'two distinct refusals against the same project must produce two rows, neither overwriting the other',
        );

        $rows = CodingWorkspaceRefusal::orderBy('operation')->get();
        $this->assertCount(2, $rows);
        $this->assertNotSame($rows[0]->id, $rows[1]->id);

        $operations = $rows->pluck('operation')->all();
        $this->assertContains('read_file', $operations);
        $this->assertContains('write_file', $operations);

        $reasons = $rows->pluck('reason')->all();
        $this->assertContains('path traversal', $reasons);
        $this->assertContains('outside the registered project', $reasons);
    }

    // -----------------------------------------------------------------
    // AS2/FR-004: recording does not depend on an agent conversation/run
    // existing at all -- this whole test file only ever drives direct,
    // non-agent HTTP calls (ordinary getJson()/postJson()), never
    // AgentLoopService/an AgentRun/a conversation.
    // -----------------------------------------------------------------

    #[Test]
    public function a_refusal_driven_as_a_direct_non_agent_call_is_still_recorded_with_the_identical_shape(): void
    {
        $project = $this->registerProject($this->projectDir);

        $this->assertSame(0, DB::table('conversations')->count(), 'fixture sanity: no conversation exists');
        $this->assertSame(0, DB::table('agent_runs')->count(), 'fixture sanity: no AgentRun exists');

        $traversalQuery = http_build_query(['path' => '../outside.txt']);
        $response = $this->getJson($this->apiUrl("coding-project/{$project->id}/file?{$traversalQuery}"));

        $response->assertStatus(422);

        $this->assertSame(0, DB::table('conversations')->count(), 'no conversation was ever created by this direct call');
        $this->assertSame(0, DB::table('agent_runs')->count(), 'no AgentRun was ever created by this direct call');

        $row = CodingWorkspaceRefusal::first();
        $this->assertNotNull($row, 'a refusal row must still be created even though no agent conversation/run is involved anywhere in this test');
        $this->assertSame($project->id, $row->coding_project_id);
        $this->assertSame('read_file', $row->operation);
        $this->assertSame('path traversal', $row->reason);
    }

    // -----------------------------------------------------------------
    // Edge Case: a refusal-record write failure degrades gracefully --
    // the caller still receives its ordinary 422, never a 500, and the
    // failure is logged rather than silent.
    // -----------------------------------------------------------------

    #[Test]
    public function a_refusal_record_write_failure_never_turns_the_callers_422_into_a_500_and_is_logged(): void
    {
        $project = $this->registerProject($this->projectDir);

        Log::spy();
        Schema::dropIfExists('coding_workspace_refusals');

        try {
            $traversalQuery = http_build_query(['path' => '../outside.txt']);
            $response = $this->getJson($this->apiUrl("coding-project/{$project->id}/file?{$traversalQuery}"));

            $response->assertStatus(422);
            $this->assertSame('path traversal', $response->json('error'), 'the caller must still receive the ordinary refusal reason, never a 500 or a broken body');
        } finally {
            $this->recreateCodingWorkspaceRefusalsTable();
        }

        Log::shouldHaveReceived('warning')->atLeast()->once();
    }
}
