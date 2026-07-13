<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('episodic_memories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('conversation_id');
            $table->text('summary');
            $table->json('topics');
            $table->boolean('protected')->default(false);
            $table->unsignedInteger('word_count');
            $table->unsignedInteger('summary_word_count');
            $table->timestamps();
            $table->softDeletes();

            $table->index('user_id');
            $table->index('conversation_id');
            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'protected']);
            $table->index('deleted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('episodic_memories');
    }
};
