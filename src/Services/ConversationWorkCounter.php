<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\ValueObjects\ConversationWorkReading;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The sole reader/writer of the fixed-window conversation work counter — a
 * Cache key, never a table (data-model.md §2). ConversationWorkGate never
 * calls Cache:: directly; every touch of a
 * llm-client:conversation-work:* key goes through this class alone.
 *
 * This class replicates RateLimitCounter's protocol fresh, rather than
 * extending or parameterizing it, for two independent reasons: the key
 * prefix is a hardcoded private const, not a constructor parameter, so
 * passing a conversation id into what would otherwise read as a $userId
 * parameter would be semantically wrong and collision-prone; and call
 * frequency genuinely differs — this counter can be called dozens of
 * times within a single response, where RateLimitCounter is called at
 * most twice per request.
 *
 * The protocol is increment-then-check, not check-then-increment: this
 * class performs Cache::add($key, 0, $ttl) to seed the key only if it does
 * not already exist, then unconditionally Cache::increment($key), and
 * returns the atomically-assigned post-increment value as the count. The
 * caller (ConversationWorkGate) compares that value to a configured
 * ceiling; this class makes no admission decision of its own and holds no
 * reference to a ConversationWorkCeiling row.
 *
 * A check-then-increment shape — read the count, decide, then write —
 * carries a race between the read and the write. Incrementing first and
 * treating the atomically assigned result as the check is race-free by
 * construction.
 *
 * The key embeds both the conversation id and the window duration:
 *
 *   llm-client:conversation-work:{conversationId}:{windowSeconds}:{windowStart}
 *
 * where windowStart is the fixed-window bucket boundary in Unix seconds
 * (intdiv(now, windowSeconds) * windowSeconds). Naming the window duration
 * in the key means a resolved ceiling that changes shape — not just
 * amount — between one work unit and the next ages out under its own TTL
 * rather than being compared against a count accumulated under a
 * different window's semantics.
 *
 * This key's own namespace, conversation-work, never collides with
 * RateLimitCounter's rate-limit namespace even if a conversation id and a
 * user id ever coincided as strings.
 *
 * The TTL is double the window, a clock-skew safety margin matching the
 * 2x pattern this codebase's RateLimitCounter/BufferEvictionCounter both
 * already use for their own Cache::add()-then-increment() primitive.
 *
 * A store that cannot be read or written yields ConversationWorkReading
 * with available = false and every other field genuinely null — never a
 * partial count, never a fabricated zero — and every occurrence is
 * logged. This class fails open unconditionally; that is not a decision
 * it makes, only a fact it reports for ConversationWorkGate to act on.
 *
 * "Cannot be read or written" covers two shapes, not one. Most stores
 * signal failure by throwing, which the try/catch below handles. But
 * several of Laravel's own stores report a failed increment by *return
 * value* instead: NullStore::increment() always returns false,
 * DatabaseStore::increment() returns false when the row is gone (evicted
 * or expired between the add and the increment), and Memcached's
 * increment returns false against an unreachable server. Taking that
 * false at face value would produce a count of zero that reads as "this
 * conversation has done no work this window" — a fabricated figure that
 * always compares under any ceiling, so enforcement would silently stop
 * with no exception raised, no warning logged, and nothing anywhere
 * recording that the count was never actually measured. That is a
 * strictly worse failure than the deliberate fail-open, which is why a
 * non-numeric increment result is treated as unavailable and logged like
 * any other outage.
 */
class ConversationWorkCounter
{
    private const KEY_PREFIX = 'llm-client:conversation-work:';

    public function increment(string $conversationId, int $windowSeconds): ConversationWorkReading
    {
        $now = Carbon::now()->timestamp;
        $windowStart = intdiv($now, $windowSeconds) * $windowSeconds;
        $key = self::KEY_PREFIX.$conversationId.':'.$windowSeconds.':'.$windowStart;
        $ttl = $windowSeconds * 2;

        try {
            $store = Cache::store(config('llm-client.conversation_work.store'));

            $store->add($key, 0, $ttl);
            $count = $store->increment($key);

            // A store that reports failure by returning false rather than by
            // throwing. Not a count of zero — no count at all.
            if (!is_numeric($count)) {
                return $this->unavailable(
                    $conversationId,
                    $windowSeconds,
                    $windowStart,
                    'the store returned no count from increment()',
                );
            }

            $windowStartAt = Carbon::createFromTimestamp($windowStart)->toImmutable();

            return new ConversationWorkReading(
                count: (int) $count,
                maxWorkUnits: null,
                windowSeconds: $windowSeconds,
                windowStart: $windowStartAt,
                resetsAt: $windowStartAt->addSeconds($windowSeconds),
                available: true,
            );
        } catch (\Throwable $e) {
            return $this->unavailable($conversationId, $windowSeconds, $windowStart, $e->getMessage());
        }
    }

    /**
     * Report an unmeasurable window: never a zero and never a partial
     * figure, because a zero would read as "no work yet" and let a
     * caller reason about a count that was never actually taken. Every
     * occurrence is logged, even though it is never itself a refusal.
     */
    private function unavailable(
        string $conversationId,
        int $windowSeconds,
        int $windowStart,
        string $error,
    ): ConversationWorkReading {
        Log::warning('Conversation work counter could not be read or written', [
            'conversation_id' => $conversationId,
            'window_seconds' => $windowSeconds,
            'window_start' => $windowStart,
            'error' => $error,
        ]);

        return ConversationWorkReading::unavailable();
    }
}
