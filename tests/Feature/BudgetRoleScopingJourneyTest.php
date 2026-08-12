<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Events\SpendingCeilingReached;
use ClarionApp\LlmClient\Events\SpendingThresholdWarned;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\CostSummary;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\SpendingCeiling;
use ClarionApp\LlmClient\Services\SpendingCeilingService;
use ClarionApp\LlmClient\ValueObjects\BudgetScope;
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
 * Who may *configure* a ceiling, and whose figures a caller may *see* —
 * across every surface this feature adds, in one place.
 *
 * The distinction this file exists to hold is that operator status governs
 * configuration and disclosure, and never measurement. An operator may set
 * every ceiling and read every user's standing; an operator's own work is
 * refused by a reached ceiling exactly like anyone else's. A design that
 * quietly exempted operators from enforcement would pass every other budget
 * test in the suite, because every other file acts as an ordinary user.
 *
 * The disclosure rule has two halves and both are asserted here, because
 * only one of them is obvious:
 *
 *  - A non-operator's standing **does** carry the installation ceiling, the
 *    installation consumption, and the remaining headroom. That is an
 *    installation-wide aggregate, not any individual's figures, and it is
 *    the ceiling that will stop them — withholding it would leave them to be
 *    surprised by a limit they had no way to see, and it is in any case
 *    exactly what the 402 body already hands them when that ceiling bites.
 *  - No **other individual user's** id, ceiling amount, or consumption may
 *    appear anywhere in a non-operator's response body. That is asserted
 *    against the whole serialized body rather than against the fields this
 *    file happens to name, so a leak through a field nobody thought of is
 *    still caught.
 *
 * Every distinguishing figure in the fixtures is a distinct number, so
 * "userB's amount is absent" is a real assertion rather than one that would
 * hold by coincidence with userA's.
 *
 * Note on request boundaries: Laravel's test harness keeps one container
 * across every simulated request in a test method, while a deployment builds
 * one per request. The gate remembers an admitted scope and the ledger
 * memoizes a figure for the life of a request, so the boundary between
 * simulated requests is drawn explicitly.
 */
class BudgetRoleScopingJourneyTest extends TestCase
{
    /** userB's override amount — a number that appears nowhere else. */
    private const OTHER_USER_CEILING = '77.00';

    /** userB's recorded consumption — likewise unique in these fixtures. */
    private const OTHER_USER_SPEND = '43.0000000000';

    /** userA's recorded consumption. */
    private const OWN_SPEND = '7.0000000000';

    private User $operator;
    private User $userA;
    private User $userB;
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
        $this->userA = User::factory()->create();
        $this->userB = User::factory()->create();

        config(['llm-client.cost.operator_user_ids' => [$this->operator->id]]);

        $this->server = Server::create([
            'name' => 'Test Server',
            'server_url' => 'http://localhost:11434',
            'provider_type' => 'llama_cpp',
        ]);

        $this->conversation = $this->conversationFor($this->userA);

