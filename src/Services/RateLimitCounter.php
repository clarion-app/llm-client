<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\ValueObjects\RateLimitReading;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The sole reader/writer of the fixed-window request counter — a Cache
 * key, never a table (data-model.md §2). RateLimitGate never calls
 * Cache:: directly; every touch of a llm-client:rate-limit:* key goes
 * through this class alone.
 *
 * The protocol is increment-then-check, not check-then-increment: this
 * class performs Cache::add($key, 0, $ttl) to seed the key only if it does
 * not already exist, then unconditionally Cache::increment($key), and
 * returns the atomically-assigned post-increment value as the count. The
 * caller (RateLimitGate) compares that value to a configured limit; this
 * class makes no admission decision of its own and holds no reference to
 * a RateLimit row.
 *
 * A check-then-increment shape — read the count, decide, then write —
 * carries a race between the read and the write; this codebase's own
 * nearest precedent (life-log-backend/src/Sync/RequestBudget.php) has
 * exactly that shape. Incrementing first and treating the atomically
 * assigned result as the check is race-free by construction.
 *
 * The key embeds both the user id and the window duration:
 *
 *   llm-client:rate-limit:user:{userId}:{windowSeconds}:{windowStart}
 *
 * where windowStart is the fixed-window bucket boundary in Unix seconds
 * (intdiv(now, windowSeconds) * windowSeconds). Naming the window duration
 * in the key means a resolved limit that changes shape — not just amount —
 * between one request and the next ages out under its own TTL rather than
 * being compared against a count accumulated under a different window's
 * semantics.
 *
 * The TTL is double the window, a clock-skew safety margin matching the
 * 2x pattern this codebase's BufferEvictionCounter already uses for its
 * own Cache::add()-then-increment() primitive.
 *
 * Any \Throwable from either Cache call yields RateLimitReading with
 * available = false and every other field genuinely null — never a
 * partial count, never a fabricated zero — and every occurrence is
 * logged. This class fails open unconditionally; that is not a decision
 * it makes, only a fact it reports for RateLimitGate to act on.
 */
class RateLimitCounter
{
    private const KEY_PREFIX = 'llm-client:rate-limit:user:';

    public function increment(string $userId, int $windowSeconds): RateLimitReading
    {
        $now = Carbon::now()->timestamp;
        $windowStart = intdiv($now, $windowSeconds) * $windowSeconds;
        $key = self::KEY_PREFIX.$userId.':'.$windowSeconds.':'.$windowStart;
        $ttl = $windowSeconds * 2;

        try {
            $store = Cache::store(config('llm-client.rate_limit.store'));

            $store->add($key, 0, $ttl);
            $count = $store->increment($key);

            $windowStartAt = Carbon::createFromTimestamp($windowStart)->toImmutable();

            return new RateLimitReading(
                count: $count,
                maxRequests: null,
                windowSeconds: $windowSeconds,
                windowStart: $windowStartAt,
                resetsAt: $windowStartAt->addSeconds($windowSeconds),
                available: true,
            );
        } catch (\Throwable $e) {
            // Never a zero and never a partial figure: a zero would read
            // as "no requests yet" and let a caller reason about a count
            // that was never actually measured. Every occurrence is
            // logged, even though it is never itself a refusal.
            Log::warning('Rate limit counter could not be read or written', [
                'user_id' => $userId,
                'window_seconds' => $windowSeconds,
                'window_start' => $windowStart,
                'error' => $e->getMessage(),
            ]);

            return RateLimitReading::unavailable();
        }
    }
}
