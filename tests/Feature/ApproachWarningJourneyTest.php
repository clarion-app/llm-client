<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Events\SpendingCeilingReached;
use ClarionApp\LlmClient\Events\SpendingThresholdWarned;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\CostSummary;
use ClarionApp\LlmClient\Models\ModelPrice;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\SpendingCeiling;
use ClarionApp\LlmClient\Services\MetricsRecorder;
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
 * User Story 3 end to end: a scope is warned while there is still room
 * left, exactly once, and a warn-only ceiling notifies without blocking.
 *
 * Four things are under test here and each has a plausible-looking wrong
 * implementation that this file is written to reject:
 *
 *  - **The warning carries the same three facts a refusal does.** A warning
 *    that says only "you are approaching your limit" is not actionable: the
 *    reader has to know the amount, what has been spent, and when the
 *    period turns over, or their next move is to go looking. The
 *    approximation caveat travels with it as a field, not as prose the
 *    reader is expected to reconstruct.
 *  - **Once per threshold per scope per period, durably.** The latch is the
 *    unique index on budget_threshold_notifications, won with
 *    insertOrIgnore returning 1. A SELECT-then-INSERT would pass a
 *    single-process test and duplicate under two workers, so this file
 *    asserts the *mechanism* as well as the outcome — no SELECT against
 *    that table precedes the insert.
 *  - **Warn-only never blocks.** A ceiling in warn mode that has been
 *    reached and exceeded notifies operators and lets the work through. An
 *    implementation that treats "reached" as "stop" regardless of mode
 *    would look correct on every stop-mode test in the suite.
 *  - **A user-scoped warning is addressed to that user and to nobody
 *    else.** The channel is resolved the way Events/RunUpdated.php resolves
 *    its own — look up the owner, hand the id to PrivateChannel — with no
 *    identifier comparison anywhere, which is the standing rule this
 *    package adopted after an (int)-cast UUID collision on this very
 *    channel let one user's payload reach another.
 *
 * Consumption is produced by MetricsRecorder against a configured price
 * rather than written straight into cost_summaries, because the warning is
 * fired by the write that changes the number: writing the number by hand
 * would test the notifier while skipping the hook that calls it.
 */
class ApproachWarningJourneyTest extends TestCase
{
    /** Currency cost of one recorded unit, at the rate configured below. */
    private const COST_PER_TOKEN = '0.1000000000';

    private User $user;
    private User $otherUser;
    private User $operatorOne;
    private User $operatorTwo;
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
        $this->otherUser = User::factory()->create();
        $this->operatorOne = User::factory()->create();
        $this->operatorTwo = User::factory()->create();

        config(['llm-client.cost.operator_user_ids' => [
            $this->operatorOne->id,
            $this->operatorTwo->id,
        ]]);

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
        DB::table('agent_runs')->delete();

        Mockery::close();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Fixtures — deliberately not named seed(); Orchestra's TestCase
    // declares a public seed() of its own and redeclaring it privately is
    // a fatal error rather than a test failure.
    // ---------------------------------------------------------------

