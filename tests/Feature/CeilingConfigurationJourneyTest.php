<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\SpendingCeiling;
use ClarionApp\LlmClient\Services\SpendingCeilingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;

/**
 * User Story 1 end-to-end, through the real HTTP endpoints: an operator
 * declares an installation-wide ceiling and a default per-user ceiling,
 * reads them back exactly as declared, changes one without disturbing the
 * other, and removes one; a non-operator can neither read nor write any of
 * them.
 *
 * Response-shape assumptions, resolved against the API contract:
 *
 * - PUT /budget/ceilings/{installation,user-default} returns 200 with the
 *   `ceiling` shape at the top level: id, scope_type, scope_id, amount,
 *   period_type, enforcement_mode, approach_threshold, waived.
 * - GET /budget/ceilings returns 200 with every live ceiling row under a
 *   "data" key — the same envelope GET /model-prices already uses for the
 *   other operator-only configuration list in this package. Additional
 *   sibling keys (a currency, say) are not asserted against, only "data".
 * - DELETE returns 204 with no body.
 *
 * The constraint that pins every monetary field is that it is a decimal
 * *string* and never a JSON number: a JSON number is a float on the far
 * side of every parser, and this package's bcmath-only arithmetic does not
 * stop at the HTTP boundary. `assertIsString` on each such field is the
 * whole point of those assertions, not a formality — a float-typed body
 * would satisfy a value comparison and still be wrong.
 *
 * Nothing here exercises enforcement. No usage is recorded, no work is
 * refused, and a warn-mode ceiling is asserted only to be *stored* as
 * warn-mode; that it never blocks is a later story's property.
 */
class CeilingConfigurationJourneyTest extends TestCase
{
    private User $operator;
    private User $nonOperator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = User::factory()->create();
        $this->nonOperator = User::factory()->create();

