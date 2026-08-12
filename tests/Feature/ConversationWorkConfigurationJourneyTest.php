<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\RateLimit;
use ClarionApp\LlmClient\Services\ConversationWorkCeilingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;

/**
 * User Story 1 end-to-end, through the real HTTP endpoints: an operator
 * declares a general per-conversation work ceiling, reads it back exactly
 * as declared, changes it without disturbing anything else, and removes
 * it; a non-operator can neither read nor write it; the general default can
 * never be waived.
 *
 * Response-shape assumptions, resolved against the API contract:
 *
 * - PUT /conversation-work-ceilings/conversation-default returns 200 with
 *   the `ceiling` shape at the top level: id, scope_type, scope_id,
 *   max_work_units, window_seconds, waived.
 * - GET /conversation-work-ceilings returns 200 with every live ceiling
 *   row under a "data" key.
 * - DELETE returns 204 with no body.
 *
 * Only the conversation_default scope is exercised through HTTP here. The
 * per-conversation override surface (PUT/DELETE
 * /conversation-work-ceilings/conversations/{conversationId}) is a later
 * story's addition — ConversationWorkCeilingService already resolves both
 * scope kinds internally, but nothing routes to the per-conversation
 * endpoints yet.
 */
class ConversationWorkConfigurationJourneyTest extends TestCase
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
        DB::table('conversation_work_ceilings')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function base(): string
    {
        return '/api/clarion-app/llm-client/conversation-work-ceilings';
    }

    private function conversationDefaultEndpoint(): string
    {
        return $this->base().'/conversation-default';
    }

    private function conversationEndpoint(string $conversationId): string
    {
        return $this->base().'/conversations/'.$conversationId;
    }

    private function liveRowCount(): int
    {
        return DB::table('conversation_work_ceilings')->whereNull('deleted_at')->count();
    }

    private function totalRowCount(): int
    {
        return DB::table('conversation_work_ceilings')->count();
    }

    // ---------------------------------------------------------------
    // Scenario 1 — declared and read back exactly
    // ---------------------------------------------------------------

    #[Test]
    public function an_operator_sets_the_conversation_default_ceiling_and_reads_it_back_exactly_as_declared(): void
    {
        $put = $this->actingAs($this->operator)->putJson($this->conversationDefaultEndpoint(), [
            'max_work_units' => 5,
            'window_seconds' => 60,
        ]);

        $put->assertStatus(200);
        $put->assertJsonStructure([
            'id',
            'scope_type',
            'scope_id',
            'max_work_units',
            'window_seconds',
            'waived',
        ]);

        $this->assertSame('conversation_default', $put->json('scope_type'));
        $this->assertSame(RateLimit::INSTALLATION_SCOPE_ID, $put->json('scope_id'));
        $this->assertSame(5, $put->json('max_work_units'));
        $this->assertSame(60, $put->json('window_seconds'));
        $this->assertFalse($put->json('waived'));

        $get = $this->actingAs($this->operator)->getJson($this->base());
        $get->assertStatus(200);

        $row = collect($get->json('data'))->firstWhere('scope_type', 'conversation_default');

        $this->assertNotNull($row, 'The stored ceiling must be visible in the list');
        $this->assertSame(5, $row['max_work_units']);
        $this->assertSame(60, $row['window_seconds']);
    }

    // ---------------------------------------------------------------
    // Scenario 2 — a change takes effect on the next work unit, no restart
    // ---------------------------------------------------------------

    #[Test]
    public function a_second_put_changes_the_stored_row_immediately_rather_than_adding_a_second_one(): void
    {
        $first = $this->actingAs($this->operator)->putJson($this->conversationDefaultEndpoint(), [
            'max_work_units' => 5,
            'window_seconds' => 60,
        ]);
        $first->assertStatus(200);

        $changed = $this->actingAs($this->operator)->putJson($this->conversationDefaultEndpoint(), [
            'max_work_units' => 50,
            'window_seconds' => 3600,
        ]);

        $changed->assertStatus(200);
        $this->assertSame($first->json('id'), $changed->json('id'), 'A change updates the existing row rather than adding one');
        $this->assertSame(50, $changed->json('max_work_units'));
        $this->assertSame(3600, $changed->json('window_seconds'));

        $this->assertSame(1, $this->liveRowCount());

        $resolved = app(ConversationWorkCeilingService::class)->resolveForConversation((string) Str::uuid());
        $this->assertNotNull($resolved);
        $this->assertSame(50, $resolved->max_work_units, 'Resolution must reflect the change immediately, with no restart');
    }

    // ---------------------------------------------------------------
    // Scenario 3 — the default cannot be waived
    // ---------------------------------------------------------------

    #[Test]
    public function waiving_the_conversation_default_ceiling_is_rejected(): void
    {
        $this->actingAs($this->operator)->putJson($this->conversationDefaultEndpoint(), [
            'waived' => true,
        ])->assertStatus(422);

        $this->assertSame(0, $this->totalRowCount(), 'A rejected waiver must leave no row behind');
    }

    // ---------------------------------------------------------------
    // Scenario 4 — a non-operator is locked out of reading and writing,
    // both scope kinds
    // ---------------------------------------------------------------

    #[Test]
    public function a_non_operator_cannot_write_the_conversation_default_ceiling_and_changes_nothing(): void
    {
        $this->actingAs($this->operator)->putJson($this->conversationDefaultEndpoint(), [
            'max_work_units' => 5,
            'window_seconds' => 60,
        ])->assertStatus(200);

        $before = DB::table('conversation_work_ceilings')->orderBy('id')->get()->toArray();

        $this->actingAs($this->nonOperator)->putJson($this->conversationDefaultEndpoint(), [
            'max_work_units' => 999999,
            'window_seconds' => 1,
        ])->assertStatus(403);

        $this->actingAs($this->nonOperator)->deleteJson($this->conversationDefaultEndpoint())->assertStatus(403);

        $after = DB::table('conversation_work_ceilings')->orderBy('id')->get()->toArray();

        $this->assertEquals($before, $after, 'A refused write must create and change nothing');
        $this->assertSame(1, $this->liveRowCount());
    }

    #[Test]
    public function a_non_operator_cannot_write_a_per_conversation_override_either(): void
    {
        $conversationId = (string) Str::uuid();

        $this->actingAs($this->nonOperator)->putJson($this->conversationEndpoint($conversationId), [
            'max_work_units' => 1,
            'window_seconds' => 1,
        ])->assertStatus(403);

        $this->assertSame(0, $this->totalRowCount(), 'A refused write must create nothing, including for the caller\'s own conversation');
    }

    #[Test]
    public function a_non_operator_cannot_read_the_ceiling_list(): void
    {
        $this->actingAs($this->operator)->putJson($this->conversationDefaultEndpoint(), [
            'max_work_units' => 5,
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
        $created = $this->actingAs($this->operator)->putJson($this->conversationDefaultEndpoint(), [
            'max_work_units' => 5,
            'window_seconds' => 60,
        ]);
        $created->assertStatus(200);

        $this->actingAs($this->operator)->deleteJson($this->conversationDefaultEndpoint())->assertStatus(204);

        $this->assertSame(0, $this->liveRowCount(), 'The ceiling is gone');
        $this->assertSame(1, $this->totalRowCount(), 'The row survives as a soft delete rather than being erased');

        $list = $this->actingAs($this->operator)->getJson($this->base());
        $list->assertStatus(200);
        $this->assertCount(0, $list->json('data'), 'A soft-deleted ceiling must not appear in the live list');

        $this->assertNull(
            app(ConversationWorkCeilingService::class)->resolveForConversation((string) Str::uuid()),
            'With no conversation_work_ceilings row at all, no conversation is stopped'
        );

        $restored = $this->actingAs($this->operator)->putJson($this->conversationDefaultEndpoint(), [
            'max_work_units' => 10,
            'window_seconds' => 120,
        ]);

        $restored->assertStatus(200);
        $this->assertSame($created->json('id'), $restored->json('id'), 'The soft-deleted row must be restored, not duplicated');
        $this->assertSame(10, $restored->json('max_work_units'));
        $this->assertSame(1, $this->liveRowCount());
        $this->assertSame(1, $this->totalRowCount());
    }

    // ---------------------------------------------------------------
    // Validation
    // ---------------------------------------------------------------

    #[Test]
    public function a_missing_zero_negative_or_non_integer_max_work_units_is_a_422(): void
    {
        $bodies = [
            'missing' => ['window_seconds' => 60],
            'zero' => ['max_work_units' => 0, 'window_seconds' => 60],
            'negative' => ['max_work_units' => -5, 'window_seconds' => 60],
            'non_numeric' => ['max_work_units' => 'lots', 'window_seconds' => 60],
        ];

        foreach ($bodies as $label => $body) {
            $this->actingAs($this->operator)
                ->putJson($this->conversationDefaultEndpoint(), $body)
                ->assertStatus(422, "max_work_units case '{$label}' must be rejected");
        }

        $this->assertSame(0, $this->totalRowCount(), 'A rejected write must persist nothing');
    }

    #[Test]
    public function a_missing_zero_negative_or_non_integer_window_seconds_is_a_422(): void
    {
        $bodies = [
            'missing' => ['max_work_units' => 5],
            'zero' => ['max_work_units' => 5, 'window_seconds' => 0],
            'negative' => ['max_work_units' => 5, 'window_seconds' => -60],
            'non_numeric' => ['max_work_units' => 5, 'window_seconds' => 'an hour'],
        ];

        foreach ($bodies as $label => $body) {
            $this->actingAs($this->operator)
                ->putJson($this->conversationDefaultEndpoint(), $body)
                ->assertStatus(422, "window_seconds case '{$label}' must be rejected");
        }

        $this->assertSame(0, $this->totalRowCount(), 'A rejected write must persist nothing');
    }
}
