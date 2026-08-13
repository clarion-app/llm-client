<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentVersion;
use ClarionApp\LlmClient\ValueObjects\AgentChangeSource;
use Illuminate\Support\Facades\DB;

/**
 * The sole write path for `agents`/`agent_versions` (contracts §12) —
 * mirrors EvalCaseService::addCase()/editCase()'s exact
 * DB::transaction() + MAX(version_number) + 1 pattern verbatim
 * (research.md D1/D4).
 *
 * create()/update() are Phase 3/US1's own surface. restore() is Phase
 * 4/US2's own addition. link()/syncFromFile()/unlink() are Phase 5/US3's
 * own addition (research.md D8/D9/D11) — the only methods that read a
 * file off disk (via the injected GitDefinitionFileReader) rather than
 * accepting raw YAML text directly.
 *
 * Every method that writes an AgentVersion calls
 * AgentDefinitionParser::parse() first, before any write, and propagates
 * any thrown AgentDefinitionParseException/AgentDefinitionResolutionException
 * unchanged — no Agent or AgentVersion row is ever partially written
 * (research.md D7).
 */
class AgentService
{
    public function __construct(
        private readonly AgentDefinitionParser $parser,
        private readonly GitDefinitionFileReader $fileReader,
    ) {
    }

    /**
     * Create a new agent: inserts exactly one agent_versions row
     * (version_number = 1, source = created) and one agents row pointing
     * at it, in one transaction (SC-001).
     *
     * @throws \ClarionApp\LlmClient\Exceptions\AgentDefinitionParseException
     * @throws \ClarionApp\LlmClient\Exceptions\AgentDefinitionResolutionException
     */
    public function create(string $userId, string $rawYaml): Agent
    {
        $definition = $this->parser->parse($rawYaml);

        return DB::transaction(function () use ($userId, $rawYaml, $definition) {
            $agent = Agent::create([
                'user_id' => $userId,
                'name' => $definition->name,
                'current_version_id' => null,
            ]);

            $version = AgentVersion::create([
                'agent_id' => $agent->id,
                'version_number' => 1,
                'raw_definition' => $rawYaml,
                'content_hash' => hash('sha256', $rawYaml),
                'source' => AgentChangeSource::Created->value,
                'changed_by_user_id' => $userId,
            ]);

            $agent->current_version_id = $version->id;
            $agent->save();

            return $agent->fresh();
        });
    }

    /**
     * Update an agent's definition: inserts exactly one *new*
     * agent_versions row (version_number = previous max + 1, source =
     * product_edit) and repoints agents.current_version_id/name at it, in
     * one transaction (SC-002). Never issues an UPDATE or DELETE against
     * any existing agent_versions row — every previously written version
     * stays byte-identical, forever (FR-003/FR-012). The next
     * version_number is derived from MAX(version_number), never
     * COUNT(*), so a version sequence with a gap can never collide with
     * or renumber an existing row.
     *
     * @throws \ClarionApp\LlmClient\Exceptions\AgentDefinitionParseException
     * @throws \ClarionApp\LlmClient\Exceptions\AgentDefinitionResolutionException
     */
    public function update(Agent $agent, string $userId, string $rawYaml): Agent
    {
        $definition = $this->parser->parse($rawYaml);

        return DB::transaction(function () use ($agent, $userId, $rawYaml, $definition) {
            $nextVersionNumber = (int) AgentVersion::where('agent_id', $agent->id)->max('version_number') + 1;

            $version = AgentVersion::create([
                'agent_id' => $agent->id,
                'version_number' => $nextVersionNumber,
                'raw_definition' => $rawYaml,
                'content_hash' => hash('sha256', $rawYaml),
                'source' => AgentChangeSource::ProductEdit->value,
                'changed_by_user_id' => $userId,
            ]);

            $agent->current_version_id = $version->id;
            $agent->name = $definition->name;
            $agent->save();

            return $agent->fresh();
        });
    }

