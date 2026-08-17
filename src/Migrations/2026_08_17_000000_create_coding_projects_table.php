<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * coding_projects — the registered project a coding-agent conversation
     * is pointed at (112-coding-agent, Foundational, data-model.md §1).
     * `root_path` is stored already `realpath()`-resolved at registration
     * time (CodingProjectController::store()), never the raw input, so
     * every later path-containment check compares against a value already
     * free of `..`/symlink indirection at its own top level.
     * `test_command` is an opaque, user-authored string, never parsed or
     * altered by this feature; `null` means "no runnable test setup".
     *
     * Bridged (`EloquentMultiChainBridge`) like `Server` — persistent,
     * user-owned configuration, not ephemeral/frequently-changing
     * execution data.
     */
    public function up(): void
    {
        if (Schema::hasTable('coding_projects')) {
            return;
        }

        Schema::create('coding_projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('name');
            $table->string('root_path');
            $table->string('test_command')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('coding_projects');
    }
};
