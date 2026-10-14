<?php

namespace ClarionApp\LlmClient\ValueObjects;

use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Services\CorrectionPromptBuilder;

/**
 * The mutable state the agent loop carries across iterations.
 *
 * Built by the run's prologue for a fresh run (starting at iteration 1)
 * and by the resumer for a resumed run (starting at the open step's
 * iteration, with the open step and the checkpointed response already in
 * place). The loop reads and writes it; the resumer sets the resume
 * fields before entering it.
 */
final class LoopState
{
    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $formattedTools
     */
    public function __construct(
        public Conversation $conversation,
        public ?string $runId,
        public int $iteration,
        public array $options,
        public ?string $deadlineAt,
        public bool $unattended,
        public ?int $maxIterations,
        public DegradationDecision $decision,
        public array $formattedTools,
        public bool $shouldValidate,
        public bool $retryOnValidationFailure,
        public int $maxSchemaRetries,
        public int $schemaRetryCount,
        public CorrectionPromptBuilder $correctionPromptBuilder,
        public string $presetSystemPrompt,
        public int $stepOrdinal,
        public ?string $currentStepId,
        public ?string $activeActionId,
        public ?string $unrecoverableFailureReason,
        /**
         * The model response already recorded at C2 (a resumed run whose
         * checkpoint is `model_recorded`): when set, the loop skips the
         * model call and uses this response, going straight to the tool
         * phase. Null for a fresh run and for a `RepeatModelCall` resume
         * (whose model call was lost and is repeated).
         *
         * @var array{content: ?string, tool_calls: list<array<string, mixed>>}|null
         */
        public ?array $recordedModelResponse = null,
        /**
         * The call dispositions for a resumed run whose checkpoint is
         * `model_recorded` (FinishToolBatch), keyed by tool_call_id: the
         * loop's tool phase answers each recorded call by its disposition
         * (a completed call reuses its result, a not-started call runs, a
         * started effectful call is outcome-unknown). Null for a fresh run
         * and for a `RepeatModelCall` resume.
         *
         * @var array<string, \ClarionApp\LlmClient\ValueObjects\CallDisposition>|null
         */
        public ?array $resumedDispositions = null,
        /**
         * True when a resume re-enters the run's open step: that step's
         * budget claim was made when it was first opened, so the loop's
         * first iteration does not claim another.
         */
        public bool $reclaimedStep = false,
    ) {
    }
}
