<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Exceptions\AgentDefinitionParseException;
use ClarionApp\LlmClient\Exceptions\AgentDefinitionResolutionException;
use ClarionApp\LlmClient\Models\AgentVersion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\ConversationHandoff;
use ClarionApp\LlmClient\ValueObjects\AgentDefinition;

/**
 * Resolves "the AgentDefinition this conversation is bound to," if any
 * (090-agent-version-binding, Phase 4/US2, contracts §2, research.md D4).
 *
 * Always resolves via the conversation's own fixed agent_version_id (the
 * agentVersion() relation) — never via agent()->currentVersion, which would
 * silently re-resolve to whatever version the agent has since been edited
 * to, defeating the whole point of the binding (FR-003/research.md D1).
 *
 * forConversation() never throws: a resolution failure (a since-deleted
 * AgentVersion row, or a raw_definition that no longer parses/resolves
 * against current installation state — e.g. its named model was later
 * removed) degrades to null, running the turn with no bound
 * instructions/permission narrowing for that turn only. It must never
 * retroactively change conversation.agent_version_id itself.
 */
class ConversationAgentDefinitionResolver
{
    public function __construct(private readonly AgentDefinitionParser $parser)
    {
    }

    public function forConversation(Conversation $conversation): ?AgentDefinition
    {
        if ($conversation->agent_version_id === null) {
            return null;
        }

        $version = $conversation->agentVersion;

        if ($version === null) {
            return null;
        }

        try {
            return $this->parser->parse($version->raw_definition);
        } catch (AgentDefinitionParseException | AgentDefinitionResolutionException) {
            return null;
        }
    }

    /**
     * Resolves "the AgentDefinition currently governing this conversation" —
     * the latest handoff's target if the conversation has ever been handed
     * off (093-agent-handoff, data-model.md §3), otherwise falling back to
     * forConversation()'s own original-binding resolution unchanged.
     *
     * Degrades to null on any resolution failure, identically to
     * forConversation() — never throws.
     */
    public function effectiveDefinitionFor(Conversation $conversation): ?AgentDefinition
    {
        $latest = ConversationHandoff::where('conversation_id', $conversation->id)
            ->orderByDesc('position')
            ->first();

        if ($latest === null) {
            return $this->forConversation($conversation);
        }

        $version = AgentVersion::find($latest->to_agent_version_id);

        if ($version === null) {
            return null;
        }

        try {
            return $this->parser->parse($version->raw_definition);
        } catch (AgentDefinitionParseException | AgentDefinitionResolutionException) {
            return null;
        }
    }
}
