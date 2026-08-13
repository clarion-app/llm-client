<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agent_versions')) {
            return;
        }

        Schema::create('agent_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // The owning agent identity. No DB-level FK — this package's
            // established posture for this shape (eval_case_versions.case_id,
            // spending_ceilings, agent_run_steps all omit them too), relying
            // on application-level integrity and soft deletes rather than
            // ON DELETE CASCADE.
            $table->uuid('agent_id');

            // 1 at creation, MAX(version_number) + 1 on every subsequent
            // write for the same agent — never renumbered, never reused,
            // even across a restore. The agent_run_steps.position precedent
            // for deterministic, human-readable ordering.
            $table->unsignedInteger('version_number');

            // The exact YAML bytes this version holds — verbatim, not a
            // re-serialization of a parsed value object. What
            // AgentDefinitionParser::parse() is called with to reconstruct
            // the resolved AgentDefinition on demand, and what content_hash
            // is computed from.
            $table->text('raw_definition');

            // sha256 hex digest of raw_definition, computed once at write
            // time (immutable thereafter, since raw_definition never
            // changes on an existing row). Used by AgentDivergenceChecker
            // without needing to re-hash on every check.
            $table->string('content_hash', 64);

            // AgentChangeSource value: created | product_edit | restoration
            // | file_sync. Observability/attribution context only — never a
            // branch point for AgentVersionResolver's resolution logic,
            // which treats every version identically regardless of how it
            // was produced.
            $table->string('source', 16);

            // The acting user, for created/product_edit/restoration.
            // Always null for file_sync (research.md D8 — attributed to
            // git metadata instead, never invented as a product user).
            $table->uuid('changed_by_user_id')->nullable();

            // Set only when source = restoration — the id of the version
            // that was restored (self-referential, no DB FK, same no-FK
            // posture as agent_id above). Null for every other source.
            $table->uuid('restored_from_version_id')->nullable();

            // Populated only for source = file_sync, when
            // GitDefinitionFileReader::latestCommitFor() resolved a commit.
            // Null when the file was uncommitted at sync time, or for any
            // non-file-sync source.
            $table->string('git_commit_hash', 40)->nullable();

            // Companion to git_commit_hash — the commit's %an. Same
            // nullability rule.
            $table->string('git_author_name', 255)->nullable();

            // Companion to git_commit_hash — the commit's %at, parsed.
            // Same nullability rule.
            $table->timestamp('git_committed_at')->nullable();

            $table->timestamps();

            // Required by EloquentMultiChainBridge, not optional (see the
            // agents migration's identical note). Stays null for every row
            // this feature ever writes — nothing in this feature's write
            // path ever soft-deletes a version.
            $table->softDeletes();

            // The two-writer race backstop (research.md D1's "Testing"
            // note: a genuine simultaneous double-write is prevented here,
            // not by application-level locking).
            $table->unique(['agent_id', 'version_number']);

            $table->index('agent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_versions');
    }
};
