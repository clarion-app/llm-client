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
 * A store that cannot be read or written yields RateLimitReading with
 * available = false and every other field genuinely null — never a
 * partial count, never a fabricated zero — and every occurrence is
 * logged. This class fails open unconditionally; that is not a decision
 * it makes, only a fact it reports for RateLimitGate to act on.
 *
 * "Cannot be read or written" covers two shapes, not one, and the second
 * is easy to miss. Most stores signal failure by throwing, which the
 * try/catch below handles. But several of Laravel's own stores report a
 * failed increment by *return value* instead: `NullStore::increment()`
 * always returns false, `DatabaseStore::increment()` returns false when
 * the row is gone (evicted or expired between the add and the increment),
 * and Memcached's `increment` returns false against an unreachable
 * server. Taking that false at face value would produce a count of zero
 * that reads as "this user has started no requests this window" — a
 * fabricated figure that always compares under any limit, so enforcement
 * would silently stop with no exception raised, no warning logged, and
 * nothing anywhere recording that the count was never actually measured.
 * That is a strictly worse failure than the deliberate fail-open, which
 * is why a non-numeric increment result is treated as unavailable and
 * logged like any other outage.
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

            // A store that reports failure by returning false rather than by
            // throwing. Not a count of zero — no count at all.
            if (!is_numeric($count)) {
                return $this->unavailable(
                    $userId,
                    $windowSeconds,
                    $windowStart,
                    'the store returned no count from increment()',
                );
            }

            $windowStartAt = Carbon::createFromTimestamp($windowStart)->toImmutable();

            return new RateLimitReading(
                count: (int) $count,
                maxRequests: null,
                windowSeconds: $windowSeconds,
                windowStart: $windowStartAt,
                resetsAt: $windowStartAt->addSeconds($windowSeconds),
                available: true,
            );
        } catch (\Throwable $e) {
            return $this->unavailable($userId, $windowSeconds, $windowStart, $e->getMessage());
        }
    }

    /**
     * A non-mutating read of the current window's count — never
     * Cache::add()/Cache::increment(), only Cache::get() (research.md D9,
     * contracts §3). Added for DegradationGate/DegradationStatusController,
     * neither of which may consume any of the allowance they are only
     * trying to report on.
     *
     * A key that does not exist yet is a fact ("nothing has happened this
     * window"), not a failure: this returns a zero-count, available = true
     * reading, never unavailable() — the one place this method's contract
     * genuinely differs from increment()'s. windowStart/resetsAt are
     * derived from the identical arithmetic increment() itself uses,
     * duplicated read-only rather than shared, so a reader can never
     * accidentally call the write half.
     */
    public function peek(string $userId, int $windowSeconds): RateLimitReading
    {
        $now = Carbon::now()->timestamp;
        $windowStart = intdiv($now, $windowSeconds) * $windowSeconds;
        $key = self::KEY_PREFIX.$userId.':'.$windowSeconds.':'.$windowStart;

        try {
            $store = Cache::store(config('llm-client.rate_limit.store'));
            $count = $store->get($key);

            $windowStartAt = Carbon::createFromTimestamp($windowStart)->toImmutable();

            return new RateLimitReading(
                count: $count === null ? 0 : (int) $count,
                maxRequests: null,
                windowSeconds: $windowSeconds,
                windowStart: $windowStartAt,
                resetsAt: $windowStartAt->addSeconds($windowSeconds),
                available: true,
            );
        } catch (\Throwable $e) {
            return $this->unavailable($userId, $windowSeconds, $windowStart, $e->getMessage());
        }
    }

    /**
     * Report an unmeasurable window: never a zero and never a partial
     * figure, because a zero would read as "no requests yet" and let a
     * caller reason about a count that was never actually taken. Every
     * occurrence is logged, even though it is never itself a refusal.
     */
    private function unavailable(
        string $userId,
        int $windowSeconds,
        int $windowStart,
        string $error,
    ): RateLimitReading {
        Log::warning('Rate limit counter could not be read or written', [
            'user_id' => $userId,
            'window_seconds' => $windowSeconds,
            'window_start' => $windowStart,
            'error' => $error,
        ]);

        return RateLimitReading::unavailable();
    }
}
