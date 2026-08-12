<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use Tests\TestCase;
use ClarionApp\LlmClient\Exceptions\RateLimitExceededException;
use ClarionApp\LlmClient\Services\RateLimitCounter;
use ClarionApp\LlmClient\Services\RateLimitGate;
use ClarionApp\LlmClient\Services\RateLimitService;
use ClarionApp\LlmClient\ValueObjects\BudgetWorkKind;
use ClarionApp\LlmClient\ValueObjects\RateLimitDecision;
use ClarionApp\LlmClient\ValueObjects\RateLimitReading;
use ClarionApp\LlmClient\ValueObjects\RateLimitScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for RateLimitGate — the sole decision authority for whether a
 * user's request may start.
 *
 *   admit(string $userId, BudgetWorkKind $kind, ?string $conversationId = null): void
 *   evaluate(string $userId): RateLimitDecision   // never throws
 *
 * RateLimitCounter is replaced with a Mockery double throughout, so every
 * assertion about "the counter was/was not touched" is a fact about calls
 * made, not an inference from the cache store's own state. RateLimitService
 * is exercised for real (real rows, real resolution) — only the counting
 * primitive is doubled, matching the class boundary the contract draws.
 *
 * Four properties are load-bearing rather than incidental:
 *
 *  - With no limit configured for a user, admit() returns before the
 *    counter is touched at all — zero cache traffic for an installation
 *    that has not opted in.
 *  - The comparison is against the counter's own post-increment return
 *    value, never a count the gate recomputes for itself.
 *  - A unit of work is admitted once per instance: a second admit() call
 *    for the same user, on the same instance, must not increment the
 *    counter a second time. A fresh instance evaluates again.
 *  - A waived user is never refused, however many requests are attempted,
 *    because a waiver resolves to "no limit" exactly like nothing configured.
 */
