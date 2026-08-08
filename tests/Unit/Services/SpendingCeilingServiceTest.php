<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use Tests\TestCase;
use ClarionApp\LlmClient\Models\SpendingCeiling;
use ClarionApp\LlmClient\Services\SpendingCeilingService;
use ClarionApp\LlmClient\Support\CalendarPeriod;
use ClarionApp\LlmClient\ValueObjects\BudgetScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for SpendingCeilingService — the sole write path for
 * spending_ceilings rows, covering the validation rules and the state
 * transitions the data model specifies:
 *
 *   upsert(BudgetScope $scopeType, string $scopeId, array $attributes): SpendingCeiling
 *   remove(BudgetScope $scopeType, string $scopeId): void
 *   resolveForUser(string $userId): ?SpendingCeiling
 *   resolveInstallation(): ?SpendingCeiling
 *
 * Two properties here are load-bearing rather than incidental:
 *
 *  - There is no unique constraint on (scope_type, scope_id): the table
 *    carries a plain index, because SoftDeletes and a unique constraint
 *    interact badly in both directions. "Exactly one live row per scope" is
 *    therefore a property of this service and of nothing else, which is why
 *    both the second-upsert case and the soft-deleted-row case assert the
 *    live row count directly rather than trusting the schema.
 *  - An unsupported period type or enforcement mode is *rejected*, never
 *    silently coerced to a supported one. A coerced period would enforce a
 *    limit over a window the operator never declared.
 *
 * Rejections are expected as \InvalidArgumentException — the convention
 * already used for invalid inputs elsewhere in this package's services
 * (MemoryService, EpisodicMemorySearchService, MetricsRecorder). The HTTP
 * layer maps a rejected write to a 422 of its own; that mapping is asserted
 * in CeilingConfigurationJourneyTest, not here.
 *
 * Every monetary assertion compares plain-decimal strings. No float is
 * formed anywhere in this file.
 */
