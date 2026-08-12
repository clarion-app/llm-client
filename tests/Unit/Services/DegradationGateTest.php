<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\ConversationWorkCeiling;
use ClarionApp\LlmClient\Models\DegradationEvent;
use ClarionApp\LlmClient\Models\DegradationSummary;
use ClarionApp\LlmClient\Models\RateLimit;
use ClarionApp\LlmClient\Models\ReductionStep;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\SpendingCeiling;
use ClarionApp\LlmClient\Services\ConversationWorkCounter;
use ClarionApp\LlmClient\Services\DegradationGate;
use ClarionApp\LlmClient\ValueObjects\ConsumptionSnapshot;
use ClarionApp\LlmClient\ValueObjects\ConversationWorkReading;
use ClarionApp\LlmClient\ValueObjects\EnforcementDecision;
use ClarionApp\LlmClient\ValueObjects\RateLimitDecision;
use ClarionApp\LlmClient\ValueObjects\RateLimitReading;
use ClarionApp\LlmClient\ValueObjects\ReservationSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for DegradationGate::evaluate()/linkRun()/forRun() covering
 * research.md D1/D2/D3/D7/D8 and quickstart.md's mutation-testing rows
 * 2/3/9/10/15.
 *
 * DegradationGate does not exist yet (Phase 3, T034) — every test here is
 * expected to fail with a "class not found" style error until it is built.
 * The tests are written against the exact contract contracts/degradation-
 * api.md §3 and data-model.md §2 already fix, so they should require no
 * changes once T034 lands.
 */
class DegradationGateTest extends TestCase
{
    private User $user;
    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-12 10:00:00', 'UTC'));

        $this->user = User::factory()->create();

        $server = Server::create([
            'name' => 'Test Server',
            'server_url' => 'http://localhost:11434',
            'provider_type' => 'llama_cpp',
        ]);

