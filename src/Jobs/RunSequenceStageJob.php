<?php

namespace ClarionApp\LlmClient\Jobs;

use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\SequenceRun;
use ClarionApp\LlmClient\Models\Stage;
use ClarionApp\LlmClient\Models\StageResult;
use ClarionApp\LlmClient\Services\ContentSanitizer;
use ClarionApp\LlmClient\Services\DelegationService;
use ClarionApp\LlmClient\Services\SequenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * 105-stage-pipeline (research.md D6/D7, Grounding note item 7). The
 * chain-of-short-lived-jobs shape RunManagedTaskStepJob already
 * established: one invocation runs exactly ONE stage's own
 * DelegationService::delegate() call, records its outcome, then either
 * re-dispatches a fresh instance of itself for the next stage or leaves
 * the run alone once it reaches a terminal state.
 *
 * Phase 3 (US1) ships the happy path ONLY (tasks.md "Ordering rationale"):
 * a stage that fails outright, or a handoff a stage's own input_schema
 * rejects, is Phase 5/US3's own addition (T044-T046), layered onto this
 * same class without changing the happy path this phase ships. A refusal
 * or a completed-but-'failure' delegation result is detected (Grounding
 * note item 2's isset($result['error']) branch, checked BEFORE ever
 * reading 'status') but left as a 'running' StageResult with no further
 * action -- Phase 5 replaces that no-op with the real stop/report logic;
 * Phase 3's own tests never exercise that branch. Resuming from an
 * arbitrary stage (rather than always the first pending one) is Phase
 * 6/US4's own addition (T059) -- this phase's "find the next pending
 * stage by position" logic already produces the correct starting point
 * for a brand-new run (position 1 is the only pending stage at that
 * point), so no separate code path is needed for that case specifically.
 */
class RunSequenceStageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const TERMINAL_STATUSES = ['completed', 'failed'];

    public function __construct(public readonly string $sequenceRunId)
    {
    }

    public function handle(DelegationService $delegationService, ContentSanitizer $contentSanitizer, SequenceService $sequenceService): void
    {
        $run = SequenceRun::find($this->sequenceRunId);
        if ($run === null || in_array($run->status, self::TERMINAL_STATUSES, true)) {
            return;
        }

        $stageResult = StageResult::with('stage')
            ->where('sequence_run_id', $run->id)
            ->where('status', 'pending')
            ->get()
            ->sortBy(fn (StageResult $r) => $r->stage->position)
            ->first();

        if ($stageResult === null) {
            return;
        }

        $stage = $stageResult->stage;

        $inputPayload = $this->inputPayloadFor($run, $stage);

        $stageResult->status = 'running';
        $stageResult->input = $contentSanitizer->truncate((string) $inputPayload);
        $stageResult->started_at = now();
        $stageResult->save();

        $run->current_stage_position = $stage->position;
        $run->last_progress_at = now();
        $run->save();

        // research.md D8 (Phase 4/US2): a stage transitioning to running
        // is one of the five named broadcast points.
        $sequenceService->broadcastRunUpdated($run->id);

        $conversation = Conversation::find($run->conversation_id);

        $taskDescription = "Execute stage \"{$stage->name}\" of an automated sequence pipeline, using the input provided below.";
        $result = $delegationService->delegate($conversation, $stage->helper_agent_id, $taskDescription, (string) $inputPayload);

        // Grounding note item 2: a refusal ({"error": ...}) never carries a
        // 'status' key -- checked first, before ever reading 'status'.
        // Phase 3 happy path only -- see this class's own doc above.
        if (isset($result['error']) || ($result['status'] ?? null) === 'failure') {
            return;
        }

        $stageResult->output = $contentSanitizer->truncate(json_encode($result['output'] ?? null));
        $stageResult->delegation_id = $result['delegation_id'] ?? null;
        $stageResult->status = 'completed';
        $stageResult->completed_at = now();
        $stageResult->save();

        $run->last_progress_at = now();
        $run->save();

        // research.md D8 (Phase 4/US2): a stage reaching a terminal
        // per-stage status is one of the five named broadcast points.
        $sequenceService->broadcastRunUpdated($run->id);

        $totalStages = Stage::where('sequence_definition_id', $run->sequence_definition_id)->count();

        if ($stage->position >= $totalStages) {
            $run->status = 'completed';
            $run->completed_at = now();
            $run->save();

            // research.md D8 (Phase 4/US2): run finalization is one of
            // the five named broadcast points -- distinct from the
            // per-stage terminal broadcast just above, even though both
            // fire within this same job invocation for the last stage.
            $sequenceService->broadcastRunUpdated($run->id);

            return;
        }

        self::dispatch($run->id)->onQueue(config('llm-client.pipeline.queue', 'sequence-runs'));
    }

    /**
     * Stage 1 receives the run's own starting_input verbatim; every later
     * stage receives the immediately preceding stage's stored output
     * verbatim (FR-002/FR-011) -- never re-derived, re-summarized, or
     * re-fetched from anywhere but that one stage's own StageResult row.
     */
    private function inputPayloadFor(SequenceRun $run, Stage $stage): ?string
    {
        if ($stage->position === 1) {
            return $run->starting_input;
        }

        $previousStage = Stage::where('sequence_definition_id', $run->sequence_definition_id)
            ->where('position', $stage->position - 1)
            ->first();

        $previousResult = StageResult::where('sequence_run_id', $run->id)
            ->where('stage_id', $previousStage->id)
            ->first();

        return $previousResult?->output;
    }
}