    /**
     * Restore an agent to an earlier version: re-validates the target's
     * raw_definition against *current* installation state before writing
     * anything (research.md D7 — a version that could no longer resolve
     * must never become current), then inserts one new agent_versions row
     * whose content is byte-identical to the target's (source =
     * restoration, restored_from_version_id = $target->id) and repoints
     * current_version_id/name at it, in one transaction (FR-006/FR-007).
     *
     * Deliberately never special-cases restoring to the agent's own
     * already-current version — there is no early-return no-op branch, so
     * restoring to the current version still produces a new, distinct
     * version (spec Edge Cases; contracts §7; mutation-checklist row 10).
     *
     * @throws \ClarionApp\LlmClient\Exceptions\AgentDefinitionParseException
     * @throws \ClarionApp\LlmClient\Exceptions\AgentDefinitionResolutionException
     */
    public function restore(Agent $agent, string $userId, AgentVersion $target): Agent
    {
        $definition = $this->parser->parse($target->raw_definition);

        return DB::transaction(function () use ($agent, $userId, $target, $definition) {
            $nextVersionNumber = (int) AgentVersion::where('agent_id', $agent->id)->max('version_number') + 1;

            $version = AgentVersion::create([
                'agent_id' => $agent->id,
                'version_number' => $nextVersionNumber,
                'raw_definition' => $target->raw_definition,
                'content_hash' => $target->content_hash,
                'source' => AgentChangeSource::Restoration->value,
                'changed_by_user_id' => $userId,
                'restored_from_version_id' => $target->id,
            ]);

            $agent->current_version_id = $version->id;
            $agent->name = $definition->name;
            $agent->save();

            return $agent->fresh();
        });
    }

    /**
     * Link an agent to a definition file in a git-tracked project (contracts
     * §8, US3 AC1): reads the file's current working-tree content
     * (propagating AgentFileUnreadableException on a filesystem-level
     * failure), validates it via the parser (propagating a parse/resolution
     * exception, nothing changed on failure), then always imports it as a
     * new version immediately — linking always starts in-step, regardless
     * of what the stored agent held immediately before linking.
     *
     * The new version is attributed to the file's own git commit, never to
     * $userId (research.md D8) — changed_by_user_id is always null for a
     * file_sync version; $userId is accepted only to keep this method's
     * signature consistent with every other write method in this class
     * (contracts §12), not because it is ever recorded.
     *
     * @throws \ClarionApp\LlmClient\Exceptions\AgentFileUnreadableException
     * @throws \ClarionApp\LlmClient\Exceptions\AgentDefinitionParseException
     * @throws \ClarionApp\LlmClient\Exceptions\AgentDefinitionResolutionException
     */
    public function link(Agent $agent, string $userId, string $repositoryPath, string $filePath): Agent
    {
        $rawYaml = $this->fileReader->readWorkingTreeContent($repositoryPath, $filePath);
        $definition = $this->parser->parse($rawYaml);

        return DB::transaction(function () use ($agent, $repositoryPath, $filePath, $rawYaml, $definition) {
            $contentHash = hash('sha256', $rawYaml);
            $nextVersionNumber = (int) AgentVersion::where('agent_id', $agent->id)->max('version_number') + 1;
            $commit = $this->fileReader->latestCommitFor($repositoryPath, $filePath);

            $version = AgentVersion::create([
                'agent_id' => $agent->id,
                'version_number' => $nextVersionNumber,
                'raw_definition' => $rawYaml,
                'content_hash' => $contentHash,
                'source' => AgentChangeSource::FileSync->value,
                'changed_by_user_id' => null,
                'git_commit_hash' => $commit?->hash,
                'git_author_name' => $commit?->authorName,
                'git_committed_at' => $commit?->committedAt,
            ]);

            $agent->current_version_id = $version->id;
            $agent->name = $definition->name;
            $agent->linked_repository_path = $repositoryPath;
            $agent->linked_file_path = $filePath;
            $agent->linked_synced_file_hash = $contentHash;
            $agent->save();

            return $agent->fresh();
        });
    }

    /**
     * Re-import an already-linked agent's current file content as a new
     * version (contracts §11) — the one explicit action that resolves a
     * FileAhead/BothChanged divergence. Identical import step to link()
     * (same attribution, same "always writes a new version" posture), just
     * against the agent's own already-recorded link columns rather than a
     * caller-supplied path — reuses link() directly rather than
     * duplicating its logic.
     *
     * Callers must confirm the agent is actually linked before calling
     * this (StoredAgentController does so, contracts §11's 422
     * "not_linked" case) — this method trusts linked_repository_path/
     * linked_file_path are both already set.
     *
     * @throws \ClarionApp\LlmClient\Exceptions\AgentFileUnreadableException
     * @throws \ClarionApp\LlmClient\Exceptions\AgentDefinitionParseException
     * @throws \ClarionApp\LlmClient\Exceptions\AgentDefinitionResolutionException
     */
    public function syncFromFile(Agent $agent, string $userId): Agent
    {
        return $this->link($agent, $userId, $agent->linked_repository_path, $agent->linked_file_path);
    }

    /**
     * Clear an agent's link (contracts §9) — resets all three linked_*
     * columns to null together and touches no agent_versions row at all.
     * History is never rewritten by unlinking: every prior file_sync-
     * sourced version stays exactly as it was written.
     */
    public function unlink(Agent $agent): Agent
    {
        $agent->linked_repository_path = null;
        $agent->linked_file_path = null;
        $agent->linked_synced_file_hash = null;
        $agent->save();

        return $agent->fresh();
    }
}
