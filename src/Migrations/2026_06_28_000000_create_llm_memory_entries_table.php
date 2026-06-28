<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('llm_memory_entries')) {
            return;
        }
        Schema::create('llm_memory_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->enum('scope', ['scratch', 'short_term', 'long_term']);
            $table->uuid('agent_id');
            $table->uuid('user_id');
            $table->uuid('conversation_id')->nullable();
            $table->string('turn_id')->nullable();
            $table->string('key', 64)->nullable();
            $table->text('content');
            $table->timestamp('last_accessed_at')->useCurrent();
            $table->timestamps();

            $table->unique(['scope', 'agent_id', 'key']);
            $table->index(['scope', 'agent_id']);
            $table->index(['scope', 'user_id']);
            $table->index(['scope', 'conversation_id']);
            $table->index(['scope', 'last_accessed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_memory_entries');
    }
};
