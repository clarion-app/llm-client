<?php

namespace ClarionApp\LlmClient\ValueObjects;

use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\TaskBudget;

/**
 * What is needed to ask a user about a task paused at its budget ceiling:
 * where (the conversation, the paused run and how it continues), the report
 * shown above the question, the standing it describes, and the additional
 * allowance continuing would grant for each reached ceiling.
 */
final readonly class CeilingPromptRequest
{
    public function __construct(
        public Conversation $conversation,
        public TaskBudget $task,
        public ?string $runId,
        public string $resumeMode,
        public string $report,
        public TaskBudgetStanding $standing,
        public ?int $offeredSteps = null,
        public ?string $offeredSpend = null,
        public bool $stepsReducedToMaximum = false,
        public bool $spendReducedToMaximum = false,
    ) {
    }
}
