<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chunk_summaries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id')->index();
            $table->unsignedInteger('chunk_index');
            $table->string('source_hash', 64);
            $table->unsignedInteger('source_message_count');
            $table->json('summary');
            $table->unsignedInteger('summary_tokens')->nullable();
            $table->string('condensation_model')->nullable();
            $table->string('condensation_provider')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'chunk_index']);
        });

        Schema::create('condensation_states', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id')->unique();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('cooldown_until')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('condensation_states');
        Schema::dropIfExists('chunk_summaries');
    }
};
