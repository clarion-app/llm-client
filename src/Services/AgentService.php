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
 * 4/US2's own addition — link()/syncFromFile()/unlink() remain User Story
 * 3's own scope, added later.
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
}