    /**
     * One token of input costs exactly 0.10, so a recorded unit's cost is a
     * round number chosen by the caller rather than something the reader of
     * this file has to derive from a rate table.
     */
    private function declarePrice(): void
    {
        ModelPrice::create([
            'provider_type' => 'llama_cpp',
            'model' => 'priced-model',
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

    private function declareCeiling(
        BudgetScope $scope,
        string $amount,
        string $mode = 'stop',
        string $periodType = 'day',
        ?string $threshold = '0.80',
    ): SpendingCeiling {
        return app(SpendingCeilingService::class)->upsert(
            $scope,
            SpendingCeiling::INSTALLATION_SCOPE_ID,
            array_filter([
                'amount' => $amount,
                'period_type' => $periodType,
                'enforcement_mode' => $mode,
                'approach_threshold' => $threshold,
            ], fn ($v) => $v !== null),
        );
    }

    /**
     * One completed unit of work costing $tokens x 0.10, recorded the way
     * the metrics path records it — which is the hook the warning hangs
     * off.
     */
    private function recordCompletedWork(int $tokens, ?User $for = null): void
    {
        (new MetricsRecorder())->recordUsage(
            conversationId: $this->conversation->id,
            userId: (string) ($for ?? $this->user)->id,
            attemptGroupId: (string) Str::uuid(),
            providerUsage: [
                'prompt_tokens' => $tokens,
                'completion_tokens' => 0,
                'total_tokens' => $tokens,
            ],
            inputText: 'input',
            outputText: '',
            model: 'priced-model',
            providerType: 'llama_cpp',
        );
    }

    /** Consumption recorded directly, for the cases that start mid-period. */
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

    /**
     * End one simulated request and begin another. The gate remembers an
     * admitted scope for the life of a request; a deployment discards that
     * at the request boundary and the test harness does not.
     */
    private function newRequestBoundary(): void
    {
        $this->app->forgetScopedInstances();
    }

    private function requestAgentWork(?User $as = null)
    {
        return $this->actingAs($as ?? $this->user, 'api')
            ->postJson('/api/clarion-app/llm-client/agent', [
                'message' => 'Do some work.',
                'conversation_id' => $this->conversation->id,
            ]);
    }

    /** @return string[] SQL statements touching the latch table */
    private function latchQueriesDuring(callable $fn): array
    {
        $seen = [];

        DB::listen(function ($query) use (&$seen) {
            if (str_contains($query->sql, 'budget_threshold_notifications')) {
                $seen[] = $query->sql;
            }
        });

        $fn();

        return $seen;
    }

    /** @return array<int, object> */
    private function notificationRows(?string $kind = null): array
    {
        $query = DB::table('budget_threshold_notifications');

        if ($kind !== null) {
            $query->where('kind', $kind);
        }

        return $query->orderBy('created_at')->get()->all();
    }

    // ---------------------------------------------------------------
    // Scenario 1 — crossing the threshold warns, once, with the facts
    // ---------------------------------------------------------------

    #[Test]
    public function crossing_the_approach_threshold_below_the_ceiling_delivers_exactly_one_warning(): void
    {
        // 25.00 ceiling, 0.80 threshold => the warning is due at 20.00.
        $this->declareCeiling(BudgetScope::UserDefault, '25.00', 'stop', 'day', '0.80');

        Event::fake([SpendingThresholdWarned::class, SpendingCeilingReached::class]);

        // 10.00 — below the threshold, nothing is due.
        $this->recordCompletedWork(100);
        Event::assertNotDispatched(SpendingThresholdWarned::class);

        // 20.00 — the threshold is crossed by this unit of work, and it is
        // this unit's own completion that must produce the warning.
        $this->recordCompletedWork(100);

        Event::assertDispatchedTimes(SpendingThresholdWarned::class, 1);

        // ...and the ceiling itself has not been reached, so no reached
        // notification rides along with it.
        Event::assertNotDispatched(SpendingCeilingReached::class);
    }

    #[Test]
    public function the_warning_states_the_limit_the_consumption_the_reset_time_and_the_caveat(): void
    {
        $this->declareCeiling(BudgetScope::UserDefault, '25.00', 'stop', 'day', '0.80');

        Event::fake([SpendingThresholdWarned::class, SpendingCeilingReached::class]);

        $this->recordCompletedWork(200); // 20.00 — crosses in one unit

        Event::assertDispatched(SpendingThresholdWarned::class, function ($event) {
            $payload = $event->broadcastWith();

            // The same §1 shapes the refusal body uses, so one interface
            // code path renders a warning and a refusal alike.
            $this->assertSame('approach', $payload['kind']);
            $this->assertArrayHasKey('ceiling', $payload);
            $this->assertArrayHasKey('period', $payload);
            $this->assertArrayHasKey('consumption', $payload);
            $this->assertArrayHasKey('remaining', $payload);
            $this->assertArrayHasKey('message', $payload);

            // Money stays a decimal string all the way to the wire.
            $this->assertIsString($payload['ceiling']['amount']);
            $this->assertIsString($payload['consumption']['amount']);
            $this->assertSame(0, bccomp($payload['ceiling']['amount'], '25.00', 10));
            $this->assertSame(0, bccomp($payload['consumption']['amount'], '20.00', 10));
            $this->assertSame(0, bccomp($payload['remaining'], '5.00', 10));

            // The reset instant is the exclusive upper bound of the day.
            $this->assertStringContainsString('2026-08-15', $payload['period']['resets_at']);

            // The caveat is a field, always present, never reconstructed
            // from the sentence by the reader.
            $this->assertTrue($payload['consumption']['approximate']);
            $this->assertNotEmpty($payload['consumption']['approximation_note']);

            // ...and the sentence a human reads carries all three facts,
            // plus the caveat, in plain language.
            $message = (string) $payload['message'];
            $this->assertStringContainsString('25.00', $message);
            $this->assertStringContainsString('20.00', $message);
            $this->assertStringContainsString('2026-08-15', $message);
            $this->assertStringContainsString('approximate', strtolower($message));

            return true;
        });
    }

    // ---------------------------------------------------------------
    // Scenario 2 — no duplicate, and the latch is the unique index
    // ---------------------------------------------------------------

    #[Test]
    public function further_usage_above_the_threshold_and_below_the_ceiling_delivers_no_duplicate(): void
    {
        $this->declareCeiling(BudgetScope::UserDefault, '25.00', 'stop', 'day', '0.80');

        Event::fake([SpendingThresholdWarned::class, SpendingCeilingReached::class]);

        $this->recordCompletedWork(200); // 20.00 — crosses
        Event::assertDispatchedTimes(SpendingThresholdWarned::class, 1);

        $this->recordCompletedWork(10);  // 21.00 — still above, still below the ceiling
        $this->recordCompletedWork(10);  // 22.00
        $this->recordCompletedWork(10);  // 23.00

        Event::assertDispatchedTimes(SpendingThresholdWarned::class, 1);
        Event::assertNotDispatched(SpendingCeilingReached::class);

        $this->assertCount(
            1,
            $this->notificationRows('approach'),
            'One warning per threshold per scope per period means one latch row, not one per unit of work'
        );
    }

    /**
     * The latch is the unique index, won with insertOrIgnore returning 1 —
     * not a SELECT followed by an INSERT, which passes single-process and
     * duplicates the moment two workers cross the same threshold at once.
     * Asserted as a property of the SQL, because the outcome alone cannot
     * tell the two implementations apart in a single-process test.
     */
    #[Test]
    public function the_latch_is_an_insert_or_ignore_against_the_unique_index_not_a_select_then_insert(): void
    {
        $this->declareCeiling(BudgetScope::UserDefault, '25.00', 'stop', 'day', '0.80');

        Event::fake([SpendingThresholdWarned::class, SpendingCeilingReached::class]);

        $statements = $this->latchQueriesDuring(function () {
            $this->recordCompletedWork(200); // wins the latch
            $this->recordCompletedWork(10);  // must lose it
        });

        $this->assertNotEmpty($statements, 'The latch must be attempted against budget_threshold_notifications');

        $inserts = array_values(array_filter(
            $statements,
            fn (string $sql) => (bool) preg_match('/insert\s+(or\s+)?ignore\s+into/i', $sql)
        ));

        $this->assertGreaterThanOrEqual(
            2,
            count($inserts),
            "Both attempts must go through insertOrIgnore — the second one losing is the latch working:\n"
            .implode("\n", $statements)
        );

        $selects = array_values(array_filter(
            $statements,
            fn (string $sql) => (bool) preg_match('/^\s*select\b/i', $sql)
        ));

        $this->assertSame(
            [],
            $selects,
            "A SELECT against the latch table means a test-and-set that races:\n".implode("\n", $selects)
        );
    }

    #[Test]
    public function the_latch_row_records_the_scope_the_period_and_the_figure_at_the_moment_it_fired(): void
    {
        $this->declareCeiling(BudgetScope::UserDefault, '25.00', 'stop', 'day', '0.80');

        Event::fake([SpendingThresholdWarned::class, SpendingCeilingReached::class]);

        $this->recordCompletedWork(200);

        $rows = $this->notificationRows('approach');
        $this->assertCount(1, $rows);

        $row = $rows[0];

        // The scope the warning is *about* — a user_default ceiling warns
        // about a specific user, not about the installation.
        $this->assertSame('user', $row->scope_type);
        $this->assertSame((string) $this->user->id, (string) $row->scope_id);
        $this->assertSame('day', $row->period_type);
        $this->assertSame('2026-08-14', substr((string) $row->period_start, 0, 10));
        $this->assertSame('approach', $row->kind);
        $this->assertSame(0, bccomp((string) $row->consumption_at_fire, '20.00', 10));
    }

    // ---------------------------------------------------------------
    // Scenario 4 — warn-only notifies and never blocks
    // ---------------------------------------------------------------

    #[Test]
    public function a_warn_mode_ceiling_that_is_exceeded_lets_the_work_proceed(): void
    {
        $this->declareCeiling(BudgetScope::Installation, '25.00', 'warn', 'day', '0.80');
        $this->recordSpend((string) $this->user->id, '30.0000000000');

        Event::fake([SpendingThresholdWarned::class, SpendingCeilingReached::class]);

        $this->newRequestBoundary();

        $this->requestAgentWork()->assertStatus(200);
    }

    #[Test]
    public function a_warn_mode_ceiling_that_is_exceeded_notifies_operators_that_it_was_reached(): void
    {
        $this->declareCeiling(BudgetScope::Installation, '25.00', 'warn', 'day', '0.80');
        $this->recordSpend((string) $this->user->id, '30.0000000000');

        Event::fake([SpendingThresholdWarned::class, SpendingCeilingReached::class]);

        $this->newRequestBoundary();
        $this->requestAgentWork()->assertStatus(200);

        Event::assertDispatched(SpendingCeilingReached::class, function ($event) {
            $payload = $event->broadcastWith();

            $this->assertSame('reached', $payload['kind']);
            $this->assertSame(0, bccomp($payload['ceiling']['amount'], '25.00', 10));
            $this->assertSame(0, bccomp($payload['consumption']['amount'], '30.00', 10));
            $this->assertTrue($payload['consumption']['approximate']);

            // Over the ceiling is reported as no headroom, never as a
            // negative allowance.
            $this->assertSame(0, bccomp($payload['remaining'], '0', 10));

            // An installation-wide statement goes to the operators, who are
            // the only people who can act on it.
            $channels = array_map(fn ($c) => (string) $c, $event->broadcastOn());
            sort($channels);

            $expected = [
                'private-User.'.$this->operatorOne->id,
                'private-User.'.$this->operatorTwo->id,
            ];
            sort($expected);

            $this->assertSame($expected, $channels);

            return true;
        });

        // The gate and the metrics path are two evaluation moments in one
        // request; the durable latch is what keeps that one notification.
        Event::assertDispatchedTimes(SpendingCeilingReached::class, 1);
        $this->assertCount(1, $this->notificationRows('reached'));
    }

    /**
     * A ceiling reached in warn mode fires the reached notification from
     * the gate's own evaluation, which is what makes a ceiling *lowered*
     * below existing consumption warn on the next request rather than
     * waiting for the next completion.
     */
    #[Test]
    public function a_ceiling_lowered_below_existing_consumption_notifies_on_the_next_request(): void
    {
        $this->recordSpend((string) $this->user->id, '30.0000000000');
        $this->declareCeiling(BudgetScope::UserDefault, '10.00', 'warn', 'day', '0.80');

        Event::fake([SpendingThresholdWarned::class, SpendingCeilingReached::class]);

        $this->newRequestBoundary();
        $this->requestAgentWork()->assertStatus(200);

        Event::assertDispatched(SpendingCeilingReached::class);
    }

    // ---------------------------------------------------------------
    // Scenario 5 — delivered to its audience and to nobody else
    // ---------------------------------------------------------------

    #[Test]
    public function a_user_scoped_warning_is_addressed_only_to_that_users_private_channel(): void
    {
        $this->declareCeiling(BudgetScope::UserDefault, '25.00', 'stop', 'day', '0.80');

        Event::fake([SpendingThresholdWarned::class, SpendingCeilingReached::class]);

        $this->recordCompletedWork(200);

        Event::assertDispatched(SpendingThresholdWarned::class, function ($event) {
            $channels = array_map(fn ($c) => (string) $c, $event->broadcastOn());

            $this->assertSame(['private-User.'.$this->user->id], $channels);

            $this->assertNotContains(
                'private-User.'.$this->otherUser->id,
                $channels,
                'A user-scoped payload must never be addressed to another user'
            );
            $this->assertNotContains('private-User.'.$this->operatorOne->id, $channels);
            $this->assertNotContains('private-User.'.$this->operatorTwo->id, $channels);

            return true;
        });
    }

    #[Test]
    public function an_installation_scoped_warning_fans_out_to_every_operator_and_no_one_else(): void
    {
        // Only the installation ceiling is declared, so the only scope that
        // can cross a threshold here is the installation.
        $this->declareCeiling(BudgetScope::Installation, '25.00', 'warn', 'day', '0.80');

        Event::fake([SpendingThresholdWarned::class, SpendingCeilingReached::class]);

        $this->recordCompletedWork(200);

        Event::assertDispatched(SpendingThresholdWarned::class, function ($event) {
            $channels = array_map(fn ($c) => (string) $c, $event->broadcastOn());
            sort($channels);

            $expected = [
                'private-User.'.$this->operatorOne->id,
                'private-User.'.$this->operatorTwo->id,
            ];
            sort($expected);

            $this->assertSame($expected, $channels);

            $this->assertNotContains('private-User.'.$this->user->id, $channels);
            $this->assertNotContains('private-User.'.$this->otherUser->id, $channels);

            return true;
        });

        $rows = $this->notificationRows('approach');
        $this->assertCount(1, $rows);
        $this->assertSame('installation', $rows[0]->scope_type);
        $this->assertSame(SpendingCeiling::INSTALLATION_SCOPE_ID, (string) $rows[0]->scope_id);
    }

    /**
     * One user's crossing must not warn another user, even when both are
     * spending in the same period against the same default ceiling.
     */
    #[Test]
    public function one_users_crossing_never_reaches_another_users_channel(): void
    {
        $this->declareCeiling(BudgetScope::UserDefault, '25.00', 'stop', 'day', '0.80');

        Event::fake([SpendingThresholdWarned::class, SpendingCeilingReached::class]);

        $this->recordCompletedWork(50, $this->otherUser);  // 5.00 for the other user
        $this->recordCompletedWork(200, $this->user);      // 20.00 for ours — crosses

        Event::assertDispatchedTimes(SpendingThresholdWarned::class, 1);

        Event::assertDispatched(SpendingThresholdWarned::class, function ($event) {
            $channels = array_map(fn ($c) => (string) $c, $event->broadcastOn());
            $this->assertSame(['private-User.'.$this->user->id], $channels);

            return true;
        });

        $rows = $this->notificationRows('approach');
        $this->assertCount(1, $rows);
        $this->assertSame((string) $this->user->id, (string) $rows[0]->scope_id);
    }

    /**
     * The standing rule, stated against the source: neither event resolves
     * its channel by comparing identifiers, and neither introduces a
     * Broadcast::channel() predicate of its own. Events/RunUpdated.php is
     * run through the same predicate in the same test, so a change to what
     * "no identifier comparison" means cannot quietly exempt the new
     * events while the file still claims they match RunUpdated.
     */
    #[Test]
    public function both_events_resolve_their_channel_exactly_as_run_updated_does(): void
    {
        $eventsDir = dirname(__DIR__, 2).'/src/Events/';

        $files = [
            'SpendingThresholdWarned.php',
            'SpendingCeilingReached.php',
            // The reference implementation, checked by the same rules.
            'RunUpdated.php',
        ];

        foreach ($files as $file) {
            $path = $eventsDir.$file;
            $this->assertFileExists($path);

            $source = (string) file_get_contents($path);

            $this->assertDoesNotMatchRegularExpression(
                '/\(\s*int(eger)?\s*\)/',
                $source,
                "{$file}: an (int) cast on an identifier is the exact defect this rule exists for"
            );
            $this->assertDoesNotMatchRegularExpression(
                '/(?<![=!<>])==(?!=)/',
                $source,
                "{$file}: loose equality between identifiers is never how a channel is chosen"
            );
            $this->assertDoesNotMatchRegularExpression(
                '/!=(?!=)/',
                $source,
                "{$file}: loose inequality between identifiers is never how a channel is chosen"
            );
            $this->assertStringNotContainsString(
                'Broadcast::channel(',
                $source,
                "{$file}: this feature adds zero new channel authorization predicates"
            );
            $this->assertStringContainsString(
                "'User.'",
                $source,
                "{$file}: the channel name is the looked-up owner id handed to the already-hardened channel"
            );
        }
    }
}
