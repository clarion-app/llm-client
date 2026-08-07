<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cost_summaries')) {
            return;
        }

        Schema::create('cost_summaries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->enum('entity_type', ['conversation', 'user', 'agent']);
            $table->string('entity_id', 255);
            $table->uuid('user_id');
            $table->date('period_date');
            $table->integer('request_count')->default(0);
            $table->decimal('priced_cost_total', 20, 10)->default(0);
            $table->integer('zero_priced_request_count')->default(0);
            $table->integer('unpriced_request_count')->default(0);
            $table->bigInteger('unpriced_total_tokens')->default(0);
            $table->integer('estimated_request_count')->default(0);
            $table->timestamp('updated_at')->useCurrent();

            $table->unique(['entity_type', 'entity_id', 'user_id', 'period_date']);
            $table->index(['entity_type', 'period_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_summaries');
    }
};
