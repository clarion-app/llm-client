<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Widens agent_run_actions.action_type to include 'delegation'
     * (101-parallel-subagent-execution, Phase 4/T025 finding).
     *
     * ActionType::Delegation (src/ValueObjects/ActionType.php) was added
     * back in 098-delegation-protocol and has been used by
     * DelegationService::createDelegationRow() ever since, on both the
     * solo delegate() path and this feature's own delegateBatch() path --
     * but the agent_run_actions.action_type column, created by
     * 2026_08_05_000000_create_agent_run_actions_table.php, was never
     * widened to accept it. Every RunTraceRecorder::openAction() call
     * passing ActionType::Delegation has therefore been failing its own
     * INSERT against the column's CHECK/ENUM constraint since 098 shipped,
     * caught by openAction()'s own try/catch (logged, returns null) --
     * silent because every existing delegation test builds its ambient
     * Context['run_id'] as a bare, never-opened uuid (Context::add('run_id',
     * (string) Str::uuid())), so currentOpenStepId() always resolves null
     * and openAction() short-circuits BEFORE ever reaching the INSERT that
     * would have surfaced this. ParallelDelegationLiveProgressTest (T024)
     * is the first test in this package to open a genuine
     * RunTraceRecorder::openRun()/openStep() pair before calling
     * delegateBatch(), which is what finally exercised the real INSERT and
     * surfaced the failure.
     *
     * Net effect prior to this fix: EVERY delegation's parent_action_id has
     * always been null in practice, and no delegation has ever appeared as
     * its own live-trace action in the RunDiagram UI (070-run-execution-graph)
     * -- not a regression this feature introduced, a pre-existing gap this
     * feature's own dedicated US2 proof test was the first to actually
     * exercise end-to-end.
     *
     * Laravel's Blueprint::change() does not reliably support enum columns
     * across drivers, so -- matching this feature's own established
     * precedent (2026_08_14_000003_add_batch_columns_to_agent_delegations_table.php)
     * -- the widening uses a raw MODIFY COLUMN statement, MySQL/MariaDB only
     * (this package's production target, plan.md Storage). SQLite is
     * skipped: the test suite's own hand-declared schema
     * (tests/TestCase.php) already includes 'delegation' directly in its
     * Schema::create() closure for SQLite-backed unit/feature tests that
     * don't run real migrations.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE agent_run_actions MODIFY COLUMN action_type ENUM('llm_request','tool_invocation','context_reshape','delegation') NOT NULL");
        }
    }

    /**
     * Best-effort narrowing back to the pre-101 enum. This fails if any row
     * still holds 'delegation' at rollback time -- an accepted, disclosed
     * limitation, matching this feature's own precedent for the analogous
     * agent_delegations.status widening.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE agent_run_actions MODIFY COLUMN action_type ENUM('llm_request','tool_invocation','context_reshape') NOT NULL");
        }
    }
};