        $this->seedZeroRatePrice();
        $this->fakeProvider();
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
     * A priced (zero-rate) row for this file's test-model. 084 added an
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
            'effective_from' => Carbon::now()->subDay(),
            'effective_until' => null,
        ]);
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    private function conversationFor(User $owner): Conversation
    {
        return Conversation::create([
            'user_id' => $owner->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'character' => 'Clarion',
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
        ?string $threshold = null,
    ): SpendingCeiling {
        return app(SpendingCeilingService::class)->upsert(
            $scope,
            $scopeId ?? SpendingCeiling::INSTALLATION_SCOPE_ID,
            array_filter([
                'amount' => $amount,
                'period_type' => $periodType,
                'enforcement_mode' => $mode,
                'approach_threshold' => $threshold,
            ], fn ($v) => $v !== null),
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

    private function ceilingsEndpoint(): string
    {
        return '/api/clarion-app/llm-client/budget/ceilings';
    }

    private function standingEndpoint(): string
    {
        return '/api/clarion-app/llm-client/budget/standing';
    }

    /**
     * Every row in spending_ceilings, soft-deleted ones included, as a
     * comparable snapshot.
     *
     * Soft-deleted rows are part of the snapshot on purpose: a DELETE that
     * a non-operator managed to slip through would stamp deleted_at without
     * changing the row count, and a count-only assertion would miss it.
     *
     * @return array<int, object>
     */
    private function ceilingTableSnapshot(): array
    {
        return DB::table('spending_ceilings')->orderBy('id')->get()->all();
    }

    /**
     * Nothing anywhere in this body belongs to any user other than the
     * caller.
     *
     * Asserted against the raw serialized response rather than against named
     * fields: a leak that arrives through a key this file never thought to
     * check is exactly the leak worth catching.
     */
    private function assertNoOtherUsersFigures(string $body, string $context): void
    {
        $this->assertStringNotContainsString(
            $this->userB->id,
            $body,
            "{$context}: another user's identifier must not appear"
        );
        $this->assertStringNotContainsString(
            '77.0000000000',
            $body,
            "{$context}: another user's ceiling amount must not appear"
        );
        $this->assertStringNotContainsString(
            self::OTHER_USER_SPEND,
            $body,
            "{$context}: another user's consumption must not appear"
        );
    }

    // ---------------------------------------------------------------
    // Configuration is operator-only — all seven routes
    // ---------------------------------------------------------------

    /**
     * FR-007, quickstart step 3, mutation-checklist row 24.
     *
     * All seven routes, including a non-operator acting on *their own*
     * ceiling — the case an implementation is most likely to wave through,
     * on the reasoning that raising your own limit only affects you. It does
     * not: a self-service raise is the whole enforcement mechanism opting
     * itself out.
     */
    #[Test]
    public function a_non_operator_is_refused_on_every_one_of_the_seven_ceiling_configuration_routes(): void
    {
        // Rows an operator put in place first, so the DELETE cases have
        // something real to fail to remove.
        $this->declareCeiling(BudgetScope::Installation, '500.00');
        $this->declareCeiling(BudgetScope::UserDefault, '25.00');
        $this->declareCeiling(BudgetScope::User, self::OTHER_USER_CEILING, scopeId: $this->userB->id);

        $before = $this->ceilingTableSnapshot();
        $this->assertCount(3, $before, 'Three ceilings exist before the non-operator touches anything');

        $payload = [
            'amount' => '9999.00',
            'period_type' => 'month',
            'enforcement_mode' => 'warn',
        ];

        $attempts = [
            'GET /budget/ceilings' => fn () => $this->actingAs($this->userA, 'api')
                ->getJson($this->ceilingsEndpoint()),
            'PUT /budget/ceilings/installation' => fn () => $this->actingAs($this->userA, 'api')
                ->putJson($this->ceilingsEndpoint().'/installation', $payload),
            'PUT /budget/ceilings/user-default' => fn () => $this->actingAs($this->userA, 'api')
                ->putJson($this->ceilingsEndpoint().'/user-default', $payload),
            // Their own id. Still a 403.
            'PUT /budget/ceilings/users/{own id}' => fn () => $this->actingAs($this->userA, 'api')
                ->putJson($this->ceilingsEndpoint().'/users/'.$this->userA->id, $payload),
            'PUT /budget/ceilings/users/{another id}' => fn () => $this->actingAs($this->userA, 'api')
                ->putJson($this->ceilingsEndpoint().'/users/'.$this->userB->id, $payload),
            'DELETE /budget/ceilings/installation' => fn () => $this->actingAs($this->userA, 'api')
                ->deleteJson($this->ceilingsEndpoint().'/installation'),
            'DELETE /budget/ceilings/user-default' => fn () => $this->actingAs($this->userA, 'api')
                ->deleteJson($this->ceilingsEndpoint().'/user-default'),
            'DELETE /budget/ceilings/users/{another id}' => fn () => $this->actingAs($this->userA, 'api')
                ->deleteJson($this->ceilingsEndpoint().'/users/'.$this->userB->id),
        ];

        foreach ($attempts as $label => $attempt) {
            $this->newRequestBoundary();

            $response = $attempt();

            $this->assertSame(
                403,
                $response->status(),
                "{$label} must be forbidden to a non-operator, whoever the ceiling belongs to"
            );
        }

        $this->assertEquals(
            $before,
            $this->ceilingTableSnapshot(),
            'A refused configuration attempt creates, rewrites, and soft-deletes nothing'
        );

        $this->assertDatabaseMissing('spending_ceilings', [
            'scope_type' => BudgetScope::User->value,
            'scope_id' => $this->userA->id,
        ]);
    }

    /**
     * The same seven routes, from an operator, all reachable — so the case
     * above is "the check is on" rather than "the routes are broken".
     */
    #[Test]
    public function an_operator_reaches_every_one_of_the_same_seven_routes(): void
    {
        $payload = [
            'amount' => '30.00',
            'period_type' => 'month',
            'enforcement_mode' => 'stop',
        ];

        $expected = [
            'GET /budget/ceilings' => [200, fn () => $this->actingAs($this->operator, 'api')
                ->getJson($this->ceilingsEndpoint())],
            'PUT /budget/ceilings/installation' => [200, fn () => $this->actingAs($this->operator, 'api')
                ->putJson($this->ceilingsEndpoint().'/installation', $payload)],
            'PUT /budget/ceilings/user-default' => [200, fn () => $this->actingAs($this->operator, 'api')
                ->putJson($this->ceilingsEndpoint().'/user-default', $payload)],
            'PUT /budget/ceilings/users/{id}' => [200, fn () => $this->actingAs($this->operator, 'api')
                ->putJson($this->ceilingsEndpoint().'/users/'.$this->userB->id, $payload)],
            'DELETE /budget/ceilings/users/{id}' => [204, fn () => $this->actingAs($this->operator, 'api')
                ->deleteJson($this->ceilingsEndpoint().'/users/'.$this->userB->id)],
            'DELETE /budget/ceilings/user-default' => [204, fn () => $this->actingAs($this->operator, 'api')
                ->deleteJson($this->ceilingsEndpoint().'/user-default')],
            'DELETE /budget/ceilings/installation' => [204, fn () => $this->actingAs($this->operator, 'api')
                ->deleteJson($this->ceilingsEndpoint().'/installation')],
        ];

        foreach ($expected as $label => [$status, $attempt]) {
            $this->newRequestBoundary();

            $this->assertSame($status, $attempt()->status(), "{$label} is reachable by an operator");
        }
    }

    // ---------------------------------------------------------------
    // Standing: whose figures may be asked for
    // ---------------------------------------------------------------

    /**
     * FR-036/SC-015, quickstart step 18, mutation-checklist row 23.
     *
     * A 403 rather than a filtered 200: a shaped-but-empty body would let a
     * caller infer another user's existence, and eventually their spend,
     * from the difference between two answers.
     */
    #[Test]
    public function a_non_operator_is_refused_another_users_standing_and_the_installation_address(): void
    {
        $this->declareCeiling(BudgetScope::Installation, '500.00');
        $this->declareCeiling(BudgetScope::User, self::OTHER_USER_CEILING, scopeId: $this->userB->id);
        $this->recordSpend($this->userB->id, self::OTHER_USER_SPEND);

        $this->newRequestBoundary();
        $foreign = $this->actingAs($this->userA, 'api')
            ->getJson($this->standingEndpoint().'/users/'.$this->userB->id);

        $foreign->assertStatus(403);
        $this->assertNoOtherUsersFigures(
            $foreign->getContent(),
            "A refused read of another user's standing"
        );

        $this->newRequestBoundary();
        $this->actingAs($this->userA, 'api')
            ->getJson($this->standingEndpoint().'/installation')
            ->assertStatus(403);
    }

    #[Test]
    public function an_operator_reads_both_the_foreign_user_and_the_installation_addresses(): void
    {
        $this->declareCeiling(BudgetScope::Installation, '500.00');
        $this->declareCeiling(BudgetScope::User, self::OTHER_USER_CEILING, scopeId: $this->userB->id);
        $this->recordSpend($this->userB->id, self::OTHER_USER_SPEND);

        $this->newRequestBoundary();
        $foreign = $this->actingAs($this->operator, 'api')
            ->getJson($this->standingEndpoint().'/users/'.$this->userB->id);

        $foreign->assertStatus(200);
        $this->assertSame(
            $this->userB->id,
            $foreign->json('user_ceiling.ceiling.scope_id'),
            'An operator asking about a named user is answered about that user'
        );
        $this->assertSame(
            0,
            bccomp($foreign->json('user_ceiling.consumption.amount'), self::OTHER_USER_SPEND, 10)
        );

        $this->newRequestBoundary();
        $this->actingAs($this->operator, 'api')
            ->getJson($this->standingEndpoint().'/installation')
            ->assertStatus(200)
            ->assertJsonPath('installation_ceiling.applies', true);
    }

    /**
     * The first half of the disclosure ruling: a non-operator's own standing
     * carries the installation ceiling, its consumption, and the headroom
     * left against it.
     *
     * This is the half that looks like a leak and is not. The figure is an
     * installation-wide aggregate; it is also the limit that will stop this
     * caller, and the entire purpose of this surface is that nobody is
     * stopped by a ceiling they had no way to see. The 402 body already
     * hands them precisely these numbers when that ceiling bites, so a
     * standing report that withheld them would contradict the refusal built
     * from the same computation.
     */
    #[Test]
    public function a_non_operators_own_standing_carries_the_installation_ceiling_consumption_and_remaining(): void
    {
        $this->declareCeiling(BudgetScope::Installation, '500.00');
        $this->declareCeiling(BudgetScope::UserDefault, '25.00');

        $this->recordSpend($this->userA->id, self::OWN_SPEND);
        $this->recordSpend($this->userB->id, self::OTHER_USER_SPEND);

        $this->newRequestBoundary();
        $response = $this->actingAs($this->userA, 'api')->getJson($this->standingEndpoint());

        $response->assertStatus(200);

        $block = $response->json('installation_ceiling');

        $this->assertTrue($block['applies'], 'The installation ceiling applies to a non-operator too');
        $this->assertSame(BudgetScope::Installation->value, $block['ceiling']['scope_type']);
        $this->assertSame(0, bccomp($block['ceiling']['amount'], '500.00', 10), 'The installation ceiling amount is disclosed');

        // 7 + 43 = 50: the aggregate, not this caller's own 7.
        $this->assertSame(
            0,
            bccomp($block['consumption']['amount'], '50.0000000000', 10),
            'The installation consumption is the installation-wide aggregate'
        );
        $this->assertSame(
            0,
            bccomp($block['remaining'], '450.0000000000', 10),
            'The headroom left against the installation ceiling is disclosed'
        );
    }

    /**
     * The second half of the same ruling: no *other individual user's* id,
     * ceiling, or consumption anywhere in the body.
     *
     * The aggregate above is 50.00 and userB's own contribution is 43.00 —
     * distinct numbers, so this assertion cannot pass by arithmetic
     * coincidence with the figure the previous case requires to be present.
     */
    #[Test]
    public function a_non_operators_own_standing_contains_no_other_individual_users_figures(): void
    {
        $this->declareCeiling(BudgetScope::Installation, '500.00');
        $this->declareCeiling(BudgetScope::UserDefault, '25.00');
        $this->declareCeiling(BudgetScope::User, self::OTHER_USER_CEILING, scopeId: $this->userB->id);

        $this->recordSpend($this->userA->id, self::OWN_SPEND);
        $this->recordSpend($this->userB->id, self::OTHER_USER_SPEND);

        $this->newRequestBoundary();
        $response = $this->actingAs($this->userA, 'api')->getJson($this->standingEndpoint());

        $response->assertStatus(200);

        // Their own figures are there, so this is not passing by returning
        // an empty body. userA is on the unchanged per-user default, whose
        // row is installation-scoped, so what identifies the block as theirs
        // is the consumption rather than the ceiling's scope id.
        $this->assertSame('default', $response->json('user_ceiling.source'));
        $this->assertSame(
            0,
            bccomp($response->json('user_ceiling.consumption.amount'), self::OWN_SPEND, 10)
        );

        $this->assertNoOtherUsersFigures(
            $response->getContent(),
            "A non-operator's own standing"
        );
    }

    /**
     * The same rule on the self-addressed user route: asking about yourself
     * by name is allowed and answers about you alone.
     */
    #[Test]
    public function a_non_operator_may_read_their_own_standing_by_name_and_sees_nobody_elses_figures(): void
    {
        $this->declareCeiling(BudgetScope::UserDefault, '25.00');
        $this->declareCeiling(BudgetScope::User, self::OTHER_USER_CEILING, scopeId: $this->userB->id);

        $this->recordSpend($this->userA->id, self::OWN_SPEND);
        $this->recordSpend($this->userB->id, self::OTHER_USER_SPEND);

        $this->newRequestBoundary();
        $response = $this->actingAs($this->userA, 'api')
            ->getJson($this->standingEndpoint().'/users/'.$this->userA->id);

        $response->assertStatus(200);
        $this->assertSame(
            0,
            bccomp($response->json('user_ceiling.consumption.amount'), self::OWN_SPEND, 10),
            'The self-addressed route answers about the caller'
        );

        $this->assertNoOtherUsersFigures(
            $response->getContent(),
            "A non-operator's self-addressed standing"
        );
    }

    // ---------------------------------------------------------------
    // Refusals and warnings disclose only the caller's own figures
    // ---------------------------------------------------------------

    /**
     * A 402 handed to a non-operator names their ceiling and their
     * consumption, and nobody else's.
     *
     * The refusal body is the one place in this feature where a figure
     * reaches a user who did not ask for it, which makes it the place a leak
     * would be least likely to be noticed.
     */
    #[Test]
    public function a_refusal_delivered_to_a_non_operator_names_only_their_own_ceiling_and_consumption(): void
    {
        $this->declareCeiling(BudgetScope::UserDefault, '25.00', 'stop');
        $this->declareCeiling(BudgetScope::User, self::OTHER_USER_CEILING, scopeId: $this->userB->id);

        $this->recordSpend($this->userA->id, '30.0000000000');
        $this->recordSpend($this->userB->id, self::OTHER_USER_SPEND);

        $response = $this->requestAgentWork($this->userA);

        $response->assertStatus(402);

        $body = $response->json();

        $this->assertSame('user', $body['governing_scope']);
        $this->assertSame(0, bccomp($body['ceiling']['amount'], '25.00', 10));
        $this->assertSame(0, bccomp($body['consumption']['amount'], '30.0000000000', 10));

        $this->assertNoOtherUsersFigures(
            $response->getContent(),
            'A ceiling refusal delivered to a non-operator'
        );
    }

    /**
     * A refusal caused by the *installation* ceiling still names the
     * installation figures to a non-operator — the same ruling the standing
     * report follows, held here so the two surfaces cannot diverge.
     */
    #[Test]
    public function an_installation_caused_refusal_names_the_installation_figures_to_a_non_operator(): void
    {
        $this->declareCeiling(BudgetScope::Installation, '40.00', 'stop');
        $this->declareCeiling(BudgetScope::UserDefault, '5000.00', 'stop');

        $this->recordSpend($this->userA->id, self::OWN_SPEND);
        $this->recordSpend($this->userB->id, self::OTHER_USER_SPEND);

        $response = $this->requestAgentWork($this->userA);

        $response->assertStatus(402);

        $body = $response->json();

        $this->assertSame('installation', $body['governing_scope']);
        $this->assertSame(0, bccomp($body['ceiling']['amount'], '40.00', 10));
        $this->assertSame(
            0,
            bccomp($body['consumption']['amount'], '50.0000000000', 10),
            'The installation aggregate is what stopped them, and is what they are told'
        );

        $this->assertNoOtherUsersFigures(
            $response->getContent(),
            'An installation-caused refusal delivered to a non-operator'
        );
    }

    /**
     * A user-scoped warning is addressed to the user it concerns and to
     * nobody else — not to the other user, and not to the operator.
     *
     * The channel is resolved the way Events/RunUpdated.php resolves its
     * own; this asserts the resulting address rather than the mechanism, so
     * it holds however the resolution is written.
     */
    #[Test]
    public function a_user_scoped_warning_is_addressed_only_to_the_user_it_concerns(): void
    {
        Event::fake([SpendingThresholdWarned::class, SpendingCeilingReached::class]);

        // 25.00 at a 0.80 threshold warns from 20.00; 22.00 is across it and
        // still under the ceiling, so the work proceeds and a warning fires.
        $this->declareCeiling(BudgetScope::UserDefault, '25.00', 'stop', threshold: '0.80');
        $this->recordSpend($this->userA->id, '22.0000000000');
        $this->recordSpend($this->userB->id, self::OTHER_USER_SPEND);

        $this->requestAgentWork($this->userA)->assertStatus(200);

        Event::assertDispatched(
            SpendingThresholdWarned::class,
            function (SpendingThresholdWarned $event) {
                $this->assertSame(
                    $this->userA->id,
                    $event->userId,
                    'A user-scoped warning concerns the user who crossed the threshold'
                );

                $channels = array_map(
                    fn ($channel) => (string) $channel,
                    $event->broadcastOn()
                );

                $this->assertCount(1, $channels, 'A user-scoped warning goes to exactly one channel');
                $this->assertStringContainsString($this->userA->id, $channels[0]);
                $this->assertStringNotContainsString($this->userB->id, $channels[0]);
                $this->assertStringNotContainsString($this->operator->id, $channels[0]);

                return true;
            }
        );
    }

    // ---------------------------------------------------------------
    // Operator status governs configuration, never measurement
    // ---------------------------------------------------------------

    /**
     * Quickstart step 19. An operator's own agent work is refused by a
     * reached installation ceiling exactly like anyone else's, while the
     * configuration routes stay reachable — which is the intended way back
     * to capability, and the reason those routes are never themselves gated.
     */
    #[Test]
    public function an_operators_own_work_is_refused_while_the_configuration_routes_stay_reachable(): void
    {
        $operatorConversation = $this->conversationFor($this->operator);

        $this->declareCeiling(BudgetScope::Installation, '10.00', 'stop');
        $this->recordSpend($this->operator->id, '50.0000000000');

        $this->requestAgentWork($this->operator, $operatorConversation)
            ->assertStatus(402)
            ->assertJson(['governing_scope' => 'installation']);

        // Being over the ceiling does not cost an operator the ability to
        // read or change one.
        $this->newRequestBoundary();
        $this->actingAs($this->operator, 'api')
            ->getJson($this->ceilingsEndpoint())
            ->assertStatus(200);

        $this->newRequestBoundary();
        $this->actingAs($this->operator, 'api')
            ->putJson($this->ceilingsEndpoint().'/installation', [
                'amount' => '1000.00',
                'period_type' => 'month',
                'enforcement_mode' => 'stop',
            ])
            ->assertStatus(200);

        $this->newRequestBoundary();
        $this->requestAgentWork($this->operator, $operatorConversation)->assertStatus(200);
    }

    /**
     * And an operator is measured against the *per-user* ceiling too, on
     * their own consumption — operator status is not a per-user exemption
     * either.
     */
    #[Test]
    public function an_operator_is_measured_against_the_per_user_ceiling_like_any_other_user(): void
    {
        $operatorConversation = $this->conversationFor($this->operator);

        $this->declareCeiling(BudgetScope::UserDefault, '25.00', 'stop');
        $this->recordSpend($this->operator->id, '30.0000000000');

        $this->requestAgentWork($this->operator, $operatorConversation)
            ->assertStatus(402)
            ->assertJson(['governing_scope' => 'user']);
    }
}
