<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a question was presented to its user and how it was answered.
 *
 * - `preamble`: the assistant text shown above the question (synchronous
 *   path only), so a form rebuilt from the row shows what the display
 *   message shows. LONGTEXT like that message's content: the model's text
 *   is not length-limited, and a TEXT column would make opening a question
 *   with a long preamble fail on MySQL.
 * - `presentation`: `rich` | `plain_text`, written once delivery finished.
 * - `presentation_fallback_reason`: why plain text was used
 *   (`channel_lacks_options` | `too_many_options` | `rich_send_rejected`).
 * - `answer_method`: `typed_reply` | `web_form` | `messaging_option`, set
 *   with the answer.
 *
 * All nullable plain strings rather than ENUMs, so a new value never needs
 * a column change.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('agent_questions')) {
            return;
        }

        Schema::table('agent_questions', function (Blueprint $table) {
            if (!Schema::hasColumn('agent_questions', 'preamble')) {
                $table->longText('preamble')->nullable();
            }
            if (!Schema::hasColumn('agent_questions', 'presentation')) {
                $table->string('presentation', 16)->nullable();
            }
            if (!Schema::hasColumn('agent_questions', 'presentation_fallback_reason')) {
                $table->string('presentation_fallback_reason', 32)->nullable();
            }
            if (!Schema::hasColumn('agent_questions', 'answer_method')) {
                $table->string('answer_method', 24)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('agent_questions')) {
            return;
        }

        foreach (['answer_method', 'presentation_fallback_reason', 'presentation', 'preamble'] as $column) {
            if (Schema::hasColumn('agent_questions', $column)) {
                Schema::table('agent_questions', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
