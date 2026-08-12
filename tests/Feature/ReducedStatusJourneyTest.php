<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\CostSummary;
use ClarionApp\LlmClient\Models\RateLimit;
use ClarionApp\LlmClient\Models\ReductionStep;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\SpendingCeiling;
use ClarionApp\LlmClient\Services\ConversationWorkCeilingService;
use ClarionApp\LlmClient\Services\ConversationWorkCounter;
use ClarionApp\LlmClient\Services\SpendingCeilingService;
use ClarionApp\LlmClient\ValueObjects\BudgetScope;
use ClarionApp\LlmClient\ValueObjects\ConversationWorkScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * spec.md US4 Acceptance Scenarios 1-2, through the real HTTP endpoint
 * (contracts §2), covering FR-007/SC-004 and quickstart.md steps 17-19.
 *
 * GET /degradation/status is a live, non-persisted read — it must compute
 * the same "would a fresh request be reduced right now" answer
 * DegradationGate::evaluate() would, without ever writing a
 * degradation_events row or consuming any of the allowance it reports on
 * (research.md D9). Every scenario here reuses the exact spending-ceiling
 * and conversation-work-ceiling machinery ReducedNotRefusedJourneyTest
 * (Phase 3) and the ConversationWork*JourneyTest family already establish
 * — this endpoint reads the same standing, never a second notion of it
 * (FR-012).
 */
class ReducedStatusJourneyTest extends TestCase
{
    private User $user;
    private User $otherUser;
    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-12 10:00:00', 'UTC'));

        $this->user = User::factory()->create();
        $this->otherUser = User::factory()->create();

        $this->server = Server::create([
            'name' => 'Primary Server',
            'server_url' => 'http://primary.local:11434',
            'provider_type' => 'llama_cpp',
        ]);

        config(['llm-client.budget.on_unpriced_model' => 'admit_untracked']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        DB::table('reduction_steps')->delete();
        DB::table('degradation_events')->delete();
        DB::table('degradation_summaries')->delete();
        DB::table('cost_summaries')->delete();
        DB::table('spending_ceilings')->delete();
        DB::table('conversation_work_ceilings')->delete();
        DB::table('rate_limits')->delete();
        DB::table('agent_runs')->delete();
        if (DB::getSchemaBuilder()->hasTable('cost_reservations')) {
            DB::table('cost_reservations')->delete();
        }
        if (DB::getSchemaBuilder()->hasTable('budget_reservation_ledger')) {
            DB::table('budget_reservation_ledger')->delete();
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function base(): string
    {
        return '/api/clarion-app/llm-client/degradation/status';
    }

    private function statusFor(User $user, ?string $conversationId = null): \Illuminate\Testing\TestResponse
    {
        $query = $conversationId !== null ? ('?conversation_id='.$conversationId) : '';

        return $this->actingAs($user, 'api')->getJson($this->base().$query);
    }

    private function declareCeiling(string $amount, string $mode = 'stop'): SpendingCeiling
    {
        return app(SpendingCeilingService::class)->upsert(
            BudgetScope::UserDefault,
            SpendingCeiling::INSTALLATION_SCOPE_ID,
            ['amount' => $amount, 'period_type' => 'month', 'enforcement_mode' => $mode],
        );
    }

    private function recordSpend(User $user, string $amount): void
    {
        DB::table('cost_summaries')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'entity_type' => CostSummary::ENTITY_USER,
            'entity_id' => $user->id,
            'user_id' => $user->id,
            'period_date' => '2026-08-12',
            'request_count' => 1,
            'priced_cost_total' => $amount,
            'zero_priced_request_count' => 0,
            'unpriced_request_count' => 0,
            'unpriced_total_tokens' => 0,
            'estimated_request_count' => 0,
            'updated_at' => Carbon::now(),
        ]);
    }

    private function declareReductionStep(array $overrides = []): ReductionStep
    {
        return ReductionStep::create(array_merge([
            'axis' => 'budget_user',
            'threshold_ratio' => '0.7500',
            'substitute_model' => 'small-model',
            'enabled' => true,
        ], $overrides));
    }

    private function newConversation(User $owner): Conversation
    {
        return Conversation::create([
            'user_id' => $owner->id,
            'server_id' => $this->server->id,
            'model' => 'big-model',
            'character' => 'Clarion',
            'title' => 'Already titled',
        ]);
    }

    private function declareConversationDefault(int $maxWorkUnits, int $windowSeconds): void
    {
        app(ConversationWorkCeilingService::class)->upsert(
            ConversationWorkScope::ConversationDefault,
            RateLimit::INSTALLATION_SCOPE_ID,
            ['max_work_units' => $maxWorkUnits, 'window_seconds' => $windowSeconds],
        );
    }

