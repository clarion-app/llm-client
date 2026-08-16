<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\ManagedTaskPart;

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
     * $callerFacing (109-agent-as-capability, FR-003): when true, excludes
     * `origin: 'capability_offering'` rows before combining. This view is
     * reused by two structurally different consumers -- a human reviewing
     * a run after the fact via `DelegationController::combinedResults()`
     * (FR-020 explicitly preserves full origin visibility there, exactly
     * like `DelegationQuery`/`RunDiagram`), and
     * `AgentLoopService::buildCombinedHelperResultsSection()`, which
     * injects this method's own output directly into the CALLING agent's
     * own system prompt for its next turn -- i.e. squarely inside "the
     * calling agent's ordinary discovery or invocation flow" FR-003
     * governs. Without this exclusion, two or more capability-agent calls
     * completing on the same run (no delegate_to_helper call needed at
     * all) would name the offered agent(s) via `helper_agent_name` right
     * back into the caller's own "## Combined Helper Results" section --
     * the same class of leak `AgentLoopService::composeDelegationDisclosure()`
     * was fixed against in Phase 3, but through this entirely separate,
     * 099-result-aggregation-owned code path that this feature's own
     * phases never touched. Defaults to false so every pre-existing
     * caller (`DelegationController`, every test in this file written
     * before 109) is unaffected.
     *
     * @return array{contributors: array<int, array<string, mixed>>, combined_output: array<string, mixed>, conflicts: array<int, array<string, mixed>>, truncated: bool}|null
     */
    public function combineForRun(string $runId, bool $callerFacing = false): ?array
    {
        $query = Delegation::where('parent_run_id', $runId)
            ->whereNotNull('result_status');

        if ($callerFacing) {
            $query->where('origin', 'delegate_to_helper');
        }

        $delegations = $query->orderBy('started_at')->get();

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

        // 099-result-aggregation Phase 7 gap fix (tasks.md T048, FR-014):
        // `truncated` alone is not enough -- the combined view's own
        // returned combined_output/conflicts must actually be reduced to
        // fit within $cap when reassembled in the same {combined_output,
        // conflicts} shape used above to detect the overage, applied once
        // to the assembled whole regardless of contributor count
        // (mutation-checklist row 7), never per-contributor and never
        // conflated with result_output_cap_bytes.
        if ($truncated) {
            [$combinedOutput, $conflicts] = $this->pruneToFitCap($combinedOutput, $conflicts, $cap);
        }

        return [
            'contributors' => $contributors,
            'combined_output' => $combinedOutput,
            'conflicts' => $conflicts,
            'truncated' => $truncated,
        ];
    }

    /**
     * 103-manager-agent (US3, data-model.md §7, Grounding note item 14,
     * tasks.md T037/T041). The same conflict-detection algorithm
     * combineForRun() implements above, regrouped: reads every `accepted`
     * ManagedTaskPart's own accepted_delegation_id row (NOT a
     * parent_run_id-scoped set -- a managed task's parts span many
     * different manager-turn runs) rather than one run's full delegation
     * set. Returns null when fewer than two accepted parts have a
     * resolvable accepted_delegation_id, exactly mirroring
     * combineForRun()'s own "fewer than two qualifying contributors"
     * null contract.
     *
     * Each contributor/conflict-occurrence entry carries an additional
     * `part_id` field beyond combineForRun()'s own shape, since
     * `ManagerService::finalize()`'s conflict_note (FR-016) and any
     * future part-level UI both need to attribute a conflicting value
     * back to the specific PART it came from, not only the helper.
     *
     * @return array{contributors: array<int, array<string, mixed>>, combined_output: array<string, mixed>, conflicts: array<int, array<string, mixed>>, truncated: bool}|null
     */
    public function combineForManagedTask(string $managedTaskId): ?array
    {
        $parts = ManagedTaskPart::where('managed_task_id', $managedTaskId)
            ->where('state', 'accepted')
            ->whereNotNull('accepted_delegation_id')
            ->get();

        if ($parts->count() < 2) {
            return null;
        }

        $delegationIds = $parts->pluck('accepted_delegation_id')->unique()->values()->all();
        $delegations = Delegation::whereIn('id', $delegationIds)->orderBy('started_at')->get();

        if ($delegations->count() < 2) {
            return null;
        }

        $partsByDelegationId = $parts->keyBy('accepted_delegation_id');

        $agentIds = $delegations->pluck('helper_agent_id')->filter()->unique()->values()->all();
        $names = empty($agentIds) ? [] : Agent::whereIn('id', $agentIds)->pluck('name', 'id')->all();

        $contributors = [];
        $keyOccurrences = [];

        foreach ($delegations as $delegation) {
            $part = $partsByDelegationId[$delegation->id] ?? null;
            $decodedOutput = $this->decodeOutput($delegation->result_output);

            $contributors[] = [
                'part_id' => $part?->id,
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
                        'part_id' => $part?->id,
                        'delegation_id' => $delegation->id,
                        'helper_agent_id' => $delegation->helper_agent_id,
                        'helper_agent_name' => $names[$delegation->helper_agent_id] ?? null,
                    ];
                }
            }
        }

        // research.md D6 (same rule combineForRun() applies): a key is a
        // conflict only when two or more contributors disagree on its
        // value.
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

        if ($truncated) {
            [$combinedOutput, $conflicts] = $this->pruneToFitCap($combinedOutput, $conflicts, $cap);
        }

        return [
            'contributors' => $contributors,
            'combined_output' => $combinedOutput,
            'conflicts' => $conflicts,
            'truncated' => $truncated,
        ];
    }

    /**
     * Drops whole combined_output entries first (arbitrary but
     * deterministic order -- most-recently-unioned key first), then whole
     * conflicts entries if still over cap, re-measuring the exact
     * {combined_output, conflicts} pair -- the same shape a caller (API
     * response, system-prompt renderer) actually receives -- after every
     * removal, until it fits or nothing is left to drop.
     *
     * @return array{0: array<string, mixed>, 1: array<int, array<string, mixed>>}
     */
    private function pruneToFitCap(array $combinedOutput, array $conflicts, int $cap): array
    {
        $fits = fn (array $output, array $confl): bool => strlen(json_encode([
            'combined_output' => $output,
            'conflicts' => $confl,
        ])) <= $cap;

        $outputKeys = array_keys($combinedOutput);
        while (!$fits($combinedOutput, $conflicts) && !empty($outputKeys)) {
            unset($combinedOutput[array_pop($outputKeys)]);
        }

        while (!$fits($combinedOutput, $conflicts) && !empty($conflicts)) {
            array_pop($conflicts);
        }

        return [$combinedOutput, $conflicts];
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
