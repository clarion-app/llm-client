<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Exceptions\AgentDefinitionParseException;
use ClarionApp\LlmClient\Exceptions\AgentDefinitionResolutionException;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentShareGrant;
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
     * Find an agent by id, accessible to the caller either because they
     * own it or because an active (non-revoked) AgentShareGrant of either
     * permission level names them as recipient (096-agent-sharing,
     * data-model.md §3). Null uniformly for "doesn't exist," "not yours,"
     * and "not shared with you," matching findAgent()'s own contract.
     *
     * A grant's default query already excludes soft-deleted (revoked) rows
     * — AgentShareGrant uses SoftDeletes, so no explicit
     * whereNull('deleted_at')/withTrashed() exclusion is needed here.
     *
     * @return Agent|null
     */
    public function findAccessibleAgent(string $callerUserId, string $agentId): ?Agent
    {
        return Agent::where('id', $agentId)
            ->where(fn ($query) => $query
                ->where('user_id', $callerUserId)
                ->orWhereIn('id', $this->activeGrantAgentIds($callerUserId)))
            ->first();
    }

    /**
     * The editable counterpart to findAccessibleAgent() — identical
     * shape, but a grant only satisfies it when its permission is
     * 'use_and_edit'; a 'use'-only grant does not (096-agent-sharing,
     * data-model.md §3).
     *
     * @return Agent|null
     */
    public function findEditableAgent(string $callerUserId, string $agentId): ?Agent
    {
        return Agent::where('id', $agentId)
            ->where(fn ($query) => $query
                ->where('user_id', $callerUserId)
                ->orWhereIn('id', $this->activeGrantAgentIds($callerUserId, 'use_and_edit')))
            ->first();
    }

    /**
     * The ids of every agent actively (non-revoked) shared with
     * $callerUserId, optionally narrowed to a specific permission level.
     *
     * @return Collection<int, string>
     */
    private function activeGrantAgentIds(string $callerUserId, ?string $permission = null): Collection
    {
        return AgentShareGrant::where('recipient_user_id', $callerUserId)
            ->when($permission !== null, fn ($query) => $query->where('permission', $permission))
            ->pluck('agent_id');
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
     * Extended by 096-agent-sharing (data-model.md §3) to also include
     * every agent actively (non-revoked) shared with the caller, alongside
     * the agents they own outright — the one query behind GET
     * /agents/search, so this is where FR-003/FR-004 are made real.
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
            ->orWhereIn('id', $this->activeGrantAgentIds($callerUserId))
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
