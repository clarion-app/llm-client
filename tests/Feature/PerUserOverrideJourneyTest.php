<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\CostSummary;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\SpendingCeiling;
use ClarionApp\LlmClient\Services\SpendingCeilingService;
use ClarionApp\LlmClient\Support\Decimal;
use ClarionApp\LlmClient\ValueObjects\BudgetScope;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * User Story 4 end to end: an operator raises one user's ceiling, lowers
 * another's, and waives a third's, and none of it reaches anybody else.
 *
 * "Without touching everyone" is not a claim about stored rows — a write
 * path that quietly rewrote the default while appearing to write an
 * override would still leave four plausible-looking rows behind. It is a
 * claim about what each user is *allowed to do*, so every change here is
 * followed by driving a real request for all four users and asserting the
 * enforcement outcome each of them gets. That is what SC-010's "verified
 * against at least two other users and the installation-wide scope"
 * requires, and it is why this file works through the agent endpoint rather
 * than through the resolution function alone.
 *
 * The single most important assertion in the file is the FR-010 one: a
 * user whose *user-scoped* ceiling has been waived is still stopped by the
 * installation-wide ceiling. A waiver exempts one named user from their own
 * limit; it is not a licence against the installation's. The two live on
 * different resolution axes and this test is what would notice if they were
 * ever collapsed into one.
 *
 * Note on request boundaries: Laravel's test harness keeps one container
 * across every simulated request in a test method, while a deployment
 * builds one per request. The gate remembers, for the life of one request,
 * that it has already admitted a scope — so the boundary between simulated
 * requests is drawn explicitly here, or a user admitted before a change
 * would appear to be admitted after it too.
 *
 * Consumption is written straight into cost_summaries rather than earned
 * through completed work: what is under test is which *limit* each user is
 * measured against, and hand-writing the figure keeps the four users'
 * consumption identical so that any difference in outcome can only be a
 * difference in ceiling.
 */
class PerUserOverrideJourneyTest extends TestCase
{
    private User $operator;

    /** @var array<string, User> keyed A, B, C, D */
    private array $users = [];

    /** @var array<string, Conversation> keyed A, B, C, D */
    private array $conversations = [];

    private Server $server;

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

        $this->operator = User::factory()->create();

        config(['llm-client.cost.operator_user_ids' => [$this->operator->id]]);

        $this->server = Server::create([
            'name' => 'Test Server',
            'server_url' => 'http://localhost:11434',
            'provider_type' => 'llama_cpp',
        ]);

        foreach (['A', 'B', 'C', 'D'] as $label) {
            $user = User::factory()->create();
            $this->users[$label] = $user;
            $this->conversations[$label] = Conversation::create([
                'user_id' => $user->id,
                'server_id' => $this->server->id,
                'model' => 'test-model',
                'character' => 'Clarion',
                // Titled already, so first-exchange title generation stays
                // out of the way of what is under test.
                'title' => 'Already titled',
            ]);
        }

        $this->fakeProvider();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        DB::table('budget_threshold_notifications')->delete();
        DB::table('cost_summaries')->delete();
        DB::table('spending_ceilings')->delete();

        Mockery::close();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * A provider that answers instantly, so a request that is *not* refused
     * completes for a reason of its own rather than failing on the network
     * and being mistaken for enforcement.
     */
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

    /**
     * End one simulated request and begin another. The gate's per-request
     * memory of an already-admitted scope is what makes this necessary.
     */
    private function newRequestBoundary(): void
    {
        $this->app->forgetScopedInstances();
    }

    private function ceilingsBase(): string
    {
        return '/api/clarion-app/llm-client/budget/ceilings';
    }

    private function userCeilingEndpoint(string $label): string
    {
        return $this->ceilingsBase().'/users/'.$this->users[$label]->id;
    }

