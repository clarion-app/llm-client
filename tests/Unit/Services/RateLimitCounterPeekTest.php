<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use Tests\TestCase;
use ClarionApp\LlmClient\Services\RateLimitCounter;
use ClarionApp\LlmClient\ValueObjects\RateLimitReading;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for RateLimitCounter::peek() — the new, deliberately narrow,
 * non-mutating read added for DegradationGate/DegradationStatusController
 * (research.md D9, contracts §3).
 *
 *   peek(string $userId, int $windowSeconds): RateLimitReading
 *
 * Three properties are load-bearing:
 *
 *  - A key with no prior increment() call is a fact ("nothing has happened
 *    yet this window"), not a failure — peek() returns a zero-count,
 *    available = true reading, never unavailable().
 *  - peek() never mutates: a plain Cache::get() against the current
 *    window's key, no Cache::add()/Cache::increment() call of any kind —
 *    calling it any number of times in a row must return the identical
 *    count.
 *  - A store failure returns unavailable(), identical in shape to
 *    increment()'s own failure case, and resetsAt is derived from the
 *    identical windowStart/windowSeconds arithmetic increment() itself
 *    uses — never independently computed.
 */
class RateLimitCounterPeekTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 8, 12, 0, 0, 0));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function peek_against_a_key_with_no_prior_increment_returns_a_zero_count_available_reading(): void
    {
        $counter = new RateLimitCounter();
        $userId = (string) Str::uuid();

        $reading = $counter->peek($userId, 60);

        $this->assertInstanceOf(RateLimitReading::class, $reading);
        $this->assertTrue($reading->available, 'No prior activity this window is a fact, not a failure.');
        $this->assertSame(0, $reading->count);
    }

    #[Test]
    public function peek_reads_the_current_count_without_mutating_it(): void
    {
        $counter = new RateLimitCounter();
        $userId = (string) Str::uuid();

        $counter->increment($userId, 60);
        $counter->increment($userId, 60);
        $counter->increment($userId, 60);

        $first = $counter->peek($userId, 60);
        $second = $counter->peek($userId, 60);

        $this->assertSame(3, $first->count);
        $this->assertSame(3, $second->count, 'peek() must never change the count it reads.');

        // A subsequent increment() proves peek() placed no seed/write of
        // its own that would have shifted the sequence.
        $fourth = $counter->increment($userId, 60);
        $this->assertSame(4, $fourth->count);
    }

    #[Test]
    public function peek_returns_unavailable_identical_in_shape_to_increments_own_failure_case_on_a_store_failure(): void
    {
        $driverName = 'rate_limit_counter_peek_throwing';

        Cache::extend($driverName, fn () => new Repository(new class implements Store {
            public function get($key) { throw new \RuntimeException('store unavailable'); }
            public function many(array $keys) { return array_fill_keys($keys, null); }
            public function put($key, $value, $seconds) { return true; }
            public function putMany(array $values, $seconds) { return true; }
            public function add($key, $value, $seconds) { return true; }
            public function increment($key, $value = 1) { return $value; }
            public function decrement($key, $value = 1) { return -$value; }
            public function forever($key, $value) { return true; }
            public function forget($key) { return true; }
            public function flush() { return true; }
            public function getPrefix() { return ''; }
        }));
        config(["cache.stores.{$driverName}" => ['driver' => $driverName]]);
        config(['llm-client.rate_limit.store' => $driverName]);

        Log::spy();

        $counter = new RateLimitCounter();
        $reading = $counter->peek((string) Str::uuid(), 60);

        $this->assertFalse($reading->available);
        $this->assertNull($reading->count);
        $this->assertNull($reading->maxRequests);
        $this->assertNull($reading->windowSeconds);
        $this->assertNull($reading->windowStart);
        $this->assertNull($reading->resetsAt);

        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    #[Test]
    public function peeks_resets_at_matches_the_window_arithmetic_increment_would_compute_for_the_same_instant(): void
    {
        $counter = new RateLimitCounter();
        $userId = (string) Str::uuid();
        $windowSeconds = 60;

        $counter->increment($userId, $windowSeconds);
        $peeked = $counter->peek($userId, $windowSeconds);

        $now = Carbon::now()->timestamp;
        $windowStart = intdiv($now, $windowSeconds) * $windowSeconds;
        $expectedWindowStart = Carbon::createFromTimestamp($windowStart)->toImmutable();
        $expectedResetsAt = $expectedWindowStart->addSeconds($windowSeconds);

        $this->assertTrue($expectedWindowStart->equalTo($peeked->windowStart));
        $this->assertTrue($expectedResetsAt->equalTo($peeked->resetsAt));
    }

    #[Test]
    public function peek_never_calls_add_or_increment(): void
    {
        $calls = [];

        $trackingStore = new class($calls) implements Store {
            private $calls;

            public function __construct(&$calls)
            {
                $this->calls = &$calls;
            }

            public function get($key) { $this->calls[] = 'get'; return null; }
            public function many(array $keys) { return array_fill_keys($keys, null); }
            public function put($key, $value, $seconds) { $this->calls[] = 'put'; return true; }
            public function putMany(array $values, $seconds) { return true; }
            public function add($key, $value, $seconds) { $this->calls[] = 'add'; return true; }
            public function increment($key, $value = 1) { $this->calls[] = 'increment'; return $value; }
            public function decrement($key, $value = 1) { $this->calls[] = 'decrement'; return -$value; }
            public function forever($key, $value) { return true; }
            public function forget($key) { return true; }
            public function flush() { return true; }
            public function getPrefix() { return ''; }
        };

        Cache::extend('rate_limit_counter_peek_tracking', fn () => new Repository($trackingStore));
        config(['cache.stores.rate_limit_counter_peek_tracking' => ['driver' => 'rate_limit_counter_peek_tracking']]);
        config(['llm-client.rate_limit.store' => 'rate_limit_counter_peek_tracking']);

        $counter = new RateLimitCounter();
        $counter->peek((string) Str::uuid(), 60);

        $this->assertSame(['get'], $calls, 'peek() must be a plain Cache::get() — no add()/increment()/put().');
    }
}
