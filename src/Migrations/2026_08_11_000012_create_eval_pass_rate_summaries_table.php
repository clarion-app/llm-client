<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('eval_pass_rate_summaries')) {
            return;
        }

        Schema::create('eval_pass_rate_summaries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('agent_label', 255);
            $table->date('period_date');
            $table->unsignedInteger('pass_count')->default(0);
            $table->unsignedInteger('fail_count')->default(0);
            $table->unsignedInteger('needs_human_review_count')->default(0);
            $table->unsignedInteger('errored_count')->default(0);
            $table->unsignedInteger('unjudged_count')->default(0);
            $table->unsignedInteger('total_count')->default(0);
            $table->timestamp('updated_at')->useCurrent();

            $table->unique(['agent_label', 'period_date']);
            $table->index(['agent_label', 'period_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eval_pass_rate_summaries');
    }
};
