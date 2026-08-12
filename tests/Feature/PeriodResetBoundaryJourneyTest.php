<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Events\SpendingThresholdWarned;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\CostSummary;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\SpendingCeiling;
use ClarionApp\LlmClient\Services\BudgetLedger;
use ClarionApp\LlmClient\Services\BudgetThresholdNotifier;
use ClarionApp\LlmClient\Services\MetricsRecorder;
use ClarionApp\LlmClient\Services\SpendingCeilingService;
use ClarionApp\LlmClient\Support\CalendarPeriod;
use ClarionApp\LlmClient\ValueObjects\BudgetScope;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A period turning over is not an event anything has to handle.
 *
 * There is no reset job, no scheduler entry, and nothing for an operator to
 * do. The period is derived from the clock, and consumption is summed over
 * the calendar dates that period spans, so the moment the calendar moves on
 * the sum is over a different — empty — set of rows. This file states that
 * as a property rather than testing a mechanism, because the absence of the
 * mechanism is the design.
 *
 * Two details that are easy to get wrong, and both of which a user would
 * see:
 *
 *  - The reset instant is the *exclusive* upper bound: the day after the
 *    period's last day, at midnight UTC. Reporting the end of the last day
 *    instead produces the classic off-by-one where somebody watching the
 *    clock sees a minute of apparent limbo between the time they were
 *    quoted and the reset taking effect.
 *  - A unit of work straddling the boundary belongs to exactly one period.
 *    Cost is computed at completion from a single captured instant, so
 *    attribution is to the day the work finished — and nothing double-counts
 *    a request into both periods. That already holds today; the point of
 *    stating it is that it would stop holding silently.
 *
 * (User Story 3 extends this file with the fresh-warning clause: a warning
 * that fired last period fires again in the new one, because the period is
 * part of the latch key.)
 */
class PeriodResetBoundaryJourneyTest extends TestCase
{
    private User $user;
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

        $this->user = User::factory()->create();

        $server = Server::create([
            'name' => 'Test Server',
            'server_url' => 'http://localhost:11434',
            'provider_type' => 'llama_cpp',
        ]);

        $this->conversation = Conversation::create([
            'user_id' => $this->user->id,
            'server_id' => $server->id,
            'model' => 'test-model',
            'character' => 'Clarion',
            'title' => 'Already titled',
        ]);

        \Illuminate\Support\Facades\Http::fake();
        Queue::fake();

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

        $this->seedZeroRatePrice();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        DB::table('budget_threshold_notifications')->delete();
        DB::table('cost_summaries')->delete();
        DB::table('spending_ceilings')->delete();
        DB::table('agent_runs')->delete();
        DB::table('model_prices')->delete();

        Mockery::close();

