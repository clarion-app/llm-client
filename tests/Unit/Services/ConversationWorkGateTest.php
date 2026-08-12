<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use Tests\TestCase;
use ClarionApp\LlmClient\Services\ConversationWorkCeilingService;
use ClarionApp\LlmClient\Services\ConversationWorkCounter;
use ClarionApp\LlmClient\Services\ConversationWorkGate;
use ClarionApp\LlmClient\ValueObjects\ConversationWorkDecision;
use ClarionApp\LlmClient\ValueObjects\ConversationWorkReading;
use ClarionApp\LlmClient\ValueObjects\ConversationWorkScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for ConversationWorkGate — the sole decision authority for
 * whether one more unit of agent-initiated work may proceed within a
 * conversation's current window.
 *
 *   evaluate(string $conversationId): ConversationWorkDecision   // never throws
 *
 * ConversationWorkCounter is replaced with a Mockery double throughout, so
 * every assertion about "the counter was/was not touched" is a fact about
 * calls made, not an inference from the cache store's own state.
 * ConversationWorkCeilingService is exercised for real (real rows, real
 * resolution) — only the counting primitive is doubled, matching the class
 * boundary the contract draws.
 *
 * Unlike RateLimitGate, this class exposes no admit() and carries no
 * per-instance "already evaluated" memo: every one of the four in-loop call
 * sites this gate serves is a genuinely distinct unit of work that must be
 * counted, not the same unit of work reachable two ways within one request.
 * A second evaluate() call for the same conversation, on the same instance,
 * must therefore reach the counter a second time — the opposite property
 * from RateLimitGate::admit()'s deliberate memo.
 */
