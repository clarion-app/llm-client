<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Widens agent_run_actions.action_type to include 'notification', the
     * action type recorded when a run that stopped for an unauthorized action
     * tries to notify its owner.
     *
     * This ships in the same commit as the ActionType::Notification PHP case
     * and the test suite's own hand-declared SQLite schemas, deliberately:
     * splitting them apart is precisely what went wrong with
     * ActionType::Delegation. That case was added in 098-delegation-protocol
     * and used on every delegation path for an entire feature's lifetime while
     * this column still rejected the value, so every openAction() INSERT
     * failed against the ENUM/CHECK constraint and was swallowed by
     * openAction()'s own try/catch — logged, returning null, with no delegation
     * ever appearing as its own trace action. It went unnoticed until
     * 2026_08_14_000004_add_delegation_to_agent_run_actions_action_type_enum.php
     * finally widened the column, more than a full feature later.
     *
     * Laravel's Blueprint::change() does not reliably support enum columns
     * across drivers, so — matching that migration exactly — the widening uses
     * a raw MODIFY COLUMN statement, MySQL/MariaDB only (this package's
     * production target). SQLite is skipped because the test suite declares
     * its own agent_run_actions schema by hand rather than running migrations;
     * both of those declarations (tests/TestCase.php and
     * tests/Integration/AssembledSystemTestCase.php) carry 'notification'
     * directly.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE agent_run_actions MODIFY COLUMN action_type ENUM('llm_request','tool_invocation','context_reshape','delegation','notification') NOT NULL");
        }
    }

    /**
     * Best-effort narrowing back to the previous enum. This fails if any row
     * still holds 'notification' at rollback time — an accepted, disclosed
     * limitation, matching the 'delegation' widening's own precedent.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE agent_run_actions MODIFY COLUMN action_type ENUM('llm_request','tool_invocation','context_reshape','delegation') NOT NULL");
        }
    }
};
