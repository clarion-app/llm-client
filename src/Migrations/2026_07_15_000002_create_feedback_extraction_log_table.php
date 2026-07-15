<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('feedback_extraction_log')) {
            return;
        }

        Schema::create('feedback_extraction_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('declarative_memory_id')->nullable();
            $table->string('pattern_key');
            $table->integer('signals_count');
            $table->json('signal_ids');
            $table->integer('confidence_score');
            $table->string('outcome');
            $table->string('llm_call_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('user_id');
            $table->index('declarative_memory_id');
            $table->index('pattern_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_extraction_log');
    }
};
