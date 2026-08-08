<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Carbon\CarbonImmutable;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\CostSummary;
use ClarionApp\LlmClient\Models\SpendingCeiling;
use ClarionApp\LlmClient\Services\CostRollupQuery;
use ClarionApp\LlmClient\Services\SpendingCeilingService;
use ClarionApp\LlmClient\ValueObjects\BudgetScope;
use ClarionApp\LlmClient\ValueObjects\ConsumptionSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Where do I stand?" — asked and answered without anything being warned
 * about, refused, or otherwise disturbed.
 *
 * The whole point of this surface is that a user should not have to be
 * stopped to discover how much of their allowance is left. So every case
 * here reads standing on a scope that is variously under, at, and over its
 * ceiling, and every one of them is a 200 with figures in it — a 402 from
 * this endpoint would be the feature failing at its stated purpose.
 *
 * Four properties are load-bearing and each is asserted rather than
 * assumed:
 *
 *  - **A scope with no ceiling says so.** Not a ceiling of "0", not an empty
 *    object, not a 404. An unconstrained user rendered as a zero ceiling
 *    reads as "you may spend nothing", which is the exact opposite of the
 *    truth.
 *  - **Every figure carries the approximation caveat.** As fields, not as
 *    prose an interface has to reconstruct, and on every block of every
 *    response including the degraded ones.
 *  - **A non-operator sees their own figures and nobody else's.** Asserted
 *    against the whole serialized body, not just the fields this file
 *    happens to name, so a leak through a field nobody thought of is still
 *    caught.
 *  - **The period is not caller-choosable.** Standing is always the current
 *    period of each applicable ceiling, resolved server-side — deliberately
 *    unlike the cost-rollup endpoints, because a caller-chosen range would
 *    not be the range enforcement actually uses.
 *
 * Consumption is written straight into cost_summaries rather than earned
 * through completed work. What is under test is how a figure is *reported*,
 * and hand-writing it makes the expected output arithmetic rather than a
 * consequence of whatever the fake provider happened to charge.
 *
 * Note on request boundaries: Laravel's test harness keeps one container
 * across every simulated request in a test method, while a deployment builds
 * one per request. The ledger memoizes a consumption figure for the life of
 * a request, so the boundary between simulated requests is drawn explicitly
 * before each call or a figure read before a change would be served again
 * after it.
 */
class BudgetStandingJourneyTest extends TestCase
{
    /** The whole-period clock every case in this file runs on. */
    private const NOW = '2026-08-14 10:00:00';