        parent::tearDown();
    }

    /**
     * A priced (zero-rate) row for this file's test-model, effective well
     * before any date this file's tests fake the clock to. 084 added an
     * admission-time cost estimate that treats a genuinely unpriced model
     * under a stop-mode ceiling as refused by default (research.md D8) — a
     * policy this file's tests are not about. A zero-rate price keeps every
     * request here priced (so that policy never engages) while adding
     * nothing measurable to what is held.
     *
     * provider_type is 'openai', not this file's own 'llama_cpp' server
     * value: Server::getProviderTypeAttribute() maps any string ProviderType
     * does not recognize — 'llama_cpp' is not 'llama.cpp' — back to
     * ProviderType::OpenAI, and that resolved value is what
     * Conversation::getEffectiveProviderTypeAttribute() actually returns.
     */
    private function seedZeroRatePrice(): void
    {
        \ClarionApp\LlmClient\Models\ModelPrice::create([
            'provider_type' => 'openai',
            'model' => 'test-model',
            'reused_input_rate' => '0.00000000',
            'fresh_input_rate' => '0.00000000',
            'output_rate' => '0.00000000',
            'effective_from' => '2020-01-01 00:00:00',
            'effective_until' => null,
        ]);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * The last instant inside the current period, and the first instant of
     * the next one, for each supported period type.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function boundaries(): array
    {
        return [
            // A Friday inside the week of Mon 10th – Sun 16th August 2026.
            'day' => ['2026-08-14 23:30:00', '2026-08-15 00:30:00'],
            'week' => ['2026-08-16 23:30:00', '2026-08-17 00:30:00'],
            'month' => ['2026-08-31 23:30:00', '2026-09-01 00:30:00'],
        ];
    }

    private function declareCeiling(string $periodType, string $amount = '25.00'): void
    {
        app(SpendingCeilingService::class)->upsert(
            BudgetScope::UserDefault,
            SpendingCeiling::INSTALLATION_SCOPE_ID,
            ['amount' => $amount, 'period_type' => $periodType, 'enforcement_mode' => 'stop'],
        );
    }

    private function recordSpend(string $amount, string $date): void
    {
        DB::table('cost_summaries')->updateOrInsert(
            [
                'entity_type' => CostSummary::ENTITY_USER,
                'entity_id' => $this->user->id,
                'user_id' => $this->user->id,
                'period_date' => $date,
            ],
            [
                'id' => (string) Str::uuid(),
                'request_count' => 1,
                'priced_cost_total' => $amount,
                'zero_priced_request_count' => 0,
                'unpriced_request_count' => 0,
                'unpriced_total_tokens' => 0,
                'estimated_request_count' => 0,
                'updated_at' => Carbon::now(),
            ]
        );
    }

    private function newRequestBoundary(): void
    {
        $this->app->forgetScopedInstances();
    }

    private function requestAgentWork()
    {
        return $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/agent', [
            'message' => 'Do some work.',
            'conversation_id' => $this->conversation->id,
        ]);
    }

    private function resetEverything(): void
    {
        DB::table('budget_threshold_notifications')->delete();
        DB::table('cost_summaries')->delete();
        DB::table('spending_ceilings')->delete();
        DB::table('agent_runs')->delete();
        $this->newRequestBoundary();
    }

    private function evaluateThresholds(): void
    {
        app(BudgetThresholdNotifier::class)->notify((string) $this->user->id);
    }

    /** @return string[] the period_start of every approach warning on record */
    private function warnedPeriodStarts(): array
    {
        $starts = DB::table('budget_threshold_notifications')
            ->where('kind', 'approach')
            ->pluck('period_start')
            ->map(fn ($value) => substr((string) $value, 0, 10))
            ->all();

        sort($starts);

        return $starts;
    }

    // ---------------------------------------------------------------
    // The block lifts on its own
    // ---------------------------------------------------------------

    #[Test]
    public function a_scope_blocked_in_one_period_is_unblocked_in_the_next_for_every_period_type(): void
    {
        foreach ($this->boundaries() as $periodType => [$before, $after]) {
            $this->resetEverything();

            Carbon::setTestNow(Carbon::parse($before, 'UTC'));

            $this->declareCeiling($periodType);
            $this->recordSpend('30.0000000000', Carbon::now()->toDateString());

            $this->requestAgentWork()->assertStatus(402, "{$periodType}: precondition — the scope is blocked");

            $ceilingsBefore = DB::table('spending_ceilings')->get()->toArray();

            Carbon::setTestNow(Carbon::parse($after, 'UTC'));
            $this->newRequestBoundary();

            // The figure the new period is measured against starts at a real
            // zero, not an empty state.
            $snapshot = app(BudgetLedger::class)->forUser($this->user->id, $periodType);
            $this->assertTrue($snapshot->available, "{$periodType}: a new period is not an unreadable one");
            $this->assertSame('0.0000000000', $snapshot->amount, "{$periodType}: the new period starts at zero");

            $this->newRequestBoundary();
            $this->requestAgentWork()->assertStatus(200, "{$periodType}: the next request proceeds");

            $this->assertEquals(
                $ceilingsBefore,
                DB::table('spending_ceilings')->get()->toArray(),
                "{$periodType}: nothing was reconfigured — no operator action, no reset job, no restart"
            );
        }
    }

    #[Test]
    public function the_previous_periods_consumption_is_still_on_record_and_simply_out_of_range(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 23:30:00', 'UTC'));

        $this->declareCeiling('day');
        $this->recordSpend('30.0000000000', '2026-08-14');

        Carbon::setTestNow(Carbon::parse('2026-08-15 00:30:00', 'UTC'));
        $this->newRequestBoundary();

        $this->assertSame('0.0000000000', app(BudgetLedger::class)->forUser($this->user->id, 'day')->amount);

        // Nothing was deleted or zeroed; the sum is simply over a different
        // set of days now.
        $this->assertSame(
            1,
            DB::table('cost_summaries')->where('entity_id', $this->user->id)->count()
        );
    }

    // ---------------------------------------------------------------
    // The reset instant that gets reported
    // ---------------------------------------------------------------

    #[Test]
    public function the_reported_reset_instant_is_the_first_moment_of_the_next_period(): void
    {
        foreach ($this->boundaries() as $periodType => [$before, $after]) {
            $this->resetEverything();

            Carbon::setTestNow(Carbon::parse($before, 'UTC'));

            $this->declareCeiling($periodType);
            $this->recordSpend('30.0000000000', Carbon::now()->toDateString());

            $period = $this->requestAgentWork()->assertStatus(402)->json('period');

            $expected = Carbon::parse($after, 'UTC')->startOfDay();

            $this->assertSame(
                $expected->toDateString(),
                Carbon::parse($period['resets_at'])->utc()->toDateString(),
                "{$periodType}: the reset date must be the first day of the next period"
            );
            $this->assertSame(
                '00:00:00',
                Carbon::parse($period['resets_at'])->utc()->format('H:i:s'),
                "{$periodType}: an exclusive upper bound at midnight, never 23:59:59 on the last day"
            );

            // And it agrees, exactly, with the shared calendar convention the
            // cost_summaries buckets are summed over.
            $this->assertSame(
                CalendarPeriod::resetsAt($periodType, $period['to'])->toIso8601String(),
                Carbon::parse($period['resets_at'])->utc()->toIso8601String(),
                "{$periodType}: the period reported and the period measured must be the same period"
            );
        }
    }

    #[Test]
    public function the_reported_period_bounds_are_the_calendar_period_that_contains_now(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 23:30:00', 'UTC'));

        $this->declareCeiling('week');
        $this->recordSpend('30.0000000000', '2026-08-14');

        $period = $this->requestAgentWork()->assertStatus(402)->json('period');

        // Weeks start Monday, pinned explicitly rather than taken from the
        // active locale.
        $this->assertSame('week', $period['type']);
        $this->assertSame('2026-08-10', $period['from']);
        $this->assertSame('2026-08-16', $period['to']);
    }

    // ---------------------------------------------------------------
    // A unit of work straddling the boundary
    // ---------------------------------------------------------------

    /**
     * Cost is computed at completion, from one captured instant, and
     * bucketed on that instant's UTC date. So a turn that began before
     * midnight and finished after it lands wholly in the new day — one
     * bucket, never two.
     *
     * This already holds; it is stated here because it would stop holding
     * quietly, and the whole period model rests on it.
     */
    #[Test]
    public function a_unit_of_work_straddling_the_boundary_is_attributed_to_exactly_one_period(): void
    {
        $recorder = new MetricsRecorder();

        // The work starts at 23:59:30 on the 14th...
        Carbon::setTestNow(Carbon::parse('2026-08-14 23:59:30', 'UTC'));

        // ...and completes at 00:00:30 on the 15th, which is when its cost is
        // known and therefore when it is recorded.
        Carbon::setTestNow(Carbon::parse('2026-08-15 00:00:30', 'UTC'));

        $recorder->recordUsage(
            conversationId: $this->conversation->id,
            userId: (string) $this->user->id,
            attemptGroupId: (string) Str::uuid(),
            providerUsage: ['prompt_tokens' => 50, 'completion_tokens' => 100, 'total_tokens' => 150],
            inputText: 'input',
            outputText: 'output',
            model: 'test-model',
            providerType: 'llama_cpp',
        );

        $rows = DB::table('cost_summaries')
            ->where('entity_type', CostSummary::ENTITY_USER)
            ->where('entity_id', $this->user->id)
            ->get();

        $this->assertCount(1, $rows, 'One completed unit of work is one bucket, never one on each side');
        $this->assertSame('2026-08-15', substr((string) $rows[0]->period_date, 0, 10));
    }

    #[Test]
    public function the_new_period_begins_at_zero_even_with_work_recorded_seconds_earlier(): void
    {
        $recorder = new MetricsRecorder();

        Carbon::setTestNow(Carbon::parse('2026-08-14 23:59:30', 'UTC'));
        $recorder->recordUsage(
            conversationId: $this->conversation->id,
            userId: (string) $this->user->id,
            attemptGroupId: (string) Str::uuid(),
            providerUsage: ['prompt_tokens' => 50, 'completion_tokens' => 100, 'total_tokens' => 150],
            inputText: 'input',
            outputText: 'output',
            model: 'test-model',
            providerType: 'llama_cpp',
        );

        Carbon::setTestNow(Carbon::parse('2026-08-15 00:00:30', 'UTC'));
        $this->newRequestBoundary();

        $this->assertSame(
            '0.0000000000',
            app(BudgetLedger::class)->forUser($this->user->id, 'day')->amount,
            'Consumption for the new period begins at zero; the previous unit belongs to the day it finished in'
        );
    }

    // ---------------------------------------------------------------
    // A warning is fresh in a new period, for the same reason
    // ---------------------------------------------------------------

    /**
     * The once-per-period latch is keyed on the period itself, so a new
     * period is a new key. That is the whole mechanism: there is nothing to
     * clear, no reset job to run, and no operator action of any kind — the
     * warning that fired yesterday is simply a different row from the one
     * that fires today.
     *
     * Take period_start out of the key and this is the test that notices:
     * the warning would fire once in the life of the installation and never
     * again, and every later period would pass its threshold in silence.
     */
    #[Test]
    public function a_warning_that_fired_in_the_previous_period_fires_again_in_the_new_one(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-14 23:30:00', 'UTC'));

        // 25.00 daily ceiling, default 0.80 threshold => due at 20.00.
        $this->declareCeiling('day');
        $this->recordSpend('21.0000000000', '2026-08-14');

        Event::fake([SpendingThresholdWarned::class]);

        $this->evaluateThresholds();
        Event::assertDispatchedTimes(SpendingThresholdWarned::class, 1);

        // Still the same period: no duplicate, however often it is asked.
        $this->evaluateThresholds();
        $this->evaluateThresholds();
        Event::assertDispatchedTimes(SpendingThresholdWarned::class, 1);

        $ceilingsBefore = DB::table('spending_ceilings')->get()->toArray();

        // Midnight passes. Nothing is run, nothing is cleared, nobody is
        // asked to do anything.
        Carbon::setTestNow(Carbon::parse('2026-08-15 00:30:00', 'UTC'));
        $this->newRequestBoundary();

        $this->recordSpend('21.0000000000', '2026-08-15');
        $this->evaluateThresholds();

        Event::assertDispatchedTimes(SpendingThresholdWarned::class, 2);

        $this->assertSame(
            ['2026-08-14', '2026-08-15'],
            $this->warnedPeriodStarts(),
            'Two warnings on record, one per period — and the previous period\'s row was never deleted'
        );

        $this->assertEquals(
            $ceilingsBefore,
            DB::table('spending_ceilings')->get()->toArray(),
            'No reset job and no operator action: the ceiling is byte-identical across the boundary'
        );
    }
}
