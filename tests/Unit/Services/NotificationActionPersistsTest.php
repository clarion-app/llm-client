<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\ActionOutcome;
use ClarionApp\LlmClient\ValueObjects\ActionType;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ActionType::Notification must survive a real INSERT, not merely exist as a
 * PHP case.
 *
 * This is a deliberate guard against a defect that already happened once in
 * this package: ActionType::Delegation was added to the enum and used by
 * DelegationService for an entire feature's lifetime while
 * agent_run_actions.action_type still rejected the value, so every one of
 * those INSERTs failed silently — openAction() catches its own write failures,
 * logs, and returns null. A test asserting only that the PHP case exists would
 * have passed throughout. The fix is spread across a MySQL ALTER TABLE
 * migration and two hand-declared SQLite schemas in the test suite, and any of
 * the four locations can be forgotten independently; this test fails if the
 * one the suite actually runs against is missed.
 */
class NotificationActionPersistsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app['config']->set('llm-client.run_trace.enabled', true);
        $this->app['config']->set('llm-client.run_trace.action_row_cap', 500);
    }

    protected function tearDown(): void
    {
        foreach (['agent_run_actions', 'agent_run_steps', 'agent_run_messages', 'agent_runs'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }
        parent::tearDown();
    }

    /** @test */
    public function notification_action_is_actually_persisted(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);

        $runId = $recorder->openRun(RunKind::SystemInitiated, 'user-1');
        $this->assertNotNull($runId);

        $stepId = $recorder->openStep($runId);
        $this->assertNotNull($stepId);

        $actionId = $recorder->openAction($stepId, ActionType::Notification, 'scheduler_trigger_run_refused');

        // A null id here is exactly the silent-failure shape the Delegation
        // defect wore: openAction() swallowed the constraint violation.
        $this->assertNotNull($actionId, 'openAction() returned null for ActionType::Notification — the INSERT was rejected or swallowed');

        $row = DB::table('agent_run_actions')->where('id', $actionId)->first();

        $this->assertNotNull($row, 'No agent_run_actions row was persisted for the notification action');
        $this->assertEquals('notification', $row->action_type);
        $this->assertEquals($runId, $row->run_id);
        $this->assertEquals($stepId, $row->step_id);
        $this->assertEquals('scheduler_trigger_run_refused', $row->target);
        $this->assertEquals(ActionOutcome::InProgress->value, $row->outcome);
    }

    /** @test */
    public function notification_action_closes_and_remains_readable(): void
    {
        $recorder = $this->app->make(RunTraceRecorder::class);

        $runId = $recorder->openRun(RunKind::SystemInitiated, 'user-1');
        $stepId = $recorder->openStep($runId);
        $actionId = $recorder->openAction($stepId, ActionType::Notification, 'scheduler_trigger_run_refused');

        $this->assertNotNull($actionId);

        $recorder->closeAction($actionId, ActionOutcome::Success);

        $row = DB::table('agent_run_actions')->where('id', $actionId)->first();

        $this->assertNotNull($row);
        $this->assertEquals('notification', $row->action_type);
        $this->assertEquals(ActionOutcome::Success->value, $row->outcome);
        $this->assertNotNull($row->ended_at);
    }
}
