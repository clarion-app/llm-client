<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('feedback_signals')) {
            return;
        }

        Schema::create('feedback_signals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('source_event_id')->nullable();
            $table->uuid('conversation_id')->nullable();
            $table->string('signal_type');
            $table->string('pattern_key')->nullable();
            $table->text('raw_context');
            $table->timestamps();
            $table->timestamp('processed_at')->nullable();
            $table->softDeletes();

            $table->unique(['user_id', 'source_event_id']);
            $table->index(['user_id', 'pattern_key', 'processed_at']);
            $table->index(['user_id', 'processed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_signals');
    }
};
