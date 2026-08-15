<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the batch_id grouping column and widens the status enum with
     * 'queued' (data-model.md §1, 101-parallel-subagent-execution) on the
     * existing agent_delegations table (098-delegation-protocol,
     * 099-result-aggregation). Purely additive: every row written by the
     * pre-existing single-call delegate() path stays batch_id = null and
     * never observes 'queued' (it goes straight to 'in_progress' as it
     * always has — the concurrency gate is not consulted on that path).
     *
     * Laravel's Blueprint::change() does not reliably support enum columns
     * across drivers, so the status enum widening uses a raw MODIFY COLUMN
     * statement (MySQL/MariaDB, this package's production target per
     * plan.md Storage).
     */
    public function up(): void
    {
        if (!Schema::hasColumn('agent_delegations', 'batch_id')) {
            Schema::table('agent_delegations', function (Blueprint $table) {
                $table->uuid('batch_id')->nullable()->index();
            });
        }

        // MySQL/MariaDB only, this package's production target (plan.md
        // Storage) — matches the established "skip on SQLite" idiom this
        // package already uses for driver-specific DDL (see e.g.
        // 2026_06_19_000001_add_type_to_operation_search_index.php). The
        // test suite's own hand-declared schema
        // (tests/TestCase.php::defineAgentDelegationSchema()) already
        // widens the enum directly for SQLite-backed unit/feature tests
        // that don't run real migrations; no delegation test in this
        // package drives Delegation writes through a real-migration
        // (RefreshDatabase) sqlite connection.
        if (DB::connection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE agent_delegations MODIFY COLUMN status ENUM('queued','in_progress','completed','exhausted','failed') NOT NULL");
        }
    }

    /**
     * Best-effort narrowing back to the pre-101 enum. This fails if any row
     * still holds 'queued' at rollback time — an accepted, disclosed
     * limitation, not a new one this feature introduces beyond what any
     * additive enum-widening migration already carries.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE agent_delegations MODIFY COLUMN status ENUM('in_progress','completed','exhausted','failed') NOT NULL");
        }

        if (Schema::hasColumn('agent_delegations', 'batch_id')) {
            Schema::table('agent_delegations', function (Blueprint $table) {
                $table->dropColumn('batch_id');
            });
        }
    }
};
