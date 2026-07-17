<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('usage_summaries')) {
            return;
        }

        Schema::create('usage_summaries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->enum('entity_type', ['conversation', 'user']);
            $table->uuid('entity_id');
            $table->bigInteger('input_tokens')->default(0);
            $table->bigInteger('output_tokens')->default(0);
            $table->bigInteger('total_tokens')->default(0);
            $table->bigInteger('estimated_input_tokens')->default(0);
            $table->bigInteger('estimated_output_tokens')->default(0);
            $table->bigInteger('estimated_total_tokens')->default(0);
            $table->integer('request_count')->default(0);
            $table->timestamp('updated_at')->useCurrent();

            $table->unique(['entity_type', 'entity_id']);
            $table->index(['entity_type', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_summaries');
    }
};
