<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agents')) {
            return;
        }

        Schema::create('agents', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // The owning user. Never nullable — unlike agent_runs.user_id
            // (076's null-user system-run case), an Agent is always
            // deliberately authored by a specific person; there is no
            // system-initiated agent.
            $table->uuid('user_id');

            // Denormalized from the current version's parsed
            // AgentDefinition::name (086) — kept in sync by AgentService on
            // every write (create/update/restore/file-sync all re-set it
            // from the newly-current version's parsed name). Exists purely
            // so GET /agents can list agents without parsing every one's
            // raw_definition on every request. Never independently
            // editable — it only ever moves in lockstep with
            // current_version_id.
            $table->string('name', 255);

            // Points at the agent_versions row currently in effect.
            // Nullable at the schema level only because the identity row
            // must exist before its first version can reference agent_id;
            // AgentService::create() sets this in the same transaction that
            // creates both rows (matching EvalCaseService::addCase()'s
            // exact pattern), so it is never observed null by any read
            // outside that transaction.
            $table->uuid('current_version_id')->nullable();

            // The filesystem path to a git working directory this agent's
            // definition is linked to. Set/cleared together with
            // linked_file_path/linked_synced_file_hash only by
            // AgentService::link()/unlink() — no partial-link state is
            // ever persisted.
            $table->string('linked_repository_path', 1024)->nullable();

            // Path to the definition file, relative to
            // linked_repository_path. Null iff linked_repository_path is
            // null.
            $table->string('linked_file_path', 1024)->nullable();

            // sha256 hex digest of the file's content as of the last
            // confirmed sync point (research.md D9). Null iff not linked.
            $table->string('linked_synced_file_hash', 64)->nullable();

            $table->timestamps();

            // Required by EloquentMultiChainBridge (declares SoftDeletes
            // internally — omitting the column breaks every query, the
            // eval_cases.deleted_at/messages.deleted_at precedent).
            // Archiving an agent (if ever exposed) never cascades to its
            // versions — the identical posture EvalCaseService::archive()
            // already established.
            $table->softDeletes();

            $table->index('user_id');
            $table->index('current_version_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};