class RateLimitGateTest extends TestCase
{
    private string $userA;
    private string $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userA = (string) Str::uuid();
        $this->userB = (string) Str::uuid();
    }

    protected function tearDown(): void
    {
        DB::table('rate_limits')->delete();

        Mockery::close();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function limits(): RateLimitService
    {
        return app(RateLimitService::class);
    }

    private function declareUserDefault(int $maxRequests, int $windowSeconds): void
    {
        $this->limits()->upsert(
            RateLimitScope::UserDefault,
            \ClarionApp\LlmClient\Models\RateLimit::INSTALLATION_SCOPE_ID,
            ['max_requests' => $maxRequests, 'window_seconds' => $windowSeconds],
        );
    }

    private function declareWaiver(string $userId): void
    {
        $this->limits()->upsert(
            RateLimitScope::User,
            $userId,
            ['waived' => true, 'max_requests' => null, 'window_seconds' => null],
        );
    }

    /**
     * A RateLimitCounter double that must never be called. Bound into the
     * container so app(RateLimitGate::class) picks it up.
     */
    private function bindUncalledCounter(): Mockery\MockInterface
    {
        $counter = Mockery::mock(RateLimitCounter::class);
        $counter->shouldNotReceive('increment');

        $this->app->instance(RateLimitCounter::class, $counter);

        return $counter;
    }

    /**
     * A RateLimitCounter double that returns an increasing post-increment
     * count on every call, starting at 1 — the same sequence the real
     * atomic counter would produce for a burst of admissions.
     */
    private function bindCountingCounter(int $windowSeconds): Mockery\MockInterface
    {
        $counter = Mockery::mock(RateLimitCounter::class);
        $n = 0;
        $counter->shouldReceive('increment')
            ->andReturnUsing(function () use (&$n, $windowSeconds) {
                $n++;

                return new RateLimitReading(
                    count: $n,
                    maxRequests: null,
                    windowSeconds: $windowSeconds,
                    windowStart: null,
                    resetsAt: null,
                    available: true,
                );
            });

        $this->app->instance(RateLimitCounter::class, $counter);

        return $counter;
    }

    private function gate(): RateLimitGate
    {
        return app(RateLimitGate::class);
    }

    private function newInstanceBoundary(): void
    {
        $this->app->forgetScopedInstances();
    }

    // ---------------------------------------------------------------
    // No limit configured — the short-circuit
    // ---------------------------------------------------------------

    #[Test]
    public function with_no_limit_configured_admit_returns_without_touching_the_counter(): void
    {
        $this->bindUncalledCounter();

        $this->gate()->admit($this->userA, BudgetWorkKind::Interactive);

        // If the counter had been called, the mock's shouldNotReceive()
        // expectation would already have failed the test above.
        $this->assertTrue(true);
    }

    #[Test]
    public function with_no_limit_configured_evaluate_allows_and_names_no_limit_or_reading(): void
    {
        $this->bindUncalledCounter();

        $decision = $this->gate()->evaluate($this->userA);

        $this->assertInstanceOf(RateLimitDecision::class, $decision);
        $this->assertNull($decision->limit);
        $this->assertNull($decision->reading);
    }

    // ---------------------------------------------------------------
    // A configured limit is enforced against the counter's own count
    // ---------------------------------------------------------------

    #[Test]
    public function requests_up_to_max_requests_are_admitted_and_the_one_past_it_is_refused(): void
    {
        $this->declareUserDefault(3, 60);
        $this->bindCountingCounter(60);

        // Each admission is a fresh instance: admit() memoizes per instance,
        // and three genuinely separate requests must each reach the counter.
        $this->gate()->admit($this->userA, BudgetWorkKind::Interactive);
        $this->newInstanceBoundary();
        $this->gate()->admit($this->userA, BudgetWorkKind::Interactive);
        $this->newInstanceBoundary();
        $this->gate()->admit($this->userA, BudgetWorkKind::Interactive);

        $this->newInstanceBoundary();
        $this->expectException(RateLimitExceededException::class);
        $this->gate()->admit($this->userA, BudgetWorkKind::Interactive);
    }

    #[Test]
    public function the_refusal_carries_the_decision_the_counter_actually_produced(): void
    {
        $this->declareUserDefault(1, 60);
        $this->bindCountingCounter(60);

        $this->gate()->admit($this->userA, BudgetWorkKind::Interactive);
        $this->newInstanceBoundary();

        try {
            $this->gate()->admit($this->userA, BudgetWorkKind::Interactive);
            $this->fail('The second request must be refused once max_requests is reached');
        } catch (RateLimitExceededException $e) {
            $this->assertInstanceOf(RateLimitDecision::class, $e->decision);
            $this->assertNotNull($e->decision->reading);
            $this->assertSame(2, $e->decision->reading->count, 'The refusal must reflect the counter\'s own post-increment value');
        }
    }

    #[Test]
    public function evaluate_never_throws_even_when_the_limit_is_exceeded(): void
    {
        $this->declareUserDefault(1, 60);
        $this->bindCountingCounter(60);

        $decision = $this->gate()->evaluate($this->userA);
        $this->assertInstanceOf(RateLimitDecision::class, $decision);

        $this->newInstanceBoundary();
        $this->bindCountingCounterStartingOver(60, 5);
        $decision = $this->gate()->evaluate($this->userA);
        $this->assertInstanceOf(RateLimitDecision::class, $decision);
    }

    private function bindCountingCounterStartingOver(int $windowSeconds, int $startingCount): void
    {
        $counter = Mockery::mock(RateLimitCounter::class);
        $counter->shouldReceive('increment')->andReturn(new RateLimitReading(
            count: $startingCount,
            maxRequests: null,
            windowSeconds: $windowSeconds,
            windowStart: null,
            resetsAt: null,
            available: true,
        ));

        $this->app->instance(RateLimitCounter::class, $counter);
    }

    // ---------------------------------------------------------------
    // A waiver is never refused
    // ---------------------------------------------------------------

    #[Test]
    public function a_waived_user_is_never_refused_and_the_counter_is_never_touched(): void
    {
        $this->declareWaiver($this->userA);
        $this->bindUncalledCounter();

        for ($i = 0; $i < 10; $i++) {
            $this->gate()->admit($this->userA, BudgetWorkKind::Interactive);
            $this->newInstanceBoundary();
            $this->bindUncalledCounter();
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function a_waiver_for_one_user_does_not_exempt_another_user_from_the_default(): void
    {
        $this->declareUserDefault(1, 60);
        $this->declareWaiver($this->userA);
        $this->bindCountingCounter(60);

        // userB is not waived, and is still bound by the user_default limit.
        $this->gate()->admit($this->userB, BudgetWorkKind::Interactive);
        $this->newInstanceBoundary();

        $this->expectException(RateLimitExceededException::class);
        $this->gate()->admit($this->userB, BudgetWorkKind::Interactive);
    }

    // ---------------------------------------------------------------
    // Admitted once per instance
    // ---------------------------------------------------------------

    #[Test]
    public function a_second_admit_for_the_same_user_on_one_instance_does_not_call_the_counter_again(): void
    {
        $this->declareUserDefault(100, 60);

        $counter = Mockery::mock(RateLimitCounter::class);
        $counter->shouldReceive('increment')
            ->once()
            ->andReturn(new RateLimitReading(
                count: 1,
                maxRequests: null,
                windowSeconds: 60,
                windowStart: null,
                resetsAt: null,
                available: true,
            ));
        $this->app->instance(RateLimitCounter::class, $counter);

        $gate = $this->gate();
        $gate->admit($this->userA, BudgetWorkKind::Interactive);

        // A second admit() on the SAME instance for the SAME user. The
        // mock's ->once() expectation fails the test if this reaches the
        // counter a second time.
        $gate->admit($this->userA, BudgetWorkKind::SystemInitiated);
        $gate->admit($this->userA, BudgetWorkKind::Resumed);

        $this->assertTrue(true);
    }

    #[Test]
    public function a_new_instance_calls_the_counter_again_for_the_same_user(): void
    {
        $this->declareUserDefault(100, 60);

        $first = Mockery::mock(RateLimitCounter::class);
        $first->shouldReceive('increment')->once()->andReturn(new RateLimitReading(
            count: 1,
            maxRequests: null,
            windowSeconds: 60,
            windowStart: null,
            resetsAt: null,
            available: true,
        ));
        $this->app->instance(RateLimitCounter::class, $first);

        $this->gate()->admit($this->userA, BudgetWorkKind::Interactive);

        $this->newInstanceBoundary();

        $second = Mockery::mock(RateLimitCounter::class);
        $second->shouldReceive('increment')->once()->andReturn(new RateLimitReading(
            count: 2,
            maxRequests: null,
            windowSeconds: 60,
            windowStart: null,
            resetsAt: null,
            available: true,
        ));
        $this->app->instance(RateLimitCounter::class, $second);

        // What a queue worker does between jobs, and what a new HTTP
        // request gets by construction — a fresh instance must evaluate
        // again rather than trusting the previous instance's memo.
        $this->gate()->admit($this->userA, BudgetWorkKind::Deferred);

        $this->assertTrue(true);
    }

    #[Test]
    public function the_already_admitted_record_is_per_user_and_not_a_blanket_pass(): void
    {
        $this->declareUserDefault(1, 60);
        $this->bindCountingCounter(60);

        $gate = $this->gate();

        // userA's single allowance is consumed.
        $gate->admit($this->userA, BudgetWorkKind::Interactive);

        // Admitting userA says nothing about userB, on the very same
        // instance.
        $this->expectException(RateLimitExceededException::class);
        $gate->admit($this->userB, BudgetWorkKind::Interactive);
    }

    // ---------------------------------------------------------------
    // Comparison boundary belongs to RateLimitGate alone
    // ---------------------------------------------------------------

    /**
     * RateLimitCounter performs no comparison against a configured limit at
     * all — it returns a raw reading and nothing else. The comparison lives
     * exclusively in RateLimitGate.
     */
    #[Test]
    public function the_counter_class_contains_no_reference_to_max_requests(): void
    {
        $source = file_get_contents((new \ReflectionClass(RateLimitCounter::class))->getFileName());

        $this->assertStringNotContainsString(
            'max_requests',
            $source,
            'RateLimitCounter must make no admission decision; comparing a count to a limit is RateLimitGate\'s job alone'
        );
    }
}
