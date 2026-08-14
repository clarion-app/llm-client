<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Exceptions\AgentDefinitionParseException;
use ClarionApp\LlmClient\Exceptions\AgentDefinitionResolutionException;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentVersion;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Ownership-scoped reads for `agents`/`agent_versions` (contracts §12).
 *
 * findAgent() is Phase 3/US1's own surface, used by
 * StoredAgentController::update() to resolve the target agent before
 * handing it to AgentService::update(). listForUser()/versionsForAgent()/
 * findVersion() are Phase 4/US2's own addition (contracts §2/§5/§6).
 * searchForUser() is 094-agent-search-listing's own addition (data-model.md
 * §4) — the caller's full agent list, optionally narrowed by a free-text
 * query over name/instructions, paginated.
 */
class AgentQuery
{
    public function __construct(
        private readonly AgentDefinitionParser $parser,
    ) {}

    /**
     * Find an agent by id, filtered by caller's user ownership — verbatim
     * RunTraceQuery::findRun()'s shape (research.md D5).
     *
     * @return Agent|null Null uniformly for "doesn't exist" and "belongs
     *   to someone else," so a caller never has to special-case which.
     */
    public function findAgent(string $callerUserId, string $agentId): ?Agent
    {
        return Agent::where('id', $agentId)
            ->where('user_id', $callerUserId)
            ->first();
    }

    /**
     * The trash-inclusive counterpart to findAgent() (091, research.md D5)
     * — finds an agent by id regardless of whether it has been retired
     * (soft-deleted), still scoped by caller ownership.
     *
     * Two call sites: clone()'s own source resolution (Phase 3, FR-013 —
     * a retired source is found, not 404'd), and cloned_from display
     * (Phase 4, FR-008 — a since-removed origin still resolves for
     * display). findAgent() itself is deliberately left untouched — every
     * other existing action keeps excluding trashed agents exactly as
     * before.
     *
     * @return Agent|null Null uniformly for "doesn't exist" and "belongs
     *   to someone else," identical to findAgent()'s own contract.
     */
    public function findAgentIncludingTrashed(string $callerUserId, string $agentId): ?Agent
    {
        return Agent::withTrashed()
            ->where('id', $agentId)
            ->where('user_id', $callerUserId)
            ->first();
    }

    /**
     * Every agent the caller owns (contracts §2) — unpaginated, per
     * contracts §2's own "scale/scope expects a small per-user count" note.
     *
     * @return Collection<int, Agent>
     */
    public function listForUser(string $callerUserId): Collection
    {
        return Agent::where('user_id', $callerUserId)->get();
    }

    /**
     * Every version of an agent, in order, paginated (contracts §5). Null
     * uniformly when the agent itself doesn't exist or isn't the caller's
     * own (research.md D5) — the same "not found" signal findAgent() gives,
     * so the controller never has to special-case which.
     */
    public function versionsForAgent(string $callerUserId, string $agentId, int $page = 1): ?LengthAwarePaginator
    {
        if ($this->findAgent($callerUserId, $agentId) === null) {
            return null;
        }

        return AgentVersion::where('agent_id', $agentId)
            ->orderBy('version_number')
            ->paginate(
                config('llm-client.agents.versions_per_page', 25),
                ['*'],
                'page',
                $page,
            );
    }

    /**
     * A single version, scoped by both the parent agent's ownership and
     * the version's own agent_id — a version belonging to a different
     * agent is indistinguishable from a nonexistent one (contracts §6).
     */
    public function findVersion(string $callerUserId, string $agentId, string $versionId): ?AgentVersion
    {
        if ($this->findAgent($callerUserId, $agentId) === null) {
            return null;
        }

        return AgentVersion::where('id', $versionId)
            ->where('agent_id', $agentId)
            ->first();
    }

    /**
     * The caller's full agent list, optionally narrowed by a free-text
     * query over name/instructions, paginated (094-agent-search-listing,
     * data-model.md §4).
     *
     * @return array{
     *   data: list<Agent>,   // already sliced to the requested page
     *   total: int,          // count after filtering by $query
     *   total_unfiltered: int, // count with no $query filter (research.md D7)
     * }
     */
    public function searchForUser(
        string $callerUserId,
        ?string $query,
        int $page,
        int $perPage,
    ): array {
        $agents = Agent::where('user_id', $callerUserId)
            ->with('currentVersion')
            ->orderBy('name')
            ->get();

        $totalUnfiltered = $agents->count();

        $trimmedQuery = $query !== null ? trim($query) : '';

        if ($trimmedQuery === '') {
            $filtered = $agents;
        } else {
            $needle = strtolower($trimmedQuery);

            $filtered = $agents->filter(function (Agent $agent) use ($needle): bool {
                if (str_contains(strtolower($agent->name), $needle)) {
                    return true;
                }

                $instructions = '';

                try {
                    $instructions = $this->parser->parse($agent->currentVersion->raw_definition)->instructions;
                } catch (AgentDefinitionParseException|AgentDefinitionResolutionException) {
                    // Fall back to name-only matching for this one agent
                    // rather than aborting the whole search (research.md D1).
                }

                return str_contains(strtolower($instructions), $needle);
            })->values();
        }

        $total = $filtered->count();
        $data = $filtered->slice(($page - 1) * $perPage, $perPage)->values()->all();

        return [
            'data' => $data,
            'total' => $total,
            'total_unfiltered' => $totalUnfiltered,
        ];
    }
}
