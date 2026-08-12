<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Events\SpendingThresholdWarned;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\CostSummary;
use ClarionApp\LlmClient\Models\ModelPrice;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\SpendingCeiling;
use ClarionApp\LlmClient\Models\UsageRecord;
use ClarionApp\LlmClient\Services\MetricsRecorder;
use ClarionApp\LlmClient\Services\SpendingCeilingService;
use ClarionApp\LlmClient\ValueObjects\BudgetScope;
use ClarionApp\LlmClient\ValueObjects\ConsumptionSnapshot;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Usage on a model with no configured price is disclosed wherever the
 * period's consumption is reported — and is never quietly counted as free.
 *
 * The awkward fact this feature has to carry honestly is that a currency
 * ceiling cannot measure spend it has no rate for. Spec 073 records such a
 * request as `cost_unpriced = true` with a **null** total cost, deliberately
 * not as a zero, and this feature inherits that: the currency figure a
 * ceiling is compared against genuinely excludes those requests. The only
 * defensible response is to say so, every time the figure is reported —
 * otherwise a reader sees a number that looks complete and is not.
 *
 * "Every time" is the whole requirement, so this file asserts the disclosure
 * on all three surfaces that publish a figure: the standing report, the
 * threshold warning payload, and the 402 refusal body. Carrying it on
 * standing alone would be the plausible half-implementation — the standing
 * endpoint is where a developer thinks about reporting, and the refusal is
 * where the reader is least able to go and check.
 *
 * Two symmetric properties round it out:
 *
 *  - The disclosure is **absent** when there is nothing to disclose. A
 *    permanent "0 unpriced requests" line is noise, and noise is what a
 *    reader learns to skip past.
 *  - An **estimated** cost is a different thing from an unpriced one and is
 *    handled differently: a rate exists, so the money is real and counts
 *    toward the ceiling. It sets has_estimated_cost, which reinforces the
 *    approximation caveat rather than replacing it.
 */
class UnpricedDisclosureJourneyTest extends TestCase
{
    /** A model with a rate: one input token costs exactly 0.10. */
    private const PRICED_MODEL = 'priced-model';

    /** A model with no rate configured anywhere. */
    private const UNPRICED_MODEL = 'unpriced-model';

