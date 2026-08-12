<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use Tests\TestCase;
use ClarionApp\LlmClient\Models\RateLimit;
use ClarionApp\LlmClient\Services\RateLimitService;
use ClarionApp\LlmClient\ValueObjects\RateLimitScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for RateLimitService — the sole write path for rate_limits
 * rows, covering the validation rules and the state transitions the data
 * model specifies:
 *
 *   upsert(RateLimitScope $scopeType, string $scopeId, array $attributes): RateLimit
 *   remove(RateLimitScope $scopeType, string $scopeId): void
 *   list(): Collection
 *   resolveForUser(string $userId): ?RateLimit
 *   applicableUserRow(string $userId): ?RateLimit
 *
 * Two properties here are load-bearing rather than incidental:
 *
 *  - There is no unique constraint on (scope_type, scope_id): the table
 *    carries a plain index, because SoftDeletes and a unique constraint
 *    interact badly in both directions. "Exactly one live row per scope" is
 *    therefore a property of this service and of nothing else, which is why
 *    both the second-upsert case and the soft-deleted-row case assert the
 *    live row count directly rather than trusting the schema.
 *  - A waiver is accepted only for a user-scoped row. Waiving the default
 *    that applies to everyone has no meaning: a waiver exempts one named
 *    user, never the general population.
 *
 * Rejections are expected as \InvalidArgumentException — the convention
 * this package's other configuration services already use. The HTTP layer
 * maps a rejected write to a 422 of its own; that mapping is asserted in
 * RateLimitConfigurationJourneyTest, not here.
 */
