<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tool_reliability_summaries')) {
            return;
        }

        Schema::create('tool_reliability_summaries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tool_name', 256);
            $table->string('agent_id', 255);
            $table->uuid('user_id');
            $table->date('period_date');
            $table->integer('invocation_count')->default(0);
            $table->integer('success_count')->default(0);
            $table->integer('failure_count')->default(0);
            $table->integer('failure_timeout_count')->default(0);
            $table->integer('failure_connection_failure_count')->default(0);
            $table->integer('failure_authentication_failure_count')->default(0);
            $table->integer('failure_invalid_input_count')->default(0);
            $table->integer('failure_server_error_count')->default(0);
            $table->integer('failure_other_count')->default(0);
            $table->integer('failure_uncategorized_count')->default(0);
            $table->timestamp('updated_at')->useCurrent();

            $table->unique(['tool_name', 'agent_id', 'user_id', 'period_date']);
            $table->index(['tool_name', 'period_date']);
            $table->index(['tool_name', 'agent_id', 'period_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tool_reliability_summaries');
    }
};