    private User $user;
    private User $operator;
    private Server $server;
    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasTable('condensation_states')) {
            Schema::create('condensation_states', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('conversation_id')->unique();
                $table->unsignedInteger('consecutive_failures')->default(0);
                $table->timestamp('cooldown_until')->nullable();
                $table->timestamps();
            });
        }

        Carbon::setTestNow(Carbon::parse('2026-08-14 10:00:00', 'UTC'));

        $this->user = User::factory()->create();
        $this->operator = User::factory()->create();

        config(['llm-client.cost.operator_user_ids' => [$this->operator->id]]);

        $this->server = Server::create([
            'name' => 'Test Server',
            'server_url' => 'http://localhost:11434',
            'provider_type' => 'llama_cpp',
        ]);

        $this->conversation = Conversation::create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'character' => 'Clarion',
            'title' => 'Already titled',
        ]);

        // 084 added an admission-time cost estimate: by default, a request
        // on a model with no configured price is refused outright under a
        // stop-mode ceiling (research.md D8) — but this entire file's
        // premise is admitting unpriced work and then asserting how it is
        // disclosed once recorded, which is precisely what the
        // 'admit_untracked' policy preserves (076's own prior implicit
        // behaviour, made explicit and operator-chosen per D8).
        config(['llm-client.budget.on_unpriced_model' => 'admit_untracked']);

        $this->declarePrice();
        $this->fakeProvider();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        DB::table('budget_threshold_notifications')->delete();
        DB::table('cost_summaries')->delete();
        DB::table('spending_ceilings')->delete();
        DB::table('model_prices')->delete();
        DB::table('usage_records')->delete();
        DB::table('agent_runs')->delete();

        Mockery::close();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    /**
     * A rate for PRICED_MODEL only. UNPRICED_MODEL is deliberately absent
     * from model_prices, which is the entire condition under test.
     */
    private function declarePrice(): void
    {
        ModelPrice::create([
            'provider_type' => 'llama_cpp',
            'model' => self::PRICED_MODEL,
            'reused_input_rate' => '0.00000000',
            'fresh_input_rate' => '100000.00000000',
            'output_rate' => '0.00000000',
            'effective_from' => Carbon::now()->subDay(),
            'effective_until' => null,
        ]);
    }

    private function fakeProvider(): void
    {
        \Illuminate\Support\Facades\Http::fake();

        $provider = Mockery::mock(\ClarionApp\LlmClient\Contracts\LlmProvider::class);
        $provider->shouldReceive('chat')->andReturn([
            'choices' => [['message' => ['content' => 'Here is your answer.']]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ]);
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(\ClarionApp\LlmClient\Providers\ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);
        $this->app->instance(\ClarionApp\LlmClient\Providers\ProviderRegistry::class, $registry);
    }

    private function newRequestBoundary(): void
    {
        $this->app->forgetScopedInstances();
    }

    private function declareCeiling(
        string $amount,
        string $mode = 'stop',
        string $periodType = 'day',
        string $threshold = '0.80',
    ): SpendingCeiling {
        return app(SpendingCeilingService::class)->upsert(
            BudgetScope::UserDefault,
            SpendingCeiling::INSTALLATION_SCOPE_ID,
            [
                'amount' => $amount,
                'period_type' => $periodType,
                'enforcement_mode' => $mode,
                'approach_threshold' => $threshold,
            ],
        );
    }

    /**
     * One completed unit of work on a model with no configured price,
     * recorded the way the metrics path records it.
     *
     * Driven through MetricsRecorder rather than written into
     * cost_summaries by hand, because what makes this case real is spec
     * 073's own decision to store a null cost rather than a zero — and
     * hand-writing the counters would test the disclosure while skipping the
     * decision it exists to disclose.
     */
    private function recordUnpricedWork(int $tokens = 400): void
    {
        (new MetricsRecorder())->recordUsage(
            conversationId: $this->conversation->id,
            userId: $this->user->id,
            attemptGroupId: (string) Str::uuid(),
            providerUsage: [
                'prompt_tokens' => $tokens,
                'completion_tokens' => 0,
                'total_tokens' => $tokens,
            ],
            inputText: 'input',
            outputText: '',
            model: self::UNPRICED_MODEL,
            providerType: 'llama_cpp',
        );
    }

    /**
     * One completed unit of work on a priced model whose token counts the
     * provider did not report, so they are estimated. The money is real —
     * there is a rate — and only the token count is an approximation.
     */
    private function recordEstimatedPricedWork(): void
    {
        (new MetricsRecorder())->recordUsage(
            conversationId: $this->conversation->id,
            userId: $this->user->id,
            attemptGroupId: (string) Str::uuid(),
            providerUsage: [],
            inputText: str_repeat('some input text ', 40),
            outputText: 'some output text',
            model: self::PRICED_MODEL,
            providerType: 'llama_cpp',
        );
    }

    /**
     * Priced consumption written directly, so the arithmetic is the
     * caller's rather than a consequence of whatever the rate table charged.
     *
     * Added to the period's existing bucket rather than inserted blindly:
     * the unpriced fixture above already creates that row, and the two are
     * combined in most cases here.
     */
    private function recordSpend(string $amount, string $date = '2026-08-14'): void
    {
        DB::table('cost_summaries')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'entity_type' => CostSummary::ENTITY_USER,
            'entity_id' => $this->user->id,
            'user_id' => $this->user->id,
            'period_date' => $date,
            'request_count' => 0,
            'priced_cost_total' => '0.0000000000',
            'zero_priced_request_count' => 0,
            'unpriced_request_count' => 0,
            'unpriced_total_tokens' => 0,
            'estimated_request_count' => 0,
            'updated_at' => Carbon::now(),
        ]);

        DB::table('cost_summaries')
            ->where('entity_type', CostSummary::ENTITY_USER)
            ->where('entity_id', $this->user->id)
            ->where('period_date', $date)
            ->update([
                'request_count' => DB::raw('request_count + 1'),
                'priced_cost_total' => DB::raw("priced_cost_total + {$amount}"),
                'updated_at' => Carbon::now(),
            ]);
    }

    private function requestAgentWork()
    {
        return $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/agent', [
            'message' => 'Please do some work.',
            'conversation_id' => $this->conversation->id,
        ]);
    }

    private function standing()
    {
        $this->newRequestBoundary();

        return $this->actingAs($this->user, 'api')
            ->getJson('/api/clarion-app/llm-client/budget/standing');
    }

    // ---------------------------------------------------------------
    // Assertion helpers
    // ---------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $consumption
     */
    private function assertDiscloses(array $consumption, int $expectedCount, string $context): void
    {
        $this->assertSame(
            $expectedCount,
            $consumption['unpriced_request_count'] ?? null,
            "{$context}: the unpriced request count must be reported"
        );

        $this->assertArrayHasKey(
            'unpriced_disclosure',
            $consumption,
            "{$context}: FR-018 requires the disclosure wherever this period's consumption is reported"
        );

        $this->assertStringContainsString(
            (string) $expectedCount,
            $consumption['unpriced_disclosure'],
            "{$context}: the disclosure names how many requests it excludes"
        );
        $this->assertStringContainsString(
            'no configured price',
            $consumption['unpriced_disclosure'],
            "{$context}: the disclosure says why those requests are excluded"
        );

        // The disclosure never displaces the approximation caveat; a figure
        // is approximate for a different reason than it is incomplete.
        $this->assertTrue($consumption['approximate'], "{$context}: the caveat travels with every figure");
        $this->assertSame(ConsumptionSnapshot::APPROXIMATION_NOTE, $consumption['approximation_note'], $context);
    }

    /**
     * @param  array<string, mixed>  $consumption
     */
    private function assertDisclosesNothing(array $consumption, string $context): void
    {
        $this->assertSame(0, $consumption['unpriced_request_count'] ?? null, $context);
        $this->assertArrayNotHasKey(
            'unpriced_disclosure',
            $consumption,
            "{$context}: with nothing unpriced there is nothing to disclose, and a permanent \"0 unpriced\" line is noise"
        );
    }

    // ---------------------------------------------------------------
    // The premise: 073 records unpriced work as null, never as zero
    // ---------------------------------------------------------------

    #[Test]
    public function unpriced_work_is_recorded_as_unpriced_rather_than_as_a_zero_cost(): void
    {
        $this->recordUnpricedWork(400);

        $record = UsageRecord::where('model', self::UNPRICED_MODEL)->firstOrFail();

        $this->assertTrue((bool) $record->cost_unpriced, 'A model with no rate produces an unpriced record');
        $this->assertNull($record->total_cost, 'An unpriced request has no cost — not a zero cost');

        $row = DB::table('cost_summaries')
            ->where('entity_type', CostSummary::ENTITY_USER)
            ->where('entity_id', $this->user->id)
            ->firstOrFail();

        $this->assertSame(1, (int) $row->unpriced_request_count);
        $this->assertSame(400, (int) $row->unpriced_total_tokens);
        $this->assertSame(
            0,
            bccomp((string) $row->priced_cost_total, '0', 10),
            'Unpriced usage adds nothing to the currency figure — which is exactly why it has to be disclosed'
        );
    }

    // ---------------------------------------------------------------
    // Surface 1 — the standing report
    // ---------------------------------------------------------------

    #[Test]
    public function the_standing_report_discloses_unpriced_usage_in_the_period(): void
    {
        $this->declareCeiling('25.00');
        $this->recordSpend('10.0000000000');
        $this->recordUnpricedWork(400);
        $this->recordUnpricedWork(1200);

        $response = $this->standing();

        $response->assertStatus(200);

        $consumption = $response->json('user_ceiling.consumption');

        $this->assertDiscloses($consumption, 2, 'The standing report');
        $this->assertSame(1600, $consumption['unpriced_total_tokens']);

        // The currency figure is the priced spend alone, unchanged by the
        // two unpriced requests — the disclosure exists precisely because
        // that is true.
        $this->assertSame(0, bccomp($consumption['amount'], '10.0000000000', 10));
    }

    // ---------------------------------------------------------------
    // Surface 2 — the 402 refusal body
    // ---------------------------------------------------------------

    #[Test]
    public function the_refusal_body_discloses_unpriced_usage_in_the_period(): void
    {
        $this->recordUnpricedWork(400);
        $this->recordSpend('30.0000000000');
        $this->declareCeiling('25.00', 'stop');

        $response = $this->requestAgentWork();

        $response->assertStatus(402);
        $this->assertSame('budget_ceiling_reached', $response->json('code'));

        $this->assertDiscloses($response->json('consumption'), 1, 'The 402 refusal body');
    }

    // ---------------------------------------------------------------
    // Surface 3 — the threshold warning payload
    // ---------------------------------------------------------------

    #[Test]
    public function the_warning_payload_discloses_unpriced_usage_in_the_period(): void
    {
        // Recorded before any ceiling exists, so the notifier has nothing to
        // announce yet and the warning below is unambiguously the gate's.
        $this->recordUnpricedWork(400);

        Event::fake([SpendingThresholdWarned::class]);

        // 25.00 at a 0.80 threshold warns from 20.00; 22.00 is across it and
        // still under the ceiling, so the work proceeds.
        $this->recordSpend('22.0000000000');
        $this->declareCeiling('25.00', 'stop');

        $this->requestAgentWork()->assertStatus(200);

        Event::assertDispatched(
            SpendingThresholdWarned::class,
            function (SpendingThresholdWarned $event) {
                $payload = $event->broadcastWith();

                $this->assertDiscloses($payload['consumption'], 1, 'The threshold warning payload');

                return true;
            }
        );
    }

    // ---------------------------------------------------------------
    // The symmetric case: nothing unpriced, nothing disclosed
    // ---------------------------------------------------------------

    #[Test]
    public function the_disclosure_is_absent_from_the_standing_report_when_nothing_is_unpriced(): void
    {
        $this->declareCeiling('25.00');
        $this->recordSpend('10.0000000000');

        $response = $this->standing();

        $response->assertStatus(200);
        $this->assertDisclosesNothing($response->json('user_ceiling.consumption'), 'The standing report');
    }

    #[Test]
    public function the_disclosure_is_absent_from_the_refusal_body_when_nothing_is_unpriced(): void
    {
        $this->recordSpend('30.0000000000');
        $this->declareCeiling('25.00', 'stop');

        $response = $this->requestAgentWork();

        $response->assertStatus(402);
        $this->assertDisclosesNothing($response->json('consumption'), 'The 402 refusal body');
    }

    #[Test]
    public function the_disclosure_is_absent_from_the_warning_payload_when_nothing_is_unpriced(): void
    {
        Event::fake([SpendingThresholdWarned::class]);

        $this->recordSpend('22.0000000000');
        $this->declareCeiling('25.00', 'stop');

        $this->requestAgentWork()->assertStatus(200);

        Event::assertDispatched(
            SpendingThresholdWarned::class,
            function (SpendingThresholdWarned $event) {
                $this->assertDisclosesNothing(
                    $event->broadcastWith()['consumption'],
                    'The threshold warning payload'
                );

                return true;
            }
        );
    }

    // ---------------------------------------------------------------
    // FR-019 — an estimated cost is real money and counts
    // ---------------------------------------------------------------

    /**
     * An estimated token count is not an unpriced request. A rate exists, so
     * a currency cost exists, and it counts toward the ceiling exactly like
     * any other. What it changes is only how confident the figure is —
     * has_estimated_cost reinforces the approximation caveat rather than
     * standing in for the unpriced disclosure.
     */
    #[Test]
    public function consumption_derived_from_estimated_token_counts_counts_toward_the_ceiling(): void
    {
        $this->recordEstimatedPricedWork();

        $row = DB::table('cost_summaries')
            ->where('entity_type', CostSummary::ENTITY_USER)
            ->where('entity_id', $this->user->id)
            ->firstOrFail();

        $this->assertSame(1, (int) $row->estimated_request_count, 'The token counts were estimated');
        $this->assertSame(0, (int) $row->unpriced_request_count, 'An estimated request is not an unpriced one');
        $this->assertSame(
            1,
            bccomp((string) $row->priced_cost_total, '0', 10),
            'A priced model produces a real currency cost even when the token count is estimated'
        );

        $this->declareCeiling('25.00');

        $consumption = $this->standing()->assertStatus(200)->json('user_ceiling.consumption');

        $this->assertTrue($consumption['has_estimated_cost'], 'The estimation is disclosed as its own flag');
        $this->assertTrue($consumption['approximate'], 'And it reinforces rather than replaces the caveat');
        $this->assertSame(ConsumptionSnapshot::APPROXIMATION_NOTE, $consumption['approximation_note']);
        $this->assertDisclosesNothing($consumption, 'An estimated but priced figure');

        // And it is genuinely measured against the ceiling: a ceiling below
        // the estimated cost stops the next request.
        $this->newRequestBoundary();
        app(SpendingCeilingService::class)->upsert(
            BudgetScope::UserDefault,
            SpendingCeiling::INSTALLATION_SCOPE_ID,
            ['amount' => '0.01', 'period_type' => 'day', 'enforcement_mode' => 'stop'],
        );

        $this->newRequestBoundary();
        $this->requestAgentWork()->assertStatus(402);
    }

    /**
     * The two flags are independent, and a period that has both an unpriced
     * request and an estimated one reports both — neither masks the other.
     */
    #[Test]
    public function an_unpriced_request_and_an_estimated_one_in_the_same_period_are_both_disclosed(): void
    {
        $this->recordUnpricedWork(400);
        $this->recordEstimatedPricedWork();

        $this->declareCeiling('25.00');

        $consumption = $this->standing()->assertStatus(200)->json('user_ceiling.consumption');

        $this->assertDiscloses($consumption, 1, 'A period with both an unpriced and an estimated request');
        $this->assertTrue($consumption['has_estimated_cost']);
    }
}
