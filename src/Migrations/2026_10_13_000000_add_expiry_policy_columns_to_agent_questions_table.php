<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What happens to a question nobody answers, decided when it is asked.
 *
 * - `unanswered_policy`: `proceed_on_assumption` | `stop`. NULL marks a
 *   question asked before policies existed, which expires as it always did.
 * - `consequential`: the answer gates an action with real-world
 *   consequences; such a question always stops.
 * - `assumed_answer_raw` / `assumed_answer_selected`: the answer assumed
 *   when the period passes, in the shape a user's answer has (the reply
 *   text and the choices it selects). NULL unless the policy proceeds.
 * - `requested_wait_minutes`: the period the agent asked for, as given
 *   (kept within ±1 000 000 000); `effective_wait_minutes`: the period
 *   applied after the installation's bounds (at most one year).
 * - `stopped_at`: when a stop-policy question's period passed. It survives
 *   a later late answer or discard, which it then marks.
 * - `late_reply_noticed_at`: when the user was told their reply came after
 *   the agent had already proceeded on its assumption; set at most once.
 *
 * All plain columns rather than ENUMs, so a new value never needs a column
 * change; no index, since nothing selects by them.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'unanswered_policy',
        'consequential',
        'assumed_answer_raw',
        'assumed_answer_selected',
        'requested_wait_minutes',
        'effective_wait_minutes',
        'stopped_at',
        'late_reply_noticed_at',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('agent_questions')) {
            return;
        }

        Schema::table('agent_questions', function (Blueprint $table) {
            if (!Schema::hasColumn('agent_questions', 'unanswered_policy')) {
                $table->string('unanswered_policy', 32)->nullable();
            }
            if (!Schema::hasColumn('agent_questions', 'consequential')) {
                $table->boolean('consequential')->default(false);
            }
            if (!Schema::hasColumn('agent_questions', 'assumed_answer_raw')) {
                $table->text('assumed_answer_raw')->nullable();
            }
            if (!Schema::hasColumn('agent_questions', 'assumed_answer_selected')) {
                $table->json('assumed_answer_selected')->nullable();
            }
            if (!Schema::hasColumn('agent_questions', 'requested_wait_minutes')) {
                $table->integer('requested_wait_minutes')->nullable();
            }
            if (!Schema::hasColumn('agent_questions', 'effective_wait_minutes')) {
                $table->unsignedInteger('effective_wait_minutes')->nullable();
            }
            if (!Schema::hasColumn('agent_questions', 'stopped_at')) {
                $table->timestamp('stopped_at', 6)->nullable();
            }
            if (!Schema::hasColumn('agent_questions', 'late_reply_noticed_at')) {
                $table->timestamp('late_reply_noticed_at', 6)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('agent_questions')) {
            return;
        }

        foreach (array_reverse(self::COLUMNS) as $column) {
            if (Schema::hasColumn('agent_questions', $column)) {
                Schema::table('agent_questions', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
