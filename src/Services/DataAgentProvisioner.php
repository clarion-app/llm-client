<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\Agent;

/**
 * Idempotent create-if-absent per-user provisioning of the `data` agent
 * (Foundational, FR-001) — byte-for-byte the same shape as
 * ResearchAgentProvisioner/CodingAgentProvisioner.
 *
 * The single source of truth for the data agent's definition is
 * src/Templates/data.yaml. ensureForUser() reads that template and feeds
 * it to AgentService::create() — the sole write path for
 * agents/agent_versions — only when the user has no `data` agent yet.
 * A repeat call for the same user returns the existing agent without
 * writing a duplicate Agent or AgentVersion row, so provisioning is safe
 * to invoke from the conversation-creation seam on every store().
 *
 * A soft-deleted data agent is treated as absent: the default query
 * excludes trashed rows, so a user who deleted their data agent gets a
 * fresh one provisioned on the next call.
 */
class DataAgentProvisioner
{
    /** The agent name the template declares and the lookup is keyed on. */
    private const AGENT_NAME = 'data';

    /** The template is the single source of truth. */
    private const TEMPLATE_PATH = __DIR__.'/../Templates/data.yaml';

    public function __construct(
        private readonly AgentService $agentService,
    ) {}

    /**
     * Ensure the given user has a `data` agent, provisioning one from the
     * template if they do not, and return it.
     *
     * @throws \ClarionApp\LlmClient\Exceptions\AgentDefinitionParseException
     * @throws \ClarionApp\LlmClient\Exceptions\AgentDefinitionResolutionException
     */
    public function ensureForUser(string $userId): Agent
    {
        $existing = Agent::where('user_id', $userId)
            ->where('name', self::AGENT_NAME)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $rawYaml = (string) file_get_contents(self::TEMPLATE_PATH);

        return $this->agentService->create($userId, $rawYaml);
    }
}
