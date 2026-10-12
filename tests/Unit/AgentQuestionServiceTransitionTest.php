<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\AgentQuestionService;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\RecordingChannelDelivery;
use Tests\TestCase;

/**
 * Every resolution of a question — answered, expired, withdrawn,
 * undeliverable — goes through AgentQuestionService's single private
 * compare-and-set transition. It is exercised here directly (through
 * reflection, as the narrowest seam) because every public resolving
 * method depends on exactly these properties:
 *
 *  - only a `pending` row can transition; the first caller wins (`true`),
 *    every later caller loses (`false`) and changes nothing;
 *  - the winner sets the state, `resolved_at`, clears the one-pending-per-
 *    conversation latch (`pending_conversation_id`) and applies its fields;
 *  - a run-continuing outcome marks the conversation processing in the same
 *    commit; a withdrawal leaves it untouched.
 */
class AgentQuestionServiceTransitionTest extends TestCase
{
    protected function tearDown(): void
    {
        if (Schema::hasTable('agent_questions')) {
            DB::table('agent_questions')->delete();
        }
        DB::table('messages')->delete();
        DB::table('conversations')->delete();

        parent::tearDown();
    }

    private function pendingQuestion(Conversation $conversation, array $overrides = []): string
    {
        $id = (string) Str::uuid();
        $now = now();

        DB::table('agent_questions')->insert($overrides + [
            'id' => $id,
            'conversation_id' => $conversation->id,
            'user_id' => $conversation->user_id,
            'pending_conversation_id' => $conversation->id,
            'run_id' => null,
            'step_id' => null,
            'action_id' => null,
            'paused_message_id' => (string) Str::uuid(),
            'display_message_id' => null,
            'tool_call_id' => 'call_ask',
            'channel' => 'web',
            'resume_mode' => 'stream',
            'question' => 'Which part should I change first?',
            'choices' => json_encode(['Backend', 'Frontend', 'Docs']),
            'allow_multiple' => false,
            'state' => 'pending',
            'asked_at' => $now,
            'expires_at' => $now->copy()->addMinutes(1440),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    private function transition(string $id, QuestionState $to, array $fields, bool $setProcessing): bool
    {
        $service = app(AgentQuestionService::class);
        $method = new \ReflectionMethod($service, 'transition');
        $method->setAccessible(true);

        return $method->invoke($service, $id, $to, $fields, $setProcessing);
    }

    private function row(string $id): object
    {
        return DB::table('agent_questions')->where('id', $id)->first();
    }

    #[Test]
    public function the_first_caller_wins_and_resolves_the_row(): void
    {
        $conversation = Conversation::factory()->create(['is_processing' => false]);
        $id = $this->pendingQuestion($conversation);

        $won = $this->transition($id, QuestionState::Answered, ['answer_raw' => '2'], true);

        $this->assertTrue($won);
        $row = $this->row($id);
        $this->assertSame('answered', $row->state);
        $this->assertNotNull($row->resolved_at);
        $this->assertNull($row->pending_conversation_id, 'the winner releases the one-pending-per-conversation latch');
        $this->assertSame('2', $row->answer_raw);
    }

    #[Test]
    public function a_second_caller_on_the_same_row_loses_and_changes_nothing(): void
    {
        $conversation = Conversation::factory()->create(['is_processing' => false]);
        $id = $this->pendingQuestion($conversation);

        $this->assertTrue($this->transition($id, QuestionState::Answered, ['answer_raw' => '2'], true));
        $before = (array) $this->row($id);

        // refresh() first: the in-memory model still holds the pre-transition
        // value, so update() alone would see no change and write nothing.
        $conversation->refresh()->update(['is_processing' => false]);
        $lost = $this->transition($id, QuestionState::Expired, [], true);

        $this->assertFalse($lost);
        $this->assertSame($before, (array) $this->row($id), 'a losing transition must not touch the row');
        $this->assertFalse((bool) $conversation->fresh()->is_processing, 'a losing transition must not touch the conversation');
    }

    #[Test]
    public function terminal_states_never_transition_again(): void
    {
        foreach (['answered', 'expired', 'withdrawn', 'undeliverable'] as $terminal) {
            $conversation = Conversation::factory()->create(['is_processing' => false]);
            $id = $this->pendingQuestion($conversation, [
                'state' => $terminal,
                'pending_conversation_id' => null,
                'resolved_at' => now()->subMinute(),
            ]);
            $before = (array) $this->row($id);

            foreach ([QuestionState::Answered, QuestionState::Expired, QuestionState::Withdrawn, QuestionState::Undeliverable] as $to) {
                $this->assertFalse(
                    $this->transition($id, $to, [], true),
                    "a {$terminal} question must never become {$to->value}",
                );
            }

            $this->assertSame($before, (array) $this->row($id));
            $this->assertFalse((bool) $conversation->fresh()->is_processing);
        }
    }

    #[Test]
    public function run_continuing_outcomes_mark_the_conversation_processing_in_the_winning_commit(): void
    {
        foreach ([QuestionState::Answered, QuestionState::Expired, QuestionState::Undeliverable] as $to) {
            $this->assertTrue($to->continuesRun(), "{$to->value} continues the run");

            $conversation = Conversation::factory()->create(['is_processing' => false]);
            $id = $this->pendingQuestion($conversation);

            $this->assertTrue($this->transition($id, $to, [], true));

            $this->assertSame($to->value, $this->row($id)->state);
            $this->assertTrue((bool) $conversation->fresh()->is_processing, "{$to->value} must set is_processing in the winning commit");
        }
    }

    #[Test]
    public function withdrawal_leaves_the_conversation_not_processing(): void
    {
        $this->assertFalse(QuestionState::Withdrawn->continuesRun());

        $conversation = Conversation::factory()->create(['is_processing' => false]);
        $id = $this->pendingQuestion($conversation);

        $this->assertTrue($this->transition($id, QuestionState::Withdrawn, [], false));

        $row = $this->row($id);
        $this->assertSame('withdrawn', $row->state);
        $this->assertNull($row->pending_conversation_id);
        $this->assertNotNull($row->resolved_at);
        $this->assertFalse((bool) $conversation->fresh()->is_processing);
    }

    #[Test]
    public function transition_only_touches_its_own_row(): void
    {
        $conversation = Conversation::factory()->create(['is_processing' => false]);
        $other = Conversation::factory()->create(['is_processing' => false]);
        $id = $this->pendingQuestion($conversation);
        $otherId = $this->pendingQuestion($other);

        $this->assertTrue($this->transition($id, QuestionState::Undeliverable, ['delivery_failure_reason' => 'telegram account not linked'], true));

        $this->assertSame('telegram account not linked', $this->row($id)->delivery_failure_reason);
        $this->assertSame('pending', $this->row($otherId)->state);
        $this->assertSame($other->id, $this->row($otherId)->pending_conversation_id);
        $this->assertFalse((bool) $other->fresh()->is_processing);
    }

    #[Test]
    public function every_state_reports_whether_it_is_terminal(): void
    {
        $this->assertFalse(QuestionState::Pending->isTerminal());
        $this->assertFalse(QuestionState::Pending->continuesRun());

        foreach ([QuestionState::Answered, QuestionState::Expired, QuestionState::Withdrawn, QuestionState::Undeliverable] as $state) {
            $this->assertTrue($state->isTerminal(), "{$state->value} is terminal");
        }
    }

    // ---------------------------------------------------------------
    // expire() and markUndeliverable(): the public resolutions built on
    // the same compare-and-set. Only the winner tells the user.
    // ---------------------------------------------------------------

    private function recordingWebChannel(): RecordingChannelDelivery
    {
        // Nothing here may resume a run inline through a queued job.
        Queue::fake();
        Event::fake([NewConversationMessageEvent::class]);

        $web = new RecordingChannelDelivery('web');
        app(ConversationChannelRegistry::class)->register($web);

        return $web;
    }

    /** @return list<Message> the notices persisted for a question */
    private function noticesFor(string $questionId): array
    {
        return Message::all()
            ->filter(fn (Message $m) => is_array($m->tool_data)
                && ($m->tool_data['kind'] ?? null) === AgentQuestionService::KIND_NOTICE
                && ($m->tool_data['question_id'] ?? null) === $questionId)
            ->values()
            ->all();
    }

    #[Test]
    public function expire_wins_on_a_pending_question_and_tells_the_user(): void
    {
        $web = $this->recordingWebChannel();
        $conversation = Conversation::factory()->create(['is_processing' => false, 'channel' => 'web']);
        $id = $this->pendingQuestion($conversation);

        $won = app(AgentQuestionService::class)->expire(AgentQuestion::findOrFail($id));

        $this->assertTrue($won);
        $row = $this->row($id);
        $this->assertSame('expired', $row->state);
        $this->assertNotNull($row->resolved_at);
        $this->assertNull($row->pending_conversation_id, 'expiry frees the conversation\'s question slot');
        $this->assertTrue((bool) $conversation->fresh()->is_processing, 'an expired question continues its run, so the conversation is processing');

        $expected = 'The question "Which part should I change first?" expired without an answer. '
            .'The agent will continue without it; replies sent now are treated as new messages.';

        $notices = $this->noticesFor($id);
        $this->assertCount(1, $notices, 'the winner persists exactly one expiry notice');
        $this->assertSame($conversation->id, $notices[0]->conversation_id);
        $this->assertSame('assistant', $notices[0]->role);
        $this->assertSame($expected, $notices[0]->content);
        $this->assertEquals(
            ['kind' => 'agent_question_notice', 'question_id' => $id, 'state' => 'expired'],
            $notices[0]->tool_data,
        );

        $this->assertSame(
            [['conversation_id' => $conversation->id, 'text' => $expected]],
            $web->deliveries,
            'the notice is delivered once, on the conversation\'s channel',
        );
    }

    #[Test]
    public function expire_loses_on_a_resolved_question_and_changes_nothing(): void
    {
        $web = $this->recordingWebChannel();
        $conversation = Conversation::factory()->create(['is_processing' => false, 'channel' => 'web']);
        $id = $this->pendingQuestion($conversation);

        $this->assertTrue($this->transition($id, QuestionState::Answered, ['answer_raw' => '2'], true));
        $conversation->refresh()->update(['is_processing' => false]);
        $before = (array) $this->row($id);

        $lost = app(AgentQuestionService::class)->expire(AgentQuestion::findOrFail($id));

        $this->assertFalse($lost);
        $this->assertSame($before, (array) $this->row($id), 'a losing expiry must not touch the row');
        $this->assertFalse((bool) $conversation->fresh()->is_processing, 'a losing expiry must not touch the conversation');
        $this->assertSame([], $this->noticesFor($id), 'only the winner shows a notice');
        $this->assertSame([], $web->deliveries, 'only the winner delivers anything');
    }

    #[Test]
    public function mark_undeliverable_wins_records_the_reason_and_attempts_one_follow_up_notice(): void
    {
        $web = $this->recordingWebChannel();
        $web->failWith('telegram account not linked');
        $conversation = Conversation::factory()->create(['is_processing' => false, 'channel' => 'web']);
        $id = $this->pendingQuestion($conversation);

        $won = app(AgentQuestionService::class)->markUndeliverable(AgentQuestion::findOrFail($id), 'telegram account not linked');

        $this->assertTrue($won, 'a failing follow-up notice does not undo the resolution');
        $row = $this->row($id);
        $this->assertSame('undeliverable', $row->state);
        $this->assertSame('telegram account not linked', $row->delivery_failure_reason);
        $this->assertNotNull($row->resolved_at);
        $this->assertNull($row->pending_conversation_id, 'an undeliverable question frees the slot');
        $this->assertTrue((bool) $conversation->fresh()->is_processing, 'an undeliverable question continues its run');

        $expected = 'The question "Which part should I change first?" could not be delivered (telegram account not linked). '
            .'The agent was told and will continue without an answer.';

        $notices = $this->noticesFor($id);
        $this->assertCount(1, $notices);
        $this->assertSame($expected, $notices[0]->content);
        $this->assertEquals(
            ['kind' => 'agent_question_notice', 'question_id' => $id, 'state' => 'undeliverable'],
            $notices[0]->tool_data,
        );

        $this->assertSame(
            [['conversation_id' => $conversation->id, 'text' => $expected]],
            $web->deliveries,
            'the follow-up notice is attempted exactly once; its own failure is not retried',
        );
    }

    #[Test]
    public function mark_undeliverable_survives_an_adapter_that_throws(): void
    {
        $web = $this->recordingWebChannel();
        $web->throwWith('socket closed');
        $conversation = Conversation::factory()->create(['is_processing' => false, 'channel' => 'web']);
        $id = $this->pendingQuestion($conversation);

        $won = app(AgentQuestionService::class)->markUndeliverable(AgentQuestion::findOrFail($id), 'RuntimeException: socket closed');

        $this->assertTrue($won);
        $this->assertSame('undeliverable', $this->row($id)->state);
        $this->assertCount(1, $web->deliveries, 'the follow-up notice is attempted once');
    }

    #[Test]
    public function mark_undeliverable_keeps_an_overlong_reason_within_its_column(): void
    {
        // `delivery_failure_reason` is a 512-character column; an adapter's
        // reason is free text (an exception class and message), and a longer
        // one would make the resolving UPDATE fail on a strict database,
        // leaving the question pending though the user never saw it.
        $this->recordingWebChannel();
        $conversation = Conversation::factory()->create(['is_processing' => false, 'channel' => 'web']);
        $id = $this->pendingQuestion($conversation);
        $reason = 'RuntimeException: '.str_repeat('é', 700);

        $won = app(AgentQuestionService::class)->markUndeliverable(AgentQuestion::findOrFail($id), $reason);

        $this->assertTrue($won);
        $stored = (string) $this->row($id)->delivery_failure_reason;
        $this->assertLessThanOrEqual(512, mb_strlen($stored), 'the stored reason fits the column');
        $this->assertStringStartsWith('RuntimeException: éé', $stored, 'the reason is shortened, not replaced');
    }

    #[Test]
    public function mark_undeliverable_loses_on_a_resolved_question_and_changes_nothing(): void
    {
        $web = $this->recordingWebChannel();
        $conversation = Conversation::factory()->create(['is_processing' => false, 'channel' => 'web']);
        $id = $this->pendingQuestion($conversation);

        $this->assertTrue(app(AgentQuestionService::class)->expire(AgentQuestion::findOrFail($id)));
        $conversation->refresh()->update(['is_processing' => false]);
        $web->deliveries = [];
        $before = (array) $this->row($id);

        $lost = app(AgentQuestionService::class)->markUndeliverable(AgentQuestion::findOrFail($id), 'telegram account not linked');

        $this->assertFalse($lost);
        $this->assertSame($before, (array) $this->row($id), 'a losing transition must not touch the row');
        $this->assertNull($this->row($id)->delivery_failure_reason);
        $this->assertFalse((bool) $conversation->fresh()->is_processing);
        $this->assertCount(1, $this->noticesFor($id), 'only the expiry\'s notice exists');
        $this->assertSame([], $web->deliveries, 'the loser delivers nothing');
    }
}