    /** Directly bumps a conversation's work counter by $times units. */
    private function bumpConversationWork(string $conversationId, int $windowSeconds, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            app(ConversationWorkCounter::class)->increment($conversationId, $windowSeconds);
        }
    }

    // =================================================================
    // Scenario 1 — currently reduced: status reports it and when it lifts
    // =================================================================

    #[Test]
    public function a_user_in_a_reduced_state_sees_reduced_true_with_the_governing_rung_and_return_time(): void
    {
        $this->declareCeiling('100.0000000000');
        $this->recordSpend($this->user, '80.0000000000'); // 80% — crosses 75%
        $step = $this->declareReductionStep(['threshold_ratio' => '0.7500', 'substitute_model' => 'small-model']);

        $response = $this->statusFor($this->user);

        $response->assertStatus(200);
        $this->assertTrue($response->json('reduced'), 'standing past a configured threshold must report reduced: true');
        $this->assertSame('budget_user', $response->json('axis'));
        $this->assertSame(
            $step->id,
            $response->json('governing_step.id'),
            'the reported governing_step must be the exact rung that would actually govern a fresh request right now'
        );

        $expectedReturn = $response->json('expected_return_at');
        $this->assertNotNull($expectedReturn, 'a reduced status must report when full capability is expected to return');
        $this->assertTrue(
            Carbon::parse($expectedReturn)->equalTo(Carbon::parse('2026-09-01 00:00:00', 'UTC')),
            'expected_return_at must match the governing axis\'s own reset figure (the monthly period boundary), not an independently computed one'
        );
    }

    #[Test]
    public function once_standing_recovers_below_every_threshold_status_reports_full_capacity_with_every_field_cleared(): void
    {
        $this->declareCeiling('100.0000000000');
        $this->recordSpend($this->user, '10.0000000000'); // 10% — well below any threshold
        $this->declareReductionStep(['threshold_ratio' => '0.7500', 'substitute_model' => 'small-model']);

        $response = $this->statusFor($this->user);

        $response->assertStatus(200);
        $this->assertFalse($response->json('reduced'));
        $this->assertNull($response->json('axis'));
        $this->assertNull($response->json('governing_step'));
        $this->assertNull($response->json('expected_return_at'));
    }

    // =================================================================
    // Scenario — conversation_work axis is scoped by ?conversation_id=
    // =================================================================

    #[Test]
    public function a_conversation_near_its_own_ceiling_reports_reduced_while_a_second_conversation_does_not(): void
    {
        $this->declareConversationDefault(10, 3600);
        $this->declareReductionStep([
            'axis' => 'conversation_work',
            'threshold_ratio' => '0.7500',
            'substitute_model' => 'small-model',
        ]);

        $nearCeiling = $this->newConversation($this->user);
        $farFromCeiling = $this->newConversation($this->user);

        $this->bumpConversationWork($nearCeiling->id, 3600, 8); // 8/10 = 80% — crosses 75%
        $this->bumpConversationWork($farFromCeiling->id, 3600, 1); // 1/10 = 10% — well below

        $near = $this->statusFor($this->user, $nearCeiling->id);
        $near->assertStatus(200);
        $this->assertTrue($near->json('reduced'), 'the near-ceiling conversation must report reduced');
        $this->assertSame('conversation_work', $near->json('axis'));

        $far = $this->statusFor($this->user, $farFromCeiling->id);
        $far->assertStatus(200);
        $this->assertFalse($far->json('reduced'), 'a second conversation far from its own ceiling must not be affected');
    }

    #[Test]
    public function omitting_conversation_id_entirely_omits_the_conversation_work_axis_rather_than_guessing(): void
    {
        $this->declareConversationDefault(10, 3600);
        $this->declareReductionStep([
            'axis' => 'conversation_work',
            'threshold_ratio' => '0.7500',
            'substitute_model' => 'small-model',
        ]);

        $conversation = $this->newConversation($this->user);
        $this->bumpConversationWork($conversation->id, 3600, 8); // 80% of that one conversation's ceiling

        $response = $this->statusFor($this->user); // no conversation_id at all

        $response->assertStatus(200);
        $this->assertFalse(
            $response->json('reduced'),
            'with no conversation_id supplied, the conversation_work axis must never be guessed from some other conversation'
        );
        $this->assertSame(
            'no_conversation_id_supplied',
            $response->json('axes.conversation_work.reason'),
            'the conversation_work axis must explicitly report why it was not evaluated, not simply be silently absent'
        );
        $this->assertFalse($response->json('axes.conversation_work.applies'));
    }

    // =================================================================
    // Ownership leak prevention (Constitution §IV, mutation-checklist row 17)
    // =================================================================

    #[Test]
    public function a_conversation_id_belonging_to_another_user_is_never_leaked_and_never_a_403(): void
    {
        $this->declareConversationDefault(10, 3600);
        $this->declareReductionStep([
            'axis' => 'conversation_work',
            'threshold_ratio' => '0.7500',
            'substitute_model' => 'small-model',
        ]);

        // The other user's conversation is genuinely near ITS OWN ceiling —
        // if this ever leaked, the requester would see reduced: true.
        $foreignConversation = $this->newConversation($this->otherUser);
        $this->bumpConversationWork($foreignConversation->id, 3600, 9); // 90% of its own ceiling

        $withForeignId = $this->statusFor($this->user, $foreignConversation->id);
        $withNoId = $this->statusFor($this->user);

        $withForeignId->assertStatus(200);
        $this->assertNotSame(
            403,
            $withForeignId->getStatusCode(),
            'a conversation_id naming another user\'s conversation must never itself be treated as a distinguishable, forbidden case'
        );

        $this->assertSame(
            $withNoId->json(),
            $withForeignId->json(),
            'a conversation_id naming another user\'s conversation must produce a response byte-identical in shape to '
            .'omitting conversation_id altogether — never that other user\'s actual standing'
        );
    }
}
