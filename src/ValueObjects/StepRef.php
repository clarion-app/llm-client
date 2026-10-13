<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * The reference to the step a checkpoint write or tool funnel call
 * concerns: the run, the step, and the loop iteration the step carries.
 */
final class StepRef
{
    public function __construct(
        public readonly string $runId,
        public readonly string $stepId,
        public readonly int $iteration,
    ) {
    }
}
