<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\Agent;

/**
 * Idempotent create-if-absent per-user provisioning of the `coding` agent
 * (112-coding-agent, Foundational, D3, FR-001) — byte-for-byte the same
 * shape as ResearchAgentProvisioner.
 *
 * The single source of truth for the coding agent's definition is
 * src/Templates/coding.yaml. ensureForUser() reads that template and
 * feeds it to AgentService::create() — the sole write path for
 * agents/agent_versions — only when the user has no `coding` agent yet.
 * A repeat call for the same user returns the existing agent without
 * writing a duplicate Agent or AgentVersion row, so provisioning is safe
 * to invoke from the conversation-creation seam on every store().
 *
 * A soft-deleted coding agent is treated as absent: the default query
 * excludes trashed rows, so a user who deleted their coding agent gets a
 * fresh one provisioned on the next call.
 *
 * A separate, unrelated `AgentKind::coding()` slug (089-agent-scaffolding-
 * cli) also exists, as a human-invoked CLI scaffolding starting point
 * (`agent:create <name> --kind=coding`). It never itself creates an Agent
 * row named "coding" — the caller-supplied name is always used instead —
 * so there is no functional collision with this provisioner UNLESS a user
 * has separately, manually named a scaffolded agent exactly "coding", in
 * which case ensureForUser()'s existing-agent lookup would treat that
 * unrelated, differently-permissioned agent as already provisioned. This
 * narrow, out-of-scope edge case is documented here rather than guarded
 * against (spec.md does not address it; inventing a disambiguation
 * mechanism would be unrequested scope).
 */
class CodingAgentProvisioner
{
    /** The agent name the template declares and the lookup is keyed on. */
    private const AGENT_NAME = 'coding';

    /** The template is the single source of truth. */
    private const TEMPLATE_PATH = __DIR__.'/../Templates/coding.yaml';

    public function __construct(
        private readonly AgentService $agentService,
    ) {}

    /**
     * Ensure the given user has a `coding` agent, provisioning one from
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
