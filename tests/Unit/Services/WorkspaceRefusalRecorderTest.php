<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\CodingProject;
use ClarionApp\LlmClient\Models\CodingWorkspaceRefusal;
use ClarionApp\LlmClient\Services\WorkspaceRefusalRecorder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 121-workspace-boundary-hardening, US2, T015 (contracts/refusal-recording.md
 * §2, data-model.md §3). Unit-level proof of
 * WorkspaceRefusalRecorder::record(CodingProject, string, string): void --
 * exactly one row per call, arguments passed verbatim (no
 * re-description/generalization), id/created_at auto-populated, and a
 * write failure degrades to a logged warning rather than propagating,
 * mirroring RunTraceRecorder::broadcast()'s established shape.
 */
class WorkspaceRefusalRecorderTest extends TestCase
{
    private User $user;

    private CodingProject $project;

    private WorkspaceRefusalRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->project = CodingProject::create([
            'user_id' => $this->user->id,
            'name' => 'recorder project',
            'root_path' => '/tmp/does-not-need-to-exist-for-this-unit-test',
            'test_command' => null,
        ]);

        $this->recorder = new WorkspaceRefusalRecorder();
    }

    protected function tearDown(): void
    {
        DB::table('coding_workspace_refusals')->delete();
        DB::table('coding_projects')->delete();
        DB::table('users')->delete();

        // Safety net: a test below deliberately drops this table to force
        // record() into its catch branch. If an assertion failed before
        // that test's own recreate step ran, restore it here so later
        // tests in the same process are unaffected.
        if (!Schema::hasTable('coding_workspace_refusals')) {
            $this->recreateCodingWorkspaceRefusalsTable();
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

    #[Test]
    public function record_creates_exactly_one_row_with_arguments_passed_verbatim(): void
    {
        $this->recorder->record($this->project, 'read_file', 'path traversal');

        $this->assertSame(
            1,
            DB::table('coding_workspace_refusals')->count(),
            'record() must create exactly one row per call',
        );

        $row = CodingWorkspaceRefusal::first();
        $this->assertNotNull($row);
        $this->assertSame($this->project->id, $row->coding_project_id, 'coding_project_id must match the project argument exactly');
        $this->assertSame('read_file', $row->operation, 'operation must be stored verbatim, never re-described');
        $this->assertSame('path traversal', $row->reason, 'reason must be stored verbatim, never re-described or generalized');
    }

    #[Test]
    public function record_auto_populates_id_and_created_at(): void
    {
        $this->recorder->record($this->project, 'write_file', 'outside the registered project');

        $row = CodingWorkspaceRefusal::first();
        $this->assertNotNull($row);
        $this->assertNotEmpty($row->id, 'id must be auto-populated by the model creating listener');
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $row->id,
            'id must be a genuine UUID, not left blank or sequential',
        );
        $this->assertNotEmpty($row->created_at, 'created_at must be auto-populated by the database-level useCurrent() default');
    }

    #[Test]
    public function a_second_call_creates_an_independent_second_row_neither_overwrites_the_other(): void
    {
        $this->recorder->record($this->project, 'read_file', 'path traversal');
        $this->recorder->record($this->project, 'delete_file', 'outside the registered project');

        $this->assertSame(
            2,
            DB::table('coding_workspace_refusals')->count(),
            'a second, distinct refusal against the same project must create a second row, not overwrite the first',
        );

        $rows = CodingWorkspaceRefusal::orderBy('operation')->get();
        $this->assertCount(2, $rows);
        $this->assertNotSame($rows[0]->id, $rows[1]->id, 'each row must have its own independent id');

        $operations = $rows->pluck('operation')->all();
        $this->assertContains('read_file', $operations);
        $this->assertContains('delete_file', $operations);
    }

    #[Test]
    public function a_create_failure_does_not_propagate_and_a_warning_is_logged(): void
    {
        Log::spy();

        // Force the underlying CodingWorkspaceRefusal::create() call to
        // throw, mirroring "a test double / forced DB error" -- dropping
        // the table it writes to is a genuine, driver-level failure, not a
        // stubbed outcome.
        Schema::dropIfExists('coding_workspace_refusals');

        try {
            $this->recorder->record($this->project, 'read_file', 'path traversal');
            $this->addToAssertionCount(1); // reaching this line proves record() did not propagate
        } finally {
            $this->recreateCodingWorkspaceRefusalsTable();
        }

        Log::shouldHaveReceived('warning')->atLeast()->once();
    }
}
