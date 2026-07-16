<?php

namespace ClarionApp\LlmClient\Observers;

use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Services\OperationCache;
use Illuminate\Support\Facades\Log;

/**
 * Observer that clears operation cache when a conversation is deleted.
 *
 * Conversation uses EloquentMultiChainBridge which provides SoftDeletes,
 * so `deleted` fires on both hard and soft deletes. A soft-deleted
 * conversation must stop contributing to prompts immediately.
 */
class ConversationObserver
{
    public function __construct(private OperationCache $cache)
    {
    }

    /**
     * Handle the Conversation "deleted" event.
     *
     * Delegates to OperationCache so the key format and the configured store
     * stay in one place — clearing via the Cache facade would always hit the
     * default store, which is not necessarily the one holding the entries.
     *
     * @param Conversation $conversation
     */
    public function deleted(Conversation $conversation): void
    {
        try {
            $this->cache->forget($conversation->id);
        } catch (\Throwable $e) {
            try {
                Log::warning('Failed to clear operation cache on conversation delete', [
                    'conversation_id' => $conversation->id,
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable) {
                // Log facade unavailable — silently degrade
            }
        }
    }
}
