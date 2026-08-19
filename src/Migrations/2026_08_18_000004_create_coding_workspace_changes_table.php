<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('coding_workspace_changes')) {
            return;
        }

        Schema::create('coding_workspace_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('coding_project_id');
            $table->uuid('user_id');
            $table->string('root_path');
            $table->string('path');
            $table->string('operation');
            $table->longText('old_content')->nullable();
            $table->boolean('old_content_truncated')->default(false);
            $table->boolean('old_binary')->default(false);
            $table->unsignedBigInteger('old_size')->nullable();
            $table->longText('new_content')->nullable();
            $table->boolean('new_content_truncated')->default(false);
            $table->boolean('new_binary')->default(false);
            $table->unsignedBigInteger('new_size')->nullable();
            $table->uuid('agent_id')->nullable();
            $table->string('agent_name')->nullable();
            $table->uuid('conversation_id')->nullable();
            // Load-bearing, not cosmetic: CodingWorkspaceChange sets
            // $timestamps = false and WorkspaceChangeRecorder::record()
            // never passes created_at explicitly, so without this
            // database-level default every insert would violate this
            // column's own not-null constraint and be silently swallowed
            // by record()'s own catch (\Throwable) -- mirrors
            // 2026_08_18_000002_create_coding_workspace_refusals_table.php's
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
        Schema::dropIfExists('coding_workspace_changes');
    }
};
