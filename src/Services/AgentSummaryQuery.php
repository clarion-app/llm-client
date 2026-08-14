<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\LlmClient\Exceptions\AgentDefinitionParseException;
use ClarionApp\LlmClient\Exceptions\AgentDefinitionResolutionException;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentRun;
use ClarionApp\LlmClient\ValueObjects\AgentDefinition;
use ClarionApp\LlmClient\ValueObjects\MemoryKind;
use ClarionApp\LlmClient\ValueObjects\ReducibleTool;
use Illuminate\Support\Carbon;

/**
 * The composition point for an agent summary card's full field set
 * (095-agent-summary-cards, data-model.md §7, research.md D1-D6) — the only
 * new service class this feature adds. Composes exactly three batched
 * queries (run count, cost, reliability) plus one catalog resolution, never
 * one query per agent (research.md D6): a page of any size costs the same
 * three SELECTs as a single agent would.
 *
 * A parse failure on one agent's current version degrades only that one
 * agent's purpose/capabilities/operation_count/memory_enabled to empty/zero
 * values, mirroring AgentQuery::searchForUser()'s own per-agent defensive
 * catch — the whole call never aborts for one bad definition. An absent
 * lookup entry in any of the three batched maps is a normal, expected case
 * (an agent with no cost/reliability/run activity yet), resolved to that
 * map's own established zero-value/no_activity default shape, never a
 * missing key on the returned per-agent array.
 */
class AgentSummaryQuery
{
    /**
     * Standing in for "lifetime" (research.md D1/D2) — cost_summaries/
     * tool_reliability_summaries are both confirmed never-purged rollups, so
     * this wide, hardcoded range is effectively unbounded on the near side.
     */
    private const LIFETIME_FROM = '1970-01-01';

    public function __construct(
        private readonly AgentDefinitionParser $parser,
        private readonly CostRollupQuery $costQuery,
        private readonly ToolReliabilityQuery $reliabilityQuery,
    ) {
    }

    /**
     * @param list<Agent> $agents Already-loaded, with currentVersion
     *   eager-loaded (the same collection AgentQuery::searchForUser()
     *   already produces).
     * @return array<string, array> Keyed by agent id -> the card-summary
     *   shape (data-model.md §8).
     */
    public function summariesFor(array $agents, string $callerUserId): array
    {
        $agentIds = array_map(fn (Agent $agent): string => $agent->id, $agents);

        $catalog = $this->buildCatalog();

        $to = Carbon::now()->toDateString();

        $runCounts = AgentRun::whereIn('agent_id', $agentIds)
            ->where('user_id', $callerUserId)
            ->selectRaw('agent_id, COUNT(*) as run_count')
            ->groupBy('agent_id')
            ->get()
            ->keyBy('agent_id');

        $costRows = collect($this->costQuery->agentList(self::LIFETIME_FROM, $to, $callerUserId, false))
            ->keyBy('agent_id');

        $reliabilityRows = collect($this->reliabilityQuery->agentList(self::LIFETIME_FROM, $to, $callerUserId, false))
            ->keyBy('agent_id');

        $summaries = [];

        foreach ($agents as $agent) {
            $summaries[$agent->id] = $this->assembleSummary(
                $agent,
                $catalog,
                $runCounts->get($agent->id),
                $costRows->get($agent->id),
                $reliabilityRows->get($agent->id),
            );
        }

        return $summaries;
    }

    /**
     * @param list<array{operationId: string, method: string}> $catalog
     */
    private function assembleSummary(Agent $agent, array $catalog, mixed $runCountRow, mixed $costRow, mixed $reliabilityRow): array
    {
        $purpose = '';
        $capabilities = [];
        $operationCount = 0;
        $memoryEnabled = false;

        try {
            $definition = $this->parser->parse($agent->currentVersion->raw_definition);

            $purpose = $definition->instructions;
            $capabilities = array_map(
                static fn (ReducibleTool $tool): string => $tool->value,
                $definition->capabilities,
            );
            $operationCount = count($definition->permittedOperationIds($catalog));
            $memoryEnabled = $this->anyMemoryKindEnabled($definition);
        } catch (AgentDefinitionParseException|AgentDefinitionResolutionException) {
            // Degrade this one agent's fields to empty/zero rather than
            // aborting the whole call (data-model.md §7 step 5, mirroring
            // AgentQuery::searchForUser()'s own per-agent degrade pattern).
        }

        $requestCount = $costRow['request_count'] ?? 0;

        return [
            'purpose' => $purpose,
            'capabilities' => $capabilities,
            'operation_count' => $operationCount,
            'memory_enabled' => $memoryEnabled,
            'usage' => [
                // has_run is sourced from cost_summaries request_count, not
                // agent_runs -- agent_runs is retention-purged, so an
                // agent_runs-sourced signal would silently regress a
                // long-lived but quiet agent to "never run" after 90 days
                // (research.md D3, the single most important correctness
                // property in this feature).
                'has_run' => $requestCount > 0,
                'run_count' => $runCountRow !== null ? (int) $runCountRow->run_count : 0,
                'reliability' => [
                    'invocation_count' => $reliabilityRow['invocation_count'] ?? 0,
                    'success_count' => $reliabilityRow['success_count'] ?? 0,
                    'failure_count' => $reliabilityRow['failure_count'] ?? 0,
                    'low_sample' => $reliabilityRow['low_sample'] ?? true,
                    'no_activity' => $reliabilityRow['no_activity'] ?? true,
                ],
                'cost' => [
                    'priced_cost_total' => $costRow['priced_cost_total'] ?? '0.0000000000',
                    'request_count' => $requestCount,
                    'unpriced_request_count' => $costRow['unpriced_request_count'] ?? 0,
                    'has_estimated_cost' => $costRow['has_estimated_cost'] ?? false,
                ],
            ],
        ];
    }

    private function anyMemoryKindEnabled(AgentDefinition $definition): bool
    {
        foreach (MemoryKind::cases() as $kind) {
            if ($definition->memoryEnabled($kind)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Builds the live [{operationId, method}, ...] catalog once per call
     * (data-model.md §7 step 1, research.md D4) -- the identical shape
     * AgentDefinition::resolveCatalog() builds internally, duplicated here
     * as a public-facing catalog builder so the same array can be passed
     * into every agent's permittedOperationIds() call without each agent
     * re-resolving it independently. Not a query against this app's own
     * tables -- ApiManager is a statically-cached, Scramble-Generator-backed
     * doc source.
     *
     * @return list<array{operationId: string, method: string}>
     */
    private function buildCatalog(): array
    {
        $catalog = [];

        foreach (ApiManager::getOperations() as $operation) {
            $details = (array) ApiManager::getOperationDetails($operation['operationId']);

            if (!isset($details['method'])) {
                continue;
            }

            $catalog[] = [
                'operationId' => $operation['operationId'],
                'method' => $details['method'],
            ];
        }

        return $catalog;
    }
}
