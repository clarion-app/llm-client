<?php

namespace ClarionApp\LlmClient\Jobs;

use ClarionApp\LlmClient\Exceptions\SchemaValidationError;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\SequenceRun;
use ClarionApp\LlmClient\Models\Stage;
use ClarionApp\LlmClient\Models\StageResult;
use ClarionApp\LlmClient\Services\ContentSanitizer;
use ClarionApp\LlmClient\Services\DelegationService;
use ClarionApp\LlmClient\Services\SchemaValidator;
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
 * Phase 3 (US1) shipped the happy path ONLY (tasks.md "Ordering
 * rationale"). Phase 5 (US3, research.md D9, FR-006/007/008) layers two
 * stop conditions on top of that happy path without changing it for the
 * case where nothing goes wrong:
 *
 *   - A boundary/handoff check (T044): before this stage's own agent is
 *     EVER invoked, its declared input_schema (when non-null) is
 *     validated against the incoming payload (the previous stage's
 *     output, or the run's own starting_input for stage 1). A caught
 *     SchemaValidationError marks THIS stage's own StageResult
 *     'handoff_rejected' -- distinct from 'failed' -- with no
 *     Delegation row ever created for it.
 *   - An output self-check (T045): immediately after a stage produces
 *     output, its declared output_schema (when non-null) is validated
 *     against what it actually produced, BEFORE that output is ever
 *     handed to the next stage's own input_schema check -- a failure
 *     here is treated as this same stage's own handoff_rejected-shaped
 *     stop (a Delegation row DOES exist, since the stage genuinely ran).
 *   - A stage's-own-execution-failure branch (T046): a refusal
 *     ({"error": ...}, Grounding note item 2, no Delegation row created)
 *     or a completed-but-result_status='failure' delegation (a
 *     Delegation row WAS created, output stayed null) marks the
 *     StageResult 'failed'.
 *
 * Any of the three stops sets SequenceRun.status = 'failed' (FR-010,
 * never 'completed'), dispatches no further job, and broadcasts the
 * terminal event via SequenceService::broadcastRunUpdated() -- every
 * later stage is left untouched at its pre-created 'pending' default
 * (FR-009).
 *
 * Resuming from an arbitrary stage (rather than always the first
 * pending one) is Phase 6/US4's own addition (T059) -- this phase's
 * "find the next pending stage by position" logic already produces the
 * correct starting point for a brand-new run (position 1 is the only
 * pending stage at that point), so no separate code path is needed for
 * that case specifically.
 */
class RunSequenceStageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const TERMINAL_STATUSES = ['completed', 'failed'];

    public function __construct(public readonly string $sequenceRunId)
    {
    }

    public function handle(DelegationService $delegationService, ContentSanitizer $contentSanitizer, SequenceService $sequenceService, ?SchemaValidator $schemaValidator = null): void
    {
        $schemaValidator ??= app(SchemaValidator::class);

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

        // Phase 5 (US3, T044, research.md D9, FR-006/FR-007): before this
        // stage ever starts -- its own agent is NEVER invoked if this
        // rejects.
        if ($stage->input_schema !== null) {
            try {
                $schemaValidator->validate((string) $inputPayload, $stage->input_schema);
            } catch (SchemaValidationError $e) {
                $this->stopOnHandoffRejection($run, $stageResult, $stage, $inputPayload, $e, $contentSanitizer, $sequenceService);

                return;
            }
        }

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

        // Phase 5 (US3, T046, FR-008): a refusal ({"error": ...}) never
        // carries a 'status' key -- checked first, before ever reading
        // 'status' (Grounding note item 2). Either shape means this
        // stage's own execution genuinely failed.
        if (isset($result['error']) || ($result['status'] ?? null) === 'failure') {
            $this->stopOnStageFailure($run, $stageResult, $stage, $result, $sequenceService);

            return;
        }

        $encodedOutput = json_encode($result['output'] ?? null);

        // Phase 5 (US3, T045, data-model.md §2's defense-in-depth): this
        // stage's OWN output must satisfy its own output_schema (when
        // declared) before it is ever handed to the next stage's own
        // input_schema check.
        if ($stage->output_schema !== null) {
            try {
                $schemaValidator->validate($encodedOutput, $stage->output_schema);
            } catch (SchemaValidationError $e) {
                $this->stopOnOwnOutputRejection($run, $stageResult, $stage, $result, $encodedOutput, $e, $contentSanitizer, $sequenceService);

                return;
            }
        }

        $stageResult->output = $contentSanitizer->truncate($encodedOutput);
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
     * T044 (research.md D9, FR-006/FR-007/SC-005): $stageResult (still
     * 'pending' up to this point) goes straight to 'handoff_rejected' --
     * this stage's own agent is NEVER invoked. The rejected input itself
     * is recorded for diagnosis (data-model.md §4), distinct from FR-008's
     * own execution-failure branch below.
     */
    private function stopOnHandoffRejection(SequenceRun $run, StageResult $stageResult, Stage $stage, ?string $inputPayload, SchemaValidationError $e, ContentSanitizer $contentSanitizer, SequenceService $sequenceService): void
    {
        $violationSummary = $this->violationSummary($e);

        $stageResult->status = 'handoff_rejected';
        $stageResult->input = $contentSanitizer->truncate((string) $inputPayload);
        $stageResult->failure_reason = $violationSummary;
        $stageResult->completed_at = now();
        $stageResult->save();

        $previousStage = $this->previousStageFor($run, $stage);
        $sourceDescription = $previousStage !== null
            ? "the output of stage '{$previousStage->name}'"
            : "the run's starting input";

        $this->finalizeRunAsFailed($run, "Stage '{$stage->name}' rejected {$sourceDescription}: {$violationSummary}", $sequenceService);
    }

    /**
     * T046 (FR-008): this stage's own execution genuinely failed -- either
     * an up-front refusal (Grounding note item 2's {error, message} shape,
     * no Delegation row created) or a completed delegation whose OWN
     * result_status is 'failure' (a Delegation row WAS created, but
     * output stayed null -- DelegationService's own FR-007 guarantee).
     */
    private function stopOnStageFailure(SequenceRun $run, StageResult $stageResult, Stage $stage, array $result, SequenceService $sequenceService): void
    {
        if (isset($result['error'])) {
            $stageFailureReason = $result['message'] ?? 'The delegated stage was refused.';
        } else {
            $stageFailureReason = trim(($result['summary'] ?? 'The delegated stage failed.') . (!empty($result['undone']) ? ' ' . $result['undone'] : ''));
        }

        $stageResult->status = 'failed';
        $stageResult->failure_reason = $stageFailureReason;
        $stageResult->delegation_id = $result['delegation_id'] ?? null;
        $stageResult->completed_at = now();
        $stageResult->save();

        $this->finalizeRunAsFailed($run, "Stage '{$stage->name}' failed: {$stageFailureReason}", $sequenceService);
    }

    /**
     * T045 (data-model.md §2): this stage genuinely ran and a Delegation
     * row exists -- but its own produced output fails its own declared
     * output_schema. Treated as this SAME stage's own handoff_rejected-
     * shaped stop (not a new status), before that output is ever handed
     * to the next stage's own input_schema check.
     */
    private function stopOnOwnOutputRejection(SequenceRun $run, StageResult $stageResult, Stage $stage, array $result, string $encodedOutput, SchemaValidationError $e, ContentSanitizer $contentSanitizer, SequenceService $sequenceService): void
    {
        $violationSummary = $this->violationSummary($e);

        $stageResult->status = 'handoff_rejected';
        $stageResult->output = $contentSanitizer->truncate($encodedOutput);
        $stageResult->delegation_id = $result['delegation_id'] ?? null;
        $stageResult->failure_reason = $violationSummary;
        $stageResult->completed_at = now();
        $stageResult->save();

        $this->finalizeRunAsFailed($run, "Stage '{$stage->name}' produced output that fails its own output schema: {$violationSummary}", $sequenceService);
    }

    /**
     * Every Phase 5 stop (T044/T045/T046) ends here: SequenceRun.status =
     * 'failed' (FR-010, never 'completed'), no further job is ever
     * dispatched (this is a `return` inside handle(), never falling
     * through to the self::dispatch() call at the bottom), and the
     * terminal event is broadcast (research.md D8) -- every later stage is
     * left untouched at its pre-created 'pending' default (FR-009).
     */
    private function finalizeRunAsFailed(SequenceRun $run, string $failureReason, SequenceService $sequenceService): void
    {
        $run->status = 'failed';
        $run->failure_reason = $failureReason;
        $run->completed_at = now();
        $run->last_progress_at = now();
        $run->save();

        $sequenceService->broadcastRunUpdated($run->id);
    }

    /**
     * Joins SchemaValidationError's own violation list (property + message
     * per entry) into one human-readable string that names the specific
     * missing/mismatched property (FR-007) -- falls back to the
     * exception's own message on the rare case the library raised without
     * a structured violation list (e.g. malformed JSON).
     */
    private function violationSummary(SchemaValidationError $e): string
    {
        $violations = $e->getViolations();
        if (empty($violations)) {
            return $e->getMessage();
        }

        return implode('; ', array_map(function (array $v) {
            $property = $v['property'] ?? '';
            $message = $v['message'] ?? '';

            return $property !== '' ? "{$property}: {$message}" : $message;
        }, $violations));
    }

    private function previousStageFor(SequenceRun $run, Stage $stage): ?Stage
    {
        if ($stage->position === 1) {
            return null;
        }

        return Stage::where('sequence_definition_id', $run->sequence_definition_id)
            ->where('position', $stage->position - 1)
            ->first();
    }

    /**
     * Stage 1 receives the run's own starting_input verbatim; every later
     * stage receives the immediately preceding stage's stored output
     * verbatim (FR-002/FR-011) -- never re-derived, re-summarized, or
     * re-fetched from anywhere but that one stage's own StageResult row.
     */
    private function inputPayloadFor(SequenceRun $run, Stage $stage): ?string
    {
        $previousStage = $this->previousStageFor($run, $stage);
        if ($previousStage === null) {
            return $run->starting_input;
        }

        $previousResult = StageResult::where('sequence_run_id', $run->id)
            ->where('stage_id', $previousStage->id)
            ->first();

        return $previousResult?->output;
    }
}
