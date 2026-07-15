<?php

namespace ClarionApp\LlmClient\Listeners;

use ClarionApp\LlmClient\Events\FeedbackReceived;
use ClarionApp\LlmClient\Jobs\ExtractFeedbackPreferencesJob;
use ClarionApp\LlmClient\Models\FeedbackSignal;
use Illuminate\Support\Str;

/**
 * Persists a feedback signal when FeedbackReceived is dispatched,
 * then dispatches the extraction job for deferred processing.
 */
class PersistFeedbackSignal
{
    public function handle(FeedbackReceived $event): void
    {
        // Idempotence: skip if signal already exists for this source event
        $existing = FeedbackSignal::withoutGlobalScope('user')
            ->where('user_id', $event->userId)
            ->where('source_event_id', $event->sourceEventId)
            ->first();

        if ($existing) {
            return;
        }

        // Persist the raw signal
        FeedbackSignal::withoutGlobalScope('user')->create([
            'id' => Str::uuid()->toString(),
            'user_id' => $event->userId,
            'source_event_id' => $event->sourceEventId,
            'conversation_id' => $event->conversationId,
            'signal_type' => $event->signalType,
            'pattern_key' => $event->patternKey,
            'raw_context' => $event->rawContext,
            'created_at' => now(),
        ]);

        // Dispatch extraction job for deferred processing
        dispatch(new ExtractFeedbackPreferencesJob($event->userId));
    }
}
