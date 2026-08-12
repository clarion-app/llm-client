<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Exceptions\RateLimitExceededException;
use ClarionApp\LlmClient\Models\RateLimit;
use ClarionApp\LlmClient\Services\RateLimitGate;
use ClarionApp\LlmClient\Services\RateLimitService;
use ClarionApp\LlmClient\ValueObjects\BudgetWorkKind;
use ClarionApp\LlmClient\ValueObjects\RateLimitScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;

/**
 * An operator raises, lowers, or waives the rate limit for one specific
 * user without changing what applies to anyone else, and the change is
 * live on that user's very next request — no restart, no window reset.
 *
 * The PUT/DELETE calls that declare each override go through the real
 * HTTP endpoints, exactly as the general limit's own configuration
 * journey does. Whether an override actually changed anything is then
 * checked as a fact about enforcement, not about the stored row: each
 * scenario drives RateLimitGate::admit() directly, forgetting scoped
 * container instances between calls so each attempt is a genuinely
 * separate instance, mirroring a separate HTTP request or job exactly as
 * RateLimitGate's own admitted-once memo requires.
 *
 * Four users are used throughout — A, B, C, and D — matching the
 * four-user shape of the acceptance scenarios: A is raised, B is
 * lowered, C is waived, and D is never touched at all, so D's
 * enforcement staying pinned to the user_default limit throughout is
 * itself part of what each scenario proves.
 */
class RateLimitPerUserOverrideJourneyTest extends TestCase
{
    private User $operator;
    private User $nonOperator;

    private string $userA;
    private string $userB;
    private string $userC;
    private string $userD;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = User::factory()->create();
        $this->nonOperator = User::factory()->create();

        config(['llm-client.cost.operator_user_ids' => [$this->operator->id]]);

        $this->userA = (string) Str::uuid();
        $this->userB = (string) Str::uuid();
        $this->userC = (string) Str::uuid();
        $this->userD = (string) Str::uuid();
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

    private function userEndpoint(string $userId): string
    {
        return $this->base()."/users/{$userId}";
    }

    private function userDefaultEndpoint(): string
    {
        return $this->base().'/user-default';
    }

    private function declareUserDefault(int $maxRequests, int $windowSeconds): void
    {
        $response = $this->actingAs($this->operator)->putJson($this->userDefaultEndpoint(), [
            'max_requests' => $maxRequests,
            'window_seconds' => $windowSeconds,
        ]);

        $response->assertStatus(200);
    }

    /**
     * A single admission attempt through RateLimitGate, on its own
     * container instance, so the gate's admitted-once-per-instance memo
     * cannot carry a later attempt on an earlier one's result. Returns
     * true when admitted, false when refused — this method never lets a
     * refusal escape as an exception, since a scenario needs to keep
     * going after a refusal to prove the next request's outcome.
     */
    private function attempt(string $userId): bool
    {
        try {
            app(RateLimitGate::class)->admit($userId, BudgetWorkKind::Interactive);
            $granted = true;
        } catch (RateLimitExceededException $e) {
            $granted = false;
        }

        $this->app->forgetScopedInstances();

        return $granted;
    }

    private function grantedCount(string $userId, int $attempts): int
    {
        $granted = 0;

        for ($i = 0; $i < $attempts; $i++) {
            if ($this->attempt($userId)) {
                $granted++;
            }
        }

        return $granted;
    }

    private function liveRowCount(): int
    {
        return DB::table('rate_limits')->whereNull('deleted_at')->count();
    }

    // ---------------------------------------------------------------
    // Scenario 1 — raised for one user, everyone else unaffected
    // ---------------------------------------------------------------

    #[Test]
    public function raising_one_users_limit_lets_them_pass_the_default_while_others_stay_bound_by_it(): void
    {
        $this->declareUserDefault(5, 60);

        $put = $this->actingAs($this->operator)->putJson($this->userEndpoint($this->userA), [
            'max_requests' => 100,
            'window_seconds' => 60,
        ]);

        $put->assertStatus(200);
        $this->assertSame('user', $put->json('scope_type'));
        $this->assertSame($this->userA, $put->json('scope_id'));
        $this->assertSame(100, $put->json('max_requests'));

        // Far more than the default of 5, and every one of them granted.
        $this->assertSame(20, $this->grantedCount($this->userA, 20));

        // D was never touched: still bound by the default alone.
        $this->assertSame(5, $this->grantedCount($this->userD, 6), 'D must still be refused on the 6th request under the untouched default');
    }

    // ---------------------------------------------------------------
    // Scenario 2 — lowered for one user, everyone else unaffected
    // ---------------------------------------------------------------

