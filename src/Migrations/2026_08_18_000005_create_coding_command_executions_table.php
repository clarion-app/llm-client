<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('coding_command_executions')) {
            return;
        }

        Schema::create('coding_command_executions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('coding_project_id');
            $table->uuid('user_id');
            $table->text('command');
            $table->string('status');
            $table->integer('exit_code')->nullable();
            $table->boolean('timed_out')->default(false);
            $table->longText('stdout')->nullable();
            $table->longText('stderr')->nullable();
            $table->boolean('output_truncated')->default(false);
            $table->boolean('network_enabled');
            $table->integer('duration_ms')->nullable();
            $table->uuid('agent_id')->nullable();
            $table->string('agent_name')->nullable();
            $table->uuid('conversation_id')->nullable();
            // Load-bearing, not cosmetic: CodingCommandExecution sets
            // $timestamps = false and no write site ever passes
            // created_at explicitly -- without this database-level
            // default every insert would violate this column's own
            // not-null constraint, mirroring
            // 2026_08_18_000004_create_coding_workspace_changes_table.php's
            // identical useCurrent() precedent for the same situation.
            $table->timestamp('created_at')->useCurrent();

            $table->index('coding_project_id');
            $table->index('user_id');
            $table->index('conversation_id');
            $table->index(['coding_project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coding_command_executions');
    }
};
