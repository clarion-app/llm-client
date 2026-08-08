<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Exceptions\BudgetExceededException;
use ClarionApp\LlmClient\Models\CostSummary;
use ClarionApp\LlmClient\Models\SpendingCeiling;
use ClarionApp\LlmClient\Services\BudgetGate;
use ClarionApp\LlmClient\Services\SpendingCeilingService;
use ClarionApp\LlmClient\ValueObjects\BudgetScope;
use ClarionApp\LlmClient\ValueObjects\BudgetWorkKind;
use ClarionApp\LlmClient\ValueObjects\EnforcementDecision;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for BudgetGate — the sole decision authority.
 *
 *   evaluate(?string $userId): EnforcementDecision            // never throws
 *   admit(?string $userId, BudgetWorkKind $kind, ...): void   // throws only BudgetExceededException
 *
 * Five properties here are load-bearing rather than incidental, and each
 * has a mutation that would make the gate look correct while breaking one
 * of the feature's guarantees:
 *
 *  - With no ceiling configured for any applicable scope, the gate never
 *    reads consumption. An installation that has not opted in must not pay
 *    for a feature it is not using, and the short-circuit must come first
 *    for that to be true. The assertion is against the query log, not
 *    against the ledger's own state, because a ledger that was consulted
 *    and happened to answer cheaply is still a ledger that was consulted.
 *  - A warn-mode ceiling never blocks, however far past its amount the
 *    scope has gone.
 *  - When both an installation and a user-scoped ceiling apply, the
 *    governing one is the smallest remaining headroom, ties broken
 *    installation-first. Determinism is the point: a refusal has to name
 *    the ceiling that actually stopped the work, and a nondeterministic
 *    choice makes that claim untestable.
 *  - A unit of work is admitted once. A second admit() for the same scope
 *    on the same instance returns without re-evaluating, which is what
 *    stops an embedding call made inside a live turn from throwing after
 *    some other request crosses the ceiling. A *new* instance — a new
 *    request, a new queue job — evaluates again.
 *  - Every comparison is bcmath on plain-decimal strings. A float formed
 *    anywhere in the class propagates into a currency comparison.
 */
class BudgetGateTest extends TestCase
{
    private string $userA;
    private string $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userA = (string) Str::uuid();
        $this->userB = (string) Str::uuid();