        $this->conversation = Conversation::create([
            'user_id' => $this->user->id,
            'server_id' => $server->id,
            'model' => 'big-model',
            'character' => 'Clarion',
            'title' => 'Already titled',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        DB::table('reduction_steps')->delete();
        DB::table('degradation_events')->delete();
        DB::table('degradation_summaries')->delete();
        DB::table('conversation_work_ceilings')->delete();

        Mockery::close();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function reductionStep(array $overrides = []): ReductionStep
    {
        return ReductionStep::create(array_merge([
            'axis' => 'budget_user',
            'threshold_ratio' => '0.7000',
            'substitute_model' => 'small-model',
            'enabled' => true,
        ], $overrides));
    }

    /**
     * An EnforcementDecision reporting a governing ceiling of $amount with
     * $consumed already recorded against it (and nothing held), the exact
     * shape DegradationGate::evaluate() reuses per research.md D2 — never
     * re-read via a fresh standingFor() call.
     */
    private function budgetDecision(string $amount, string $consumed, bool $available = true): EnforcementDecision
    {
        $ceiling = SpendingCeiling::create([
            'scope_type' => 'user_default',
            'scope_id' => SpendingCeiling::INSTALLATION_SCOPE_ID,
            'amount' => $amount,
            'period_type' => 'month',
            'enforcement_mode' => 'stop',
        ]);

        $snapshot = $available
            ? new ConsumptionSnapshot(
                amount: $consumed,
                requestCount: 1,
                unpricedRequestCount: 0,
                unpricedTotalTokens: 0,
                hasEstimatedCost: false,
                periodType: 'month',
                periodFrom: '2026-08-01',
                periodTo: '2026-08-31',
                resetsAt: Carbon::parse('2026-09-01 00:00:00', 'UTC')->toImmutable(),
            )
            : ConsumptionSnapshot::unavailable(
                'month',
                '2026-08-01',
                '2026-08-31',
                Carbon::parse('2026-09-01 00:00:00', 'UTC')->toImmutable(),
            );

        return new EnforcementDecision(
            outcome: EnforcementDecision::ALLOW,
            governingCeiling: $ceiling,
            snapshot: $snapshot,
            degraded: false,
            reason: null,
            held: new ReservationSnapshot(amount: '0', available: true),
        );
    }

    private function rateLimitDecision(int $maxRequests, int $count, bool $available = true): RateLimitDecision
    {
        $limit = RateLimit::create([
            'scope_type' => 'user_default',
            'scope_id' => RateLimit::INSTALLATION_SCOPE_ID,
            'max_requests' => $maxRequests,
            'window_seconds' => 60,
        ]);

        $reading = $available
            ? new RateLimitReading(
                count: $count,
                maxRequests: $maxRequests,
                windowSeconds: 60,
                windowStart: Carbon::now()->toImmutable(),
                resetsAt: Carbon::now()->addSeconds(60)->toImmutable(),
                available: true,
            )
            : RateLimitReading::unavailable();

        return new RateLimitDecision(RateLimitDecision::ALLOW, $limit, $reading);
    }

    private function declareConversationWorkCeiling(int $maxWorkUnits, int $windowSeconds): void
    {
        ConversationWorkCeiling::create([
            'scope_type' => 'conversation_default',
            'scope_id' => RateLimit::INSTALLATION_SCOPE_ID,
            'max_work_units' => $maxWorkUnits,
            'window_seconds' => $windowSeconds,
        ]);
    }

    // -----------------------------------------------------------------
    // No-ladder short-circuit (mutation row 9)
    // -----------------------------------------------------------------

    #[Test]
    public function with_no_ladder_configured_evaluate_issues_exactly_one_query_and_returns_full(): void
    {
        $gate = app(DegradationGate::class);

        $budgetDecision = $this->budgetDecision('10.0000000000', '9.9999999999');
        $rateLimitDecision = $this->rateLimitDecision(10, 9);

        $seen = [];
        DB::listen(function ($query) use (&$seen) {
            $seen[] = $query->sql;
        });

        $decision = $gate->evaluate((string) $this->user->id, $this->conversation->id, $rateLimitDecision, $budgetDecision);

        $this->assertSame('full', $decision->outcome);
        $this->assertNull($decision->governingStep);
        $this->assertCount(
            1,
            $seen,
            "evaluate() must issue exactly one query (ReductionStep::exists()) when no ladder is configured, saw:\n".implode("\n", $seen)
        );
    }

    // -----------------------------------------------------------------
    // Axis ratio computation reuses the passed-in decisions (D2)
    // -----------------------------------------------------------------

    #[Test]
    public function budget_axis_ratio_is_computed_from_the_passed_enforcement_decision_never_a_fresh_standing_call(): void
    {
        $gate = app(DegradationGate::class);

        $this->reductionStep(['axis' => 'budget_user', 'threshold_ratio' => '0.5000']);

        // 60 consumed of 100 ceiling => ratio 0.60, crosses the 0.50 threshold.
        $budgetDecision = $this->budgetDecision('100.0000000000', '60.0000000000');
        $rateLimitDecision = $this->rateLimitDecision(1000, 0);

        $decision = $gate->evaluate((string) $this->user->id, $this->conversation->id, $rateLimitDecision, $budgetDecision);

        $this->assertSame('reduced', $decision->outcome);
        $this->assertSame('budget_user', $decision->axis);
        $this->assertSame(0, bccomp($decision->ratio, '0.6000', 4));
    }

    #[Test]
    public function rate_limit_axis_ratio_is_computed_from_the_passed_rate_limit_decision(): void
    {
        $gate = app(DegradationGate::class);

        $this->reductionStep(['axis' => 'rate_limit', 'threshold_ratio' => '0.5000']);

        $budgetDecision = $this->budgetDecision('100.0000000000', '0.0000000000');
        // 80 of 100 requests => ratio 0.80, crosses the 0.50 threshold.
        $rateLimitDecision = $this->rateLimitDecision(100, 80);

        $decision = $gate->evaluate((string) $this->user->id, $this->conversation->id, $rateLimitDecision, $budgetDecision);

        $this->assertSame('reduced', $decision->outcome);
        $this->assertSame('rate_limit', $decision->axis);
        $this->assertSame(0, bccomp($decision->ratio, '0.8000', 4));
    }

    #[Test]
    public function conversation_work_axis_uses_a_fresh_non_mutating_peek_never_increment(): void
    {
        $this->declareConversationWorkCeiling(10, 60);
        $this->reductionStep(['axis' => 'conversation_work', 'threshold_ratio' => '0.5000']);

        $counterSpy = Mockery::mock(ConversationWorkCounter::class);
        $counterSpy->shouldReceive('peek')
            ->once()
            ->andReturn(new ConversationWorkReading(
                count: 8,
                maxWorkUnits: 10,
                windowSeconds: 60,
                windowStart: Carbon::now()->toImmutable(),
                resetsAt: Carbon::now()->addSeconds(60)->toImmutable(),
                available: true,
            ));
        $counterSpy->shouldNotReceive('increment');
        $this->app->instance(ConversationWorkCounter::class, $counterSpy);

        $gate = app(DegradationGate::class);
        $budgetDecision = $this->budgetDecision('100.0000000000', '0.0000000000');
        $rateLimitDecision = $this->rateLimitDecision(1000, 0);

        $decision = $gate->evaluate((string) $this->user->id, $this->conversation->id, $rateLimitDecision, $budgetDecision);

        $this->assertSame('reduced', $decision->outcome);
        $this->assertSame('conversation_work', $decision->axis);
        $this->assertSame(0, bccomp($decision->ratio, '0.8000', 4));
    }

    // -----------------------------------------------------------------
    // Tightest-crossed-step selection (D7, mutation row 10)
    // -----------------------------------------------------------------

    #[Test]
    public function the_step_crossed_by_the_widest_margin_governs_regardless_of_insertion_order(): void
    {
        // rate_limit margin: 0.95 - 0.90 = 0.05
        // budget_user margin: 0.80 - 0.70 = 0.10 (the larger margin)
        // Insert the smaller-margin (rate_limit) step FIRST, so an
        // insertion-order selection would (wrongly) pick it.
        $this->reductionStep(['axis' => 'rate_limit', 'threshold_ratio' => '0.9000', 'substitute_model' => 'rl-model']);
        $this->reductionStep(['axis' => 'budget_user', 'threshold_ratio' => '0.7000', 'substitute_model' => 'budget-model']);

        $gate = app(DegradationGate::class);
        $budgetDecision = $this->budgetDecision('100.0000000000', '80.0000000000');
        $rateLimitDecision = $this->rateLimitDecision(100, 95);

        $decision = $gate->evaluate((string) $this->user->id, $this->conversation->id, $rateLimitDecision, $budgetDecision);

        $this->assertSame('budget_user', $decision->axis, 'the larger-margin rung must govern, not the first inserted');
        $this->assertSame('budget-model', $decision->effectiveModel);
    }

    #[Test]
    public function tied_margins_break_installation_first(): void
    {
        // Equal margins on budget_installation and budget_user: both 0.10
        // past their own threshold. Installation must win the tie.
        $this->reductionStep(['axis' => 'budget_installation', 'threshold_ratio' => '0.7000', 'substitute_model' => 'install-model']);
        $this->reductionStep(['axis' => 'budget_user', 'threshold_ratio' => '0.7000', 'substitute_model' => 'user-model']);

        $gate = app(DegradationGate::class);

        // Reuse the same EnforcementDecision's ratio for both axes is not
        // possible (DegradationGate only receives one EnforcementDecision
        // for both budget axes) — the governing ceiling's own scope_type
        // is what tells budget_installation from budget_user apart in a
        // real evaluate() call; this test constructs the ratio to be equal
        // regardless of which axis it is read for, since both read the
        // identical passed-in EnforcementDecision (research.md D2).
        $budgetDecision = $this->budgetDecision('100.0000000000', '80.0000000000');
        $rateLimitDecision = $this->rateLimitDecision(1000, 0);

        $decision = $gate->evaluate((string) $this->user->id, $this->conversation->id, $rateLimitDecision, $budgetDecision);

        $this->assertSame('budget_installation', $decision->axis, 'installation must win an exact-margin tie (research.md D7)');
    }

    // -----------------------------------------------------------------
    // Unreadable-axis exclusion (D8)
    // -----------------------------------------------------------------

    #[Test]
    public function an_unavailable_budget_reading_excludes_that_axis_entirely(): void
    {
        $this->reductionStep(['axis' => 'budget_user', 'threshold_ratio' => '0.1000', 'substitute_model' => 'budget-model']);
        $this->reductionStep(['axis' => 'rate_limit', 'threshold_ratio' => '0.5000', 'substitute_model' => 'rl-model']);

        $gate = app(DegradationGate::class);

        // Budget reading unavailable — must be excluded, never treated as
        // 0% or 100% consumed.
        $budgetDecision = $this->budgetDecision('100.0000000000', '99.0000000000', available: false);
        $rateLimitDecision = $this->rateLimitDecision(100, 80);

        $decision = $gate->evaluate((string) $this->user->id, $this->conversation->id, $rateLimitDecision, $budgetDecision);

        $this->assertSame('rate_limit', $decision->axis, 'the unreadable budget axis must never govern nor block the readable rate_limit axis');
    }

    #[Test]
    public function a_conversation_work_counter_peek_failure_excludes_that_axis_entirely(): void
    {
        $this->declareConversationWorkCeiling(10, 60);
        $this->reductionStep(['axis' => 'conversation_work', 'threshold_ratio' => '0.1000', 'substitute_model' => 'cw-model']);
        $this->reductionStep(['axis' => 'rate_limit', 'threshold_ratio' => '0.5000', 'substitute_model' => 'rl-model']);

        $counterSpy = Mockery::mock(ConversationWorkCounter::class);
        $counterSpy->shouldReceive('peek')->andReturn(ConversationWorkReading::unavailable());
        $this->app->instance(ConversationWorkCounter::class, $counterSpy);

        $gate = app(DegradationGate::class);
        $budgetDecision = $this->budgetDecision('100.0000000000', '0.0000000000');
        $rateLimitDecision = $this->rateLimitDecision(100, 80);

        $decision = $gate->evaluate((string) $this->user->id, $this->conversation->id, $rateLimitDecision, $budgetDecision);

        $this->assertSame('rate_limit', $decision->axis, 'an unreadable conversation_work peek must never govern nor block another readable axis');
    }

    // -----------------------------------------------------------------
    // Run-tracing-disabled fallback (D3, mutation row 18)
    // -----------------------------------------------------------------

    #[Test]
    public function evaluate_always_returns_full_when_run_trace_is_disabled(): void
    {
        config(['llm-client.run_trace.enabled' => false]);

        $this->reductionStep(['axis' => 'budget_user', 'threshold_ratio' => '0.1000']);

        $gate = app(DegradationGate::class);
        $budgetDecision = $this->budgetDecision('100.0000000000', '99.0000000000');
        $rateLimitDecision = $this->rateLimitDecision(100, 0);

        $decision = $gate->evaluate((string) $this->user->id, $this->conversation->id, $rateLimitDecision, $budgetDecision);

        $this->assertSame('full', $decision->outcome, 'with run tracing disabled, degradation must never apply regardless of standing');
    }

    // -----------------------------------------------------------------
    // linkRun()/forRun() round trip (D3, mutation rows 2/3/15)
    // -----------------------------------------------------------------

    #[Test]
    public function link_run_writes_one_degradation_event_only_when_the_decision_is_reduced(): void
    {
        $step = $this->reductionStep(['axis' => 'budget_user', 'threshold_ratio' => '0.5000']);

        $gate = app(DegradationGate::class);
        $budgetDecision = $this->budgetDecision('100.0000000000', '60.0000000000');
        $rateLimitDecision = $this->rateLimitDecision(1000, 0);

        $decision = $gate->evaluate((string) $this->user->id, $this->conversation->id, $rateLimitDecision, $budgetDecision);
        $this->assertSame('reduced', $decision->outcome);

        $runId = (string) Str::uuid();
        $gate->linkRun((string) $this->user->id, $this->conversation->id, $runId);

        $this->assertSame(1, DegradationEvent::where('run_id', $runId)->count());

        $event = DegradationEvent::where('run_id', $runId)->first();
        $this->assertSame($this->conversation->id, $event->conversation_id);
        $this->assertSame((string) $this->user->id, $event->user_id);
        $this->assertSame($step->id, $event->reduction_step_id);
        $this->assertSame('budget_user', $event->axis);
    }

    #[Test]
    public function link_run_writes_nothing_when_the_decision_is_full(): void
    {
        $gate = app(DegradationGate::class);
        $budgetDecision = $this->budgetDecision('100.0000000000', '1.0000000000');
        $rateLimitDecision = $this->rateLimitDecision(1000, 0);

        $decision = $gate->evaluate((string) $this->user->id, $this->conversation->id, $rateLimitDecision, $budgetDecision);
        $this->assertSame('full', $decision->outcome);

        $runId = (string) Str::uuid();
        $gate->linkRun((string) $this->user->id, $this->conversation->id, $runId);

        $this->assertSame(0, DegradationEvent::count());
        $this->assertSame(0, DegradationSummary::count());
    }

    #[Test]
    public function calling_link_run_once_writes_exactly_one_row_never_two(): void
    {
        $this->reductionStep(['axis' => 'budget_user', 'threshold_ratio' => '0.5000']);

        $gate = app(DegradationGate::class);
        $budgetDecision = $this->budgetDecision('100.0000000000', '60.0000000000');
        $rateLimitDecision = $this->rateLimitDecision(1000, 0);

        $gate->evaluate((string) $this->user->id, $this->conversation->id, $rateLimitDecision, $budgetDecision);

        $runId = (string) Str::uuid();
        $gate->linkRun((string) $this->user->id, $this->conversation->id, $runId);

        $this->assertSame(
            1,
            DegradationEvent::where('run_id', $runId)->count(),
            'a single linkRun() call for one run id must produce exactly one DegradationEvent row'
        );
    }

    #[Test]
    public function for_run_reads_back_the_axis_ratio_and_reduction_step_that_link_run_wrote(): void
    {
        $step = $this->reductionStep([
            'axis' => 'budget_user',
            'threshold_ratio' => '0.5000',
            'substitute_model' => 'small-model',
            'withheld_tools' => ['memory_search'],
            'history_budget_ratio' => '0.5000',
        ]);

        $gate = app(DegradationGate::class);
        $budgetDecision = $this->budgetDecision('100.0000000000', '60.0000000000');
        $rateLimitDecision = $this->rateLimitDecision(1000, 0);

        $gate->evaluate((string) $this->user->id, $this->conversation->id, $rateLimitDecision, $budgetDecision);

        $runId = (string) Str::uuid();
        $gate->linkRun((string) $this->user->id, $this->conversation->id, $runId);

        $readBack = $gate->forRun($runId);

        $this->assertSame('reduced', $readBack->outcome);
        $this->assertSame('budget_user', $readBack->axis);
        $this->assertSame(0, bccomp($readBack->ratio, '0.6000', 4));
        $this->assertSame('small-model', $readBack->effectiveModel);
        $this->assertSame(['memory_search'], $readBack->withheldTools);
        $this->assertSame(0, bccomp($readBack->historyBudgetRatio, '0.5000', 4));
        $this->assertNotNull($readBack->governingStep);
        $this->assertSame($step->id, $readBack->governingStep->id);
    }

    #[Test]
    public function for_run_returns_full_for_an_unknown_run_id_and_for_null(): void
    {
        $gate = app(DegradationGate::class);

        $this->assertSame('full', $gate->forRun((string) Str::uuid())->outcome);
        $this->assertSame('full', $gate->forRun(null)->outcome);
    }

    #[Test]
    public function for_run_tolerates_a_since_deleted_governing_reduction_step(): void
    {
        $step = $this->reductionStep(['axis' => 'budget_user', 'threshold_ratio' => '0.5000', 'substitute_model' => 'small-model']);

        $gate = app(DegradationGate::class);
        $budgetDecision = $this->budgetDecision('100.0000000000', '60.0000000000');
        $rateLimitDecision = $this->rateLimitDecision(1000, 0);

        $gate->evaluate((string) $this->user->id, $this->conversation->id, $rateLimitDecision, $budgetDecision);

        $runId = (string) Str::uuid();
        $gate->linkRun((string) $this->user->id, $this->conversation->id, $runId);

        $step->delete();

        $readBack = $gate->forRun($runId);

        $this->assertSame('reduced', $readBack->outcome, 'the response already in progress must stay consistent even after the rung is deleted');
        $this->assertSame('budget_user', $readBack->axis);
        $this->assertSame(0, bccomp($readBack->ratio, '0.6000', 4));
    }
}
