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
 * Unit tests for RateLimitCounter — the sole reader/writer of the
 * Cache-backed fixed-window request counter.
 *
 *   increment(string $userId, int $windowSeconds): RateLimitReading
 *
 * Three properties here are load-bearing rather than incidental:
 *
 *  - The returned count is the post-increment value, never the
 *    pre-increment value: Cache::add() seeds the key at 0 only if it does
 *    not already exist, then Cache::increment() runs unconditionally, so
 *    the atomically-assigned result of the increment itself is the count —
 *    a check-then-increment shape (read, decide, then write) would leave a
 *    race between the read and the write that this protocol does not have.
 *  - The TTL is double the window, not equal to it — a safety margin
 *    against clock skew between the process that seeds the key and one
 *    that reads it near the window boundary.
 *  - A store that cannot be read or written yields RateLimitReading with
 *    available = false and every other field genuinely null, and every
 *    such occurrence is logged. This class fails open; it never fabricates
 *    a count to make a decision look like it was based on real usage.
 */
class RateLimitCounterTest extends TestCase
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
    public function increment_returns_the_post_increment_count_on_successive_calls(): void
    {
        $counter = new RateLimitCounter();
        $userId = (string) Str::uuid();

        $first = $counter->increment($userId, 60);
        $second = $counter->increment($userId, 60);
        $third = $counter->increment($userId, 60);

        $this->assertSame(1, $first->count, 'The first admission must read as count 1, never 0.');
        $this->assertSame(2, $second->count);
        $this->assertSame(3, $third->count);
        $this->assertTrue($first->available);
        $this->assertTrue($second->available);
        $this->assertTrue($third->available);
    }

    #[Test]
    public function increment_writes_to_the_exact_documented_cache_key(): void
    {
        $counter = new RateLimitCounter();
        $userId = (string) Str::uuid();
        $windowSeconds = 60;

        $counter->increment($userId, $windowSeconds);

        $now = Carbon::now()->timestamp;
        $windowStart = intdiv($now, $windowSeconds) * $windowSeconds;
        $expectedKey = "llm-client:rate-limit:user:{$userId}:{$windowSeconds}:{$windowStart}";

        $this->assertTrue(
            Cache::has($expectedKey),
            'The literal computed key must exist in the store after increment().'
        );
    }

    #[Test]
    public function increment_seeds_the_key_with_a_ttl_double_the_window(): void
    {
        $capturedTtls = [];

        $trackingStore = new class($capturedTtls) implements Store {
            private $capturedTtls;

            public function __construct(&$capturedTtls)
            {
                $this->capturedTtls = &$capturedTtls;
            }

            public function get($key) { return null; }
            public function many(array $keys) { return array_fill_keys($keys, null); }
            public function put($key, $value, $seconds) { return true; }
            public function putMany(array $values, $seconds) { return true; }
            public function add($key, $value, $seconds) { $this->capturedTtls[] = $seconds; return true; }
            public function increment($key, $value = 1) { return $value; }
            public function decrement($key, $value = 1) { return -$value; }
            public function forever($key, $value) { return true; }
            public function forget($key) { return true; }
            public function flush() { return true; }
            public function getPrefix() { return ''; }
        };

        Cache::extend('rate_limit_counter_tracking', fn () => new Repository($trackingStore));
        config(['cache.stores.rate_limit_counter_tracking' => ['driver' => 'rate_limit_counter_tracking']]);
        config(['llm-client.rate_limit.store' => 'rate_limit_counter_tracking']);

        $counter = new RateLimitCounter();
        $windowSeconds = 60;

        $counter->increment((string) Str::uuid(), $windowSeconds);

        $this->assertNotEmpty($capturedTtls, 'add() should have been called with a TTL.');
        $this->assertSame(
            $windowSeconds * 2,
            $capturedTtls[0],
            'The TTL must be double the window, not the window itself — see research.md D8.'
        );
    }

    #[Test]
    public function increment_fails_open_and_logs_when_add_throws(): void
    {
        $this->assertFailsOpenAndLogs(function () {
            return new class implements Store {
                public function get($key) { return null; }
                public function many(array $keys) { return array_fill_keys($keys, null); }
                public function put($key, $value, $seconds) { return true; }
                public function putMany(array $values, $seconds) { return true; }
                public function add($key, $value, $seconds) { throw new \RuntimeException('store unavailable'); }
                public function increment($key, $value = 1) { return $value; }
                public function decrement($key, $value = 1) { return -$value; }
                public function forever($key, $value) { return true; }
                public function forget($key) { return true; }
                public function flush() { return true; }
                public function getPrefix() { return ''; }
            };
        });
    }

    #[Test]
    public function increment_fails_open_and_logs_when_increment_throws(): void
    {
        $this->assertFailsOpenAndLogs(function () {
            return new class implements Store {
                public function get($key) { return null; }
                public function many(array $keys) { return array_fill_keys($keys, null); }
                public function put($key, $value, $seconds) { return true; }
                public function putMany(array $values, $seconds) { return true; }
                public function add($key, $value, $seconds) { return true; }
                public function increment($key, $value = 1) { throw new \RuntimeException('store unavailable'); }
                public function decrement($key, $value = 1) { return -$value; }
                public function forever($key, $value) { return true; }
                public function forget($key) { return true; }
                public function flush() { return true; }
                public function getPrefix() { return ''; }
            };
        });
    }

    /**
     * The failure shape that does not throw. Laravel's own NullStore always
     * returns false from increment(); DatabaseStore returns false when the
     * row is gone between the add and the increment; Memcached returns
     * false against an unreachable server. Read at face value, false
     * becomes an integer 0 — a count that was never taken, presented as a
     * real measurement of "no requests yet", which compares under every
     * possible limit. Enforcement would then stop silently: no exception,
     * no warning, and a reading that claims to be available.
     */
    #[Test]
    public function increment_fails_open_and_logs_when_the_store_reports_failure_by_returning_false(): void
    {
        $this->assertFailsOpenAndLogs(function () {
            return new class implements Store {
                public function get($key) { return null; }
                public function many(array $keys) { return array_fill_keys($keys, null); }
                public function put($key, $value, $seconds) { return true; }
                public function putMany(array $values, $seconds) { return true; }
                public function add($key, $value, $seconds) { return true; }
                public function increment($key, $value = 1) { return false; }
                public function decrement($key, $value = 1) { return false; }
                public function forever($key, $value) { return true; }
                public function forget($key) { return true; }
                public function flush() { return true; }
                public function getPrefix() { return ''; }
            };
        });
    }

    private function assertFailsOpenAndLogs(\Closure $makeStore): void
    {
        static $driverSuffix = 0;
        $driverSuffix++;
        $driverName = 'rate_limit_counter_throwing_'.$driverSuffix;

        Cache::extend($driverName, fn () => new Repository($makeStore()));
        config(["cache.stores.{$driverName}" => ['driver' => $driverName]]);
        config(['llm-client.rate_limit.store' => $driverName]);

        Log::spy();

        $counter = new RateLimitCounter();
        $reading = $counter->increment((string) Str::uuid(), 60);

        $this->assertFalse($reading->available);
        $this->assertNull($reading->count);
        $this->assertNull($reading->maxRequests);
        $this->assertNull($reading->windowSeconds);
        $this->assertNull($reading->windowStart);
        $this->assertNull($reading->resetsAt);

        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    #[Test]
    public function it_returns_a_rate_limit_reading_instance(): void
    {
        $counter = new RateLimitCounter();

        $reading = $counter->increment((string) Str::uuid(), 60);

        $this->assertInstanceOf(RateLimitReading::class, $reading);
    }

    /**
     * C7: RateLimitCounter is the only class under src/ that touches the
     * llm-client:rate-limit:* cache key namespace. In particular,
     * RateLimitGate never calls Cache:: directly — it goes through this
     * class alone.
     */
    #[Test]
    public function only_rate_limit_counter_touches_the_cache_key_prefix(): void
    {
        $srcDir = dirname(__DIR__, 3).'/src';
        $offenders = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach (new \RegexIterator($iterator, '/\.php$/') as $file) {
            $path = $file->getPathname();

            if (basename($path) === 'RateLimitCounter.php') {
                continue;
            }

            $contents = file_get_contents($path);

            if (str_contains($contents, 'llm-client:rate-limit:')) {
                $offenders[] = $path;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Only RateLimitCounter.php may reference the llm-client:rate-limit: cache key prefix.'
        );
    }
}
