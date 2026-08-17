<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\Agent;

/**
 * Idempotent create-if-absent per-user provisioning of the `scheduler`
 * agent, mirroring ResearchAgentProvisioner/CodingAgentProvisioner/
 * DataAgentProvisioner byte-for-byte.
 *
 * The single source of truth for the scheduler agent's definition is
 * src/Templates/scheduler.yaml. ensureForUser() reads that template and
 * feeds it to AgentService::create() — the sole write path for
 * agents/agent_versions — only when the user has no `scheduler` agent yet.
 * A repeat call for the same user returns the existing agent without
 * writing a duplicate Agent or AgentVersion row, so provisioning is safe to
 * invoke from the conversation-creation seam on every store().
 *
 * A soft-deleted scheduler agent is treated as absent: the default query
 * excludes trashed rows, so a user who deleted their scheduler agent gets a
 * fresh one provisioned on the next call.
 *
 * Provisioning alone does not make a scheduler agent do anything — a user
 * still has to define at least one SchedulerTrigger, through its own
 * controller, pointed at the agent this returns, before anything fires.
 */
class SchedulerAgentProvisioner
{
    /** The agent name the template declares and the lookup is keyed on. */
    private const AGENT_NAME = 'scheduler';

    /** The template is the single source of truth. */
    public const TEMPLATE_PATH = __DIR__.'/../Templates/scheduler.yaml';

    public function __construct(
        private readonly AgentService $agentService,
    ) {}

    /**
     * Ensure the given user has a `scheduler` agent, provisioning one from
     * the template if they do not, and return it.
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
