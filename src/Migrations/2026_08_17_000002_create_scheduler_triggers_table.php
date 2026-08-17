<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * scheduler_triggers — a user-defined trigger and the work it starts when
     * it fires. Two kinds share one table because they differ only in how
     * "is it due?" is answered:
     *
     *  - kind = 'schedule'  reads schedule_expression (a 5-field cron string)
     *  - kind = 'condition' reads condition_operation_id / condition_path /
     *    condition_comparator / condition_value, and remembers the previous
     *    answer in last_condition_state so that only a false -> true
     *    transition counts as an event. last_condition_state is nullable and
     *    starts null: the first evaluation only records what it saw, it can
     *    never itself be a becoming-true event, so a condition that was
     *    already true when the trigger was created does not immediately fire.
     *
     * `kind` is immutable after creation (enforced in
     * SchedulerTriggerController). A trigger that changed kind would leave
     * behind firing keys computed under the other kind's format, so changing
     * it is modelled as delete-and-recreate.
     *
     * `retry_limit` is stored concrete on every row, never null — the default
     * is applied once, at creation. Nothing downstream re-defaults it, so
     * there is exactly one source of truth for it. 0 is a legitimate value
     * meaning "never retry".
     *
     * Bridged (`EloquentMultiChainBridge`) like `CodingProject` and `Server`:
     * persistent, user-owned configuration, not ephemeral execution data.
     * Soft-deleted, so a removed trigger's own firing history and runs stay
     * queryable; the evaluator's query excludes trashed rows.
     */
    public function up(): void
    {
        if (Schema::hasTable('scheduler_triggers')) {
            return;
        }

        Schema::create('scheduler_triggers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('agent_id');
            $table->string('name');

            $table->enum('kind', ['schedule', 'condition']);

            // kind = 'schedule' only; null for condition triggers.
            $table->string('schedule_expression')->nullable();

            // kind = 'condition' only; all null for schedule triggers.
            $table->string('condition_operation_id')->nullable();
            $table->string('condition_path')->nullable();
            $table->enum('condition_comparator', ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'contains'])->nullable();
            $table->string('condition_value')->nullable();
            $table->boolean('last_condition_state')->nullable();
            $table->timestamp('last_evaluated_at')->nullable();

            $table->text('defined_work');
            $table->integer('retry_limit');
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index('user_id');
            $table->index('agent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduler_triggers');
    }
};
