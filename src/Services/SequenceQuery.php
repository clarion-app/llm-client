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
                'output' => $result?->output,
                'failure_reason' => $result?->failure_reason,
            ];
        })->all();
    }
}