    #[Test]
    public function lowering_one_users_limit_refuses_them_sooner_while_others_stay_bound_by_the_default(): void
    {
        $this->declareUserDefault(5, 60);

        $put = $this->actingAs($this->operator)->putJson($this->userEndpoint($this->userB), [
            'max_requests' => 1,
            'window_seconds' => 60,
        ]);

        $put->assertStatus(200);
        $this->assertSame(1, $put->json('max_requests'));

        $this->assertTrue($this->attempt($this->userB), "B's first request must be admitted");
        $this->assertFalse($this->attempt($this->userB), "B's second request must be refused, well before the default's own limit of 5");

        // D was never touched: still gets the full default allowance of 5.
        $this->assertSame(5, $this->grantedCount($this->userD, 6));
    }

    // ---------------------------------------------------------------
    // Scenario 3 — waived for one user, everyone else unaffected
    // ---------------------------------------------------------------

    #[Test]
    public function waiving_one_users_limit_never_refuses_them_while_others_stay_bound_by_the_default(): void
    {
        $this->declareUserDefault(5, 60);

        $put = $this->actingAs($this->operator)->putJson($this->userEndpoint($this->userC), [
            'waived' => true,
        ]);

        $put->assertStatus(200);
        $this->assertTrue($put->json('waived'));
        $this->assertNull($put->json('max_requests'));
        $this->assertNull($put->json('window_seconds'));

        // Far more requests than the default would ever allow, none refused.
        $this->assertSame(20, $this->grantedCount($this->userC, 20));

        // D was never touched: still bound by the default.
        $this->assertSame(5, $this->grantedCount($this->userD, 6));
    }

    // ---------------------------------------------------------------
    // A raise while blocked takes effect on the very next request
    // ---------------------------------------------------------------

    #[Test]
    public function raising_a_currently_blocked_users_limit_takes_effect_on_their_very_next_request_with_no_window_reset(): void
    {
        $this->declareUserDefault(5, 60);

        $this->actingAs($this->operator)->putJson($this->userEndpoint($this->userB), [
            'max_requests' => 1,
            'window_seconds' => 60,
        ])->assertStatus(200);

        $this->assertTrue($this->attempt($this->userB));
        $this->assertFalse($this->attempt($this->userB), 'B must be blocked before the raise');

        // Same window_seconds as before: the fixed-window counter key is
        // unchanged, so this is a genuine in-place raise, not a reset in
        // disguise via a differently-keyed window.
        $raise = $this->actingAs($this->operator)->putJson($this->userEndpoint($this->userB), [
            'max_requests' => 5,
            'window_seconds' => 60,
        ]);
        $raise->assertStatus(200);

        $this->assertTrue(
            $this->attempt($this->userB),
            "B's very next request after the raise must be admitted, with no restart and no window reset"
        );

        // A, C, D were never touched by any of this.
        $this->assertSame(5, $this->grantedCount($this->userD, 6));
    }

    // ---------------------------------------------------------------
    // Removing an override reverts a user to the default
    // ---------------------------------------------------------------

    #[Test]
    public function removing_a_users_override_reverts_them_to_the_default_on_their_next_request(): void
    {
        $this->declareUserDefault(5, 60);

        $this->actingAs($this->operator)->putJson($this->userEndpoint($this->userA), [
            'max_requests' => 100,
            'window_seconds' => 60,
        ])->assertStatus(200);

        // Consume more of A's window than the default would ever allow,
        // while the override is still in force.
        $this->assertSame(10, $this->grantedCount($this->userA, 10));

        $destroy = $this->actingAs($this->operator)->deleteJson($this->userEndpoint($this->userA));
        $destroy->assertStatus(204);

        // window_seconds is identical (60) before and after, so the
        // fixed-window counter key — and its accumulated count of 10 — is
        // unchanged. Falling back to the default's max_requests of 5 must
        // therefore refuse A's very next request outright, proving the
        // fallback took effect rather than merely appearing to.
        $this->assertFalse(
            $this->attempt($this->userA),
            "A's next request must be refused under the default now that the override is gone"
        );

        // B, C, D were never touched by any of this.
        $this->assertSame(5, $this->grantedCount($this->userD, 6));
    }

    // ---------------------------------------------------------------
    // Authorization
    // ---------------------------------------------------------------

    #[Test]
    public function a_non_operator_cannot_write_a_per_user_override_for_anyone_including_themselves(): void
    {
        $this->declareUserDefault(5, 60);

        $before = DB::table('rate_limits')->orderBy('id')->get()->toArray();

        $this->actingAs($this->nonOperator)->putJson($this->userEndpoint($this->userA), [
            'max_requests' => 999999,
            'window_seconds' => 1,
        ])->assertStatus(403);

        $this->actingAs($this->nonOperator)->putJson($this->userEndpoint((string) $this->nonOperator->id), [
            'waived' => true,
        ])->assertStatus(403);

        $this->actingAs($this->nonOperator)->deleteJson($this->userEndpoint($this->userA))->assertStatus(403);

        $after = DB::table('rate_limits')->orderBy('id')->get()->toArray();

        $this->assertEquals($before, $after, 'A refused write must create and change nothing');
        $this->assertSame(1, $this->liveRowCount());
    }
}
