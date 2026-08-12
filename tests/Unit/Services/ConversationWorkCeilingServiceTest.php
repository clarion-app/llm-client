<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use Tests\TestCase;
use ClarionApp\LlmClient\Models\ConversationWorkCeiling;
use ClarionApp\LlmClient\Models\RateLimit;
use ClarionApp\LlmClient\Services\ConversationWorkCeilingService;
use ClarionApp\LlmClient\ValueObjects\ConversationWorkScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for ConversationWorkCeilingService — the sole write path for
 * conversation_work_ceilings rows, covering both scope kinds
 * (conversation_default and conversation) and their full resolution chain:
 *
 *   upsert(ConversationWorkScope $scopeType, string $scopeId, array $attributes): ConversationWorkCeiling
 *   remove(ConversationWorkScope $scopeType, string $scopeId): void
 *   list(): Collection
 *   resolveForConversation(string $conversationId): ?ConversationWorkCeiling
 *   applicableConversationRow(string $conversationId): ?ConversationWorkCeiling
 *
 * Mirrors the equivalent per-user rate limit service test one scope level
 * down. Two properties are load-bearing rather than incidental:
 *
 *  - There is no unique constraint on (scope_type, scope_id): the table
 *    carries a plain index, because SoftDeletes and a unique constraint
 *    interact badly in both directions. "Exactly one live row per scope" is
 *    a property of this service alone, which is why the second-upsert and
 *    soft-deleted-row cases assert the live row count directly rather than
 *    trusting the schema.
 *  - A waiver is accepted only for a conversation-scoped row, never for the
 *    conversation_default row: waiving the default that applies to any
 *    conversation with no override has no meaning — a waiver exempts one
 *    named conversation, never the general population.
 *
 * Rejections are expected as \InvalidArgumentException, translated to a 422
 * at the HTTP layer elsewhere, not here.
 */