        config(['llm-client.cost.operator_user_ids' => [$this->operator->id]]);
    }

    protected function tearDown(): void
    {
        DB::table('spending_ceilings')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function base(): string
    {
        return '/api/clarion-app/llm-client/budget/ceilings';
    }

    private function installationEndpoint(): string
    {
        return $this->base().'/installation';
    }

    private function userDefaultEndpoint(): string
    {
        return $this->base().'/user-default';
    }

    private function liveRowCount(): int
    {
        return DB::table('spending_ceilings')->whereNull('deleted_at')->count();
    }

    private function totalRowCount(): int
    {
        return DB::table('spending_ceilings')->count();
    }

    /**
     * Asserts every monetary/proportional field in a ceiling body is a
     * decimal string rather than a JSON number.
     */
    private function assertCeilingBodyUsesDecimalStrings(array $body): void
    {
        $this->assertIsString($body['approach_threshold'], 'approach_threshold must be a decimal string, never a JSON number');

        if ($body['amount'] !== null) {
            $this->assertIsString($body['amount'], 'amount must be a decimal string, never a JSON number');
        }
    }

    // ---------------------------------------------------------------
    // Scenario 1 — installation ceiling stored and read back
    // ---------------------------------------------------------------

    #[Test]
    public function an_operator_sets_an_installation_ceiling_and_reads_it_back_exactly_as_declared(): void
    {
        $put = $this->actingAs($this->operator)->putJson($this->installationEndpoint(), [
            'amount' => '500.00',
            'period_type' => 'month',
            'enforcement_mode' => 'warn',
        ]);

        $put->assertStatus(200);
        $put->assertJsonStructure([
            'id',
            'scope_type',
            'scope_id',
            'amount',
            'period_type',
            'enforcement_mode',
            'approach_threshold',
            'waived',
        ]);

        $this->assertSame('installation', $put->json('scope_type'));
        $this->assertSame(SpendingCeiling::INSTALLATION_SCOPE_ID, $put->json('scope_id'));
        $this->assertSame('500.0000000000', $put->json('amount'));
        $this->assertSame('month', $put->json('period_type'));
        $this->assertSame('warn', $put->json('enforcement_mode'));
        $this->assertFalse($put->json('waived'));
        $this->assertCeilingBodyUsesDecimalStrings($put->json());

        $get = $this->actingAs($this->operator)->getJson($this->base());
        $get->assertStatus(200);

        $row = collect($get->json('data'))->firstWhere('scope_type', 'installation');

        $this->assertNotNull($row, 'The stored installation ceiling must be visible in the list');
        $this->assertSame('500.0000000000', $row['amount']);
        $this->assertSame('month', $row['period_type']);
        $this->assertSame('warn', $row['enforcement_mode']);
        $this->assertSame($put->json('approach_threshold'), $row['approach_threshold']);
        $this->assertCeilingBodyUsesDecimalStrings($row);
    }

    // ---------------------------------------------------------------
    // Scenario 2 — default per-user ceiling applies to a user with no override
    // ---------------------------------------------------------------

    #[Test]
    public function an_operator_sets_a_default_per_user_ceiling_and_it_applies_to_a_user_with_no_override(): void
    {
        $put = $this->actingAs($this->operator)->putJson($this->userDefaultEndpoint(), [
            'amount' => '25.00',
            'period_type' => 'day',
            'enforcement_mode' => 'stop',
            'approach_threshold' => '0.80',
        ]);

        $put->assertStatus(200);
        $this->assertSame('user_default', $put->json('scope_type'));
        $this->assertSame(SpendingCeiling::INSTALLATION_SCOPE_ID, $put->json('scope_id'));
        $this->assertSame('25.0000000000', $put->json('amount'));
        $this->assertSame('day', $put->json('period_type'));
        $this->assertSame('stop', $put->json('enforcement_mode'));
        $this->assertSame('0.8000', $put->json('approach_threshold'));
        $this->assertCeilingBodyUsesDecimalStrings($put->json());

        // "Applies to every user who has no override of their own" is a
        // property of resolution, not of the stored row alone.
        $someUserWithNoOverride = (string) Str::uuid();
        $resolved = app(SpendingCeilingService::class)->resolveForUser($someUserWithNoOverride);

        $this->assertNotNull($resolved, 'A user with no override of their own must resolve to the default');
        $this->assertSame($put->json('id'), $resolved->id);
        $this->assertSame('25.0000000000', $resolved->amount);
    }

    // ---------------------------------------------------------------
    // Scenario 3 — a change takes effect immediately, in isolation
    // ---------------------------------------------------------------

    #[Test]
    public function a_second_put_changes_the_stored_row_immediately_and_touches_no_other_ceiling(): void
    {
        $installation = $this->actingAs($this->operator)->putJson($this->installationEndpoint(), [
            'amount' => '500.00',
            'period_type' => 'month',
            'enforcement_mode' => 'warn',
            'approach_threshold' => '0.80',
        ]);
        $installation->assertStatus(200);

        $default = $this->actingAs($this->operator)->putJson($this->userDefaultEndpoint(), [
            'amount' => '25.00',
            'period_type' => 'day',
            'enforcement_mode' => 'stop',
            'approach_threshold' => '0.80',
        ]);
        $default->assertStatus(200);

        $changed = $this->actingAs($this->operator)->putJson($this->installationEndpoint(), [
            'amount' => '750.50',
            'period_type' => 'week',
            'enforcement_mode' => 'stop',
            'approach_threshold' => '0.90',
        ]);

        $changed->assertStatus(200);
        $this->assertSame($installation->json('id'), $changed->json('id'), 'A change updates the existing row rather than adding one');
        $this->assertSame('750.5000000000', $changed->json('amount'));
        $this->assertSame('week', $changed->json('period_type'));
        $this->assertSame('stop', $changed->json('enforcement_mode'));
        $this->assertSame('0.9000', $changed->json('approach_threshold'));

        $get = $this->actingAs($this->operator)->getJson($this->base());
        $get->assertStatus(200);
        $this->assertCount(2, $get->json('data'), 'Two ceilings were declared and two must remain');

        $installationRow = collect($get->json('data'))->firstWhere('scope_type', 'installation');
        $defaultRow = collect($get->json('data'))->firstWhere('scope_type', 'user_default');

        $this->assertSame('750.5000000000', $installationRow['amount']);

        $this->assertSame('25.0000000000', $defaultRow['amount'], 'The other ceiling must be untouched');
        $this->assertSame('day', $defaultRow['period_type']);
        $this->assertSame('stop', $defaultRow['enforcement_mode']);
        $this->assertSame('0.8000', $defaultRow['approach_threshold']);
    }

    // ---------------------------------------------------------------
    // Scenario 4 — warn mode is stored as warn mode
    // ---------------------------------------------------------------

    #[Test]
    public function a_warn_mode_ceiling_is_stored_and_read_back_as_warn_mode(): void
    {
        $put = $this->actingAs($this->operator)->putJson($this->installationEndpoint(), [
            'amount' => '100.00',
            'period_type' => 'month',
            'enforcement_mode' => 'warn',
        ]);

        $put->assertStatus(200);
        $this->assertSame('warn', $put->json('enforcement_mode'));

        $stored = SpendingCeiling::find($put->json('id'));
        $this->assertSame('warn', $stored->enforcement_mode, 'The mode is stored, not merely echoed');
    }

    // ---------------------------------------------------------------
    // Scenario 5 — a non-operator is locked out of reading and writing
    // ---------------------------------------------------------------

    #[Test]
    public function a_non_operator_cannot_write_any_ceiling_and_changes_nothing(): void
    {
        $this->actingAs($this->operator)->putJson($this->installationEndpoint(), [
            'amount' => '500.00',
            'period_type' => 'month',
            'enforcement_mode' => 'warn',
        ])->assertStatus(200);

        $before = DB::table('spending_ceilings')->orderBy('id')->get()->toArray();

        $this->actingAs($this->nonOperator)->putJson($this->installationEndpoint(), [
            'amount' => '999999.00',
            'period_type' => 'day',
            'enforcement_mode' => 'stop',
        ])->assertStatus(403);

        $this->actingAs($this->nonOperator)->putJson($this->userDefaultEndpoint(), [
            'amount' => '999999.00',
            'period_type' => 'day',
            'enforcement_mode' => 'stop',
        ])->assertStatus(403);

        $this->actingAs($this->nonOperator)->deleteJson($this->installationEndpoint())->assertStatus(403);
        $this->actingAs($this->nonOperator)->deleteJson($this->userDefaultEndpoint())->assertStatus(403);

        $after = DB::table('spending_ceilings')->orderBy('id')->get()->toArray();

        $this->assertEquals($before, $after, 'A refused write must create and change nothing');
        $this->assertSame(1, $this->liveRowCount());
    }

    #[Test]
    public function a_non_operator_cannot_read_the_ceiling_list(): void
    {
        $this->actingAs($this->operator)->putJson($this->installationEndpoint(), [
            'amount' => '500.00',
            'period_type' => 'month',
            'enforcement_mode' => 'warn',
        ])->assertStatus(200);

        $this->actingAs($this->nonOperator)->getJson($this->base())->assertStatus(403);
    }

    // ---------------------------------------------------------------
    // Waiver is a user-scoped concept only
    // ---------------------------------------------------------------

    #[Test]
    public function waiving_the_installation_or_default_ceiling_is_rejected(): void
    {
        $this->actingAs($this->operator)->putJson($this->installationEndpoint(), [
            'amount' => '500.00',
            'period_type' => 'month',
            'enforcement_mode' => 'stop',
            'waived' => true,
        ])->assertStatus(422);

        $this->actingAs($this->operator)->putJson($this->userDefaultEndpoint(), [
            'amount' => '25.00',
            'period_type' => 'day',
            'enforcement_mode' => 'stop',
            'waived' => true,
        ])->assertStatus(422);

        $this->assertSame(0, $this->totalRowCount(), 'A rejected waiver must leave no row behind');
    }

    // ---------------------------------------------------------------
    // Delete, then restore rather than duplicate
    // ---------------------------------------------------------------

    #[Test]
    public function delete_soft_deletes_and_a_later_put_restores_that_row_rather_than_duplicating_it(): void
    {
        $created = $this->actingAs($this->operator)->putJson($this->installationEndpoint(), [
            'amount' => '500.00',
            'period_type' => 'month',
            'enforcement_mode' => 'warn',
        ]);
        $created->assertStatus(200);

        $this->actingAs($this->operator)->deleteJson($this->installationEndpoint())->assertStatus(204);

        $this->assertSame(0, $this->liveRowCount(), 'The ceiling is gone');
        $this->assertSame(1, $this->totalRowCount(), 'The row survives as a soft delete rather than being erased');

        $list = $this->actingAs($this->operator)->getJson($this->base());
        $list->assertStatus(200);
        $this->assertCount(0, $list->json('data'), 'A soft-deleted ceiling must not appear in the live list');

        $restored = $this->actingAs($this->operator)->putJson($this->installationEndpoint(), [
            'amount' => '600.00',
            'period_type' => 'month',
            'enforcement_mode' => 'stop',
        ]);

        $restored->assertStatus(200);
        $this->assertSame($created->json('id'), $restored->json('id'), 'The soft-deleted row must be restored, not duplicated');
        $this->assertSame('600.0000000000', $restored->json('amount'));
        $this->assertSame(1, $this->liveRowCount());
        $this->assertSame(1, $this->totalRowCount());
    }

    #[Test]
    public function deleting_the_user_default_ceiling_returns_204_and_leaves_the_installation_ceiling_alone(): void
    {
        $installation = $this->actingAs($this->operator)->putJson($this->installationEndpoint(), [
            'amount' => '500.00',
            'period_type' => 'month',
            'enforcement_mode' => 'warn',
        ]);
        $installation->assertStatus(200);

        $this->actingAs($this->operator)->putJson($this->userDefaultEndpoint(), [
            'amount' => '25.00',
            'period_type' => 'day',
            'enforcement_mode' => 'stop',
        ])->assertStatus(200);

        $this->actingAs($this->operator)->deleteJson($this->userDefaultEndpoint())->assertStatus(204);

        $this->assertSame(1, $this->liveRowCount());

        $list = $this->actingAs($this->operator)->getJson($this->base());
        $remaining = collect($list->json('data'));

        $this->assertCount(1, $remaining);
        $this->assertSame('installation', $remaining->first()['scope_type']);
        $this->assertSame($installation->json('id'), $remaining->first()['id']);
    }

    // ---------------------------------------------------------------
    // Validation
    // ---------------------------------------------------------------

    #[Test]
    public function a_missing_zero_negative_or_over_precise_amount_is_a_422(): void
    {
        $bodies = [
            'missing' => ['period_type' => 'month', 'enforcement_mode' => 'stop'],
            'zero' => ['amount' => '0', 'period_type' => 'month', 'enforcement_mode' => 'stop'],
            'negative' => ['amount' => '-5.00', 'period_type' => 'month', 'enforcement_mode' => 'stop'],
            'non_numeric' => ['amount' => 'lots', 'period_type' => 'month', 'enforcement_mode' => 'stop'],
            'over_precise' => ['amount' => '1.123456789012', 'period_type' => 'month', 'enforcement_mode' => 'stop'],
        ];

        foreach ($bodies as $label => $body) {
            $this->actingAs($this->operator)
                ->putJson($this->installationEndpoint(), $body)
                ->assertStatus(422, "amount case '{$label}' must be rejected");
        }

        $this->assertSame(0, $this->totalRowCount(), 'A rejected write must persist nothing');
    }

    #[Test]
    public function an_unsupported_period_type_is_a_422_and_is_never_coerced(): void
    {
        foreach (['year', 'hour', 'quarter', 'Month', 'monthly'] as $periodType) {
            $this->actingAs($this->operator)
                ->putJson($this->installationEndpoint(), [
                    'amount' => '500.00',
                    'period_type' => $periodType,
                    'enforcement_mode' => 'stop',
                ])
                ->assertStatus(422, "period_type '{$periodType}' must be rejected");
        }

        $this->assertSame(0, $this->totalRowCount(), 'An unsupported period must never be silently coerced into a stored row');
    }

    #[Test]
    public function an_unknown_enforcement_mode_is_a_422(): void
    {
        foreach (['block', 'Stop', 'halt', ''] as $mode) {
            $this->actingAs($this->operator)
                ->putJson($this->installationEndpoint(), [
                    'amount' => '500.00',
                    'period_type' => 'month',
                    'enforcement_mode' => $mode,
                ])
                ->assertStatus(422, "enforcement_mode '{$mode}' must be rejected");
        }

        $this->assertSame(0, $this->totalRowCount());
    }

    #[Test]
    public function an_out_of_range_or_array_valued_approach_threshold_is_a_422(): void
    {
        $thresholds = [
            'zero' => '0',
            'negative' => '-0.5',
            'above_one' => '1.5',
            'non_numeric' => 'high',
        ];

        foreach ($thresholds as $label => $threshold) {
            $this->actingAs($this->operator)
                ->putJson($this->installationEndpoint(), [
                    'amount' => '500.00',
                    'period_type' => 'month',
                    'enforcement_mode' => 'stop',
                    'approach_threshold' => $threshold,
                ])
                ->assertStatus(422, "approach_threshold case '{$label}' must be rejected");
        }

        // Exactly one threshold per ceiling: a list of them is not a
        // shorter way of asking for several warnings, it is a 422.
        $this->actingAs($this->operator)
            ->putJson($this->installationEndpoint(), [
                'amount' => '500.00',
                'period_type' => 'month',
                'enforcement_mode' => 'stop',
                'approach_threshold' => ['0.50', '0.80'],
            ])
            ->assertStatus(422, 'An array-valued approach_threshold must be rejected');

        $this->assertSame(0, $this->totalRowCount());
    }
}