class SpendingCeilingServiceTest extends TestCase
{
    private string $userA;
    private string $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userA = (string) Str::uuid();
        $this->userB = (string) Str::uuid();
    }

    protected function tearDown(): void
    {
        DB::table('spending_ceilings')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function service(): SpendingCeilingService
    {
        return new SpendingCeilingService();
    }

    /**
     * A complete, valid attribute set. Named ceilingAttributes() rather
     * than something shorter because Orchestra's TestCase already declares
     * public helpers of its own and a clashing private override is a fatal
     * error, not a test failure.
     */
    private function ceilingAttributes(array $overrides = []): array
    {
        return array_merge([
            'amount' => '500.00',
            'period_type' => 'month',
            'enforcement_mode' => 'warn',
            'approach_threshold' => '0.75',
        ], $overrides);
    }

    private function liveRowCount(): int
    {
        return DB::table('spending_ceilings')->whereNull('deleted_at')->count();
    }

    private function totalRowCount(): int
    {
        return DB::table('spending_ceilings')->count();
    }

    private function sentinel(): string
    {
        return SpendingCeiling::INSTALLATION_SCOPE_ID;
    }

    // ---------------------------------------------------------------
    // Creation
    // ---------------------------------------------------------------

    #[Test]
    public function an_upsert_for_a_scope_with_no_existing_row_creates_it_exactly_as_declared(): void
    {
        $ceiling = $this->service()->upsert(
            BudgetScope::Installation,
            $this->sentinel(),
            $this->ceilingAttributes([
                'amount' => '500.00',
                'period_type' => 'month',
                'enforcement_mode' => 'warn',
                'approach_threshold' => '0.75',
            ]),
        );

        $this->assertInstanceOf(SpendingCeiling::class, $ceiling);
        $this->assertSame('installation', $ceiling->scope_type);
        $this->assertSame($this->sentinel(), $ceiling->scope_id);
        $this->assertSame('500.0000000000', $ceiling->amount);
        $this->assertSame('month', $ceiling->period_type);
        $this->assertSame('warn', $ceiling->enforcement_mode);
        $this->assertSame('0.7500', $ceiling->approach_threshold);
        $this->assertFalse($ceiling->waived);

        $this->assertSame(1, $this->liveRowCount());
    }

    #[Test]
    public function a_user_default_row_carries_the_installation_sentinel_scope_id_and_never_null(): void
    {
        $service = $this->service();

        $installation = $service->upsert(BudgetScope::Installation, $this->sentinel(), $this->ceilingAttributes());
        $default = $service->upsert(BudgetScope::UserDefault, $this->sentinel(), $this->ceilingAttributes([
            'amount' => '25.00',
            'period_type' => 'day',
        ]));

        $this->assertSame($this->sentinel(), $installation->scope_id);
        $this->assertSame($this->sentinel(), $default->scope_id);
        $this->assertNotNull($installation->scope_id);
        $this->assertNotNull($default->scope_id);

        // Two different scope kinds sharing one scope_id are two distinct
        // live rows, not one overwriting the other.
        $this->assertSame(2, $this->liveRowCount());
    }

    // ---------------------------------------------------------------
    // Update rather than duplicate
    // ---------------------------------------------------------------

    #[Test]
    public function a_second_upsert_for_the_same_scope_updates_that_row_rather_than_inserting_a_second(): void
    {
        $service = $this->service();

        $first = $service->upsert(BudgetScope::Installation, $this->sentinel(), $this->ceilingAttributes([
            'amount' => '500.00',
            'period_type' => 'month',
            'enforcement_mode' => 'warn',
            'approach_threshold' => '0.75',
        ]));

        $second = $service->upsert(BudgetScope::Installation, $this->sentinel(), $this->ceilingAttributes([
            'amount' => '750.50',
            'period_type' => 'week',
            'enforcement_mode' => 'stop',
            'approach_threshold' => '0.90',
        ]));

        $this->assertSame($first->id, $second->id, 'The second upsert must update the same row, not create a new one');
        $this->assertSame(1, $this->liveRowCount());
        $this->assertSame(1, $this->totalRowCount(), 'No orphaned duplicate may be left behind, soft-deleted or otherwise');

        $reread = SpendingCeiling::find($first->id);
        $this->assertSame('750.5000000000', $reread->amount);
        $this->assertSame('week', $reread->period_type);
        $this->assertSame('stop', $reread->enforcement_mode);
        $this->assertSame('0.9000', $reread->approach_threshold);
    }

    #[Test]
    public function changing_one_scope_leaves_every_other_scope_untouched(): void
    {
        $service = $this->service();

        $installation = $service->upsert(BudgetScope::Installation, $this->sentinel(), $this->ceilingAttributes([
            'amount' => '500.00',
            'period_type' => 'month',
            'enforcement_mode' => 'warn',
        ]));
        $default = $service->upsert(BudgetScope::UserDefault, $this->sentinel(), $this->ceilingAttributes([
            'amount' => '25.00',
            'period_type' => 'day',
            'enforcement_mode' => 'stop',
        ]));

        $service->upsert(BudgetScope::UserDefault, $this->sentinel(), $this->ceilingAttributes([
            'amount' => '30.00',
            'period_type' => 'day',
            'enforcement_mode' => 'stop',
        ]));

        $installationReread = SpendingCeiling::find($installation->id);
        $this->assertSame('500.0000000000', $installationReread->amount);
        $this->assertSame('month', $installationReread->period_type);
        $this->assertSame('warn', $installationReread->enforcement_mode);

        $defaultReread = SpendingCeiling::find($default->id);
        $this->assertSame('30.0000000000', $defaultReread->amount);
    }

    // ---------------------------------------------------------------
    // Soft delete and restore
    // ---------------------------------------------------------------

    #[Test]
    public function remove_is_a_soft_delete(): void
    {
        $service = $this->service();

        $ceiling = $service->upsert(BudgetScope::Installation, $this->sentinel(), $this->ceilingAttributes());

        $service->remove(BudgetScope::Installation, $this->sentinel());

        $this->assertSame(0, $this->liveRowCount(), 'The row must no longer be live');
        $this->assertSame(1, $this->totalRowCount(), 'The row must still exist, soft-deleted rather than erased');

        $trashed = SpendingCeiling::withTrashed()->find($ceiling->id);
        $this->assertNotNull($trashed);
        $this->assertNotNull($trashed->deleted_at);
    }

    #[Test]
    public function an_upsert_for_a_scope_whose_only_row_is_soft_deleted_restores_and_updates_it(): void
    {
        $service = $this->service();

        $original = $service->upsert(BudgetScope::UserDefault, $this->sentinel(), $this->ceilingAttributes([
            'amount' => '25.00',
            'period_type' => 'day',
            'enforcement_mode' => 'stop',
        ]));

        $service->remove(BudgetScope::UserDefault, $this->sentinel());

        $restored = $service->upsert(BudgetScope::UserDefault, $this->sentinel(), $this->ceilingAttributes([
            'amount' => '40.00',
            'period_type' => 'week',
            'enforcement_mode' => 'warn',
        ]));

        $this->assertSame($original->id, $restored->id, 'The soft-deleted row must be restored, not duplicated');
        $this->assertNull($restored->deleted_at);
        $this->assertSame('40.0000000000', $restored->amount);
        $this->assertSame('week', $restored->period_type);
        $this->assertSame('warn', $restored->enforcement_mode);

        $this->assertSame(1, $this->liveRowCount());
        $this->assertSame(1, $this->totalRowCount());
    }

    // ---------------------------------------------------------------
    // approach_threshold
    // ---------------------------------------------------------------

    #[Test]
    public function approach_threshold_defaults_to_the_configured_value_when_omitted(): void
    {
        config(['llm-client.budget.default_approach_threshold' => 0.80]);

        $attributes = $this->ceilingAttributes();
        unset($attributes['approach_threshold']);

        $ceiling = $this->service()->upsert(BudgetScope::Installation, $this->sentinel(), $attributes);

        $this->assertSame('0.8000', $ceiling->approach_threshold);
    }

    #[Test]
    public function approach_threshold_reads_the_config_value_rather_than_a_hardcoded_default(): void
    {
        config(['llm-client.budget.default_approach_threshold' => 0.65]);

        $attributes = $this->ceilingAttributes();
        unset($attributes['approach_threshold']);

        $ceiling = $this->service()->upsert(BudgetScope::Installation, $this->sentinel(), $attributes);

        $this->assertSame('0.6500', $ceiling->approach_threshold);
    }

    #[Test]
    public function an_approach_threshold_of_exactly_one_is_accepted(): void
    {
        $ceiling = $this->service()->upsert(
            BudgetScope::Installation,
            $this->sentinel(),
            $this->ceilingAttributes(['approach_threshold' => '1']),
        );

        $this->assertSame('1.0000', $ceiling->approach_threshold);
    }

    /**
     * The range is (0, 1] — zero is excluded (a threshold of zero would
     * warn before a single request) and anything above one is excluded (a
     * threshold above the ceiling could never be crossed before the
     * ceiling itself).
     */
    #[Test]
    public function an_approach_threshold_outside_the_open_closed_zero_to_one_range_is_rejected(): void
    {
        foreach (['0', '0.0000', '-0.5', '1.0001', '2', 'abc'] as $threshold) {
            $this->assertUpsertRejected(
                BudgetScope::Installation,
                $this->sentinel(),
                $this->ceilingAttributes(['approach_threshold' => $threshold]),
                "approach_threshold '{$threshold}' must be rejected",
            );
        }
    }

    // ---------------------------------------------------------------
    // amount
    // ---------------------------------------------------------------

    #[Test]
    public function amount_is_required_when_the_ceiling_is_not_waived(): void
    {
        $attributes = $this->ceilingAttributes();
        unset($attributes['amount']);

        $this->assertUpsertRejected(
            BudgetScope::Installation,
            $this->sentinel(),
            $attributes,
            'A non-waived ceiling with no amount must be rejected',
        );
    }

    #[Test]
    public function a_zero_negative_non_numeric_or_over_precise_amount_is_rejected(): void
    {
        $cases = [
            '0',
            '0.0000000000',
            '-1.00',
            '-0.0000000001',
            'lots',
            '',
            '1.123456789012',
        ];

        foreach ($cases as $amount) {
            $this->assertUpsertRejected(
                BudgetScope::Installation,
                $this->sentinel(),
                $this->ceilingAttributes(['amount' => $amount]),
                "amount '{$amount}' must be rejected",
            );
        }
    }

    #[Test]
    public function an_amount_at_exactly_ten_decimal_places_is_accepted(): void
    {
        $ceiling = $this->service()->upsert(
            BudgetScope::Installation,
            $this->sentinel(),
            $this->ceilingAttributes(['amount' => '1.1234567890']),
        );

        $this->assertSame('1.1234567890', $ceiling->amount);
    }

    /**
     * The "unless waived" half of the amount rule. Waiver semantics
     * themselves — precedence, isolation, and the HTTP surface — belong to
     * the per-user story; all that is asserted here is that a waived
     * ceiling is the one case in which a null amount is legitimate.
     */
    #[Test]
    public function a_waived_user_scoped_ceiling_may_carry_a_null_amount(): void
    {
        $ceiling = $this->service()->upsert(
            BudgetScope::User,
            $this->userA,
            [
                'waived' => true,
                'amount' => null,
                'period_type' => 'month',
                'enforcement_mode' => 'stop',
            ],
        );

        $this->assertTrue($ceiling->waived);
        $this->assertNull($ceiling->amount);
        $this->assertSame($this->userA, $ceiling->scope_id);
    }

    // ---------------------------------------------------------------
    // period_type and enforcement_mode
    // ---------------------------------------------------------------

    #[Test]
    public function every_calendar_period_type_is_accepted(): void
    {
        $service = $this->service();

        foreach (CalendarPeriod::TYPES as $type) {
            $ceiling = $service->upsert(
                BudgetScope::Installation,
                $this->sentinel(),
                $this->ceilingAttributes(['period_type' => $type]),
            );

            $this->assertSame($type, $ceiling->period_type);
        }
    }

    #[Test]
    public function a_period_type_outside_the_calendar_period_set_is_rejected_never_coerced(): void
    {
        foreach (['year', 'hour', 'monthly', 'Month', '', 'quarter'] as $periodType) {
            $this->assertUpsertRejected(
                BudgetScope::Installation,
                $this->sentinel(),
                $this->ceilingAttributes(['period_type' => $periodType]),
                "period_type '{$periodType}' must be rejected rather than coerced",
            );
        }

        $this->assertSame(0, $this->totalRowCount(), 'A rejected period type must leave no row behind at all');
    }

    #[Test]
    public function both_enforcement_modes_are_accepted(): void
    {
        $service = $this->service();

        foreach (['warn', 'stop'] as $mode) {
            $ceiling = $service->upsert(
                BudgetScope::Installation,
                $this->sentinel(),
                $this->ceilingAttributes(['enforcement_mode' => $mode]),
            );

            $this->assertSame($mode, $ceiling->enforcement_mode);
        }
    }

    #[Test]
    public function an_unknown_enforcement_mode_is_rejected(): void
    {
        foreach (['block', 'Warn', 'stop_all', ''] as $mode) {
            $this->assertUpsertRejected(
                BudgetScope::Installation,
                $this->sentinel(),
                $this->ceilingAttributes(['enforcement_mode' => $mode]),
                "enforcement_mode '{$mode}' must be rejected",
            );
        }
    }

    // ---------------------------------------------------------------
    // Resolution
    // ---------------------------------------------------------------

    #[Test]
    public function resolve_for_user_returns_null_when_no_ceiling_of_any_kind_exists(): void
    {
        $this->assertNull($this->service()->resolveForUser($this->userA));
    }

    #[Test]
    public function resolve_installation_returns_null_when_no_installation_ceiling_exists(): void
    {
        $this->assertNull($this->service()->resolveInstallation());
    }

    #[Test]
    public function resolve_for_user_falls_through_to_the_user_default_row_for_a_user_with_no_override(): void
    {
        $service = $this->service();

        $default = $service->upsert(BudgetScope::UserDefault, $this->sentinel(), $this->ceilingAttributes([
            'amount' => '25.00',
            'period_type' => 'day',
            'enforcement_mode' => 'stop',
        ]));

        $resolved = $service->resolveForUser($this->userA);

        $this->assertNotNull($resolved);
        $this->assertSame($default->id, $resolved->id);
        $this->assertSame('user_default', $resolved->scope_type);
        $this->assertSame('25.0000000000', $resolved->amount);
    }

    #[Test]
    public function resolve_for_user_never_returns_the_installation_ceiling(): void
    {
        $service = $this->service();

        $service->upsert(BudgetScope::Installation, $this->sentinel(), $this->ceilingAttributes([
            'amount' => '500.00',
        ]));

        $this->assertNull(
            $service->resolveForUser($this->userA),
            'The installation ceiling is a separate applicable ceiling, never the user chain\'s answer',
        );
    }

    #[Test]
    public function resolve_installation_returns_the_installation_row_and_never_the_user_default_row(): void
    {
        $service = $this->service();

        $service->upsert(BudgetScope::UserDefault, $this->sentinel(), $this->ceilingAttributes([
            'amount' => '25.00',
            'period_type' => 'day',
        ]));

        $this->assertNull(
            $service->resolveInstallation(),
            'A user_default row shares the sentinel scope id but is not the installation ceiling',
        );

        $installation = $service->upsert(BudgetScope::Installation, $this->sentinel(), $this->ceilingAttributes([
            'amount' => '500.00',
            'period_type' => 'month',
        ]));

        $resolved = $service->resolveInstallation();

        $this->assertNotNull($resolved);
        $this->assertSame($installation->id, $resolved->id);
        $this->assertSame('installation', $resolved->scope_type);
        $this->assertSame('500.0000000000', $resolved->amount);
    }

    #[Test]
    public function a_removed_ceiling_stops_resolving(): void
    {
        $service = $this->service();

        $service->upsert(BudgetScope::Installation, $this->sentinel(), $this->ceilingAttributes());
        $service->upsert(BudgetScope::UserDefault, $this->sentinel(), $this->ceilingAttributes([
            'amount' => '25.00',
            'period_type' => 'day',
        ]));

        $this->assertNotNull($service->resolveInstallation());
        $this->assertNotNull($service->resolveForUser($this->userA));

        $service->remove(BudgetScope::Installation, $this->sentinel());
        $service->remove(BudgetScope::UserDefault, $this->sentinel());

        $this->assertNull($service->resolveInstallation(), 'A soft-deleted ceiling must not resolve');
        $this->assertNull($service->resolveForUser($this->userA), 'A soft-deleted ceiling must not resolve');
    }

    // ---------------------------------------------------------------
    // The user-scope branch: overrides, waivers, and their isolation
    // ---------------------------------------------------------------

    /**
     * The whole point of a per-user override is that it wins. A resolution
     * that consulted the default even when a user row existed would ignore
     * every raise and every lower an operator ever applied, silently — the
     * table would look correctly configured and the enforcement would be
     * against the wrong number.
     */
    #[Test]
    public function resolve_for_user_returns_the_users_own_override_and_does_not_fall_through_to_the_default(): void
    {
        $service = $this->service();

        $service->upsert(BudgetScope::UserDefault, $this->sentinel(), $this->ceilingAttributes([
            'amount' => '25.00',
            'period_type' => 'day',
            'enforcement_mode' => 'stop',
        ]));

        $override = $service->upsert(BudgetScope::User, $this->userA, $this->ceilingAttributes([
            'amount' => '100.00',
            'period_type' => 'month',
            'enforcement_mode' => 'warn',
        ]));

        $resolved = $service->resolveForUser($this->userA);

        $this->assertNotNull($resolved);
        $this->assertSame($override->id, $resolved->id, "A user's own override wins over the default");
        $this->assertSame('user', $resolved->scope_type);
        $this->assertSame($this->userA, $resolved->scope_id);
        $this->assertSame('100.0000000000', $resolved->amount);
        $this->assertSame('month', $resolved->period_type);
        $this->assertSame('warn', $resolved->enforcement_mode);
    }

    #[Test]
    public function resolve_for_user_returns_the_default_for_a_user_whose_neighbour_has_an_override(): void
    {
        $service = $this->service();

        $default = $service->upsert(BudgetScope::UserDefault, $this->sentinel(), $this->ceilingAttributes([
            'amount' => '25.00',
            'period_type' => 'day',
            'enforcement_mode' => 'stop',
        ]));

        $service->upsert(BudgetScope::User, $this->userA, $this->ceilingAttributes([
            'amount' => '100.00',
            'period_type' => 'month',
            'enforcement_mode' => 'warn',
        ]));

        $resolved = $service->resolveForUser($this->userB);

        $this->assertNotNull($resolved);
        $this->assertSame($default->id, $resolved->id, "One user's override must not reach another user");
        $this->assertSame('25.0000000000', $resolved->amount);
        $this->assertSame('day', $resolved->period_type);
    }

    #[Test]
    public function resolve_for_user_returns_null_when_neither_an_override_nor_a_default_exists(): void
    {
        $service = $this->service();

        $service->upsert(BudgetScope::User, $this->userA, $this->ceilingAttributes([
            'amount' => '100.00',
        ]));

        $this->assertNull($service->resolveForUser($this->userB));
    }

    /**
     * A waiver is the *absence* of a user-scoped ceiling, not a ceiling of
     * unlimited size and not a request to fall back to the default. Falling
     * back would make a waiver mean nothing at all wherever a default is
     * configured, which is every installation that would want one.
     */
    #[Test]
    public function a_waived_user_row_resolves_to_no_user_ceiling_and_never_falls_back_to_the_default(): void
    {
        $service = $this->service();

        $service->upsert(BudgetScope::UserDefault, $this->sentinel(), $this->ceilingAttributes([
            'amount' => '25.00',
            'period_type' => 'day',
            'enforcement_mode' => 'stop',
        ]));

        $service->upsert(BudgetScope::User, $this->userA, [
            'waived' => true,
            'amount' => null,
            'period_type' => 'day',
            'enforcement_mode' => 'stop',
        ]);

        $this->assertNull($service->resolveForUser($this->userA), 'A waived user has no user-scoped ceiling');
        $this->assertNotNull($service->resolveForUser($this->userB), 'Every other user stays on the default');
    }

    /**
     * FR-010, at the level where it is decided. resolveInstallation() must
     * not consult the user chain at all, so no arrangement of user rows —
     * waived or not, matching the caller or not — can change its answer.
     */
    #[Test]
    public function resolve_installation_is_unaffected_by_any_user_row_waived_or_otherwise(): void
    {
        $service = $this->service();

        $installation = $service->upsert(BudgetScope::Installation, $this->sentinel(), $this->ceilingAttributes([
            'amount' => '500.00',
            'period_type' => 'month',
            'enforcement_mode' => 'stop',
        ]));

        $before = $service->resolveInstallation();
        $this->assertNotNull($before);
        $this->assertSame($installation->id, $before->id);

        $service->upsert(BudgetScope::User, $this->userA, [
            'waived' => true,
            'amount' => null,
            'period_type' => 'month',
            'enforcement_mode' => 'stop',
        ]);
        $service->upsert(BudgetScope::User, $this->userB, $this->ceilingAttributes([
            'amount' => '5.00',
            'period_type' => 'month',
            'enforcement_mode' => 'stop',
        ]));

        $after = $service->resolveInstallation();

        $this->assertNotNull($after, 'A user-scoped waiver can never remove the installation-wide ceiling');
        $this->assertSame($installation->id, $after->id);
        $this->assertSame('500.0000000000', $after->amount);
        $this->assertSame('stop', $after->enforcement_mode);
        $this->assertFalse((bool) $after->waived);
    }

    #[Test]
    public function a_waiver_is_accepted_only_for_the_user_scope(): void
    {
        $waiver = [
            'waived' => true,
            'amount' => null,
            'period_type' => 'month',
            'enforcement_mode' => 'stop',
        ];

        $this->assertUpsertRejected(
            BudgetScope::Installation,
            $this->sentinel(),
            $waiver,
            'The installation-wide ceiling cannot be waived',
        );

        $this->assertUpsertRejected(
            BudgetScope::UserDefault,
            $this->sentinel(),
            $waiver,
            'The default per-user ceiling cannot be waived',
        );

        $accepted = $this->service()->upsert(BudgetScope::User, $this->userA, $waiver);

        $this->assertTrue($accepted->waived);
        $this->assertNull($accepted->amount);
    }

    #[Test]
    public function a_waiver_carrying_an_amount_is_rejected(): void
    {
        $this->assertUpsertRejected(
            BudgetScope::User,
            $this->userA,
            [
                'waived' => true,
                'amount' => '100.00',
                'period_type' => 'month',
                'enforcement_mode' => 'stop',
            ],
            'A waived ceiling carries no amount, so supplying one is a rejection rather than a value that is ignored',
        );
    }

    #[Test]
    public function a_user_ceiling_that_is_not_waived_still_requires_a_positive_amount(): void
    {
        $missing = [
            'period_type' => 'month',
            'enforcement_mode' => 'stop',
            'waived' => false,
        ];

        $this->assertUpsertRejected(
            BudgetScope::User,
            $this->userA,
            $missing,
            'A non-waived user override with no amount must be rejected',
        );

        foreach (['0', '-1.00', 'lots'] as $amount) {
            $this->assertUpsertRejected(
                BudgetScope::User,
                $this->userA,
                array_merge($missing, ['amount' => $amount]),
                "A non-waived user override with amount '{$amount}' must be rejected",
            );
        }
    }

    /**
     * FR-011: removing an override is a soft delete of the user row, after
     * which the user is governed by the default again — not by nothing.
     */
    #[Test]
    public function removing_a_users_override_soft_deletes_it_and_resolution_falls_back_to_the_default(): void
    {
        $service = $this->service();

        $default = $service->upsert(BudgetScope::UserDefault, $this->sentinel(), $this->ceilingAttributes([
            'amount' => '25.00',
            'period_type' => 'day',
            'enforcement_mode' => 'stop',
        ]));

        $override = $service->upsert(BudgetScope::User, $this->userA, $this->ceilingAttributes([
            'amount' => '100.00',
            'period_type' => 'month',
        ]));

        $this->assertSame($override->id, $service->resolveForUser($this->userA)->id);

        $service->remove(BudgetScope::User, $this->userA);

        $reverted = $service->resolveForUser($this->userA);

        $this->assertNotNull($reverted, 'Removing an override reverts the user to the default, not to no ceiling');
        $this->assertSame($default->id, $reverted->id);
        $this->assertSame('25.0000000000', $reverted->amount);

        $trashed = SpendingCeiling::withTrashed()->find($override->id);
        $this->assertNotNull($trashed, 'The override survives as history rather than being erased');
        $this->assertNotNull($trashed->deleted_at);
    }

    #[Test]
    public function re_adding_an_override_for_a_user_whose_row_was_removed_restores_that_row_rather_than_duplicating_it(): void
    {
        $service = $this->service();

        $original = $service->upsert(BudgetScope::User, $this->userA, $this->ceilingAttributes([
            'amount' => '100.00',
            'period_type' => 'month',
            'enforcement_mode' => 'stop',
        ]));

        $service->remove(BudgetScope::User, $this->userA);

        $restored = $service->upsert(BudgetScope::User, $this->userA, [
            'waived' => true,
            'amount' => null,
            'period_type' => 'month',
            'enforcement_mode' => 'stop',
        ]);

        $this->assertSame($original->id, $restored->id, 'The soft-deleted override must be restored, not duplicated');
        $this->assertNull($restored->deleted_at);
        $this->assertTrue($restored->waived);
        $this->assertNull($restored->amount);

        $this->assertSame(1, $this->liveRowCount());
        $this->assertSame(1, $this->totalRowCount());
    }

    /**
     * SC-010 at the level of stored configuration: raising one user, lowering
     * a second, and waiving a third leaves the fourth — and each other — on
     * exactly the value they had.
     */
    #[Test]
    public function raising_lowering_and_waiving_three_users_leaves_a_fourth_on_the_unchanged_default(): void
    {
        $service = $this->service();
        $userC = (string) Str::uuid();
        $userD = (string) Str::uuid();

        $default = $service->upsert(BudgetScope::UserDefault, $this->sentinel(), $this->ceilingAttributes([
            'amount' => '25.00',
            'period_type' => 'month',
            'enforcement_mode' => 'stop',
        ]));

        $service->upsert(BudgetScope::User, $this->userA, $this->ceilingAttributes([
            'amount' => '100.00',
            'period_type' => 'month',
            'enforcement_mode' => 'stop',
        ]));
        $service->upsert(BudgetScope::User, $this->userB, $this->ceilingAttributes([
            'amount' => '5.00',
            'period_type' => 'month',
            'enforcement_mode' => 'stop',
        ]));
        $service->upsert(BudgetScope::User, $userC, [
            'waived' => true,
            'amount' => null,
            'period_type' => 'month',
            'enforcement_mode' => 'stop',
        ]);

        $this->assertSame('100.0000000000', $service->resolveForUser($this->userA)->amount);
        $this->assertSame('5.0000000000', $service->resolveForUser($this->userB)->amount);
        $this->assertNull($service->resolveForUser($userC));

        $untouched = $service->resolveForUser($userD);
        $this->assertNotNull($untouched, 'A user nobody touched is still governed by the default');
        $this->assertSame($default->id, $untouched->id);
        $this->assertSame('25.0000000000', $untouched->amount);

        // And the default row itself was never rewritten on the way.
        $defaultReread = SpendingCeiling::find($default->id);
        $this->assertSame('25.0000000000', $defaultReread->amount);
        $this->assertFalse((bool) $defaultReread->waived);
    }

    // ---------------------------------------------------------------
    // Shared rejection assertion
    // ---------------------------------------------------------------

    private function assertUpsertRejected(BudgetScope $scopeType, string $scopeId, array $attributes, string $message): void
    {
        $liveBefore = $this->liveRowCount();
        $totalBefore = $this->totalRowCount();

        try {
            $this->service()->upsert($scopeType, $scopeId, $attributes);
            $this->fail($message);
        } catch (\InvalidArgumentException $e) {
            $this->assertNotSame('', $e->getMessage(), 'A rejection must say what was wrong');
        }

        $this->assertSame($liveBefore, $this->liveRowCount(), 'A rejected upsert must change no live row');
        $this->assertSame($totalBefore, $this->totalRowCount(), 'A rejected upsert must write nothing at all');
    }
}
