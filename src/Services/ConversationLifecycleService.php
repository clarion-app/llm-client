<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Events\ConversationEnded;
use ClarionApp\LlmClient\Models\Conversation;
use Illuminate\Support\Facades\Event;

/**
 * Owns the conversation session boundary.
 *
 * "Session ended" is a distinct fact from "the agent finished a response". The
 * agent loop finishes a response on every turn; a session ends when the user is
 * done — signalled explicitly, or inferred from inactivity. Listeners on
 * ConversationEnded (short-term memory cleanup, episodic capture) are only
 * correct under the latter meaning.
 *
 * end() is idempotent: the ended_at marker means repeated idle sweeps fire the
 * event once, and a returning user clears it so the session can end again later.
 */
class ConversationLifecycleService
{
    /**
     * End a conversation session, firing ConversationEnded exactly once.
     *
     * @return bool True if this call ended the session; false if already ended.
     */
    public function end(Conversation $conversation): bool
    {
        if ($conversation->ended_at !== null) {
            return false;
        }

        $conversation->forceFill(['ended_at' => now()])->save();

        Event::dispatch(new ConversationEnded(
            $conversation->id,
            $conversation->character ?? $conversation->id
        ));

        return true;
    }

    /**
     * Mark a conversation as active again.
     *
     * Called when the agent begins work on a new message: the user came back, so
     * the session is live and must be eligible to end again later.
     */
    public function markActive(Conversation $conversation): void
    {
        if ($conversation->ended_at === null) {
            return;
        }

        $conversation->forceFill(['ended_at' => null])->save();
    }

    /**
     * End every session idle longer than the configured timeout.
     *
     * @param int|null $idleMinutes Override for the configured timeout.
     * @return int Number of sessions ended.
     */
    public function endIdleConversations(?int $idleMinutes = null): int
    {
        $idleMinutes ??= (int) config('llm-client.conversation_lifecycle.idle_timeout_minutes', 30);
        $cutoff = now()->subMinutes($idleMinutes);

        $ended = 0;

        Conversation::whereNull('ended_at')
            ->where('is_processing', false)
            ->where('updated_at', '<', $cutoff)
            ->chunkById(100, function ($conversations) use (&$ended) {
                foreach ($conversations as $conversation) {
                    if ($this->end($conversation)) {
                        $ended++;
                    }
                }
            });

        return $ended;
    }
}
