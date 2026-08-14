<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Exceptions\AgentNameAlreadyInUseException;
use ClarionApp\LlmClient\Exceptions\LastActiveAgentException;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentVersion;
use ClarionApp\LlmClient\ValueObjects\AgentChangeSource;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Yaml;

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
    private readonly AgentHelperService $helperService;

    /**
     * $helperService is nullable-defaulted rather than a required
     * constructor-promoted dependency (100-subagent-tool-restrictions) —
     * mirroring AgentLoopService's own established late-added-collaborator
     * pattern (research.md-cited precedent, Grounding note item 5) — so
     * every existing direct `new AgentService($parser, $fileReader)` call
     * site across this package's own test suite keeps working unchanged;
     * the real container-resolved instance always passes it explicitly
     * (LlmClientServiceProvider).
     */
    public function __construct(
        private readonly AgentDefinitionParser $parser,
        private readonly GitDefinitionFileReader $fileReader,
        ?AgentHelperService $helperService = null,
    ) {
        $this->helperService = $helperService ?? new AgentHelperService(
            new AgentQuery($this->parser),
            new AgentHelperQuery(new AgentQuery($this->parser), $this->parser),
        );
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
     * Clone an agent into a complete, independent copy under a new name,
     * optionally for a different owner (091-agent-clone-fork, contracts §1,
     * research.md D1/D2/D2a/D6). Rewrites only the `name:` key of the
     * source's current raw YAML document (Yaml::parse()/Yaml::dump() —
     * research.md D10, no existing serializer to reuse), then re-validates
     * the rewritten document with a single AgentDefinitionParser::parse()
     * call — the source's *current* definition is re-checked against live
     * installation state as a side effect, but only one parse() call is
     * ever made (on the rewritten document), never two.
     *
     * A per-owner name collision is refused *before* any row is written
     * (FR-014) — `Agent`'s default query excludes trashed rows, so a name
     * freed by soft-deleting an agent is immediately reusable (research.md
     * D6).
     *
     * Inserts exactly one new Agent row (`cloned_from_agent_id` = the
     * source's id; `current_version_id` starts null) and exactly one new
     * AgentVersion (`version_number = 1`, `source = Created`) in one
     * transaction — the identical two-row shape create() already produces.
     * Deliberately does not set `linked_repository_path`/`linked_file_path`/
     * `linked_synced_file_hash` — a clone is never linked to the source's
     * git file, even when the source itself is (research.md D2a).
     *
     * @throws \ClarionApp\LlmClient\Exceptions\AgentDefinitionParseException
     * @throws \ClarionApp\LlmClient\Exceptions\AgentDefinitionResolutionException
     * @throws AgentNameAlreadyInUseException
     */
    public function clone(Agent $source, string $newOwnerUserId, string $newName): Agent
    {
        $document = Yaml::parse($source->currentVersion->raw_definition);
        $document = is_array($document) ? $document : [];
        $document['name'] = $newName;
        $rawYaml = Yaml::dump($document, 4, 2);

        $this->parser->parse($rawYaml);

        if (Agent::where('user_id', $newOwnerUserId)->where('name', $newName)->exists()) {
            throw new AgentNameAlreadyInUseException($newName);
        }

        return DB::transaction(function () use ($newOwnerUserId, $newName, $rawYaml, $source) {
            $agent = Agent::create([
                'user_id' => $newOwnerUserId,
                'name' => $newName,
                'current_version_id' => null,
                'cloned_from_agent_id' => $source->id,
            ]);

            $version = AgentVersion::create([
                'agent_id' => $agent->id,
                'version_number' => 1,
                'raw_definition' => $rawYaml,
                'content_hash' => hash('sha256', $rawYaml),
                'source' => AgentChangeSource::Created->value,
                'changed_by_user_id' => $newOwnerUserId,
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

        $this->helperService->guardAgainstExceedingActiveParents($agent, $definition);

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

        $this->helperService->guardAgainstExceedingActiveParents($agent, $definition);

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

        $this->helperService->guardAgainstExceedingActiveParents($agent, $definition);

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

    /**
     * Reactivate an agent (092-agent-activation, FR-002). A clean no-op —
     * no write at all — when the agent is already active (FR-014). Never
     * writes an AgentVersion row and never touches anything the agent has
     * already produced (FR-003/FR-004), mirroring unlink()'s own direct
     * property-assignment shape.
     */
    public function activate(Agent $agent): Agent
    {
        if ($agent->is_active === true) {
            return $agent;
        }

        $agent->is_active = true;
        $agent->save();

        return $agent->fresh();
    }

    /**
     * Deactivate an agent (092-agent-activation, FR-001/FR-003/FR-004/
     * FR-013/FR-014, research.md D6). A clean no-op — no write at all, and
     * no last-active-agent check performed — when the agent is already
     * inactive (FR-014, checked first so an already-deactivated agent can
     * never trigger a spurious warning). Otherwise, refuses before any
     * write when deactivating this agent would leave the caller with no
     * remaining active agents, unless $confirmed is true — the guard is
     * scoped strictly to this agent's own owner (`user_id`), never
     * installation-wide.
     *
     * @throws LastActiveAgentException
     */
    public function deactivate(Agent $agent, bool $confirmed = false): Agent
    {
        if ($agent->is_active === false) {
            return $agent;
        }

        $hasOtherActive = Agent::where('user_id', $agent->user_id)
            ->where('is_active', true)
            ->where('id', '!=', $agent->id)
            ->exists();

        if (!$confirmed && !$hasOtherActive) {
            throw new LastActiveAgentException($agent->id, $agent->name);
        }

        $agent->is_active = false;
        $agent->save();

        return $agent->fresh();
    }
}
