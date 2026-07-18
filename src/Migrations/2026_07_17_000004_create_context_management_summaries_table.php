<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('context_management_summaries')) {
            return;
        }

        Schema::create('context_management_summaries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->enum('entity_type', ['conversation', 'user']);
            $table->uuid('entity_id');
            $table->bigInteger('trim_activations')->default(0);
            $table->bigInteger('smart_trim_activations')->default(0);
            $table->bigInteger('condense_activations')->default(0);
            $table->bigInteger('total_tokens_saved')->default(0);
            $table->bigInteger('total_requests')->default(0);
            $table->timestamp('updated_at')->useCurrent();

            $table->unique(['entity_type', 'entity_id']);
            $table->index(['entity_type', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('context_management_summaries');
    }
};
