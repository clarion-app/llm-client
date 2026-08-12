<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\CostSummary;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\SpendingCeiling;
use ClarionApp\LlmClient\Services\SpendingCeilingService;
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
 * User Story 2 through the real HTTP boundary: a ceiling in stopping mode
 * refuses new work with a 402 that says what the limit is, how much has
 * been spent, and when the period resets.
 *
 * The status code is the first thing under test and it is not cosmetic.
 * 402 is distinguishable from ordinary permission denial (403) and from
 * ordinary failure (500) — and it is not 429, which would imply the rate
 * limiting this feature explicitly does not do. Two of this file's
 * assertions exist solely because the synchronous path passes through
 * AgentController's blanket catch, which converts anything it sees into a
 * generic `500 internal_error`: precisely the unexplained failure the whole
 * story exists to avoid.
 *
 * The second thing under test is *which* ceiling gets named. When both an
 * installation and a user ceiling apply, naming the wrong one is a message
 * that reads plausibly and sends the reader to change the wrong setting.
 *
 * Note on request boundaries: Laravel's test harness keeps one container
 * across every simulated request in a test method, while a deployment
 * builds one per request. Anything the gate remembers for the life of a
 * request would therefore leak from one simulated request into the next, so
 * a boundary is drawn explicitly between them.
 */
class CeilingStopsWorkJourneyTest extends TestCase
{
    private User $operator;
    private User $user;
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

        $this->operator = User::factory()->create();
        $this->user = User::factory()->create();

        config(['llm-client.cost.operator_user_ids' => [$this->operator->id]]);

        $this->server = Server::create([
            'name' => 'Test Server',
            'server_url' => 'http://localhost:11434',
            'provider_type' => 'llama_cpp',
        ]);

        $this->conversation = $this->conversationFor($this->user);

