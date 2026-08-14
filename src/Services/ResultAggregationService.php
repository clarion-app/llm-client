<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Delegation;

/**
 * ResultAggregationService (099-result-aggregation, US3/US4, data-model.md
 * §4, research.md D5/D6).
 *
 * Pure read/compute service, no writes, no new table — combines every
 * delegation on a run that has reached a terminal, structured outcome
 * (`result_status` non-null) into a single, provenance-preserving view.
 * Depends only on `Delegation` (read) and `ContentSanitizer`, so it is
 * unit-testable without any agent-loop/LLM scaffolding (research.md D5),
 * exactly like `DelegationQuery`.
 *
 * Phase 6 (US4) adds the key-by-key conflict comparison (research.md D6):
 * a key present in two or more contributors' `output` maps with differing
 * values is excluded from `combined_output` and recorded, with every
 * disagreeing value and its own provenance, in `conflicts`; a key present
 * once, or present multiple times with an identical value, is unioned into
 * `combined_output` as before.
 *
 * `contributors` entries carry one field beyond data-model.md §4's literal
 * list — `output`, each contributor's own decoded `result_output` — so
 * that `AgentLoopService::buildCombinedHelperResultsSection()` can
 * attribute a `combined_output` entry back to the specific helper name(s)
 * that produced it (contracts/result-aggregation-meta-tool.md §3) without
 * a second, redundant `Delegation` query. This is purely additive: it does
 * not change `combined_output`'s own flat shape or the read endpoint's
 * top-level five-key response shape.
 */
class ResultAggregationService
{
    public function __construct(
        private readonly ContentSanitizer $contentSanitizer,
    ) {}

    /**
     * Combines every delegation on $runId that has reached a terminal,
     * structured outcome (`result_status IS NOT NULL`) into one view.
     * Returns null when fewer than two such delegations exist — including
     * when zero exist, or when only one does alongside any number of
     * still-in-progress ones (`result_status IS NULL` never counts,
     * regardless of how many exist).
     *
     * @return array{contributors: array<int, array<string, mixed>>, combined_output: array<string, mixed>, conflicts: array<int, array<string, mixed>>, truncated: bool}|null
     */
    public function combineForRun(string $runId): ?array
    {
        $delegations = Delegation::where('parent_run_id', $runId)
            ->whereNotNull('result_status')
            ->orderBy('started_at')
            ->get();

        if ($delegations->count() < 2) {
            return null;
        }

        $agentIds = $delegations->pluck('helper_agent_id')->filter()->unique()->values()->all();
        $names = empty($agentIds) ? [] : Agent::whereIn('id', $agentIds)->pluck('name', 'id')->all();

        $contributors = [];
        $keyOccurrences = [];

        foreach ($delegations as $delegation) {
            $decodedOutput = $this->decodeOutput($delegation->result_output);

            $contributors[] = [
                'delegation_id' => $delegation->id,
                'helper_agent_id' => $delegation->helper_agent_id,
                'helper_agent_name' => $names[$delegation->helper_agent_id] ?? null,
                'status' => $delegation->result_status,
                'summary' => $delegation->result_summary,
                'undone' => $delegation->result_undone,
                'output' => $decodedOutput,
            ];

            if (is_array($decodedOutput)) {
                foreach ($decodedOutput as $key => $value) {
                    $keyOccurrences[$key][] = [
                        'value' => $value,
                        'delegation_id' => $delegation->id,
                        'helper_agent_id' => $delegation->helper_agent_id,
                        'helper_agent_name' => $names[$delegation->helper_agent_id] ?? null,
                    ];
                }
            }
        }

        // research.md D6: a key is a conflict only when two or more
        // contributors disagree on its value -- a key present once, or
        // present several times with an identical value, is unioned into
        // combined_output normally. Comparing json_encode()'d values
        // (rather than ===) treats two occurrences of an equal array/object
        // value as identical regardless of incidental key order.
        $combinedOutput = [];
        $conflicts = [];
        foreach ($keyOccurrences as $key => $occurrences) {
            $distinctValues = [];
            foreach ($occurrences as $occurrence) {
                $distinctValues[json_encode($occurrence['value'])] = true;
            }

            if (count($distinctValues) <= 1) {
                $combinedOutput[$key] = $occurrences[0]['value'];

                continue;
            }

            // Every disagreeing value is retained with its own provenance
            // (FR-010/FR-011) -- never silently keeping only the most
            // recent. $occurrences already carries exactly the
            // value/delegation_id/helper_agent_id/helper_agent_name shape
            // each conflict entry's own `values` array needs.
            $conflicts[] = [
                'key' => $key,
                'values' => $occurrences,
            ];
        }

        $encoded = json_encode([
            'combined_output' => $combinedOutput,
            'conflicts' => $conflicts,
        ]);
        $cap = (int) config('llm-client.delegation.combined_output_cap_bytes', 16384);
        $sanitized = $this->contentSanitizer->truncate($encoded, $cap);
        $truncated = $this->contentSanitizer->isTruncated($sanitized);

        return [
            'contributors' => $contributors,
            'combined_output' => $combinedOutput,
            'conflicts' => $conflicts,
            'truncated' => $truncated,
        ];
    }

    /**
     * Defensively decodes a stored result_output value (Grounding note item
     * 7's pattern, shared with DelegationController::delegationRows()): a
     * truncated value isn't valid JSON, so fall back to the raw string
     * rather than losing it. Null stays null.
     */
    private function decodeOutput(?string $resultOutput): mixed
    {
        if ($resultOutput === null) {
            return null;
        }

        $decoded = json_decode($resultOutput, true);

        return $decoded ?? $resultOutput;
    }
}
