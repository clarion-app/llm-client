<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\ValueObjects\AgentDefinition;

/**
 * The one-query hot-path entry point roadmap item 4.2.1 is expected to
 * consume (contracts §12, research.md D6) — not wired into any
 * conversation-start path by this feature (research.md D12); built and
 * tested here as a ready-to-consume, standalone unit.
 *
 * currentDefinitionFor() reads the agent's current_version_id relation —
 * an indexed, single-row lookup whose cost is flat regardless of how many
 * prior versions an agent has accumulated (never a MAX(version_number)- or
 * ORDER BY-based scan over the agent's full version history) — and
 * re-parses its raw_definition fresh, every call. No caching layer: this
 * deliberately mirrors AgentDefinition::isOperationPermitted()'s own
 * "never cached, live installation state" contract (086) — a resolved
 * AgentDefinition must always reflect whatever the operation catalog and
 * installation state currently allow, not a snapshot from an earlier call.
 * No caller-supplied user id either: by the time a caller holds an `Agent`
 * instance at all, ownership has already been checked by whoever obtained
 * it (AgentQuery::findAgent()) — this resolver has no notion of "whose"
 * agent it is, only "which."
 */
class AgentVersionResolver
{
    public function __construct(
        private readonly AgentDefinitionParser $parser,
    ) {
    }

    /**
     * @throws \ClarionApp\LlmClient\Exceptions\AgentDefinitionParseException
     * @throws \ClarionApp\LlmClient\Exceptions\AgentDefinitionResolutionException
     */
    public function currentDefinitionFor(Agent $agent): AgentDefinition
    {
        $agent->loadMissing('currentVersion');

        return $this->parser->parse($agent->currentVersion->raw_definition);
    }
}
