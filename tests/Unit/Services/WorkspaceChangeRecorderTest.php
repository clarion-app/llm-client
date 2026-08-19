<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\CodingProject;
use ClarionApp\LlmClient\Models\CodingWorkspaceChange;
use ClarionApp\LlmClient\Services\WorkspaceChangeRecorder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 122-workspace-browser-ui, US3, T030 (data-model.md §1, research.md D1).
 * Unit-level proof of WorkspaceChangeRecorder::record() — exactly one row
 * per call, the cap/binary/truncated classification applied symmetrically
 * to both the old and new side, attribution columns set-or-null depending
 * on whether they were supplied, and a write failure degrading to a
 * logged warning rather than propagating (mirrors
 * WorkspaceRefusalRecorderTest's established technique).
 */
class WorkspaceChangeRecorderTest extends TestCase
{
    private User $user;

    private CodingProject $project;

    private WorkspaceChangeRecorder $recorder;

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

        $this->recorder = new WorkspaceChangeRecorder();
    }

    protected function tearDown(): void
    {
        DB::table('coding_workspace_changes')->delete();
        DB::table('coding_projects')->delete();
        DB::table('users')->delete();

        // Safety net: a test below deliberately drops this table to force
        // record() into its catch branch. If an assertion failed before
        // that test's own recreate step ran, restore it here so later
        // tests in the same process are unaffected.
        if (!Schema::hasTable('coding_workspace_changes')) {
            $this->recreateCodingWorkspaceChangesTable();
        }

        parent::tearDown();
    }

    private function recreateCodingWorkspaceChangesTable(): void
    {
        Schema::create('coding_workspace_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('coding_project_id');
            $table->uuid('user_id');
            $table->string('root_path');
            $table->string('path');
            $table->string('operation');
            $table->longText('old_content')->nullable();
            $table->boolean('old_content_truncated')->default(false);
            $table->boolean('old_binary')->default(false);
            $table->unsignedBigInteger('old_size')->nullable();
            $table->longText('new_content')->nullable();
            $table->boolean('new_content_truncated')->default(false);
            $table->boolean('new_binary')->default(false);
            $table->unsignedBigInteger('new_size')->nullable();
            $table->uuid('agent_id')->nullable();
            $table->string('agent_name')->nullable();
            $table->uuid('conversation_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('coding_project_id');
            $table->index('user_id');
            $table->index('conversation_id');
            $table->index(['coding_project_id', 'created_at']);
        });
    }

    #[Test]
    public function record_writes_exactly_one_row_for_a_created_file_with_old_side_entirely_null(): void
    {
        $this->recorder->record(
            $this->project,
            'new-file.txt',
            'created',
            null,
            null,
            'hello world',
            12,
        );

        $this->assertSame(1, DB::table('coding_workspace_changes')->count());

        $row = CodingWorkspaceChange::first();
        $this->assertNotNull($row);
        $this->assertSame($this->project->id, $row->coding_project_id);
        $this->assertSame($this->project->user_id, $row->user_id);
        $this->assertSame($this->project->root_path, $row->root_path);
        $this->assertSame('new-file.txt', $row->path);
        $this->assertSame('created', $row->operation);

        $this->assertNull($row->old_content);
        $this->assertFalse((bool) $row->old_content_truncated);
        $this->assertFalse((bool) $row->old_binary);
        $this->assertNull($row->old_size);

        $this->assertSame('hello world', $row->new_content);
        $this->assertFalse((bool) $row->new_content_truncated);
        $this->assertFalse((bool) $row->new_binary);
        $this->assertSame(12, $row->new_size);
    }

    #[Test]
    public function record_writes_a_modified_row_with_both_old_and_new_content_present(): void
    {
        $this->recorder->record(
            $this->project,
            'existing.txt',
            'modified',
            'old content',
            11,
            'new content',
            11,
        );

        $row = CodingWorkspaceChange::first();
        $this->assertNotNull($row);
        $this->assertSame('modified', $row->operation);
        $this->assertSame('old content', $row->old_content);
        $this->assertSame(11, $row->old_size);
        $this->assertSame('new content', $row->new_content);
        $this->assertSame(11, $row->new_size);
    }

    #[Test]
    public function record_writes_a_deleted_row_with_new_side_entirely_null(): void
    {
        $this->recorder->record(
            $this->project,
            'gone.txt',
            'deleted',
            'the content that used to be there',
            34,
            null,
            null,
        );

        $row = CodingWorkspaceChange::first();
        $this->assertNotNull($row);
        $this->assertSame('deleted', $row->operation);
        $this->assertSame('the content that used to be there', $row->old_content);
        $this->assertSame(34, $row->old_size);

        $this->assertNull($row->new_content);
        $this->assertFalse((bool) $row->new_content_truncated);
        $this->assertFalse((bool) $row->new_binary);
        $this->assertNull($row->new_size);
    }

    #[Test]
    public function content_whose_actual_size_exceeds_the_configured_threshold_is_flagged_truncated(): void
    {
        config(['llm-client.coding_agent.file_size_threshold_bytes' => 10]);

        // Mirrors what the controller passes: a sample already bounded to
        // the threshold, alongside the true, larger actual size.
        $sample = str_repeat('a', 10);

        $this->recorder->record(
            $this->project,
            'big.txt',
            'modified',
            $sample,
            500,
            $sample,
            500,
        );

        $row = CodingWorkspaceChange::first();
        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->old_content_truncated);
        $this->assertTrue((bool) $row->new_content_truncated);
        $this->assertFalse((bool) $row->old_binary);
        $this->assertFalse((bool) $row->new_binary);
        $this->assertSame($sample, $row->old_content);
        $this->assertSame($sample, $row->new_content);
    }

    #[Test]
    public function content_within_the_threshold_is_not_flagged_truncated(): void
    {
        config(['llm-client.coding_agent.file_size_threshold_bytes' => 262144]);

        $this->recorder->record(
            $this->project,
            'small.txt',
            'modified',
            'short',
            5,
            'short too',
            9,
        );

        $row = CodingWorkspaceChange::first();
        $this->assertFalse((bool) $row->old_content_truncated);
        $this->assertFalse((bool) $row->new_content_truncated);
    }

    #[Test]
    public function binary_content_is_stored_as_null_with_binary_flagged_and_is_never_also_truncated(): void
    {
        config(['llm-client.coding_agent.file_size_threshold_bytes' => 10]);

        // A null byte marks this binary under WorkspaceFilePolicy::isBinary()
        // regardless of size -- deliberately also past the (10-byte)
        // threshold, to prove binary and truncated are never both true for
        // the same side (data-model.md §1's mutual-exclusion invariant).
        $binarySample = "\x00binary-content-well-past-the-threshold";

        $this->recorder->record(
            $this->project,
            'binary.dat',
            'created',
            null,
            null,
            $binarySample,
            500,
        );

        $row = CodingWorkspaceChange::first();
        $this->assertNotNull($row);
        $this->assertNull($row->new_content, 'binary content must never be stored');
        $this->assertTrue((bool) $row->new_binary);
        $this->assertFalse(
            (bool) $row->new_content_truncated,
            'binary and truncated must never both be true for the same side',
        );
        $this->assertSame(500, $row->new_size, 'the actual size is still recorded even when content is suppressed');
    }

    #[Test]
    public function attribution_columns_are_set_when_supplied_and_null_when_omitted(): void
    {
        $this->recorder->record(
            $this->project,
            'attributed.txt',
            'created',
            null,
            null,
            'content',
            7,
            'agent-uuid-123',
            'Refactor Bot',
            'conversation-uuid-456',
        );

        $row = CodingWorkspaceChange::first();
        $this->assertSame('agent-uuid-123', $row->agent_id);
        $this->assertSame('Refactor Bot', $row->agent_name);
        $this->assertSame('conversation-uuid-456', $row->conversation_id);

        DB::table('coding_workspace_changes')->delete();

        $this->recorder->record(
            $this->project,
            'unattributed.txt',
            'created',
            null,
            null,
            'content',
            7,
        );

        $secondRow = CodingWorkspaceChange::first();
        $this->assertNull($secondRow->agent_id);
        $this->assertNull($secondRow->agent_name);
        $this->assertNull($secondRow->conversation_id);
    }

    #[Test]
    public function record_auto_populates_id_and_created_at(): void
    {
        $this->recorder->record($this->project, 'file.txt', 'created', null, null, 'x', 1);

        $row = CodingWorkspaceChange::first();
        $this->assertNotNull($row);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $row->id,
        );
        $this->assertNotEmpty($row->created_at);
    }

    #[Test]
    public function a_second_call_creates_an_independent_second_row(): void
    {
        $this->recorder->record($this->project, 'one.txt', 'created', null, null, 'a', 1);
        $this->recorder->record($this->project, 'two.txt', 'created', null, null, 'b', 1);

        $this->assertSame(2, DB::table('coding_workspace_changes')->count());

        $rows = CodingWorkspaceChange::orderBy('path')->get();
        $this->assertNotSame($rows[0]->id, $rows[1]->id);
    }

    #[Test]
    public function a_create_failure_does_not_propagate_and_a_warning_is_logged(): void
    {
        Log::spy();

        Schema::dropIfExists('coding_workspace_changes');

        try {
            $this->recorder->record($this->project, 'file.txt', 'created', null, null, 'x', 1);
            $this->addToAssertionCount(1); // reaching this line proves record() did not propagate
        } finally {
            $this->recreateCodingWorkspaceChangesTable();
        }

        Log::shouldHaveReceived('warning')->atLeast()->once();
    }
}