class ConversationWorkGateTest extends TestCase
{
    private string $conversationA;
    private string $conversationB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->conversationA = (string) Str::uuid();
        $this->conversationB = (string) Str::uuid();
    }

    protected function tearDown(): void
    {
        DB::table('conversation_work_ceilings')->delete();

        Mockery::close();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function ceilings(): ConversationWorkCeilingService
    {
        return app(ConversationWorkCeilingService::class);
    }

    private function declareConversationDefault(int $maxWorkUnits, int $windowSeconds): void
    {
        $this->ceilings()->upsert(
            ConversationWorkScope::ConversationDefault,
            \ClarionApp\LlmClient\Models\RateLimit::INSTALLATION_SCOPE_ID,
            ['max_work_units' => $maxWorkUnits, 'window_seconds' => $windowSeconds],
        );
    }

    private function declareWaiver(string $conversationId): void
    {
        $this->ceilings()->upsert(
            ConversationWorkScope::Conversation,
            $conversationId,
            ['waived' => true, 'max_work_units' => null, 'window_seconds' => null],
        );
    }

    /**
     * A ConversationWorkCounter double that must never be called. Bound
     * into the container so app(ConversationWorkGate::class) picks it up.
     */
    private function bindUncalledCounter(): Mockery\MockInterface
    {
        $counter = Mockery::mock(ConversationWorkCounter::class);
        $counter->shouldNotReceive('increment');

        $this->app->instance(ConversationWorkCounter::class, $counter);

        return $counter;
    }

    /**
     * A ConversationWorkCounter double that returns an increasing
     * post-increment count on every call, starting at 1 per conversation id
     * — the same per-key sequence the real atomic counter would produce
     * (its cache key embeds the conversation id), so two different
     * conversation ids each get their own independent 1, 2, 3, ... sequence
     * rather than sharing one counter the way a single global $n would.
     */
    private function bindCountingCounter(int $windowSeconds): Mockery\MockInterface
    {
        $counter = Mockery::mock(ConversationWorkCounter::class);
        $counts = [];
        $counter->shouldReceive('increment')
            ->andReturnUsing(function (string $conversationId) use (&$counts, $windowSeconds) {
                $counts[$conversationId] = ($counts[$conversationId] ?? 0) + 1;

                return new ConversationWorkReading(
                    count: $counts[$conversationId],
                    maxWorkUnits: null,
                    windowSeconds: $windowSeconds,
                    windowStart: null,
                    resetsAt: null,
                    available: true,
                );
            });

        $this->app->instance(ConversationWorkCounter::class, $counter);

        return $counter;
    }

    private function gate(): ConversationWorkGate
    {
        return app(ConversationWorkGate::class);
    }

    // ---------------------------------------------------------------
    // No ceiling configured — the short-circuit (C2, FR-011)
    // ---------------------------------------------------------------

    #[Test]
    public function with_no_ceiling_configured_evaluate_allows_without_touching_the_counter(): void
    {
        $this->bindUncalledCounter();

        $decision = $this->gate()->evaluate($this->conversationA);

        $this->assertInstanceOf(ConversationWorkDecision::class, $decision);
        $this->assertFalse($decision->isStop());
        $this->assertNull($decision->ceiling);
        $this->assertNull($decision->reading);

        // If the counter had been called, the mock's shouldNotReceive()
        // expectation would already have failed the test above.
        $this->assertTrue(true);
    }

    // ---------------------------------------------------------------
    // A configured ceiling is enforced against the counter's own count
    // ---------------------------------------------------------------

    #[Test]
    public function work_up_to_max_work_units_is_allowed_and_the_unit_past_it_is_stopped(): void
    {
        $this->declareConversationDefault(3, 60);
        $this->bindCountingCounter(60);

        $gate = $this->gate();

        $first = $gate->evaluate($this->conversationA);
        $second = $gate->evaluate($this->conversationA);
        $third = $gate->evaluate($this->conversationA);

        $this->assertFalse($first->isStop());
        $this->assertFalse($second->isStop());
        $this->assertFalse($third->isStop());

        $fourth = $gate->evaluate($this->conversationA);
        $this->assertTrue($fourth->isStop(), 'The unit past max_work_units must be stopped');
    }

    #[Test]
    public function the_stop_carries_the_reading_the_counter_actually_produced(): void
    {
        $this->declareConversationDefault(1, 60);
        $this->bindCountingCounter(60);

        $gate = $this->gate();
        $gate->evaluate($this->conversationA);

        $decision = $gate->evaluate($this->conversationA);

        $this->assertTrue($decision->isStop());
        $this->assertNotNull($decision->reading);
        $this->assertSame(2, $decision->reading->count, 'The stop must reflect the counter\'s own post-increment value');
        $this->assertNotNull($decision->ceiling);
        $this->assertSame(1, $decision->ceiling->max_work_units);
    }

    #[Test]
    public function evaluate_never_throws_even_when_the_ceiling_is_exceeded(): void
    {
        $this->declareConversationDefault(1, 60);
        $this->bindCountingCounter(60);

        $gate = $this->gate();
        $decision = $gate->evaluate($this->conversationA);
        $this->assertInstanceOf(ConversationWorkDecision::class, $decision);

        $decision = $gate->evaluate($this->conversationA);
        $this->assertInstanceOf(ConversationWorkDecision::class, $decision);
        $this->assertTrue($decision->isStop());
    }

    #[Test]
    public function conversation_work_gate_has_no_admit_method(): void
    {
        $this->assertFalse(
            method_exists(ConversationWorkGate::class, 'admit'),
            'Unlike RateLimitGate, ConversationWorkGate exposes only evaluate() — every call site interprets '
            .'the returned decision with a plain conditional, never a caught exception'
        );
    }

    // ---------------------------------------------------------------
    // A waiver is never stopped
    // ---------------------------------------------------------------

    #[Test]
    public function a_waived_conversation_is_never_stopped_and_the_counter_is_never_touched(): void
    {
        $this->declareWaiver($this->conversationA);
        $this->bindUncalledCounter();

        $gate = $this->gate();

        for ($i = 0; $i < 10; $i++) {
            $decision = $gate->evaluate($this->conversationA);
            $this->assertFalse($decision->isStop());
        }
    }

    #[Test]
    public function a_waiver_for_one_conversation_does_not_exempt_another_conversation_from_the_default(): void
    {
        $this->declareConversationDefault(1, 60);
        $this->declareWaiver($this->conversationA);
        $this->bindCountingCounter(60);

        $gate = $this->gate();

        // conversationB is not waived, and is still bound by the
        // conversation_default ceiling.
        $first = $gate->evaluate($this->conversationB);
        $this->assertFalse($first->isStop());

        $second = $gate->evaluate($this->conversationB);
        $this->assertTrue($second->isStop());
    }

    // ---------------------------------------------------------------
    // No per-instance memo (C4) — the opposite property from RateLimitGate
    // ---------------------------------------------------------------

    #[Test]
    public function a_second_evaluate_for_the_same_conversation_on_one_instance_calls_the_counter_again(): void
    {
        $this->declareConversationDefault(100, 60);

        $counter = Mockery::mock(ConversationWorkCounter::class);
        $counter->shouldReceive('increment')
            ->twice()
            ->andReturn(new ConversationWorkReading(
                count: 1,
                maxWorkUnits: null,
                windowSeconds: 60,
                windowStart: null,
                resetsAt: null,
                available: true,
            ));
        $this->app->instance(ConversationWorkCounter::class, $counter);

        $gate = $this->gate();
        $gate->evaluate($this->conversationA);

        // A second evaluate() on the SAME instance for the SAME
        // conversation. The mock's ->twice() expectation fails the test if
        // this does not reach the counter a second time — every one of the
        // four in-loop call sites is a genuinely distinct unit of work.
        $gate->evaluate($this->conversationA);

        $this->assertTrue(true);
    }

    #[Test]
    public function the_already_evaluated_count_is_per_conversation_and_not_a_blanket_pass(): void
    {
        $this->declareConversationDefault(1, 60);
        $this->bindCountingCounter(60);

        $gate = $this->gate();

        // conversationA's single allowance is consumed.
        $first = $gate->evaluate($this->conversationA);
        $this->assertFalse($first->isStop());

        // Evaluating conversationA says nothing about conversationB, on the
        // very same instance.
        $decision = $gate->evaluate($this->conversationB);
        $this->assertFalse($decision->isStop());
    }

    // ---------------------------------------------------------------
    // Comparison boundary belongs to ConversationWorkGate alone (C5)
    // ---------------------------------------------------------------

    /**
     * ConversationWorkCounter performs no comparison against a configured
     * ceiling at all — it returns a raw reading and nothing else. The
     * comparison lives exclusively in ConversationWorkGate.
     */
    #[Test]
    public function the_counter_class_contains_no_reference_to_max_work_units(): void
    {
        $source = file_get_contents((new \ReflectionClass(ConversationWorkCounter::class))->getFileName());

        $this->assertStringNotContainsString(
            'max_work_units',
            $source,
            'ConversationWorkCounter must make no admission decision; comparing a count to a ceiling is ConversationWorkGate\'s job alone'
        );
    }
}
