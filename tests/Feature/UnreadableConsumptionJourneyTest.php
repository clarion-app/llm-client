<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Events\SpendingEnforcementDegraded;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\SpendingCeiling;
use ClarionApp\LlmClient\Services\CostRollupQuery;
use ClarionApp\LlmClient\Services\SpendingCeilingService;
use ClarionApp\LlmClient\ValueObjects\BudgetScope;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What happens when the consumption figure cannot be read.
 *
 * Everywhere else in this package a metrics failure degrades quietly so the
 * conversation carries on. Enforcement is the one place where that default
 * is wrong: quietly carrying on *is* the runaway spending the feature
 * exists to prevent. So the behaviour is fail-closed by default — and,
 * because reasonable operators will disagree, it is an explicit setting
 * rather than an accident of where a try/catch happened to land.
 *
 * The setting bites in exactly one place, and the two boundaries either
 * side of it are as important as the rule itself:
 *
 *  - A warn-only ceiling never blocks. A warn ceiling that starts refusing
 *    work because a query timed out is a capability reduction nobody asked
 *    for.
 *  - An installation with no ceiling configured never reaches the ledger at
 *    all, so there is nothing to be unable to read.
 *
 * The failure is reproduced with a CostRollupQuery double that throws —
 * the scoped failure the requirement is actually about (a lock timeout, a
 * malformed decimal, a missing table in a host that has not migrated).
 * A simulated total outage would fail the request for unrelated reasons
 * long before the gate mattered, and dropping a table is not something to
 * do to a schema, even a disposable one.
 */
class UnreadableConsumptionJourneyTest extends TestCase
{
    private User $operator;
    private User $user;
    private Conversation $conversation;
    private ThrowingCostRollupQuery $throwingRollups;

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
        Cache::flush();

        $this->operator = User::factory()->create();
        $this->user = User::factory()->create();

        config(['llm-client.cost.operator_user_ids' => [$this->operator->id]]);

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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        DB::table('cost_summaries')->delete();
        DB::table('spending_ceilings')->delete();
        DB::table('agent_runs')->delete();

        Mockery::close();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Make the consumption read fail in a way scoped to that one query.
     *
     * Bound as a container instance rather than a scoped one, so it
     * survives the request boundaries drawn below and every simulated
     * request sees the same persistent fault.
     */
    private function makeConsumptionUnreadable(): void
    {
        $this->throwingRollups = new ThrowingCostRollupQuery();
        $this->app->instance(CostRollupQuery::class, $this->throwingRollups);
        $this->newRequestBoundary();
    }

