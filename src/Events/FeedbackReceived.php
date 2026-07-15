<?php

namespace ClarionApp\LlmClient\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when a user performs a feedback action (approve/reject/correct).
 *
 * Listeners persist the raw signal and dispatch the extraction job.
 */
class FeedbackReceived
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $userId,
        public readonly string $sourceEventId,
        public readonly string $signalType,
        public readonly string $rawContext,
        public readonly ?string $conversationId = null,
        public readonly ?string $patternKey = null,
    ) {}
}
