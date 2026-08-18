<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('coding_workspace_refusals')) {
            return;
        }

        Schema::create('coding_workspace_refusals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('coding_project_id');
            $table->string('operation');
            $table->string('reason');
            // Load-bearing, not cosmetic: CodingWorkspaceRefusal sets
            // $timestamps = false and WorkspaceRefusalRecorder::record()
            // never passes created_at explicitly, so without this
            // database-level default every insert would violate this
            // column's own not-null constraint and be silently swallowed
            // by record()'s own catch (\Throwable) -- mirrors
            // 2026_08_05_000000_create_agent_run_actions_table.php's
            // identical useCurrent() precedent for the same situation.
            $table->timestamp('created_at')->useCurrent();

            $table->index('coding_project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coding_workspace_refusals');
    }
};
