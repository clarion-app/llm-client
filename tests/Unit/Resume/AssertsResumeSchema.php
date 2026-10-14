<?php

namespace Tests\Unit\Resume;

use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * The fast suite's hand-declared schema must carry the resumable-task
 * columns in every copy that declares the table (the spec 130 shadowing
 * lesson: Tests\TestCase's declaration shadows AssembledSystemTestCase's,
 * so both files must be updated or tests silently run against the old
 * shape). Red now: the columns do not exist in either copy yet.
 */
trait AssertsResumeSchema
{
    private const AGENT_RUNS_COLUMNS = [
        'checkpoint_version', 'resume_mode', 'resume_driver', 'resume_options',
        'resume_state', 'dispatch_id', 'lease_holder', 'lease_expires_at',
        'last_progress_at', 'interrupted_at', 'consecutive_interruptions',
        'resume_count', 'provider_attempts', 'provider_first_failure_at',
        'next_attempt_at', 'cancelled_at',
    ];

    #[Test]
    public function the_new_columns_exist_in_every_declared_table(): void
    {
        foreach (self::AGENT_RUNS_COLUMNS as $column) {
            $this->assertTrue(Schema::hasColumn('agent_runs', $column), "agent_runs.{$column}");
        }
        foreach (['iteration', 'checkpoint_state', 'checkpoint', 'message_id'] as $column) {
            $this->assertTrue(Schema::hasColumn('agent_run_steps', $column), "agent_run_steps.{$column}");
        }
        $this->assertTrue(Schema::hasColumn('agent_run_actions', 'tool_call_id'), 'agent_run_actions.tool_call_id');
        $this->assertTrue(Schema::hasColumn('agent_delegations', 'parent_tool_call_id'), 'agent_delegations.parent_tool_call_id');
    }

    #[Test]
    public function the_resume_events_table_exists_with_its_columns(): void
    {
        $this->assertTrue(Schema::hasTable('agent_run_resume_events'), 'agent_run_resume_events exists');
        foreach (['id', 'run_id', 'kind', 'step_position', 'attempt', 'payload', 'created_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('agent_run_resume_events', $column), "agent_run_resume_events.{$column}");
        }
    }
}