    private User $operator;
    private User $userA;
    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));

        $this->operator = User::factory()->create();
        $this->userA = User::factory()->create();
        $this->userB = User::factory()->create();

        config(['llm-client.cost.operator_user_ids' => [$this->operator->id]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        DB::table('budget_threshold_notifications')->delete();
        DB::table('cost_summaries')->delete();
        DB::table('spending_ceilings')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Endpoints
    // ---------------------------------------------------------------

    private function selfEndpoint(): string
    {
        return '/api/clarion-app/llm-client/budget/standing';
    }

    private function userEndpoint(User $user): string
    {
        return $this->selfEndpoint().'/users/'.$user->id;
    }

    private function installationEndpoint(): string
    {
        return $this->selfEndpoint().'/installation';
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    /**
     * End one simulated request and begin another, discarding the ledger's
     * per-request memo along with it.
     */
    private function newRequestBoundary(): void
    {
        $this->app->forgetScopedInstances();
    }

    private function declareInstallationCeiling(
        string $amount,
        string $mode = 'stop',
        string $periodType = 'month',
    ): SpendingCeiling {
        return app(SpendingCeilingService::class)->upsert(
            BudgetScope::Installation,
            SpendingCeiling::INSTALLATION_SCOPE_ID,
            ['amount' => $amount, 'period_type' => $periodType, 'enforcement_mode' => $mode],
        );
    }

    private function declareUserDefaultCeiling(
        string $amount,
        string $mode = 'stop',
        string $periodType = 'month',
    ): SpendingCeiling {
        return app(SpendingCeilingService::class)->upsert(
            BudgetScope::UserDefault,
            SpendingCeiling::INSTALLATION_SCOPE_ID,
            ['amount' => $amount, 'period_type' => $periodType, 'enforcement_mode' => $mode],
        );
    }

    private function declareUserCeiling(
        User $user,
        ?string $amount,
        string $mode = 'stop',
        string $periodType = 'month',
        bool $waived = false,
    ): SpendingCeiling {
        return app(SpendingCeilingService::class)->upsert(
            BudgetScope::User,
            $user->id,
            [
                'amount' => $amount,
                'period_type' => $periodType,
                'enforcement_mode' => $mode,
                'waived' => $waived,
            ],
        );
    }

    /**
     * Write one user-scoped consumption row for the period containing
     * $date.
     *
     * @param  array<string, int|string>  $extra  column overrides — the
     *   unpriced and estimated counters, for the disclosure cases.
     */
    private function recordSpend(
        User $user,
        string $amount,
        string $date = '2026-08-14',
        array $extra = [],
    ): void {
        DB::table('cost_summaries')->insert(array_merge([
            'id' => (string) Str::uuid(),
            'entity_type' => CostSummary::ENTITY_USER,
            'entity_id' => $user->id,
            'user_id' => $user->id,
            'period_date' => $date,
            'request_count' => 1,
            'priced_cost_total' => $amount,
            'zero_priced_request_count' => 0,
            'unpriced_request_count' => 0,
            'unpriced_total_tokens' => 0,
            'estimated_request_count' => 0,
            'updated_at' => Carbon::now(),
        ], $extra));
    }

    /**
     * A conversation-scoped row for the same spend.
     *
     * The metrics path writes one of these alongside every user-scoped row,
     * so the installation figure is only correct if it sums the user rows
     * alone. Seeding these with a deliberately different amount is what
     * makes "the installation total equals the sum of the per-user totals"
     * a real assertion rather than one that would hold either way.
     */
    private function recordConversationSpend(User $user, string $amount, string $date = '2026-08-14'): void
    {
        DB::table('cost_summaries')->insert([
            'id' => (string) Str::uuid(),
            'entity_type' => CostSummary::ENTITY_CONVERSATION,
            'entity_id' => (string) Str::uuid(),
            'user_id' => $user->id,
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
     * @param  array<string, mixed>  $query
     */
    private function standing(User $as, ?string $url = null, array $query = [])
    {
        $this->newRequestBoundary();

        $target = $url ?? $this->selfEndpoint();

        if ($query !== []) {
            $target .= '?'.http_build_query($query);
        }

        return $this->actingAs($as, 'api')->getJson($target);
    }

    // ---------------------------------------------------------------
    // Assertion helpers
    // ---------------------------------------------------------------

    /**
     * A monetary value on the wire is a plain-decimal *string* at ten
     * decimal places, never a JSON number: a JSON number is a float on the
     * far side of every parser, and this package's bcmath-only arithmetic
     * does not stop at the HTTP boundary. The value is compared with bccomp
     * as well, so the assertion covers both the quantity and its rendering.
     */
    private function assertDecimalString(mixed $actual, string $expected, string $message): void
    {
        $this->assertIsString($actual, $message.' — must be a decimal string, never a JSON number');
        $this->assertMatchesRegularExpression(
            '/^-?\d+\.\d{10}$/',
            $actual,
            $message.' — must carry ten decimal places'
        );
        $this->assertSame(0, bccomp($actual, $expected, 10), $message." — expected {$expected}, got {$actual}");
    }

    /**
     * FR-030/SC-003: the caveat is on every report of a figure, without
     * exception, and it is a pair of fields rather than prose.
     *
     * @param  array<string, mixed>  $consumption
     */
    private function assertCarriesApproximationCaveat(array $consumption, string $context): void
    {
        $this->assertArrayHasKey('approximate', $consumption, "{$context}: the approximation flag is required on every figure");
        $this->assertTrue($consumption['approximate'], "{$context}: consumption is always approximate");
        $this->assertSame(
            ConsumptionSnapshot::APPROXIMATION_NOTE,
            $consumption['approximation_note'] ?? null,
            "{$context}: the approximation note is required on every figure"
        );
    }

    /**
     * @param  array<string, mixed>  $period
     */
    private function assertCurrentMonthPeriod(array $period, string $context): void
    {
        $this->assertSame('month', $period['type'], $context);
        $this->assertSame('2026-08-01', $period['from'], $context);
        $this->assertSame('2026-08-31', $period['to'], $context);
        $this->assertTrue(
            CarbonImmutable::parse($period['resets_at'])->equalTo(CarbonImmutable::parse('2026-09-01T00:00:00Z')),
            "{$context}: the period resets at the exclusive upper bound, midnight UTC on the day after the last day"
        );
    }

    // ---------------------------------------------------------------
    // Scenario 1 — the ceiling, the figure, the reset time, the caveat
    // ---------------------------------------------------------------

    #[Test]
    public function a_user_with_a_ceiling_and_some_consumption_sees_all_four_facts_without_being_warned_or_refused(): void
    {
        $ceiling = $this->declareUserCeiling($this->userA, '25.00');
        $this->recordSpend($this->userA, '18.4210000000');

        $response = $this->standing($this->userA);

        $response->assertStatus(200);

        $block = $response->json('user_ceiling');

        $this->assertTrue($block['applies'], 'A user with a ceiling of their own has one that applies');
        $this->assertSame('override', $block['source'], "A row of the user's own is an override, and standing must say which it is");

        // The ceiling, exactly as configured.
        $this->assertSame($ceiling->id, $block['ceiling']['id']);
        $this->assertSame(BudgetScope::User->value, $block['ceiling']['scope_type']);
        $this->assertSame($this->userA->id, $block['ceiling']['scope_id']);
        $this->assertDecimalString($block['ceiling']['amount'], '25.00', 'The ceiling amount');
        $this->assertSame('month', $block['ceiling']['period_type']);
        $this->assertSame('stop', $block['ceiling']['enforcement_mode']);
        $this->assertIsString($block['ceiling']['approach_threshold'], 'approach_threshold must be a decimal string');
        $this->assertFalse($block['ceiling']['waived']);

        // The period, including when it turns over.
        $this->assertCurrentMonthPeriod($block['period'], 'The standing period');

        // The figure, and the caveat that always accompanies it.
        $this->assertTrue($block['consumption']['available']);
        $this->assertDecimalString($block['consumption']['amount'], '18.4210000000', 'Consumption to date');
        $this->assertSame(1, $block['consumption']['request_count']);
        $this->assertCarriesApproximationCaveat($block['consumption'], 'Scenario 1');

        // And the headroom left.
        $this->assertDecimalString($block['remaining'], '6.5790000000', 'Remaining headroom');
        $this->assertFalse($block['reached'], 'Well under the ceiling');
        $this->assertFalse($block['threshold_crossed'], '18.421 of 25.00 is below the 0.8 approach threshold');

        $this->assertFalse($response->json('degraded'));

        // Nothing about asking was itself a warning or a refusal.
        $this->assertSame(
            0,
            DB::table('budget_threshold_notifications')->count(),
            'Viewing standing must not fire a threshold notification of its own'
        );
    }

    #[Test]
    public function a_user_on_the_installation_default_is_told_that_is_where_their_ceiling_comes_from(): void
    {
        $this->declareUserDefaultCeiling('25.00');
        $this->recordSpend($this->userA, '10.0000000000');

        $block = $this->standing($this->userA)->assertStatus(200)->json('user_ceiling');

        $this->assertTrue($block['applies']);
        $this->assertSame(
            'default',
            $block['source'],
            'A user with no row of their own is measured against the default, and must be able to see that that is why'
        );
        $this->assertSame(BudgetScope::UserDefault->value, $block['ceiling']['scope_type']);
        $this->assertDecimalString($block['ceiling']['amount'], '25.00', 'The default ceiling amount');
        $this->assertDecimalString($block['remaining'], '15.0000000000', 'Remaining against the default');
    }

    /**
     * A period that has seen no work yet is a real zero measured against the
     * full ceiling — not an error, and not an empty state that leaves the
     * reader unsure whether the number is zero or unknown.
     */
    #[Test]
    public function a_ceiling_with_no_usage_yet_reports_a_real_zero_against_the_full_amount(): void
    {
        $this->declareUserCeiling($this->userA, '25.00', 'stop', 'day');

        $block = $this->standing($this->userA)->assertStatus(200)->json('user_ceiling');

        $this->assertTrue($block['applies']);
        $this->assertTrue($block['consumption']['available'], 'Nothing spent is a readable figure, not an unreadable one');
        $this->assertSame('0.0000000000', $block['consumption']['amount'], 'A real zero, rendered at full scale');
        $this->assertSame(0, $block['consumption']['request_count']);
        $this->assertDecimalString($block['remaining'], '25.0000000000', 'The whole ceiling is still available');
        $this->assertFalse($block['reached']);
        $this->assertFalse($block['threshold_crossed']);
        $this->assertCarriesApproximationCaveat($block['consumption'], 'An untouched period');

        $this->assertSame('day', $block['period']['type']);
        $this->assertSame('2026-08-14', $block['period']['from']);
        $this->assertSame('2026-08-14', $block['period']['to']);
        $this->assertTrue(
            CarbonImmutable::parse($block['period']['resets_at'])
                ->equalTo(CarbonImmutable::parse('2026-08-15T00:00:00Z')),
            'A day period resets at midnight UTC tomorrow'
        );
    }

    // ---------------------------------------------------------------
    // Scenario 2 — a waiver removes one ceiling, not both
    // ---------------------------------------------------------------

    /**
     * FR-010's observable face. A waived user has no user-scoped ceiling —
     * that block must say so, in those words — but the installation-wide
     * ceiling is on a different axis and still applies to them, and their
     * standing has to show it or the waiver reads as blanket exemption.
     */
    #[Test]
    public function a_waived_user_sees_no_user_ceiling_alongside_an_installation_ceiling_that_still_applies(): void
    {
        $installation = $this->declareInstallationCeiling('40.00');
        $this->declareUserDefaultCeiling('25.00');
        $this->declareUserCeiling($this->userA, null, 'stop', 'month', waived: true);

        $this->recordSpend($this->userA, '30.0000000000');

        $response = $this->standing($this->userA);
        $response->assertStatus(200);

        $userBlock = $response->json('user_ceiling');

        $this->assertFalse($userBlock['applies'], 'A waiver is the absence of a user-scoped ceiling');
        $this->assertSame('waived', $userBlock['reason'], 'And the reason must name the waiver rather than a missing configuration');
        $this->assertArrayNotHasKey('ceiling', $userBlock, 'A waived scope has no ceiling to report');
        $this->assertArrayNotHasKey('remaining', $userBlock, 'And no headroom, because there is no limit to have headroom against');

        $installationBlock = $response->json('installation_ceiling');

        $this->assertTrue($installationBlock['applies'], 'The installation ceiling survives every user-scoped waiver');
        $this->assertSame('installation', $installationBlock['source']);
        $this->assertSame($installation->id, $installationBlock['ceiling']['id']);
        $this->assertDecimalString($installationBlock['ceiling']['amount'], '40.00', 'The installation ceiling amount');
        $this->assertCurrentMonthPeriod($installationBlock['period'], 'The installation period');
    }

    // ---------------------------------------------------------------
    // Scenario 3 — an operator sees the installation and any one user
    // ---------------------------------------------------------------

    #[Test]
    public function an_operator_reads_the_installation_standing_and_it_equals_the_sum_of_the_per_user_figures(): void
    {
        $this->declareInstallationCeiling('100.00');
        $this->declareUserDefaultCeiling('25.00');

        $this->recordSpend($this->userA, '18.0000000000');
        $this->recordSpend($this->userB, '7.0000000000');

        // The conversation-scoped rows the metrics path writes alongside the
        // user rows. Summing these instead of, or as well as, the user rows
        // would double-count every unit of work in the installation.
        //
        // Their amounts deliberately do NOT match the user-scoped figures
        // above. In a deployment the two agree by construction, which is
        // exactly why matching them here would make this scenario pass
        // whichever entity_type the query happened to select — the assertion
        // would look like a real one and check nothing. 5 + 11 = 16, against
        // the 25 the user rows sum to.
        $this->recordConversationSpend($this->userA, '5.0000000000');
        $this->recordConversationSpend($this->userB, '11.0000000000');

        $response = $this->standing($this->operator, $this->installationEndpoint());
        $response->assertStatus(200);

        $block = $response->json('installation_ceiling');

        $this->assertTrue($block['applies']);
        $this->assertSame('installation', $block['source']);
        $this->assertDecimalString($block['ceiling']['amount'], '100.00', 'The installation ceiling amount');
        $this->assertCurrentMonthPeriod($block['period'], 'The installation period');
        $this->assertCarriesApproximationCaveat($block['consumption'], 'The installation figure');

        // The exact arithmetic sum of the two users' figures, and nothing
        // else that happens to be recorded in the same period.
        $perUserA = $this->standing($this->operator, $this->userEndpoint($this->userA))
            ->assertStatus(200)
            ->json('user_ceiling.consumption.amount');
        $perUserB = $this->standing($this->operator, $this->userEndpoint($this->userB))
            ->assertStatus(200)
            ->json('user_ceiling.consumption.amount');

        $this->assertDecimalString($perUserA, '18.0000000000', "User A's own figure");
        $this->assertDecimalString($perUserB, '7.0000000000', "User B's own figure");

        $this->assertDecimalString(
            $block['consumption']['amount'],
            bcadd($perUserA, $perUserB, 10),
            'The installation figure is the sum of the per-user figures'
        );
        $this->assertDecimalString($block['remaining'], '75.0000000000', 'Installation headroom');
    }

    #[Test]
    public function an_operator_reads_any_individual_users_standing(): void
    {
        $this->declareUserDefaultCeiling('25.00');
        $this->declareUserCeiling($this->userB, '60.00');

        $this->recordSpend($this->userA, '4.0000000000');
        $this->recordSpend($this->userB, '33.0000000000');

        $a = $this->standing($this->operator, $this->userEndpoint($this->userA))->assertStatus(200);
        $b = $this->standing($this->operator, $this->userEndpoint($this->userB))->assertStatus(200);

        $this->assertSame('default', $a->json('user_ceiling.source'));
        $this->assertDecimalString($a->json('user_ceiling.consumption.amount'), '4.0000000000', "User A's figure");
        $this->assertDecimalString($a->json('user_ceiling.remaining'), '21.0000000000', "User A's headroom");

        $this->assertSame('override', $b->json('user_ceiling.source'));
        $this->assertDecimalString($b->json('user_ceiling.ceiling.amount'), '60.00', "User B's own ceiling");
        $this->assertDecimalString($b->json('user_ceiling.consumption.amount'), '33.0000000000', "User B's figure");
        $this->assertDecimalString($b->json('user_ceiling.remaining'), '27.0000000000', "User B's headroom");
    }

    /**
     * The user-scoped route is not operator-only: it is "an operator, or the
     * caller themselves". Someone reading their own standing through the
     * addressable route gets the same answer about themselves as through the
     * bare one — the two routes must not be two computations of the same
     * figure, only two addresses for it.
     *
     * Asserted on the user block alone rather than the whole body, because
     * whether a user-addressed standing also carries the installation block
     * is a shape question the two routes may legitimately answer
     * differently; whether the caller's own figures agree is not.
     */
    #[Test]
    public function a_caller_may_read_their_own_standing_through_the_user_scoped_route(): void
    {
        $this->declareUserDefaultCeiling('25.00');
        $this->recordSpend($this->userA, '9.0000000000');

        $viaSelf = $this->standing($this->userA)->assertStatus(200)->json('user_ceiling');
        $viaUserRoute = $this->standing($this->userA, $this->userEndpoint($this->userA))
            ->assertStatus(200)
            ->json('user_ceiling');

        $this->assertDecimalString($viaSelf['consumption']['amount'], '9.0000000000', "The caller's own figure");
        $this->assertSame($viaSelf, $viaUserRoute, 'The same caller asking about themselves gets the same standing either way');
    }

    // ---------------------------------------------------------------
    // Scenario 4 — a non-operator sees their own figures and nobody else's
    // ---------------------------------------------------------------

    /**
     * FR-036/SC-015. Asserted against the entire serialized body rather than
     * against the fields this file happens to name: a leak through some
     * field nobody thought to check is exactly the kind this requirement
     * exists to prevent, and a field-by-field assertion would miss it.
     *
     * No installation ceiling is configured here on purpose, so every figure
     * in the response is necessarily somebody's individual figure and there
     * is no aggregate to confuse a match with.
     */
    #[Test]
    public function a_non_operators_own_standing_contains_only_their_own_figures(): void
    {
        $this->declareUserDefaultCeiling('25.00');
        $this->declareUserCeiling($this->userB, '99.99');

        $this->recordSpend($this->userA, '18.4210000000');
        $this->recordSpend($this->userB, '7.7770000000');

        $response = $this->standing($this->userA);
        $response->assertStatus(200);

        $body = $response->json();

        $this->assertDecimalString(
            $body['user_ceiling']['consumption']['amount'],
            '18.4210000000',
            "The caller's own figure"
        );
        $this->assertDecimalString($body['user_ceiling']['ceiling']['amount'], '25.00', "The caller's own ceiling");

        $serialized = json_encode($body);

        foreach ([
            "the other user's identifier" => $this->userB->id,
            "the other user's consumption" => '7.777',
            "the other user's ceiling" => '99.99',
        ] as $description => $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $serialized,
                "A non-operator's standing must not carry {$description}"
            );
        }
    }

    // ---------------------------------------------------------------
    // Scenario 5 — no ceiling configured says so, plainly
    // ---------------------------------------------------------------

    /**
     * FR-037. An unconstrained scope reported as a ceiling of "0" reads as
     * "you may spend nothing", which is the precise opposite of the truth;
     * an empty object leaves an interface guessing; and a 404 says the
     * standing does not exist when what does not exist is the ceiling.
     */
    #[Test]
    public function a_scope_with_no_ceiling_configured_says_so_rather_than_reporting_zero_an_empty_object_or_an_error(): void
    {
        $response = $this->standing($this->userA);

        $response->assertStatus(200);

        $expected = ['applies' => false, 'reason' => 'no_ceiling_configured'];

        $this->assertEquals($expected, $response->json('user_ceiling'), 'The user block states plainly that nothing applies');
        $this->assertEquals($expected, $response->json('installation_ceiling'), 'And so does the installation block');
        $this->assertFalse($response->json('degraded'));
    }

    #[Test]
    public function an_installation_ceiling_alone_leaves_the_user_block_reporting_no_ceiling_configured(): void
    {
        $this->declareInstallationCeiling('500.00');

        $response = $this->standing($this->userA);
        $response->assertStatus(200);

        $this->assertEquals(
            ['applies' => false, 'reason' => 'no_ceiling_configured'],
            $response->json('user_ceiling'),
            'No per-user ceiling of any kind is configured, and the block must say exactly that'
        );

        $installationBlock = $response->json('installation_ceiling');
        $this->assertTrue($installationBlock['applies']);
        $this->assertDecimalString($installationBlock['ceiling']['amount'], '500.00', 'The installation ceiling');
    }

    // ---------------------------------------------------------------
    // Over the ceiling is a standing, not an error
    // ---------------------------------------------------------------

    /**
     * A ceiling lowered below what has already been spent leaves the scope
     * genuinely over. That is a state to report, not a fault: the answer is
     * still a 200, `reached` is true, and the headroom is floored at zero
     * rather than rendered as a negative allowance.
     */
    #[Test]
    public function a_ceiling_lowered_below_recorded_consumption_reports_reached_with_remaining_floored_at_zero(): void
    {
        $this->declareUserCeiling($this->userA, '5.00');
        $this->recordSpend($this->userA, '30.0000000000');

        $response = $this->standing($this->userA);

        $response->assertStatus(200, 'Being over a ceiling is a standing to report, never an error and never a refusal');

        $block = $response->json('user_ceiling');

        $this->assertTrue($block['applies']);
        $this->assertTrue($block['reached'], 'Consumption is well past the ceiling');
        $this->assertTrue($block['threshold_crossed'], 'Past the ceiling is necessarily past the approach threshold');
        $this->assertDecimalString($block['consumption']['amount'], '30.0000000000', 'The figure is reported honestly');
        $this->assertSame(
            '0.0000000000',
            $block['remaining'],
            'Headroom is floored at zero — a negative allowance invites an interface to render one'
        );
    }

    // ---------------------------------------------------------------
    // The period is not caller-choosable
    // ---------------------------------------------------------------

    /**
     * Deliberately unlike GET /cost-rollups/*, which requires an explicit
     * range. A budget period is the range enforcement measures over, and a
     * caller-chosen one would not be — a standing that answered for July
     * would be answering a question nobody's ceiling is enforced against.
     */
    #[Test]
    public function from_and_to_parameters_are_not_part_of_this_endpoint_and_never_move_the_period(): void
    {
        $this->declareUserCeiling($this->userA, '1000.00');

        $this->recordSpend($this->userA, '12.0000000000', '2026-08-14');
        $this->recordSpend($this->userA, '500.0000000000', '2026-07-15');

        $response = $this->standing($this->userA, null, ['from' => '2026-07-01', 'to' => '2026-07-31']);

        $response->assertStatus(200);

        $block = $response->json('user_ceiling');

        $this->assertCurrentMonthPeriod($block['period'], 'A caller-supplied range must not move the period');
        $this->assertDecimalString(
            $block['consumption']['amount'],
            '12.0000000000',
            "Only the current period's consumption is reported, whatever range the caller asked for"
        );
    }

    // ---------------------------------------------------------------
    // FR-018/SC-013 — unpriced usage is disclosed, never counted as free
    // ---------------------------------------------------------------

    #[Test]
    public function unpriced_usage_in_the_period_is_disclosed_on_the_standing_report(): void
    {
        $this->declareUserCeiling($this->userA, '25.00');

        $this->recordSpend($this->userA, '5.0000000000');
        $this->recordSpend($this->userA, '0.0000000000', '2026-08-13', [
            'request_count' => 3,
            'unpriced_request_count' => 3,
            'unpriced_total_tokens' => 51204,
        ]);

        $consumption = $this->standing($this->userA)
            ->assertStatus(200)
            ->json('user_ceiling.consumption');

        $this->assertDecimalString($consumption['amount'], '5.0000000000', 'Unpriced usage carries no currency cost to add');
        $this->assertSame(3, $consumption['unpriced_request_count'], 'The unpriced work is counted, even though it costs nothing measurable');
        $this->assertSame(51204, $consumption['unpriced_total_tokens']);
        $this->assertArrayHasKey(
            'unpriced_disclosure',
            $consumption,
            'A period containing unpriced usage must disclose that the figure excludes it'
        );
        $this->assertStringContainsString('3 request(s)', $consumption['unpriced_disclosure']);
    }

    #[Test]
    public function the_unpriced_disclosure_is_absent_when_there_is_nothing_unpriced_to_disclose(): void
    {
        $this->declareUserCeiling($this->userA, '25.00');
        $this->recordSpend($this->userA, '5.0000000000');

        $consumption = $this->standing($this->userA)
            ->assertStatus(200)
            ->json('user_ceiling.consumption');

        $this->assertSame(0, $consumption['unpriced_request_count']);
        $this->assertArrayNotHasKey(
            'unpriced_disclosure',
            $consumption,
            'Never rendered as "0 unpriced" noise'
        );
    }

    // ---------------------------------------------------------------
    // FR-026 — the figure could not be read
    // ---------------------------------------------------------------

    /**
     * An unreadable figure is surfaced as unreadable. Standing still answers
     * — being unable to read a number is not a reason to refuse to say so —
     * but every affected block omits its figures rather than sending zeros,
     * because an omitted figure cannot be misread as "nothing spent".
     */
    #[Test]
    public function an_unreadable_ledger_marks_the_standing_degraded_and_omits_the_figures(): void
    {
        $this->declareInstallationCeiling('100.00');
        $this->declareUserDefaultCeiling('25.00');

        $this->app->instance(CostRollupQuery::class, new StandingUnreadableCostRollupQuery());

        $response = $this->standing($this->userA);

        $response->assertStatus(200, 'A standing report that cannot read the figure still answers, and says why');
        $this->assertTrue($response->json('degraded'), 'The degraded state is surfaced rather than left to be inferred');

        foreach (['user_ceiling', 'installation_ceiling'] as $key) {
            $block = $response->json($key);

            $this->assertTrue($block['applies'], "{$key}: the ceiling still applies; it is the figure that is missing");
            $this->assertFalse($block['consumption']['available'], "{$key}: the figure is marked unreadable");

            foreach (['amount', 'request_count', 'unpriced_request_count', 'unpriced_total_tokens', 'has_estimated_cost'] as $field) {
                $this->assertArrayNotHasKey(
                    $field,
                    $block['consumption'],
                    "{$key}: '{$field}' must be omitted rather than sent as zero"
                );
            }

            $this->assertCarriesApproximationCaveat($block['consumption'], $key);
            $this->assertNull($block['remaining'] ?? null, "{$key}: no headroom can be computed from a figure that could not be read");
        }
    }
}

/**
 * A CostRollupQuery whose reads fail the way a real one would — a lock
 * timeout, a malformed decimal, a table missing in a host that has not
 * migrated. Scoped to the query rather than simulated as a total outage,
 * which would fail the request for unrelated reasons long before the
 * standing report mattered.
 */
class StandingUnreadableCostRollupQuery extends CostRollupQuery
{
    public function userTotal(string $userId, string $from, string $to, ?string $callerId, bool $isOperator): array
    {
        throw new \RuntimeException('cost_summaries read failed');
    }

    public function installationTotal(string $from, string $to): array
    {
        throw new \RuntimeException('cost_summaries read failed');
    }
}
