<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\SequenceRun;
use ClarionApp\LlmClient\Models\Stage;
use ClarionApp\LlmClient\Models\StageResult;
use ClarionApp\LlmClient\Models\StageSequenceDefinition;

/**
 * 105-stage-pipeline (data-model.md §7). Owner-scoped read path over the
 * StageSequenceDefinition/SequenceRun/StageResult rows SequenceService
 * writes — mirrors ManagedTaskQuery::findManagedTask()/DelegationQuery::
 * findDelegation()'s exact "resolve, then compare owner_user_id" shape,
 * returning null/empty rather than throwing (Grounding note item 5).
 *
 * Phase 2 (Foundational) adds the three read methods with no business
 * logic layered on top yet; Phases 3-6 are the first callers.
 */
class SequenceQuery
{
    /**
     * GET /sequence-definitions (contracts §2) -- the caller's own
     * definitions, most recently created first. T065 (Phase 7 Constitution
     * alignment pass): centralizes the owner_user_id comparison here rather
     * than inline in SequenceController::index(), matching this class's own
     * "every read compares owner_user_id in one place" shape (data-model.md
     * §7) and RunTraceQuery::runsForUserPaginated()'s identical precedent
     * for an owner-scoped list endpoint.
     *
     * @return array<int, StageSequenceDefinition>
     */
    public function definitionsForOwner(string $callerUserId): array
    {
        return StageSequenceDefinition::where('owner_user_id', $callerUserId)
            ->orderByDesc('created_at')
            ->get()
            ->all();
    }

    /**
     * @return StageSequenceDefinition|null Null when absent or owned by
     *   another user.
     */
    public function findDefinition(string $callerUserId, string $definitionId): ?StageSequenceDefinition
    {
        return StageSequenceDefinition::where('id', $definitionId)
            ->where('owner_user_id', $callerUserId)
            ->first();
    }

    /**
     * @return SequenceRun|null Null when absent or owned by another user.
     */
    public function findRun(string $callerUserId, string $runId): ?SequenceRun
    {
        return SequenceRun::where('id', $runId)
            ->where('owner_user_id', $callerUserId)
            ->first();
    }

    /**
     * Every StageResult for the run, ordered by Stage.position, joined
     * with Stage.name (contracts §4's `stages` array shape) — ownership
     * checked via findRun() first.
     *
     * @return array<int, array<string, mixed>>|null Null when the run is
     *   absent or owned by another user.
     */
    public function stageResultsForRun(string $callerUserId, string $runId): ?array
    {
        $run = $this->findRun($callerUserId, $runId);
        if ($run === null) {
            return null;
        }

        $results = StageResult::where('sequence_run_id', $runId)->get()->keyBy('stage_id');

        $stages = Stage::where('sequence_definition_id', $run->sequence_definition_id)
            ->orderBy('position')
            ->get();

        return $stages->map(function (Stage $stage) use ($results) {
            $result = $results->get($stage->id);

            return [
                'stage_id' => $stage->id,
                'position' => $stage->position,
                'name' => $stage->name,
                'status' => $result?->status ?? 'pending',
                // 105-stage-pipeline Phase 3: output is stored as a
                // json_encode()'d longtext column (ContentSanitizer::
                // truncate()'d) -- decoded here so contracts §4's `stages`
                // array carries a genuine JSON value, never the raw
                // string, matching the response shape's own worked
                // example.
                'output' => $result?->output !== null ? json_decode($result->output, true) : null,
                'failure_reason' => $result?->failure_reason,
            ];
        })->all();
    }
}