class ConversationWorkCeilingServiceTest extends TestCase
{
    private string $conversationA;
    private string $conversationB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->conversationA = (string) Str::uuid();
        $this->conversationB = (string) Str::uuid();
    }

    protected function tearDown(): void
    {
        DB::table('conversation_work_ceilings')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function service(): ConversationWorkCeilingService
    {
        return new ConversationWorkCeilingService();
    }

    private function sentinel(): string
    {
        return RateLimit::INSTALLATION_SCOPE_ID;
    }

    private function ceilingAttributes(array $overrides = []): array
    {
        return array_merge([
            'max_work_units' => 5,
            'window_seconds' => 60,
        ], $overrides);
    }

    private function liveRowCount(): int
    {
        return DB::table('conversation_work_ceilings')->whereNull('deleted_at')->count();
    }

    private function totalRowCount(): int
    {
        return DB::table('conversation_work_ceilings')->count();
    }

    private function assertUpsertRejected(ConversationWorkScope $scopeType, string $scopeId, array $attributes, string $message): void
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
    public function an_upsert_for_the_conversation_default_scope_with_no_existing_row_creates_it_exactly_as_declared(): void
    {
        $ceiling = $this->service()->upsert(
            ConversationWorkScope::ConversationDefault,
            $this->sentinel(),
            $this->ceilingAttributes(['max_work_units' => 5, 'window_seconds' => 60]),
        );

        $this->assertInstanceOf(ConversationWorkCeiling::class, $ceiling);
        $this->assertSame('conversation_default', $ceiling->scope_type);
        $this->assertSame($this->sentinel(), $ceiling->scope_id);
        $this->assertSame(5, $ceiling->max_work_units);
        $this->assertSame(60, $ceiling->window_seconds);
        $this->assertFalse($ceiling->waived);

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
            ConversationWorkScope::ConversationDefault,
            $this->sentinel(),
            $this->ceilingAttributes(['max_work_units' => 5, 'window_seconds' => 60]),
        );

        $second = $service->upsert(
            ConversationWorkScope::ConversationDefault,
            $this->sentinel(),
            $this->ceilingAttributes(['max_work_units' => 10, 'window_seconds' => 120]),
        );

        $this->assertSame($first->id, $second->id, 'The second upsert must update the same row, not create a new one');
        $this->assertSame(1, $this->liveRowCount());
        $this->assertSame(1, $this->totalRowCount(), 'No orphaned duplicate may be left behind, soft-deleted or otherwise');

        $reread = ConversationWorkCeiling::find($first->id);
        $this->assertSame(10, $reread->max_work_units);
        $this->assertSame(120, $reread->window_seconds);
    }

    // ---------------------------------------------------------------
    // Soft delete and restore
    // ---------------------------------------------------------------

    #[Test]
    public function remove_is_a_soft_delete(): void
    {
        $service = $this->service();

        $ceiling = $service->upsert(ConversationWorkScope::ConversationDefault, $this->sentinel(), $this->ceilingAttributes());

        $service->remove(ConversationWorkScope::ConversationDefault, $this->sentinel());

        $this->assertSame(0, $this->liveRowCount(), 'The row must no longer be live');
        $this->assertSame(1, $this->totalRowCount(), 'The row must still exist, soft-deleted rather than erased');

        $trashed = ConversationWorkCeiling::withTrashed()->find($ceiling->id);
        $this->assertNotNull($trashed);
        $this->assertNotNull($trashed->deleted_at);
    }

    #[Test]
    public function an_upsert_for_a_scope_whose_only_row_is_soft_deleted_restores_and_updates_it(): void
    {
        $service = $this->service();

        $original = $service->upsert(
            ConversationWorkScope::ConversationDefault,
            $this->sentinel(),
            $this->ceilingAttributes(['max_work_units' => 5, 'window_seconds' => 60]),
        );

        $service->remove(ConversationWorkScope::ConversationDefault, $this->sentinel());

        $restored = $service->upsert(
            ConversationWorkScope::ConversationDefault,
            $this->sentinel(),
            $this->ceilingAttributes(['max_work_units' => 50, 'window_seconds' => 3600]),
        );

        $this->assertSame($original->id, $restored->id, 'The soft-deleted row must be restored, not duplicated');
        $this->assertNull($restored->deleted_at);
        $this->assertSame(50, $restored->max_work_units);
        $this->assertSame(3600, $restored->window_seconds);

        $this->assertSame(1, $this->liveRowCount());
        $this->assertSame(1, $this->totalRowCount());
    }

    // ---------------------------------------------------------------
    // max_work_units / window_seconds validation
    // ---------------------------------------------------------------

    #[Test]
    public function max_work_units_and_window_seconds_are_required_unless_waived(): void
    {
        $missingMax = $this->ceilingAttributes();
        unset($missingMax['max_work_units']);

        $this->assertUpsertRejected(
            ConversationWorkScope::ConversationDefault,
            $this->sentinel(),
            $missingMax,
            'A non-waived ceiling with no max_work_units must be rejected',
        );

        $missingWindow = $this->ceilingAttributes();
        unset($missingWindow['window_seconds']);

        $this->assertUpsertRejected(
            ConversationWorkScope::ConversationDefault,
            $this->sentinel(),
            $missingWindow,
            'A non-waived ceiling with no window_seconds must be rejected',
        );
    }

    #[Test]
    public function a_zero_negative_or_non_integer_max_work_units_is_rejected(): void
    {
        foreach ([0, -1, -100, 'lots', 1.5] as $maxWorkUnits) {
            $this->assertUpsertRejected(
                ConversationWorkScope::ConversationDefault,
                $this->sentinel(),
                $this->ceilingAttributes(['max_work_units' => $maxWorkUnits]),
                'max_work_units of '.var_export($maxWorkUnits, true).' must be rejected',
            );
        }
    }

    #[Test]
    public function a_zero_negative_or_non_integer_window_seconds_is_rejected(): void
    {
        foreach ([0, -1, -3600, 'an hour', 1.5] as $windowSeconds) {
            $this->assertUpsertRejected(
                ConversationWorkScope::ConversationDefault,
                $this->sentinel(),
                $this->ceilingAttributes(['window_seconds' => $windowSeconds]),
                'window_seconds of '.var_export($windowSeconds, true).' must be rejected',
            );
        }
    }

    #[Test]
    public function max_work_units_and_window_seconds_must_be_null_when_waived_is_true(): void
    {
        $this->assertUpsertRejected(
            ConversationWorkScope::Conversation,
            $this->conversationA,
            ['waived' => true, 'max_work_units' => 5, 'window_seconds' => null],
            'A waived ceiling carrying a max_work_units must be rejected',
        );

        $this->assertUpsertRejected(
            ConversationWorkScope::Conversation,
            $this->conversationA,
            ['waived' => true, 'max_work_units' => null, 'window_seconds' => 60],
            'A waived ceiling carrying a window_seconds must be rejected',
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
            ConversationWorkScope::ConversationDefault,
            $this->sentinel(),
            $this->ceilingAttributes(['max_work_units' => 1, 'window_seconds' => 1]),
        );
        $this->assertSame(1, $oneSecond->window_seconds);

        $oneWeek = $this->service()->upsert(
            ConversationWorkScope::ConversationDefault,
            $this->sentinel(),
            $this->ceilingAttributes(['max_work_units' => 1000, 'window_seconds' => 604800]),
        );
        $this->assertSame(604800, $oneWeek->window_seconds);
    }

    // ---------------------------------------------------------------
    // Waiver is a conversation-scoped concept only
    // ---------------------------------------------------------------

    #[Test]
    public function a_waiver_is_rejected_for_the_conversation_default_scope(): void
    {
        $this->assertUpsertRejected(
            ConversationWorkScope::ConversationDefault,
            $this->sentinel(),
            ['waived' => true, 'max_work_units' => null, 'window_seconds' => null],
            'The default that applies to any conversation with no override cannot be waived — a waiver exempts one named conversation',
        );
    }

    #[Test]
    public function a_waiver_is_accepted_for_a_conversation_scoped_row(): void
    {
        $ceiling = $this->service()->upsert(
            ConversationWorkScope::Conversation,
            $this->conversationA,
            ['waived' => true, 'max_work_units' => null, 'window_seconds' => null],
        );

        $this->assertTrue($ceiling->waived);
        $this->assertNull($ceiling->max_work_units);
        $this->assertNull($ceiling->window_seconds);
        $this->assertSame($this->conversationA, $ceiling->scope_id);
    }

    // ---------------------------------------------------------------
    // Resolution
    // ---------------------------------------------------------------

    #[Test]
    public function resolve_for_conversation_returns_null_when_neither_a_conversation_default_nor_a_conversation_row_exists(): void
    {
        $this->assertNull($this->service()->resolveForConversation($this->conversationA));
    }

    #[Test]
    public function resolve_for_conversation_returns_the_conversation_default_row_for_a_conversation_with_no_override(): void
    {
        $service = $this->service();

        $default = $service->upsert(
            ConversationWorkScope::ConversationDefault,
            $this->sentinel(),
            $this->ceilingAttributes(['max_work_units' => 5, 'window_seconds' => 60]),
        );

        $resolved = $service->resolveForConversation($this->conversationA);

        $this->assertNotNull($resolved);
        $this->assertSame($default->id, $resolved->id);
        $this->assertSame('conversation_default', $resolved->scope_type);
        $this->assertSame(5, $resolved->max_work_units);
    }

    /**
     * A waiver and "nothing configured" both let the same work through, but
     * they are not the same state: applicableConversationRow() must still
     * be able to tell them apart.
     */
    #[Test]
    public function a_waived_conversation_row_resolves_to_no_ceiling_but_remains_visible_as_the_applicable_row(): void
    {
        $service = $this->service();

        $waiver = $service->upsert(
            ConversationWorkScope::Conversation,
            $this->conversationA,
            ['waived' => true, 'max_work_units' => null, 'window_seconds' => null],
        );

        $this->assertNull($service->resolveForConversation($this->conversationA), 'A waived conversation has no enforceable ceiling');

        $applicable = $service->applicableConversationRow($this->conversationA);
        $this->assertNotNull($applicable, 'The pre-waiver row must still be visible to a caller that needs it');
        $this->assertSame($waiver->id, $applicable->id);
        $this->assertTrue($applicable->waived);
    }

    // ---------------------------------------------------------------
    // Per-conversation override: precedence, waiver alongside a default,
    // removal/fallback, and restore-not-duplicate
    // ---------------------------------------------------------------

    /**
     * A conversation-scoped row takes precedence over conversation_default:
     * resolution must not fall through to the default once an override
     * exists, even though the default is checked second in the chain and
     * would resolve to something too.
     */
    #[Test]
    public function resolve_for_conversation_returns_the_conversation_row_when_one_exists_and_does_not_fall_through_to_conversation_default(): void
    {
        $service = $this->service();

        $service->upsert(
            ConversationWorkScope::ConversationDefault,
            $this->sentinel(),
            $this->ceilingAttributes(['max_work_units' => 5, 'window_seconds' => 60]),
        );

        $override = $service->upsert(
            ConversationWorkScope::Conversation,
            $this->conversationA,
            $this->ceilingAttributes(['max_work_units' => 100, 'window_seconds' => 60]),
        );

        $resolved = $service->resolveForConversation($this->conversationA);

        $this->assertNotNull($resolved);
        $this->assertSame($override->id, $resolved->id);
        $this->assertSame('conversation', $resolved->scope_type);
        $this->assertSame(100, $resolved->max_work_units, 'The override must win, not the default');

        // A different, untouched conversation still resolves to the default alone.
        $untouched = $service->resolveForConversation($this->conversationB);
        $this->assertNotNull($untouched);
        $this->assertSame('conversation_default', $untouched->scope_type);
        $this->assertSame(5, $untouched->max_work_units);
    }

    /**
     * A waiver on a conversation-scoped row wins over a configured default
     * too: the resolved outcome for that one conversation is "no ceiling,"
     * while every other conversation remains bound by the default.
     */
    #[Test]
    public function a_waived_conversation_row_wins_over_a_configured_conversation_default_for_that_conversation_alone(): void
    {
        $service = $this->service();

        $service->upsert(
            ConversationWorkScope::ConversationDefault,
            $this->sentinel(),
            $this->ceilingAttributes(['max_work_units' => 5, 'window_seconds' => 60]),
        );

        $service->upsert(
            ConversationWorkScope::Conversation,
            $this->conversationA,
            ['waived' => true, 'max_work_units' => null, 'window_seconds' => null],
        );

        $this->assertNull($service->resolveForConversation($this->conversationA), 'A waived override must resolve to no ceiling even with a default configured');

        $untouched = $service->resolveForConversation($this->conversationB);
        $this->assertNotNull($untouched);
        $this->assertSame(5, $untouched->max_work_units, 'A different conversation must still be bound by the default');
    }

    #[Test]
    public function removing_a_conversations_override_soft_deletes_it_and_resolution_falls_back_to_the_default(): void
    {
        $service = $this->service();

        $service->upsert(
            ConversationWorkScope::ConversationDefault,
            $this->sentinel(),
            $this->ceilingAttributes(['max_work_units' => 5, 'window_seconds' => 60]),
        );

        $service->upsert(
            ConversationWorkScope::Conversation,
            $this->conversationA,
            $this->ceilingAttributes(['max_work_units' => 100, 'window_seconds' => 60]),
        );

        $this->assertSame(2, $this->liveRowCount());

        $service->remove(ConversationWorkScope::Conversation, $this->conversationA);

        $this->assertSame(1, $this->liveRowCount(), 'Only the default row remains live');

        $resolved = $service->resolveForConversation($this->conversationA);
        $this->assertNotNull($resolved, 'A must fall back to the default rather than resolving to no ceiling');
        $this->assertSame('conversation_default', $resolved->scope_type);
        $this->assertSame(5, $resolved->max_work_units);
    }

    #[Test]
    public function re_adding_an_override_for_a_previously_removed_conversation_restores_and_updates_that_row_rather_than_inserting_a_duplicate(): void
    {
        $service = $this->service();

        $original = $service->upsert(
            ConversationWorkScope::Conversation,
            $this->conversationA,
            $this->ceilingAttributes(['max_work_units' => 20, 'window_seconds' => 60]),
        );

        $service->remove(ConversationWorkScope::Conversation, $this->conversationA);
        $this->assertSame(0, $this->liveRowCount());

        $restored = $service->upsert(
            ConversationWorkScope::Conversation,
            $this->conversationA,
            $this->ceilingAttributes(['max_work_units' => 50, 'window_seconds' => 120]),
        );

        $this->assertSame($original->id, $restored->id, 'The soft-deleted override must be restored, not duplicated');
        $this->assertNull($restored->deleted_at);
        $this->assertSame(50, $restored->max_work_units);
        $this->assertSame(120, $restored->window_seconds);

        $this->assertSame(1, $this->liveRowCount());
        $this->assertSame(1, $this->totalRowCount());

        $resolved = $service->resolveForConversation($this->conversationA);
        $this->assertNotNull($resolved);
        $this->assertSame($restored->id, $resolved->id);
    }

    // ---------------------------------------------------------------
    // list()
    // ---------------------------------------------------------------

    #[Test]
    public function list_returns_every_live_row_and_omits_soft_deleted_ones(): void
    {
        $service = $this->service();

        $service->upsert(ConversationWorkScope::ConversationDefault, $this->sentinel(), $this->ceilingAttributes());
        $service->upsert(ConversationWorkScope::Conversation, $this->conversationA, $this->ceilingAttributes());
        $removed = $service->upsert(ConversationWorkScope::Conversation, $this->conversationB, $this->ceilingAttributes());
        $service->remove(ConversationWorkScope::Conversation, $this->conversationB);

        $rows = $service->list();

        $this->assertCount(2, $rows);
        $this->assertFalse($rows->contains('id', $removed->id));
    }
}
