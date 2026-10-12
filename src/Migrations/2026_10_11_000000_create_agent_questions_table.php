<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Questions agents ask users mid-task.
 *
 * `pending_conversation_id` carries the conversation id only while the
 * question is pending and is NULL once it is resolved. The UNIQUE index on
 * it allows at most one pending question per conversation on every
 * supported database (each permits any number of NULLs in a unique index),
 * which a partial unique index could not do portably.
 *
 * `state` is a plain string rather than an ENUM so a new state never needs
 * a column change.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agent_questions')) {
            return;
        }

        Schema::create('agent_questions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id');
            $table->uuid('user_id');
            $table->uuid('pending_conversation_id')->nullable();
            $table->uuid('run_id')->nullable();
            $table->uuid('step_id')->nullable();
            $table->uuid('action_id')->nullable();
            $table->uuid('paused_message_id');
            $table->uuid('display_message_id')->nullable();
            $table->string('tool_call_id', 128);
            $table->string('channel', 50);
            $table->string('resume_mode', 8);
            $table->text('question');
            $table->json('choices');
            $table->boolean('allow_multiple')->default(false);
            $table->string('state', 16)->default('pending');
            $table->timestamp('asked_at', 6);
            $table->timestamp('expires_at', 6);
            $table->timestamp('delivered_at', 6)->nullable();
            $table->string('delivery_failure_reason', 512)->nullable();
            $table->timestamp('resolved_at', 6)->nullable();
            $table->text('answer_raw')->nullable();
            $table->json('answer_selected')->nullable();
            $table->uuid('answered_by')->nullable();
            $table->uuid('answer_message_id')->nullable();
            $table->timestamp('resumed_at', 6)->nullable();
            $table->timestamps();

            $table->unique('pending_conversation_id');
            $table->index('conversation_id');
            $table->index(['state', 'expires_at']);
            $table->index(['state', 'resumed_at', 'resolved_at']);
            $table->index(['conversation_id', 'asked_at']);
            $table->index('run_id');
            $table->index(['user_id', 'channel', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_questions');
    }
};