        Carbon::setTestNow(Carbon::parse('2026-08-14 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        DB::table('cost_summaries')->delete();
        DB::table('spending_ceilings')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers — deliberately not named seed(); Orchestra's TestCase
    // declares a public seed() of its own and redeclaring it privately is
    // a fatal error, not a test failure.
    // ---------------------------------------------------------------

    private function gate(): BudgetGate
    {
        return app(BudgetGate::class);
    }

    private function ceilings(): SpendingCeilingService
    {
        return app(SpendingCeilingService::class);
    }

    private function declareCeiling(
        BudgetScope $scope,
        string $amount,
        string $mode = 'stop',
        string $periodType = 'month',
        ?string $scopeId = null,
        ?string $threshold = null,
    ): SpendingCeiling {
        return $this->ceilings()->upsert(
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

    /** Recorded, priced consumption for one user in the current period. */
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
     * Count only the queries that read consumption.
     *
     * Resolving whether a ceiling exists at all is itself one or two narrow
     * indexed lookups against spending_ceilings, and always will be — the
     * gate cannot know there is nothing to enforce without asking. What the
     * short-circuit guarantees, and what this counts, is that the *ledger*
     * is never reached: no cost_summaries scan, which is the read whose
     * cost grows with the installation.
     *
     * @return string[] the matching SQL statements, so a failure names them
     */
    private function consumptionQueriesDuring(callable $fn): array
    {
        $seen = [];

        DB::listen(function ($query) use (&$seen) {
            if (str_contains($query->sql, 'cost_summaries')) {
                $seen[] = $query->sql;
            }
        });

        $fn();

        return $seen;
    }

    /** @return string[] every SQL statement issued during $fn */
    private function allQueriesDuring(callable $fn): array
    {
        $seen = [];

        DB::listen(function ($query) use (&$seen) {
            $seen[] = $query->sql;
        });

        $fn();

        return $seen;
    }

    /**
     * The outcome as a plain string, whether the value object carries a
     * string or a backed enum. The three outcome *names* are the contract;
     * their PHP representation is not.
     */
    private function outcomeOf(EnforcementDecision $decision): string
    {
        $outcome = $decision->outcome;

        return $outcome instanceof \BackedEnum ? (string) $outcome->value : (string) $outcome;
    }

    // ---------------------------------------------------------------
    // No ceiling configured means the ledger is never reached
    // ---------------------------------------------------------------

    #[Test]
    public function with_no_ceiling_configured_admit_returns_without_reading_consumption(): void
    {
        $this->recordSpend($this->userA, '9999.0000000000');

        $queries = $this->consumptionQueriesDuring(function () {
            $this->gate()->admit($this->userA, BudgetWorkKind::Interactive);
        });

        $this->assertSame(
            [],
            $queries,
            'With nothing configured the gate must short-circuit before the ledger; '
            .'an installation that has not opted in pays nothing for the feature'
        );
    }

    #[Test]
    public function with_no_ceiling_configured_evaluate_allows_and_names_no_governing_ceiling(): void
    {
        $this->recordSpend($this->userA, '9999.0000000000');

        $decision = $this->gate()->evaluate($this->userA);

        $this->assertSame('allow', $this->outcomeOf($decision));
        $this->assertNull($decision->governingCeiling);
        $this->assertFalse($decision->degraded);
    }

    // ---------------------------------------------------------------
    // Stop mode
    // ---------------------------------------------------------------

    #[Test]
    public function a_stop_mode_ceiling_with_consumption_below_it_admits(): void
    {
        $this->declareCeiling(BudgetScope::UserDefault, '25.00', 'stop');
        $this->recordSpend($this->userA, '24.9999999999');

        $this->gate()->admit($this->userA, BudgetWorkKind::Interactive);

        $this->assertSame('allow_with_warning', $this->outcomeOf($this->gate()->evaluate($this->userA)));
    }

    #[Test]
    public function a_stop_mode_ceiling_reached_exactly_refuses(): void
    {
        $this->declareCeiling(BudgetScope::UserDefault, '25.00', 'stop');
        $this->recordSpend($this->userA, '25.0000000000');

        // "Reached" is at-or-above, not strictly above: a scope that has
        // spent exactly its ceiling has no headroom left.
        $this->expectException(BudgetExceededException::class);

        $this->gate()->admit($this->userA, BudgetWorkKind::Interactive);
    }

    #[Test]
    public function a_stop_mode_ceiling_exceeded_refuses_and_throws_nothing_else(): void
    {
        $this->declareCeiling(BudgetScope::UserDefault, '25.00', 'stop');
        $this->recordSpend($this->userA, '30.0000000000');

        try {
            $this->gate()->admit($this->userA, BudgetWorkKind::Interactive);
            $this->fail('A reached stop-mode ceiling must refuse new work');
        } catch (BudgetExceededException $e) {
            $this->assertInstanceOf(EnforcementDecision::class, $e->decision);
            $this->assertSame('stop', $this->outcomeOf($e->decision));
        }
    }

    /**
     * The exception's base class is load-bearing. This package already
     * catches \RuntimeException around code a ceiling refusal now travels
     * through — ConversationController::confirmApiCall() wraps resume() in
     * one and inspects the message text — so a subclass would be absorbed
     * or reshaped by a catch written about an entirely different failure.
     */
    #[Test]
    public function the_refusal_exception_extends_exception_and_not_runtime_exception(): void
    {
        $this->declareCeiling(BudgetScope::UserDefault, '5.00', 'stop');
        $this->recordSpend($this->userA, '6.0000000000');

        try {
            $this->gate()->admit($this->userA, BudgetWorkKind::Interactive);
            $this->fail('Expected a refusal');
        } catch (BudgetExceededException $e) {
            $this->assertInstanceOf(\Exception::class, $e);
            $this->assertNotInstanceOf(
                \RuntimeException::class,
                $e,
                'A \RuntimeException subclass is silently absorbed by catches this package '
                .'already has around resume() and role failures'
            );
        }
    }

    // ---------------------------------------------------------------
    // Warn mode never blocks
    // ---------------------------------------------------------------

    #[Test]
    public function a_warn_mode_ceiling_reached_or_exceeded_still_admits(): void
    {
        $this->declareCeiling(BudgetScope::UserDefault, '25.00', 'warn');
        $this->recordSpend($this->userA, '25.0000000000');

        $this->gate()->admit($this->userA, BudgetWorkKind::Interactive);

        $this->recordSpend($this->userB, '0.0000000000');
        DB::table('cost_summaries')
            ->where('entity_id', $this->userA)
            ->update(['priced_cost_total' => '900.0000000000']);

        app()->forgetScopedInstances();
        $this->gate()->admit($this->userA, BudgetWorkKind::Interactive);

        app()->forgetScopedInstances();
        $this->assertSame(
            'allow_with_warning',
            $this->outcomeOf($this->gate()->evaluate($this->userA)),
            'A warn-mode ceiling that has been passed notifies; it never stops'
        );
    }

    // ---------------------------------------------------------------
    // Most restrictive governs, deterministically
    // ---------------------------------------------------------------

    #[Test]
    public function the_outcome_is_stop_when_either_applicable_ceiling_is_stop_mode_and_reached(): void
    {
        // The installation ceiling is the one that is over; the user's own
        // ceiling has plenty of room.
        $this->declareCeiling(BudgetScope::Installation, '40.00', 'stop');
        $this->declareCeiling(BudgetScope::UserDefault, '500.00', 'stop');
        $this->recordSpend($this->userA, '50.0000000000');

        $decision = $this->gate()->evaluate($this->userA);

        $this->assertSame('stop', $this->outcomeOf($decision));
        $this->assertNotNull($decision->governingCeiling);
        $this->assertSame(BudgetScope::Installation->value, $decision->governingCeiling->scope_type);
    }

    #[Test]
    public function the_governing_ceiling_is_the_one_with_the_smallest_remaining_headroom(): void
    {
        // Both are reached, so both produce the outcome and the tie-break
        // rule is not what decides it. Installation headroom is 60 - 70 =
        // -10; the user's is 40 - 70 = -30. The user's is smaller, so the
        // user's governs — which also rules out "installation always wins"
        // and "whichever matched first".
        $this->declareCeiling(BudgetScope::Installation, '60.00', 'stop');
        $this->declareCeiling(BudgetScope::UserDefault, '40.00', 'stop');
        $this->recordSpend($this->userA, '70.0000000000');

        $decision = $this->gate()->evaluate($this->userA);

        $this->assertSame('stop', $this->outcomeOf($decision));
        $this->assertSame(
            BudgetScope::UserDefault->value,
            $decision->governingCeiling->scope_type,
            'The named ceiling must be the tightest one, not the first one found'
        );
    }

    #[Test]
    public function an_exact_tie_on_remaining_headroom_breaks_installation_first(): void
    {
        // Identical amounts against one user's spend, so the two headrooms
        // are exactly equal. The installation ceiling is named because it is
        // the one a user-scoped waiver cannot remove, and so the more
        // informative thing to tell the caller.
        $this->declareCeiling(BudgetScope::Installation, '50.00', 'stop');
        $this->declareCeiling(BudgetScope::UserDefault, '50.00', 'stop');
        $this->recordSpend($this->userA, '70.0000000000');

        $decision = $this->gate()->evaluate($this->userA);

        $this->assertSame(BudgetScope::Installation->value, $decision->governingCeiling->scope_type);
    }

    // ---------------------------------------------------------------
    // A ceiling lowered below what has already been spent
    // ---------------------------------------------------------------

    #[Test]
    public function a_ceiling_lowered_below_existing_consumption_is_reached_on_the_very_next_evaluation(): void
    {
        $this->declareCeiling(BudgetScope::UserDefault, '100.00', 'stop');
        $this->recordSpend($this->userA, '30.0000000000');

        $this->gate()->admit($this->userA, BudgetWorkKind::Interactive);

        // The operator lowers the ceiling under the recorded spend. This is
        // not an error and not a retroactively invalid state: the scope is
        // simply over, exactly as if usage had taken it there.
        $this->declareCeiling(BudgetScope::UserDefault, '10.00', 'stop');
        app()->forgetScopedInstances();

        $decision = $this->gate()->evaluate($this->userA);
        $this->assertSame('stop', $this->outcomeOf($decision));

        $this->expectException(BudgetExceededException::class);
        $this->gate()->admit($this->userA, BudgetWorkKind::Interactive);
    }

    // ---------------------------------------------------------------
    // evaluate() never throws
    // ---------------------------------------------------------------

    #[Test]
    public function evaluate_never_throws_for_any_configuration(): void
    {
        $configurations = [
            'nothing configured' => function () {
            },
            'stop mode, reached' => function () {
                $this->declareCeiling(BudgetScope::UserDefault, '1.00', 'stop');
                $this->recordSpend($this->userA, '5.0000000000');
            },
            'warn mode, reached' => function () {
                $this->declareCeiling(BudgetScope::UserDefault, '1.00', 'warn');
                $this->recordSpend($this->userA, '5.0000000000');
            },
            'both scopes, both reached' => function () {
                $this->declareCeiling(BudgetScope::Installation, '1.00', 'stop');
                $this->declareCeiling(BudgetScope::UserDefault, '2.00', 'stop');
                $this->recordSpend($this->userA, '5.0000000000');
            },
            'lowered below consumption' => function () {
                $this->recordSpend($this->userA, '5.0000000000');
                $this->declareCeiling(BudgetScope::UserDefault, '0.01', 'stop');
            },
        ];

        foreach ($configurations as $label => $arrange) {
            DB::table('cost_summaries')->delete();
            DB::table('spending_ceilings')->delete();
            app()->forgetScopedInstances();

            $arrange();

            $decision = $this->gate()->evaluate($this->userA);

            $this->assertInstanceOf(
                EnforcementDecision::class,
                $decision,
                "evaluate() must resolve '{$label}' to a decision rather than an exception"
            );
        }
    }

    // ---------------------------------------------------------------
    // A null user id evaluates the installation scope alone
    // ---------------------------------------------------------------

    #[Test]
    public function a_null_user_id_evaluates_the_installation_scope_alone(): void
    {
        // A per-user ceiling that user A has blown through, and no
        // installation ceiling at all. A null user has no user-scoped
        // ceiling to be measured against, so nothing applies.
        $this->declareCeiling(BudgetScope::UserDefault, '5.00', 'stop');
        $this->recordSpend($this->userA, '500.0000000000');

        app()->forgetScopedInstances();
        $this->gate()->admit(null, BudgetWorkKind::SystemInitiated);

        app()->forgetScopedInstances();
        $this->assertSame('allow', $this->outcomeOf($this->gate()->evaluate(null)));

        // ...while the same configuration refuses the user it does apply to,
        // so the null case is not passing for want of any ceiling at all.
        app()->forgetScopedInstances();
        $this->expectException(BudgetExceededException::class);
        $this->gate()->admit($this->userA, BudgetWorkKind::SystemInitiated);
    }

    #[Test]
    public function a_null_user_id_never_issues_a_user_scoped_ceiling_read(): void
    {
        $this->declareCeiling(BudgetScope::Installation, '500.00', 'stop');

        $queries = $this->allQueriesDuring(function () {
            $this->gate()->evaluate(null);
        });

        $userScopeReads = array_values(array_filter(
            $queries,
            fn (string $sql) => str_contains($sql, 'spending_ceilings') && str_contains($sql, 'scope_id')
        ));

        $this->assertSame(
            [],
            $userScopeReads,
            'With no user there is no user-scoped ceiling to resolve, so the user chain '
            ."must not be walked at all:\n".implode("\n", $userScopeReads)
        );
    }

    // ---------------------------------------------------------------
    // One admission per unit of work
    // ---------------------------------------------------------------

    #[Test]
    public function a_second_admit_for_the_same_scope_on_one_instance_does_not_re_evaluate(): void
    {
        $this->declareCeiling(BudgetScope::UserDefault, '100.00', 'stop');
        $this->recordSpend($this->userA, '10.0000000000');

        $gate = $this->gate();
        $gate->admit($this->userA, BudgetWorkKind::Interactive);

        // Another request crosses the ceiling while this unit of work is
        // still running. The nested call must not notice: abandoning a
        // half-built response is worse than the overshoot.
        DB::table('cost_summaries')
            ->where('entity_id', $this->userA)
            ->update(['priced_cost_total' => '900.0000000000']);

        $queries = $this->consumptionQueriesDuring(function () use ($gate) {
            $gate->admit($this->userA, BudgetWorkKind::SystemInitiated);
        });

        $this->assertSame([], $queries, 'An already-admitted scope must not be re-evaluated');
    }

    #[Test]
    public function a_new_instance_evaluates_again_so_the_next_request_or_job_sees_the_crossing(): void
    {
        $this->declareCeiling(BudgetScope::UserDefault, '100.00', 'stop');
        $this->recordSpend($this->userA, '10.0000000000');

        $first = $this->gate();
        $first->admit($this->userA, BudgetWorkKind::Interactive);

        DB::table('cost_summaries')
            ->where('entity_id', $this->userA)
            ->update(['priced_cost_total' => '900.0000000000']);

        // What a queue worker does between jobs, and what a new HTTP request
        // gets by construction.
        app()->forgetScopedInstances();
        $second = $this->gate();

        $this->assertNotSame(
            $first,
            $second,
            'BudgetGate must be bound scoped(), not singleton(); a worker keeps one container '
            .'across many jobs and flushes only scoped instances between them'
        );

        $this->expectException(BudgetExceededException::class);
        $second->admit($this->userA, BudgetWorkKind::Deferred);
    }

    #[Test]
    public function the_already_admitted_record_is_per_scope_and_not_a_blanket_pass(): void
    {
        $this->declareCeiling(BudgetScope::UserDefault, '100.00', 'stop');
        $this->recordSpend($this->userA, '10.0000000000');
        $this->recordSpend($this->userB, '900.0000000000');

        $gate = $this->gate();
        $gate->admit($this->userA, BudgetWorkKind::Interactive);

        // Admitting one scope says nothing about another.
        $this->expectException(BudgetExceededException::class);
        $gate->admit($this->userB, BudgetWorkKind::Interactive);
    }

    // ---------------------------------------------------------------
    // bcmath on plain-decimal strings, no floats
    // ---------------------------------------------------------------

    #[Test]
    public function the_class_compares_with_bcmath_and_contains_no_float_cast(): void
    {
        $source = file_get_contents((new \ReflectionClass(BudgetGate::class))->getFileName());

        $this->assertStringNotContainsString('(float)', $source);
        $this->assertStringNotContainsString('(double)', $source);
        $this->assertStringNotContainsString('floatval', $source);

        $this->assertStringContainsString(
            'bccomp',
            $source,
            'The ceiling comparison is bcmath on plain-decimal strings; a native comparison '
            .'reintroduces the float this package spent 073 keeping out'
        );
    }

    #[Test]
    public function a_ten_decimal_place_difference_is_not_rounded_away(): void
    {
        // A gap a float comparison at this magnitude would lose entirely.
        $this->declareCeiling(BudgetScope::UserDefault, '1000000.0000000001', 'stop');
        $this->recordSpend($this->userA, '1000000.0000000000');

        $this->gate()->admit($this->userA, BudgetWorkKind::Interactive);

        DB::table('cost_summaries')
            ->where('entity_id', $this->userA)
            ->update(['priced_cost_total' => '1000000.0000000001']);

        app()->forgetScopedInstances();

        $this->expectException(BudgetExceededException::class);
        $this->gate()->admit($this->userA, BudgetWorkKind::Interactive);
    }
}