    private function declareCeiling(string $mode): void
    {
        app(SpendingCeilingService::class)->upsert(
            BudgetScope::UserDefault,
            SpendingCeiling::INSTALLATION_SCOPE_ID,
            ['amount' => '25.00', 'period_type' => 'month', 'enforcement_mode' => $mode],
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

    // ---------------------------------------------------------------
    // Fail closed, by default, for a stop-mode ceiling
    // ---------------------------------------------------------------

    #[Test]
    public function an_unreadable_figure_refuses_work_under_a_stop_mode_ceiling(): void
    {
        $this->declareCeiling('stop');
        $this->makeConsumptionUnreadable();

        $response = $this->requestAgentWork();

        $response->assertStatus(402);

        $body = $response->json();

        $this->assertSame('budget_consumption_unavailable', $body['code']);
        $this->assertTrue($body['degraded']);
        $this->assertNotEmpty($body['message']);
    }

    /**
     * An omitted figure cannot be misread as "nothing spent"; a zero can.
     * This is the difference between telling a user the number is unknown
     * and telling them, wrongly, that they have spent nothing.
     */
    #[Test]
    public function the_degraded_refusal_omits_the_figures_rather_than_zeroing_them(): void
    {
        $this->declareCeiling('stop');
        $this->makeConsumptionUnreadable();

        $consumption = $this->requestAgentWork()->assertStatus(402)->json('consumption');

        $this->assertFalse($consumption['available']);
        $this->assertArrayNotHasKey('amount', $consumption);
        $this->assertArrayNotHasKey('request_count', $consumption);
        $this->assertArrayNotHasKey('unpriced_request_count', $consumption);
        $this->assertArrayNotHasKey('has_estimated_cost', $consumption);

        // The caveat is carried on every refusal without exception, degraded
        // or not.
        $this->assertTrue($consumption['approximate']);
        $this->assertNotEmpty($consumption['approximation_note']);
    }

    // ---------------------------------------------------------------
    // ...but only for a stop-mode ceiling
    // ---------------------------------------------------------------

    #[Test]
    public function an_unreadable_figure_never_blocks_a_warn_only_ceiling(): void
    {
        $this->declareCeiling('warn');
        $this->makeConsumptionUnreadable();

        $this->requestAgentWork()->assertStatus(200);
    }

    #[Test]
    public function an_unreadable_figure_changes_nothing_when_no_ceiling_is_configured(): void
    {
        $this->makeConsumptionUnreadable();

        $this->requestAgentWork()->assertStatus(200);

        $this->assertSame(
            0,
            $this->throwingRollups->attempts,
            'With nothing configured the gate short-circuits before the ledger, so there is no '
            .'read to fail in the first place'
        );
    }

    // ---------------------------------------------------------------
    // The setting, and its shipped default
    // ---------------------------------------------------------------

    #[Test]
    public function setting_on_unreadable_consumption_to_allow_lets_the_stop_mode_case_through(): void
    {
        $this->declareCeiling('stop');
        $this->makeConsumptionUnreadable();

        config(['llm-client.budget.on_unreadable_consumption' => 'allow']);

        $this->requestAgentWork()->assertStatus(200);

        // Fail-open is a change of policy about an unreadable figure, not a
        // change to anybody's ceiling.
        $ceiling = DB::table('spending_ceilings')->first();
        $this->assertSame(0, bccomp($ceiling->amount, '25.00', 10));
        $this->assertSame('month', $ceiling->period_type);
        $this->assertSame('stop', $ceiling->enforcement_mode);
        $this->assertSame(0, bccomp((string) $ceiling->approach_threshold, '0.8', 4));
    }

    /**
     * Asserted against the config file as shipped, not against a value this
     * test sets — otherwise the test would pass just as happily with the
     * default reversed.
     */
    #[Test]
    public function the_shipped_default_is_fail_closed(): void
    {
        $shipped = require dirname(__DIR__, 2).'/config/llm-client.php';

        $this->assertSame(
            'stop',
            $shipped['budget']['on_unreadable_consumption'],
            'The clarified default is fail-closed: proceeding silently on an unreadable figure is '
            .'the runaway spending this feature exists to prevent'
        );
    }

    // ---------------------------------------------------------------
    // The degraded state is surfaced, not logged and forgotten
    // ---------------------------------------------------------------

    #[Test]
    public function every_occurrence_is_logged(): void
    {
        $this->declareCeiling('stop');
        $this->makeConsumptionUnreadable();

        Log::spy();

        $this->requestAgentWork()->assertStatus(402);
        $this->newRequestBoundary();
        $this->requestAgentWork()->assertStatus(402);

        // The log is the complete record even where the broadcast is
        // throttled, so both occurrences appear.
        Log::shouldHaveReceived('warning')->atLeast()->twice();
    }

    #[Test]
    public function operators_are_notified_that_enforcement_is_degraded(): void
    {
        Event::fake([SpendingEnforcementDegraded::class]);

        $this->declareCeiling('stop');
        $this->makeConsumptionUnreadable();

        $this->requestAgentWork()->assertStatus(402);

        Event::assertDispatched(SpendingEnforcementDegraded::class);
    }

    /**
     * A storm of failing requests must not become a storm of events. What
     * matters is that the degraded state stays visible for as long as it
     * persists, which a throttled repeat satisfies — and unthrottled
     * notification would itself become the outage.
     */
    #[Test]
    public function the_degraded_notification_is_throttled_while_the_condition_persists(): void
    {
        Event::fake([SpendingEnforcementDegraded::class]);

        $this->declareCeiling('stop');
        $this->makeConsumptionUnreadable();

        config(['llm-client.budget.degraded_notice_throttle_seconds' => 60]);

        for ($i = 0; $i < 10; $i++) {
            $this->newRequestBoundary();
            $this->requestAgentWork()->assertStatus(402);
        }

        Event::assertDispatchedTimes(SpendingEnforcementDegraded::class, 1);
    }

    #[Test]
    public function the_notification_fires_again_once_the_throttle_window_has_passed(): void
    {
        Event::fake([SpendingEnforcementDegraded::class]);

        $this->declareCeiling('stop');
        $this->makeConsumptionUnreadable();

        config(['llm-client.budget.degraded_notice_throttle_seconds' => 60]);

        $this->requestAgentWork()->assertStatus(402);

        Carbon::setTestNow(Carbon::parse('2026-08-14 10:05:00', 'UTC'));
        Cache::flush();
        $this->newRequestBoundary();

        $this->requestAgentWork()->assertStatus(402);

        Event::assertDispatchedTimes(SpendingEnforcementDegraded::class, 2);
    }
}

/**
 * A CostRollupQuery whose reads fail in a way scoped to that one query,
 * counting attempts so "the ledger was never reached" can be asserted
 * directly rather than inferred from an absence of symptoms.
 */
class ThrowingCostRollupQuery extends CostRollupQuery
{
    public int $attempts = 0;

    public function userTotal(string $userId, string $from, string $to, ?string $callerId, bool $isOperator): array
    {
        $this->attempts++;

        throw new \RuntimeException('cost_summaries read failed');
    }

    public function installationTotal(string $from, string $to): array
    {
        $this->attempts++;

        throw new \RuntimeException('cost_summaries read failed');
    }
}