    /**
     * The installation-wide ceiling and the per-user default are declared
     * through the service rather than the HTTP surface: they are US1's
     * ground, already covered by their own journey, and what this file is
     * about is the per-user layer laid on top of them.
     */
    private function declareCeiling(
        BudgetScope $scope,
        ?string $amount,
        string $mode = 'stop',
        string $periodType = 'month',
    ): SpendingCeiling {
        return app(SpendingCeilingService::class)->upsert(
            $scope,
            SpendingCeiling::INSTALLATION_SCOPE_ID,
            [
                'amount' => $amount,
                'period_type' => $periodType,
                'enforcement_mode' => $mode,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function putUserCeiling(string $label, array $body, ?User $as = null)
    {
        $this->newRequestBoundary();

        return $this->actingAs($as ?? $this->operator, 'api')
            ->putJson($this->userCeilingEndpoint($label), $body);
    }

    private function deleteUserCeiling(string $label, ?User $as = null)
    {
        $this->newRequestBoundary();

        return $this->actingAs($as ?? $this->operator, 'api')
            ->deleteJson($this->userCeilingEndpoint($label));
    }

    private function recordSpend(string $label, string $amount, string $date = '2026-08-14'): void
    {
        DB::table('cost_summaries')->insert([
            'id' => (string) Str::uuid(),
            'entity_type' => CostSummary::ENTITY_USER,
            'entity_id' => $this->users[$label]->id,
            'user_id' => $this->users[$label]->id,
            'period_date' => $date,
            'request_count' => 1,
            'priced_cost_total' => $amount,
            'zero_priced_request_count' => 0,
            'unpriced_request_count' => 0,
            'unpriced_total_tokens' => 0,
            'estimated_request_count' => 0,
            'updated_at' => Carbon::now(),
        ]);
    }

    /**
     * @param  list<string>  $labels
     */
    private function recordSpendForEach(array $labels, string $amount): void
    {
        foreach ($labels as $label) {
            $this->recordSpend($label, $amount);
        }
    }

    /**
     * The consumption recorded against one user in the current period, as a
     * plain-decimal string. Read rather than assumed, because an admitted
     * request records usage of its own — unpriced here, and so worth zero,
     * but it still touches the row.
     */
    private function recordedSpend(string $label): string
    {
        $total = DB::table('cost_summaries')
            ->where('entity_type', CostSummary::ENTITY_USER)
            ->where('entity_id', $this->users[$label]->id)
            ->sum('priced_cost_total');

        return Decimal::toPlainNotation((string) $total);
    }

    private function requestAgentWork(string $label)
    {
        $this->newRequestBoundary();

        return $this->actingAs($this->users[$label], 'api')->postJson('/api/clarion-app/llm-client/agent', [
            'message' => 'Please do some work.',
            'conversation_id' => $this->conversations[$label]->id,
        ]);
    }

    /**
     * Drive a real request for every named user and assert the status each
     * one gets. Passing all four on every change is the isolation proof:
     * "nobody else was affected" is only meaningful if everybody else was
     * actually asked.
     *
     * @param  array<string, int>  $expected  label => HTTP status
     * @return array<string, array<string, mixed>|null>  label => refusal body, or null when admitted
     */
    private function assertOutcomes(array $expected, string $context): array
    {
        $bodies = [];

        foreach ($expected as $label => $status) {
            $response = $this->requestAgentWork($label);

            $this->assertSame(
                $status,
                $response->status(),
                "{$context}: user {$label} should have received {$status}, got {$response->status()}"
            );

            $bodies[$label] = $status === 402 ? $response->json() : null;
        }

        return $bodies;
    }

    private function assertRefusalNamesCeilingAmount(?array $body, string $amount, string $message): void
    {
        $this->assertNotNull($body, $message);
        $this->assertSame(0, bccomp((string) $body['ceiling']['amount'], $amount, 10), $message);
    }

    // ---------------------------------------------------------------
    // Scenario 1 — a raise reaches exactly one user
    // ---------------------------------------------------------------

    #[Test]
    public function raising_one_users_ceiling_lets_that_user_spend_more_and_leaves_every_other_user_unchanged(): void
    {
        $this->declareCeiling(BudgetScope::UserDefault, '25.00', 'stop', 'month');
        $this->recordSpendForEach(['A', 'B', 'C', 'D'], '30.0000000000');

        $put = $this->putUserCeiling('A', [
            'amount' => '100.00',
            'period_type' => 'month',
            'enforcement_mode' => 'stop',
        ]);

        $put->assertStatus(200);
        $this->assertSame('user', $put->json('scope_type'));
        $this->assertSame($this->users['A']->id, $put->json('scope_id'));
        $this->assertSame('100.0000000000', $put->json('amount'));
        $this->assertFalse($put->json('waived'));
        $this->assertIsString($put->json('amount'), 'amount must be a decimal string, never a JSON number');

        $bodies = $this->assertOutcomes(
            ['A' => 200, 'B' => 402, 'C' => 402, 'D' => 402],
            'After raising A alone',
        );

        $this->assertRefusalNamesCeilingAmount($bodies['B'], '25.00', 'B must still be measured against the default');
        $this->assertRefusalNamesCeilingAmount($bodies['C'], '25.00', 'C must still be measured against the default');
        $this->assertRefusalNamesCeilingAmount($bodies['D'], '25.00', 'D must still be measured against the default');

        // The default row itself was not rewritten on the way.
        $service = app(SpendingCeilingService::class);
        $this->assertSame('25.0000000000', $service->resolveForUser($this->users['D']->id)->amount);
    }

    // ---------------------------------------------------------------
    // Scenario 2 — a lower reaches exactly one user
    // ---------------------------------------------------------------

    #[Test]
    public function lowering_one_users_ceiling_stops_that_user_and_leaves_every_other_user_unchanged(): void
    {
        $this->declareCeiling(BudgetScope::UserDefault, '25.00', 'stop', 'month');
        $this->recordSpendForEach(['A', 'B', 'C', 'D'], '10.0000000000');

        // Everyone is below the default to begin with.
        $this->assertOutcomes(
            ['A' => 200, 'B' => 200, 'C' => 200, 'D' => 200],
            'Before any override',
        );

        $put = $this->putUserCeiling('B', [
            'amount' => '5.00',
            'period_type' => 'month',
            'enforcement_mode' => 'stop',
        ]);

        $put->assertStatus(200);
        $this->assertSame('5.0000000000', $put->json('amount'));

        $bodies = $this->assertOutcomes(
            ['A' => 200, 'B' => 402, 'C' => 200, 'D' => 200],
            'After lowering B alone',
        );

        $this->assertRefusalNamesCeilingAmount($bodies['B'], '5.00', "B's refusal must name B's own lowered ceiling");
        $this->assertSame('user', $bodies['B']['governing_scope']);
    }

    // ---------------------------------------------------------------
    // Scenario 3 — a waiver reaches exactly one user
    // ---------------------------------------------------------------

    #[Test]
    public function waiving_one_users_ceiling_exempts_only_that_user_from_the_default(): void
    {
        $this->declareCeiling(BudgetScope::UserDefault, '25.00', 'stop', 'month');
        $this->recordSpendForEach(['A', 'B', 'C', 'D'], '30.0000000000');

        $put = $this->putUserCeiling('C', [
            'period_type' => 'month',
            'enforcement_mode' => 'stop',
            'waived' => true,
        ]);

        $put->assertStatus(200);
        $this->assertSame('user', $put->json('scope_type'));
        $this->assertSame($this->users['C']->id, $put->json('scope_id'));
        $this->assertTrue($put->json('waived'));
        $this->assertNull($put->json('amount'), 'A waived ceiling carries no amount at all, not an amount of zero');

        $bodies = $this->assertOutcomes(
            ['A' => 402, 'B' => 402, 'C' => 200, 'D' => 402],
            'After waiving C alone',
        );

        $this->assertRefusalNamesCeilingAmount($bodies['A'], '25.00', 'A remains on the default');
        $this->assertRefusalNamesCeilingAmount($bodies['D'], '25.00', 'D remains on the default');

        $service = app(SpendingCeilingService::class);
        $this->assertNull(
            $service->resolveForUser($this->users['C']->id),
            'A waiver is the absence of a user-scoped ceiling, not a fall-back to the default'
        );
        $this->assertNotNull($service->resolveForUser($this->users['D']->id));
    }

    #[Test]
    public function a_waiver_that_also_supplies_an_amount_is_a_422_and_creates_nothing(): void
    {
        $this->declareCeiling(BudgetScope::UserDefault, '25.00', 'stop', 'month');

        $before = DB::table('spending_ceilings')->orderBy('id')->get()->toArray();

        $this->putUserCeiling('C', [
            'amount' => '100.00',
            'period_type' => 'month',
            'enforcement_mode' => 'stop',
            'waived' => true,
        ])->assertStatus(422);

        $this->assertEquals(
            $before,
            DB::table('spending_ceilings')->orderBy('id')->get()->toArray(),
            'A rejected waiver must leave the table exactly as it was'
        );
    }

    // ---------------------------------------------------------------
    // Scenario 4 — FR-010: a waiver never waives the installation ceiling
    // ---------------------------------------------------------------

    /**
     * The load-bearing assertion of the whole story. A waiver removes one
     * user's own limit; the installation-wide limit is a different ceiling
     * on a different axis, and no user-scoped row of any shape may reach it.
     */
    #[Test]
    public function a_waived_user_is_still_stopped_by_the_reached_installation_ceiling(): void
    {
        $installation = $this->declareCeiling(BudgetScope::Installation, '40.00', 'stop', 'month');
        $this->declareCeiling(BudgetScope::UserDefault, '25.00', 'stop', 'month');

        $this->putUserCeiling('C', [
            'period_type' => 'month',
            'enforcement_mode' => 'stop',
            'waived' => true,
        ])->assertStatus(200);

        // Only C has spent anything, and C alone has taken the installation
        // over its limit — so a failure here cannot be somebody else's spend
        // doing the stopping.
        $this->recordSpend('C', '50.0000000000');

        $bodies = $this->assertOutcomes(['C' => 402], 'A waived user under a reached installation ceiling');

        $this->assertSame(
            'installation',
            $bodies['C']['governing_scope'],
            'The installation ceiling is what stopped this work, and the refusal must say so'
        );
        $this->assertSame(BudgetScope::Installation->value, $bodies['C']['ceiling']['scope_type']);
        $this->assertRefusalNamesCeilingAmount($bodies['C'], '40.00', 'The installation amount is the one that governs');

        // And the waiver left the installation row entirely alone.
        $reread = app(SpendingCeilingService::class)->resolveInstallation();
        $this->assertNotNull($reread, 'A user-scoped waiver can never remove the installation-wide ceiling');
        $this->assertSame($installation->id, $reread->id);
        $this->assertSame('40.0000000000', $reread->amount);
        $this->assertFalse((bool) $reread->waived);
    }

    // ---------------------------------------------------------------
    // Scenarios 1–4 together — quickstart step 11
    // ---------------------------------------------------------------

    /**
     * The Independent Test as written: one default, four users, three
     * different changes, and each of the four measured against their own
     * value in a single pass.
     */
    #[Test]
    public function a_raise_a_lower_and_a_waiver_coexist_with_a_fourth_user_on_the_unchanged_default(): void
    {
        $this->declareCeiling(BudgetScope::UserDefault, '25.00', 'stop', 'month');

        $this->putUserCeiling('A', [
            'amount' => '100.00',
            'period_type' => 'month',
            'enforcement_mode' => 'stop',
        ])->assertStatus(200);

        $this->putUserCeiling('B', [
            'amount' => '5.00',
            'period_type' => 'month',
            'enforcement_mode' => 'stop',
        ])->assertStatus(200);

        $this->putUserCeiling('C', [
            'period_type' => 'month',
            'enforcement_mode' => 'stop',
            'waived' => true,
        ])->assertStatus(200);

        // Identical consumption for all four: any difference in outcome from
        // here can only be a difference in the ceiling each is measured
        // against.
        $this->recordSpendForEach(['A', 'B', 'C', 'D'], '30.0000000000');

        $bodies = $this->assertOutcomes(
            ['A' => 200, 'B' => 402, 'C' => 200, 'D' => 402],
            'Raise, lower, waive, untouched',
        );

        $this->assertRefusalNamesCeilingAmount($bodies['B'], '5.00', "B is measured against B's lowered ceiling");
        $this->assertRefusalNamesCeilingAmount($bodies['D'], '25.00', 'D is measured against the untouched default');

        // Three overrides plus the default, and nothing else.
        $list = $this->actingAs($this->operator, 'api')->getJson($this->ceilingsBase());
        $list->assertStatus(200);

        $rows = collect($list->json('data'));
        $this->assertCount(4, $rows, 'One default and three overrides — no row written for the untouched user');
        $this->assertCount(3, $rows->where('scope_type', 'user'));
        $this->assertNull(
            $rows->firstWhere('scope_id', $this->users['D']->id),
            'A user nobody touched must have no row of their own'
        );
    }

    // ---------------------------------------------------------------
    // Scenario 5 — removing an override reverts one user, and only one
    // ---------------------------------------------------------------

    #[Test]
    public function removing_an_override_reverts_that_user_to_the_default_with_no_other_user_affected(): void
    {
        $this->declareCeiling(BudgetScope::UserDefault, '25.00', 'stop', 'month');

        $created = $this->putUserCeiling('A', [
            'amount' => '100.00',
            'period_type' => 'month',
            'enforcement_mode' => 'stop',
        ]);
        $created->assertStatus(200);

        $this->putUserCeiling('B', [
            'amount' => '5.00',
            'period_type' => 'month',
            'enforcement_mode' => 'stop',
        ])->assertStatus(200);

        $this->recordSpendForEach(['A', 'B', 'C', 'D'], '30.0000000000');

        $this->assertOutcomes(['A' => 200, 'B' => 402, 'C' => 402, 'D' => 402], 'With A raised');

        $this->deleteUserCeiling('A')->assertStatus(204);

        $bodies = $this->assertOutcomes(
            ['A' => 402, 'B' => 402, 'C' => 402, 'D' => 402],
            "After removing A's override",
        );

        $this->assertRefusalNamesCeilingAmount($bodies['A'], '25.00', 'A is back on the default');
        $this->assertRefusalNamesCeilingAmount($bodies['B'], '5.00', "B's own lowered ceiling is untouched");

        // The reverted user resolves to the default row itself, rather than
        // to a rewritten copy of it.
        $service = app(SpendingCeilingService::class);
        $resolvedForA = $service->resolveForUser($this->users['A']->id);
        $this->assertNotNull($resolvedForA);
        $this->assertSame('user_default', $resolvedForA->scope_type);
        $this->assertSame('25.0000000000', $resolvedForA->amount);

        // Removal is a soft delete: the override survives as history.
        $trashed = SpendingCeiling::withTrashed()->find($created->json('id'));
        $this->assertNotNull($trashed);
        $this->assertNotNull($trashed->deleted_at);

        $list = $this->actingAs($this->operator, 'api')->getJson($this->ceilingsBase());
        $this->assertNull(
            collect($list->json('data'))->firstWhere('scope_id', $this->users['A']->id),
            'A removed override must not appear in the live list'
        );
    }

    // ---------------------------------------------------------------
    // Scenario 6 — a change takes effect on the very next request
    // ---------------------------------------------------------------

    #[Test]
    public function raising_a_blocked_users_ceiling_takes_effect_on_their_next_request_with_no_period_reset(): void
    {
        $this->declareCeiling(BudgetScope::UserDefault, '25.00', 'stop', 'month');
        $this->recordSpendForEach(['A', 'B'], '30.0000000000');

        $this->assertOutcomes(['A' => 402, 'B' => 402], 'Both blocked by the default');

        $this->putUserCeiling('A', [
            'amount' => '100.00',
            'period_type' => 'month',
            'enforcement_mode' => 'stop',
        ])->assertStatus(200);

        // Same clock, same period, same recorded consumption: the block lifts
        // because the ceiling moved, not because anything was reset.
        $this->assertSame('2026-08-14', Carbon::now()->toDateString());

        $this->assertOutcomes(['A' => 200, 'B' => 402], "Immediately after raising A's ceiling");

        foreach (['A', 'B'] as $label) {
            $this->assertSame(
                0,
                bccomp($this->recordedSpend($label), '30.00', 10),
                "No period was reset and no recorded spend was cleared for user {$label}"
            );
        }
    }

    #[Test]
    public function waiving_a_blocked_users_ceiling_takes_effect_on_their_next_request(): void
    {
        $this->declareCeiling(BudgetScope::UserDefault, '25.00', 'stop', 'month');
        $this->recordSpendForEach(['A', 'B'], '30.0000000000');

        $this->assertOutcomes(['A' => 402, 'B' => 402], 'Both blocked by the default');

        $this->putUserCeiling('A', [
            'period_type' => 'month',
            'enforcement_mode' => 'stop',
            'waived' => true,
        ])->assertStatus(200);

        $this->assertSame('2026-08-14', Carbon::now()->toDateString());
        $this->assertOutcomes(['A' => 200, 'B' => 402], 'Immediately after waiving A');
    }

    // ---------------------------------------------------------------
    // Scenario 7 — only an operator may raise, lower, or waive
    // ---------------------------------------------------------------

    #[Test]
    public function a_non_operator_cannot_raise_lower_or_waive_any_ceiling_including_their_own(): void
    {
        $this->declareCeiling(BudgetScope::UserDefault, '25.00', 'stop', 'month');

        $before = DB::table('spending_ceilings')->orderBy('id')->get()->toArray();

        $attempts = [
            'raising another user' => ['B', ['amount' => '100.00', 'period_type' => 'month', 'enforcement_mode' => 'stop']],
            'raising their own' => ['A', ['amount' => '100.00', 'period_type' => 'month', 'enforcement_mode' => 'stop']],
            'lowering another user' => ['B', ['amount' => '1.00', 'period_type' => 'month', 'enforcement_mode' => 'stop']],
            'waiving their own' => ['A', ['period_type' => 'month', 'enforcement_mode' => 'stop', 'waived' => true]],
        ];

        foreach ($attempts as $description => [$target, $body]) {
            $this->putUserCeiling($target, $body, $this->users['A'])
                ->assertStatus(403, "A non-operator {$description} must be refused");
        }

        $this->deleteUserCeiling('A', $this->users['A'])
            ->assertStatus(403, 'A non-operator must not be able to remove their own override either');
        $this->deleteUserCeiling('B', $this->users['A'])
            ->assertStatus(403, "A non-operator must not be able to remove another user's override");

        $this->assertEquals(
            $before,
            DB::table('spending_ceilings')->orderBy('id')->get()->toArray(),
            'A refused attempt must create and change nothing'
        );
    }

    #[Test]
    public function a_refused_non_operator_attempt_leaves_enforcement_exactly_where_it_was(): void
    {
        $this->declareCeiling(BudgetScope::UserDefault, '25.00', 'stop', 'month');
        $this->recordSpendForEach(['A', 'B', 'C', 'D'], '30.0000000000');

        $this->putUserCeiling('A', [
            'amount' => '100000.00',
            'period_type' => 'month',
            'enforcement_mode' => 'stop',
        ], $this->users['A'])->assertStatus(403);

        $this->assertOutcomes(
            ['A' => 402, 'B' => 402, 'C' => 402, 'D' => 402],
            'A refused self-raise must not have raised anything',
        );
    }
}