        $this->seedZeroRatePrice();
        $this->fakeProvider();
    }

    /**
     * A priced (zero-rate) row for this file's test-model.
     *
     * 084 added an admission-time cost estimate: admit() now reads
     * ModelPrice for the conversation's (provider_type, model) before
     * placing a reservation, and treats a genuinely unpriced model under a
     * stop-mode ceiling as refused by default (research.md D8) — a policy
     * this file's tests are not about. A zero-rate price keeps every request
     * here priced (so that policy never engages) while adding nothing
     * measurable to what is held, leaving this file's own ceiling-crossing
     * arithmetic exactly as it was before 084.
     *
     * provider_type is 'openai', not this file's own 'llama_cpp' server
     * value: Server::getProviderTypeAttribute() maps any string ProviderType
     * does not recognize — 'llama_cpp' is not 'llama.cpp' — back to
     * ProviderType::OpenAI, and that resolved value, not the raw column, is
     * what Conversation::getEffectiveProviderTypeAttribute() and therefore
     * CostEstimator actually look up.
     */
    private function seedZeroRatePrice(): void
    {
        \ClarionApp\LlmClient\Models\ModelPrice::create([
            'provider_type' => 'openai',
            'model' => 'test-model',
            'reused_input_rate' => '0.00000000',
            'fresh_input_rate' => '0.00000000',
            'output_rate' => '0.00000000',
            'effective_from' => Carbon::now()->subDay(),
            'effective_until' => null,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        DB::table('cost_summaries')->delete();
        DB::table('spending_ceilings')->delete();
        DB::table('model_prices')->delete();

        Mockery::close();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function conversationFor(User $owner): Conversation
    {
        return Conversation::create([
            'user_id' => $owner->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'character' => 'Clarion',
            // Titled already, so the loop's first-exchange title generation
            // stays out of the way of what is under test here.
            'title' => 'Already titled',
        ]);
    }

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
     * End one simulated request and begin another.
     *
     * The gate remembers, for the life of one request or job, that it has
     * already admitted a scope — that memory is what stops a nested call
     * throwing mid-turn. A deployment discards it at the request boundary;
     * the test harness does not, so the boundary is drawn here.
     */
    private function newRequestBoundary(): void
    {
        $this->app->forgetScopedInstances();
    }

    private function declareCeiling(
        BudgetScope $scope,
        ?string $amount,
        string $mode = 'stop',
        string $periodType = 'month',
        ?string $scopeId = null,
    ): SpendingCeiling {
        return app(SpendingCeilingService::class)->upsert(
            $scope,
            $scopeId ?? SpendingCeiling::INSTALLATION_SCOPE_ID,
            [
                'amount' => $amount,
                'period_type' => $periodType,
                'enforcement_mode' => $mode,
            ],
        );
    }

    private function recordSpend(string $userId, string $amount, string $date = '2026-08-14'): void
    {
        DB::table('cost_summaries')->insert([
            'id' => (string) Str::uuid(),
            'entity_type' => CostSummary::ENTITY_USER,
            'entity_id' => $userId,
            'user_id' => $userId,
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

    private function requestAgentWork(User $as, ?Conversation $conversation = null)
    {
        return $this->actingAs($as, 'api')->postJson('/api/clarion-app/llm-client/agent', [
            'message' => 'Please do some work.',
            'conversation_id' => ($conversation ?? $this->conversation)->id,
        ]);
    }

    // ---------------------------------------------------------------
    // Scenario 1 — refused, and told why
    // ---------------------------------------------------------------

    #[Test]
    public function a_reached_stop_mode_ceiling_refuses_new_work_with_a_402_naming_the_limit(): void
    {
        $this->declareCeiling(BudgetScope::UserDefault, '25.00', 'stop', 'month');
        $this->recordSpend($this->user->id, '25.4200000000');

        $response = $this->requestAgentWork($this->user);

        $response->assertStatus(402);
        $response->assertJsonStructure([
            'code',
            'message',
            'ceiling' => ['scope_type', 'amount', 'period_type', 'enforcement_mode'],
            'period' => ['type', 'from', 'to', 'resets_at'],
            'consumption' => ['currency', 'amount', 'approximate', 'approximation_note', 'available'],
            'governing_scope',
            'work_kind',
            'degraded',
        ]);

        $body = $response->json();

        $this->assertSame('budget_ceiling_reached', $body['code']);
        $this->assertSame('user', $body['governing_scope']);
        $this->assertSame('interactive', $body['work_kind']);
        $this->assertFalse($body['degraded']);

        // Monetary values stay decimal strings all the way to the wire; a
        // JSON number is a float on the far side of every parser.
        $this->assertIsString($body['ceiling']['amount']);
        $this->assertIsString($body['consumption']['amount']);
        $this->assertSame(0, bccomp($body['ceiling']['amount'], '25.00', 10));
        $this->assertSame(0, bccomp($body['consumption']['amount'], '25.42', 10));

        // The reset instant is the exclusive upper bound of the period: the
        // day after 'to', at midnight UTC. August's month period ends on the
        // 31st, so the reset is 2026-09-01T00:00:00Z — never 23:59:59.
        $this->assertStringContainsString('2026-09-01', $body['period']['resets_at']);

        // The three facts, in the message itself, in plain language.
        $message = $body['message'];
        $this->assertMatchesRegularExpression('/25\.42/', $message, 'The message must state the consumption to date');
        $this->assertMatchesRegularExpression('/25\.00/', $message, 'The message must state the ceiling amount');
        $this->assertStringContainsString('2026-09-01', $message, 'The message must state when the period resets');
    }

    /**
     * Row 22's target, stated as its own case so a failure reads as "the
     * status is wrong" rather than as one clause of a structure assertion.
     */
    #[Test]
    public function the_refusal_is_neither_a_permission_denial_nor_a_generic_failure(): void
    {
        $this->declareCeiling(BudgetScope::UserDefault, '25.00', 'stop', 'month');
        $this->recordSpend($this->user->id, '30.0000000000');

        $response = $this->requestAgentWork($this->user);

        $this->assertSame(402, $response->status(), 'A ceiling refusal is 402 — not 403, not 500, and not 429');
        $this->assertNotSame('internal_error', $response->json('code'));
    }

    /**
     * A refused request must leave the conversation usable. The gate runs
     * before is_processing is set, so there is nothing to unwind; if it ran
     * after, the conversation would be wedged with no path that clears it.
     */
    #[Test]
    public function a_refused_request_leaves_the_conversation_not_processing(): void
    {
        $this->declareCeiling(BudgetScope::UserDefault, '25.00', 'stop', 'month');
        $this->recordSpend($this->user->id, '30.0000000000');

        $this->requestAgentWork($this->user)->assertStatus(402);

        $this->assertFalse((bool) $this->conversation->fresh()->is_processing);
    }

    // ---------------------------------------------------------------
    // Scenario 2 — the figure is approximate, and says so
    // ---------------------------------------------------------------

    #[Test]
    public function the_refusal_body_carries_the_approximation_caveat_as_a_field(): void
    {
        $this->declareCeiling(BudgetScope::UserDefault, '25.00', 'stop', 'month');
        $this->recordSpend($this->user->id, '30.0000000000');

        $body = $this->requestAgentWork($this->user)->assertStatus(402)->json();

        // Fields, not prose: an interface renders these rather than
        // reconstructing them from the sentence.
        $this->assertTrue($body['consumption']['approximate']);
        $this->assertNotEmpty($body['consumption']['approximation_note']);
        $this->assertStringContainsString('approximate', strtolower($body['consumption']['approximation_note']));

        // ...and the sentence a human reads says it too.
        $this->assertStringContainsString('approximate', strtolower($body['message']));
    }

    // ---------------------------------------------------------------
    // Scenario 4 — the message names the ceiling that actually stopped it
    // ---------------------------------------------------------------

    #[Test]
    public function when_the_installation_ceiling_governs_the_refusal_names_that_one_and_not_the_users(): void
    {
        // The user's own ceiling has room to spare; the installation-wide
        // one does not. Telling this user their personal limit stopped them
        // would send them, and their operator, to change the wrong setting.
        $this->declareCeiling(BudgetScope::Installation, '40.00', 'stop', 'month');
        $this->declareCeiling(BudgetScope::UserDefault, '500.00', 'stop', 'month');
        $this->recordSpend($this->user->id, '50.0000000000');

        $body = $this->requestAgentWork($this->user)->assertStatus(402)->json();

        $this->assertSame('installation', $body['governing_scope']);
        $this->assertSame(BudgetScope::Installation->value, $body['ceiling']['scope_type']);
        $this->assertSame(0, bccomp($body['ceiling']['amount'], '40.00', 10));

        $this->assertMatchesRegularExpression('/40\.00/', $body['message']);
        $this->assertStringNotContainsString(
            '500',
            $body['message'],
            "The user's own, untouched ceiling must not appear in a refusal the installation ceiling caused"
        );
    }

    #[Test]
    public function when_the_users_own_ceiling_governs_the_refusal_names_that_one(): void
    {
        $this->declareCeiling(BudgetScope::Installation, '5000.00', 'stop', 'month');
        $this->declareCeiling(BudgetScope::UserDefault, '25.00', 'stop', 'month');
        $this->recordSpend($this->user->id, '30.0000000000');

        $body = $this->requestAgentWork($this->user)->assertStatus(402)->json();

        $this->assertSame('user', $body['governing_scope']);
        $this->assertSame(0, bccomp($body['ceiling']['amount'], '25.00', 10));
    }

    /**
     * The two cases above each have exactly one ceiling that could stop the
     * work, so any tie-break rule at all would name the right one. These two
     * put **both** ceilings past their limits at once, which is the only
     * situation in which the choice is a choice: the refusal must name the
     * one with the least headroom left, and the same consumption figure has
     * to produce opposite answers depending only on the amounts configured.
     *
     * Without a pair like this, "picks the largest headroom" and "picks
     * whichever matched first" are both indistinguishable from the rule
     * FR-022 actually requires.
     */
    #[Test]
    public function with_both_ceilings_reached_the_refusal_names_the_one_with_the_least_headroom(): void
    {
        // 50 spent. Installation is 10 over; the user is 30 over, so the
        // user's is the tighter of the two.
        $this->declareCeiling(BudgetScope::Installation, '40.00', 'stop', 'month');
        $this->declareCeiling(BudgetScope::UserDefault, '20.00', 'stop', 'month');
        $this->recordSpend($this->user->id, '50.0000000000');

        $body = $this->requestAgentWork($this->user)->assertStatus(402)->json();

        $this->assertSame('user', $body['governing_scope'], 'The user ceiling has the least headroom of the two');
        $this->assertSame(0, bccomp($body['ceiling']['amount'], '20.00', 10));
    }

    #[Test]
    public function with_both_ceilings_reached_the_same_consumption_names_the_installation_when_it_is_tighter(): void
    {
        // The mirror image: same 50 spent, amounts swapped, so the
        // installation is now 40 over against the user's 5.
        $this->declareCeiling(BudgetScope::Installation, '10.00', 'stop', 'month');
        $this->declareCeiling(BudgetScope::UserDefault, '45.00', 'stop', 'month');
        $this->recordSpend($this->user->id, '50.0000000000');

        $body = $this->requestAgentWork($this->user)->assertStatus(402)->json();

        $this->assertSame('installation', $body['governing_scope']);
        $this->assertSame(0, bccomp($body['ceiling']['amount'], '10.00', 10));
    }

    // ---------------------------------------------------------------
    // Scenario 5 — the period resets, with nobody doing anything
    // ---------------------------------------------------------------

    #[Test]
    public function after_the_period_resets_the_same_users_next_request_proceeds_with_zero_operator_actions(): void
    {
        $this->declareCeiling(BudgetScope::UserDefault, '25.00', 'stop', 'day');
        $this->recordSpend($this->user->id, '30.0000000000', '2026-08-14');

        $this->requestAgentWork($this->user)->assertStatus(402);

        $ceilingsBefore = DB::table('spending_ceilings')->get()->toArray();

        // Midnight UTC passes. Nothing else happens: no operator action, no
        // reset job, no restart.
        Carbon::setTestNow(Carbon::parse('2026-08-15 09:00:00', 'UTC'));
        $this->newRequestBoundary();

        $this->requestAgentWork($this->user)->assertStatus(200);

        $this->assertEquals(
            $ceilingsBefore,
            DB::table('spending_ceilings')->get()->toArray(),
            'The block must lift because the period turned over, not because anything was reconfigured'
        );
    }

    // ---------------------------------------------------------------
    // Scenario 6 — an operator raises the ceiling and the block lifts
    // ---------------------------------------------------------------

    #[Test]
    public function raising_the_applicable_ceiling_lets_the_next_request_through_with_no_period_reset(): void
    {
        $this->declareCeiling(BudgetScope::UserDefault, '25.00', 'stop', 'month');
        $this->recordSpend($this->user->id, '30.0000000000');

        $this->requestAgentWork($this->user)->assertStatus(402);

        $this->newRequestBoundary();
        $this->actingAs($this->operator, 'api')
            ->putJson('/api/clarion-app/llm-client/budget/ceilings/user-default', [
                'amount' => '100.00',
                'period_type' => 'month',
                'enforcement_mode' => 'stop',
            ])
            ->assertStatus(200);

        $this->newRequestBoundary();

        // Same clock, same period, same recorded consumption.
        $this->assertSame('2026-08-14', Carbon::now()->toDateString());
        $this->requestAgentWork($this->user)->assertStatus(200);
    }

    #[Test]
    public function removing_the_applicable_ceiling_lets_the_next_request_through(): void
    {
        $this->declareCeiling(BudgetScope::UserDefault, '25.00', 'stop', 'month');
        $this->recordSpend($this->user->id, '30.0000000000');

        $this->requestAgentWork($this->user)->assertStatus(402);

        $this->newRequestBoundary();
        $this->actingAs($this->operator, 'api')
            ->deleteJson('/api/clarion-app/llm-client/budget/ceilings/user-default')
            ->assertStatus(204);

        $this->newRequestBoundary();
        $this->requestAgentWork($this->user)->assertStatus(200);
    }

    // ---------------------------------------------------------------
    // Operators are not exempt — and keep the route back to capability
    // ---------------------------------------------------------------

    #[Test]
    public function an_operators_own_request_is_refused_exactly_like_anyone_elses(): void
    {
        $operatorConversation = $this->conversationFor($this->operator);

        $this->declareCeiling(BudgetScope::Installation, '10.00', 'stop', 'month');
        $this->recordSpend($this->operator->id, '50.0000000000');

        $this->requestAgentWork($this->operator, $operatorConversation)
            ->assertStatus(402)
            ->assertJson(['governing_scope' => 'installation']);
    }

    #[Test]
    public function a_blocked_operator_can_still_raise_the_ceiling_that_blocked_them(): void
    {
        $operatorConversation = $this->conversationFor($this->operator);

        $this->declareCeiling(BudgetScope::Installation, '10.00', 'stop', 'month');
        $this->recordSpend($this->operator->id, '50.0000000000');

        $this->requestAgentWork($this->operator, $operatorConversation)->assertStatus(402);

        // The configuration endpoints are never themselves gated. Without
        // that, a reached installation ceiling would be unrecoverable.
        $this->newRequestBoundary();
        $this->actingAs($this->operator, 'api')
            ->putJson('/api/clarion-app/llm-client/budget/ceilings/installation', [
                'amount' => '1000.00',
                'period_type' => 'month',
                'enforcement_mode' => 'stop',
            ])
            ->assertStatus(200);

        $this->newRequestBoundary();
        $this->requestAgentWork($this->operator, $operatorConversation)->assertStatus(200);
    }

    // ---------------------------------------------------------------
    // Scenario 7 — nothing configured, nothing changes
    // ---------------------------------------------------------------

    #[Test]
    public function with_no_ceiling_configured_the_request_behaves_exactly_as_before(): void
    {
        $response = $this->requestAgentWork($this->user);

        $response->assertStatus(200);
        $response->assertJsonStructure(['conversation_id', 'message_id', 'content', 'status']);
        $this->assertSame('completed', $response->json('status'));

        // No added fields, no added envelope.
        $this->assertSame(
            ['content', 'conversation_id', 'message_id', 'status'],
            collect(array_keys($response->json()))->sort()->values()->all()
        );

        $this->assertSame(2, Message::where('conversation_id', $this->conversation->id)->count());
    }
}