class RateLimitServiceTest extends TestCase
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
        DB::table('rate_limits')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function service(): RateLimitService
    {
        return new RateLimitService();
    }

    private function sentinel(): string
    {
        return RateLimit::INSTALLATION_SCOPE_ID;
    }

    private function limitAttributes(array $overrides = []): array
    {
        return array_merge([
            'max_requests' => 100,
            'window_seconds' => 3600,
        ], $overrides);
    }

    private function liveRowCount(): int
    {
        return DB::table('rate_limits')->whereNull('deleted_at')->count();
    }

    private function totalRowCount(): int
    {
        return DB::table('rate_limits')->count();
    }

    private function assertUpsertRejected(RateLimitScope $scopeType, string $scopeId, array $attributes, string $message): void
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

    // ---------------------------------------------------------------
    // Creation
    // ---------------------------------------------------------------

    #[Test]
    public function an_upsert_for_the_user_default_scope_with_no_existing_row_creates_it_exactly_as_declared(): void
    {
        $limit = $this->service()->upsert(
            RateLimitScope::UserDefault,
            $this->sentinel(),
            $this->limitAttributes(['max_requests' => 5, 'window_seconds' => 60]),
        );

        $this->assertInstanceOf(RateLimit::class, $limit);
        $this->assertSame('user_default', $limit->scope_type);
        $this->assertSame($this->sentinel(), $limit->scope_id);
        $this->assertSame(5, $limit->max_requests);
        $this->assertSame(60, $limit->window_seconds);
        $this->assertFalse($limit->waived);

        $this->assertSame(1, $this->liveRowCount());
    }

    // ---------------------------------------------------------------
    // Update rather than duplicate
    // ---------------------------------------------------------------

    #[Test]
    public function a_second_upsert_for_the_same_scope_updates_that_row_rather_than_inserting_a_second(): void
    {
        $service = $this->service();

        $first = $service->upsert(
            RateLimitScope::UserDefault,
            $this->sentinel(),
            $this->limitAttributes(['max_requests' => 5, 'window_seconds' => 60]),
        );

        $second = $service->upsert(
            RateLimitScope::UserDefault,
            $this->sentinel(),
            $this->limitAttributes(['max_requests' => 10, 'window_seconds' => 120]),
        );

        $this->assertSame($first->id, $second->id, 'The second upsert must update the same row, not create a new one');
        $this->assertSame(1, $this->liveRowCount());
        $this->assertSame(1, $this->totalRowCount(), 'No orphaned duplicate may be left behind, soft-deleted or otherwise');

        $reread = RateLimit::find($first->id);
        $this->assertSame(10, $reread->max_requests);
        $this->assertSame(120, $reread->window_seconds);
    }

    // ---------------------------------------------------------------
    // Soft delete and restore
    // ---------------------------------------------------------------

    #[Test]
    public function remove_is_a_soft_delete(): void
    {
        $service = $this->service();

        $limit = $service->upsert(RateLimitScope::UserDefault, $this->sentinel(), $this->limitAttributes());

        $service->remove(RateLimitScope::UserDefault, $this->sentinel());

        $this->assertSame(0, $this->liveRowCount(), 'The row must no longer be live');
        $this->assertSame(1, $this->totalRowCount(), 'The row must still exist, soft-deleted rather than erased');

        $trashed = RateLimit::withTrashed()->find($limit->id);
        $this->assertNotNull($trashed);
        $this->assertNotNull($trashed->deleted_at);
    }

    #[Test]
    public function an_upsert_for_a_scope_whose_only_row_is_soft_deleted_restores_and_updates_it(): void
    {
        $service = $this->service();

        $original = $service->upsert(
            RateLimitScope::UserDefault,
            $this->sentinel(),
            $this->limitAttributes(['max_requests' => 5, 'window_seconds' => 60]),
        );

        $service->remove(RateLimitScope::UserDefault, $this->sentinel());

        $restored = $service->upsert(
            RateLimitScope::UserDefault,
            $this->sentinel(),
            $this->limitAttributes(['max_requests' => 50, 'window_seconds' => 3600]),
        );

        $this->assertSame($original->id, $restored->id, 'The soft-deleted row must be restored, not duplicated');
        $this->assertNull($restored->deleted_at);
        $this->assertSame(50, $restored->max_requests);
        $this->assertSame(3600, $restored->window_seconds);

        $this->assertSame(1, $this->liveRowCount());
        $this->assertSame(1, $this->totalRowCount());
    }

    // ---------------------------------------------------------------
    // max_requests / window_seconds validation
    // ---------------------------------------------------------------

    #[Test]
    public function max_requests_and_window_seconds_are_required_unless_waived(): void
    {
        $missingMax = $this->limitAttributes();
        unset($missingMax['max_requests']);

        $this->assertUpsertRejected(
            RateLimitScope::UserDefault,
            $this->sentinel(),
            $missingMax,
            'A non-waived limit with no max_requests must be rejected',
        );

        $missingWindow = $this->limitAttributes();
        unset($missingWindow['window_seconds']);

        $this->assertUpsertRejected(
            RateLimitScope::UserDefault,
            $this->sentinel(),
            $missingWindow,
            'A non-waived limit with no window_seconds must be rejected',
        );
    }

    #[Test]
    public function a_zero_negative_or_non_integer_max_requests_is_rejected(): void
    {
        foreach ([0, -1, -100, 'lots', 1.5] as $maxRequests) {
            $this->assertUpsertRejected(
                RateLimitScope::UserDefault,
                $this->sentinel(),
                $this->limitAttributes(['max_requests' => $maxRequests]),
                'max_requests of '.var_export($maxRequests, true).' must be rejected',
            );
        }
    }

    #[Test]
    public function a_zero_negative_or_non_integer_window_seconds_is_rejected(): void
    {
        foreach ([0, -1, -3600, 'an hour', 1.5] as $windowSeconds) {
            $this->assertUpsertRejected(
                RateLimitScope::UserDefault,
                $this->sentinel(),
                $this->limitAttributes(['window_seconds' => $windowSeconds]),
                'window_seconds of '.var_export($windowSeconds, true).' must be rejected',
            );
        }
    }

    #[Test]
    public function max_requests_and_window_seconds_must_be_null_when_waived_is_true(): void
    {
        $this->assertUpsertRejected(
            RateLimitScope::User,
            $this->userA,
            ['waived' => true, 'max_requests' => 5, 'window_seconds' => null],
            'A waived limit carrying a max_requests must be rejected',
        );

        $this->assertUpsertRejected(
            RateLimitScope::User,
            $this->userA,
            ['waived' => true, 'max_requests' => null, 'window_seconds' => 60],
            'A waived limit carrying a window_seconds must be rejected',
        );
    }

    /**
     * No upper or lower bound beyond "positive integer" is imposed: an
     * operator-chosen one-second window or a one-week window is a choice
     * this service does not second-guess.
     */
    #[Test]
    public function an_arbitrarily_short_or_long_window_is_accepted(): void
    {
        $oneSecond = $this->service()->upsert(
            RateLimitScope::UserDefault,
            $this->sentinel(),
            $this->limitAttributes(['max_requests' => 1, 'window_seconds' => 1]),
        );
        $this->assertSame(1, $oneSecond->window_seconds);

        $oneWeek = $this->service()->upsert(
            RateLimitScope::UserDefault,
            $this->sentinel(),
            $this->limitAttributes(['max_requests' => 1000, 'window_seconds' => 604800]),
        );
        $this->assertSame(604800, $oneWeek->window_seconds);
    }

    // ---------------------------------------------------------------
    // Waiver is a user-scoped concept only
    // ---------------------------------------------------------------

    #[Test]
    public function a_waiver_is_rejected_for_the_user_default_scope(): void
    {
        $this->assertUpsertRejected(
            RateLimitScope::UserDefault,
            $this->sentinel(),
            ['waived' => true, 'max_requests' => null, 'window_seconds' => null],
            'The default that applies to every user cannot be waived — a waiver exempts one named user',
        );
    }

    #[Test]
    public function a_waiver_is_accepted_for_a_user_scoped_row(): void
    {
        $limit = $this->service()->upsert(
            RateLimitScope::User,
            $this->userA,
            ['waived' => true, 'max_requests' => null, 'window_seconds' => null],
        );

        $this->assertTrue($limit->waived);
        $this->assertNull($limit->max_requests);
        $this->assertNull($limit->window_seconds);
        $this->assertSame($this->userA, $limit->scope_id);
    }

    // ---------------------------------------------------------------
    // Resolution
    // ---------------------------------------------------------------

    #[Test]
    public function resolve_for_user_returns_null_when_neither_a_user_default_nor_a_user_row_exists(): void
    {
        $this->assertNull($this->service()->resolveForUser($this->userA));
    }

    #[Test]
    public function resolve_for_user_returns_the_user_default_row_for_a_user_with_no_override(): void
    {
        $service = $this->service();

        $default = $service->upsert(
            RateLimitScope::UserDefault,
            $this->sentinel(),
            $this->limitAttributes(['max_requests' => 5, 'window_seconds' => 60]),
        );

        $resolved = $service->resolveForUser($this->userA);

        $this->assertNotNull($resolved);
        $this->assertSame($default->id, $resolved->id);
        $this->assertSame('user_default', $resolved->scope_type);
        $this->assertSame(5, $resolved->max_requests);
    }

    /**
     * A waiver and "nothing configured" both let the same request through,
     * but they are not the same state: applicableUserRow() must still be
     * able to tell them apart.
     */
    #[Test]
    public function a_waived_user_row_resolves_to_no_limit_but_remains_visible_as_the_applicable_row(): void
    {
        $service = $this->service();

        $waiver = $service->upsert(
            RateLimitScope::User,
            $this->userA,
            ['waived' => true, 'max_requests' => null, 'window_seconds' => null],
        );

        $this->assertNull($service->resolveForUser($this->userA), 'A waived user has no enforceable limit');

        $applicable = $service->applicableUserRow($this->userA);
        $this->assertNotNull($applicable, 'The pre-waiver row must still be visible to a caller that needs it');
        $this->assertSame($waiver->id, $applicable->id);
        $this->assertTrue($applicable->waived);
    }
}
