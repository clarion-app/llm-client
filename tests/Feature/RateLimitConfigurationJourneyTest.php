<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\RateLimit;
use ClarionApp\LlmClient\Services\RateLimitService;
use Illuminate\Support\Facades\DB;

use PHPUnit\Framework\Attributes\Test;

/**
 * User Story 1 end-to-end, through the real HTTP endpoints: an operator
 * declares a general per-user request limit, reads it back exactly as
 * declared, changes it without disturbing anything else, and removes it;
 * a non-operator can neither read nor write it.
 *
 * Response-shape assumptions, resolved against the API contract:
 *
 * - PUT /rate-limits/user-default returns 200 with the `limit` shape at the
 *   top level: id, scope_type, scope_id, max_requests, window_seconds,
 *   waived.
 * - GET /rate-limits returns 200 with every live limit row under a "data"
 *   key, the same envelope GET /budget/ceilings already uses.
 * - DELETE returns 204 with no body.
 *
 * Only the user_default scope is exercised through HTTP here. The per-user
 * override surface (PUT/DELETE /rate-limits/users/{userId}) is a later
 * story's addition — RateLimitService already resolves both scope kinds
 * internally, but nothing routes to the per-user endpoints yet.
 */
class RateLimitConfigurationJourneyTest extends TestCase
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
        DB::table('rate_limits')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function base(): string
    {
        return '/api/clarion-app/llm-client/rate-limits';
    }

    private function userDefaultEndpoint(): string
    {
        return $this->base().'/user-default';
    }

    private function liveRowCount(): int
    {
        return DB::table('rate_limits')->whereNull('deleted_at')->count();
    }

    private function totalRowCount(): int
    {
        return DB::table('rate_limits')->count();
    }

    // ---------------------------------------------------------------
    // Scenario 1 — declared and read back exactly
    // ---------------------------------------------------------------

    #[Test]
    public function an_operator_sets_the_user_default_limit_and_reads_it_back_exactly_as_declared(): void
    {
        $put = $this->actingAs($this->operator)->putJson($this->userDefaultEndpoint(), [
            'max_requests' => 5,
            'window_seconds' => 60,
        ]);

        $put->assertStatus(200);
        $put->assertJsonStructure([
            'id',
            'scope_type',
            'scope_id',
            'max_requests',
            'window_seconds',
            'waived',
        ]);

        $this->assertSame('user_default', $put->json('scope_type'));
        $this->assertSame(RateLimit::INSTALLATION_SCOPE_ID, $put->json('scope_id'));
        $this->assertSame(5, $put->json('max_requests'));
        $this->assertSame(60, $put->json('window_seconds'));
        $this->assertFalse($put->json('waived'));

        $get = $this->actingAs($this->operator)->getJson($this->base());
        $get->assertStatus(200);

        $row = collect($get->json('data'))->firstWhere('scope_type', 'user_default');

        $this->assertNotNull($row, 'The stored limit must be visible in the list');
        $this->assertSame(5, $row['max_requests']);
        $this->assertSame(60, $row['window_seconds']);
    }

    // ---------------------------------------------------------------
    // Scenario 2 — a change takes effect immediately
    // ---------------------------------------------------------------

    #[Test]
    public function a_second_put_changes_the_stored_row_immediately_rather_than_adding_a_second_one(): void
    {
        $first = $this->actingAs($this->operator)->putJson($this->userDefaultEndpoint(), [
            'max_requests' => 5,
            'window_seconds' => 60,
        ]);
        $first->assertStatus(200);

        $changed = $this->actingAs($this->operator)->putJson($this->userDefaultEndpoint(), [
            'max_requests' => 50,
            'window_seconds' => 3600,
        ]);

        $changed->assertStatus(200);
        $this->assertSame($first->json('id'), $changed->json('id'), 'A change updates the existing row rather than adding one');
        $this->assertSame(50, $changed->json('max_requests'));
        $this->assertSame(3600, $changed->json('window_seconds'));

        $this->assertSame(1, $this->liveRowCount());

        $resolved = app(RateLimitService::class)->resolveForUser((string) $this->nonOperator->id);
        $this->assertNotNull($resolved);
        $this->assertSame(50, $resolved->max_requests, 'Resolution must reflect the change immediately, with no restart');
    }

    // ---------------------------------------------------------------
    // Scenario 3 — the default cannot be waived
    // ---------------------------------------------------------------

    #[Test]
    public function waiving_the_user_default_limit_is_rejected(): void
    {
        $this->actingAs($this->operator)->putJson($this->userDefaultEndpoint(), [
            'waived' => true,
        ])->assertStatus(422);

        $this->assertSame(0, $this->totalRowCount(), 'A rejected waiver must leave no row behind');
    }

    // ---------------------------------------------------------------
    // Scenario 4 — a non-operator is locked out
    // ---------------------------------------------------------------

    #[Test]
    public function a_non_operator_cannot_write_the_user_default_limit_and_changes_nothing(): void
    {
        $this->actingAs($this->operator)->putJson($this->userDefaultEndpoint(), [
            'max_requests' => 5,
            'window_seconds' => 60,
        ])->assertStatus(200);

        $before = DB::table('rate_limits')->orderBy('id')->get()->toArray();

        $this->actingAs($this->nonOperator)->putJson($this->userDefaultEndpoint(), [
            'max_requests' => 999999,
            'window_seconds' => 1,
        ])->assertStatus(403);

        $this->actingAs($this->nonOperator)->deleteJson($this->userDefaultEndpoint())->assertStatus(403);

        $after = DB::table('rate_limits')->orderBy('id')->get()->toArray();

        $this->assertEquals($before, $after, 'A refused write must create and change nothing');
        $this->assertSame(1, $this->liveRowCount());
    }

    #[Test]
    public function a_non_operator_cannot_read_the_limit_list(): void
    {
        $this->actingAs($this->operator)->putJson($this->userDefaultEndpoint(), [
            'max_requests' => 5,
            'window_seconds' => 60,
        ])->assertStatus(200);

        $this->actingAs($this->nonOperator)->getJson($this->base())->assertStatus(403);
    }

    // ---------------------------------------------------------------
    // Scenario 5 — delete, then restore rather than duplicate
    // ---------------------------------------------------------------

    #[Test]
    public function delete_soft_deletes_and_a_later_put_restores_that_row_rather_than_duplicating_it(): void
    {
        $created = $this->actingAs($this->operator)->putJson($this->userDefaultEndpoint(), [
            'max_requests' => 5,
            'window_seconds' => 60,
        ]);
        $created->assertStatus(200);

        $this->actingAs($this->operator)->deleteJson($this->userDefaultEndpoint())->assertStatus(204);

        $this->assertSame(0, $this->liveRowCount(), 'The limit is gone');
        $this->assertSame(1, $this->totalRowCount(), 'The row survives as a soft delete rather than being erased');

        $list = $this->actingAs($this->operator)->getJson($this->base());
        $list->assertStatus(200);
        $this->assertCount(0, $list->json('data'), 'A soft-deleted limit must not appear in the live list');

        $this->assertNull(
            app(RateLimitService::class)->resolveForUser((string) $this->nonOperator->id),
            'With no rate_limits row at all, no user is refused'
        );

        $restored = $this->actingAs($this->operator)->putJson($this->userDefaultEndpoint(), [
            'max_requests' => 10,
            'window_seconds' => 120,
        ]);

        $restored->assertStatus(200);
        $this->assertSame($created->json('id'), $restored->json('id'), 'The soft-deleted row must be restored, not duplicated');
        $this->assertSame(10, $restored->json('max_requests'));
        $this->assertSame(1, $this->liveRowCount());
        $this->assertSame(1, $this->totalRowCount());
    }

    // ---------------------------------------------------------------
    // Validation
    // ---------------------------------------------------------------

    #[Test]
    public function a_missing_zero_negative_or_non_integer_max_requests_is_a_422(): void
    {
        $bodies = [
            'missing' => ['window_seconds' => 60],
            'zero' => ['max_requests' => 0, 'window_seconds' => 60],
            'negative' => ['max_requests' => -5, 'window_seconds' => 60],
            'non_numeric' => ['max_requests' => 'lots', 'window_seconds' => 60],
        ];

        foreach ($bodies as $label => $body) {
            $this->actingAs($this->operator)
                ->putJson($this->userDefaultEndpoint(), $body)
                ->assertStatus(422, "max_requests case '{$label}' must be rejected");
        }

        $this->assertSame(0, $this->totalRowCount(), 'A rejected write must persist nothing');
    }

    #[Test]
    public function a_missing_zero_negative_or_non_integer_window_seconds_is_a_422(): void
    {
        $bodies = [
            'missing' => ['max_requests' => 5],
            'zero' => ['max_requests' => 5, 'window_seconds' => 0],
            'negative' => ['max_requests' => 5, 'window_seconds' => -60],
            'non_numeric' => ['max_requests' => 5, 'window_seconds' => 'an hour'],
        ];

        foreach ($bodies as $label => $body) {
            $this->actingAs($this->operator)
                ->putJson($this->userDefaultEndpoint(), $body)
                ->assertStatus(422, "window_seconds case '{$label}' must be rejected");
        }

        $this->assertSame(0, $this->totalRowCount(), 'A rejected write must persist nothing');
    }
}
