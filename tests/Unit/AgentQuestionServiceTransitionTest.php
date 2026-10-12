<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\AgentQuestionService;
use ClarionApp\LlmClient\Services\AnswerParser;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
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
 *  - only a row in one of the caller's `from` states can transition (a
 *    `pending` row for every 130 resolution; `answer()` and
 *    `withdrawForConversation()` also accept a `stopped` row); the first
 *    caller wins (`true`), every later caller loses (`false`) and changes
 *    nothing;
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

    /**
     * @param  list<QuestionState>  $from  the states the compare-and-set accepts
     */
    private function transition(
        string $id,
        QuestionState $to,
        array $fields,
        bool $setProcessing,
        array $from = [QuestionState::Pending],
        bool $releaseSlot = true,
        bool $resolves = true,
    ): bool {
        $service = app(AgentQuestionService::class);
        $method = new \ReflectionMethod($service, 'transition');
        $method->setAccessible(true);

        return $method->invokeArgs($service, [
            'id' => $id,
            'from' => $from,
            'to' => $to,
            'fields' => $fields,
            'setProcessing' => $setProcessing,
            'releaseSlot' => $releaseSlot,
            'resolves' => $resolves,
        ]);
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
    // ---------------------------------------------------------------
    // The second waiting state: `stopped`. A stop-policy question whose
    // period passed keeps its slot and waits for a late answer or a
    // discard; nothing else may resolve it.
    // ---------------------------------------------------------------

    private const POLICY_COLUMNS = [
        'unanswered_policy',
        'consequential',
        'assumed_answer_raw',
        'assumed_answer_selected',
        'requested_wait_minutes',
        'effective_wait_minutes',
        'expires_at',
    ];

    /** A question asked with a policy, as open() stores one. */
    private function policyQuestion(Conversation $conversation, array $overrides = []): string
    {
        $now = now();

        return $this->pendingQuestion($conversation, $overrides + [
            'unanswered_policy' => 'proceed_on_assumption',
            'consequential' => false,
            'assumed_answer_raw' => '1',
            'assumed_answer_selected' => json_encode([['number' => 1, 'text' => 'Backend']]),
            'requested_wait_minutes' => 5,
            'effective_wait_minutes' => 60,
            'expires_at' => $now->copy()->addMinutes(60),
        ]);
    }

    /** Force a question into `stopped` the way the stop transition leaves it: slot kept, unresolved. */
    private function forceStopped(string $id): void
    {
        DB::table('agent_questions')->where('id', $id)->update(['state' => 'stopped', 'stopped_at' => now()]);

        $row = $this->row($id);
        $this->assertSame('stopped', $row->state, 'precondition: stopped');
        $this->assertNotNull($row->pending_conversation_id, 'precondition: a stopped question keeps its slot');
        $this->assertNull($row->resolved_at, 'precondition: a stopped question is unresolved');
    }

    /** @return array<string, mixed> */
    private function policyColumns(string $id): array
    {
        return array_intersect_key((array) $this->row($id), array_flip(self::POLICY_COLUMNS));
    }

    #[Test]
    public function a_late_answer_wins_on_a_stopped_question(): void
    {
        $this->recordingWebChannel();
        $conversation = Conversation::factory()->create(['is_processing' => false, 'channel' => 'web']);
        $id = $this->pendingQuestion($conversation, ['unanswered_policy' => 'stop']);
        $this->forceStopped($id);
        $stoppedAt = $this->row($id)->stopped_at;
        $question = AgentQuestion::findOrFail($id);

        $message = app(AgentQuestionService::class)->answer(
            $question,
            (new AnswerParser())->parse('2', $question),
            (string) $conversation->user_id,
            '2',
        );

        $this->assertNotNull($message, 'the late answer wins the compare-and-set');
        $row = $this->row($id);
        $this->assertSame('answered', $row->state);
        $this->assertNotNull($row->resolved_at);
        $this->assertNull($row->pending_conversation_id, 'the answer releases the slot');
        $this->assertSame('2', $row->answer_raw);
        $this->assertNotNull($row->stopped_at, 'stopped_at survives, marking the answer late');
        $this->assertEquals($stoppedAt, $row->stopped_at);
        $this->assertTrue((bool) $conversation->fresh()->is_processing, 'a late answer continues the run');
    }

    #[Test]
    public function ending_the_conversation_discards_a_stopped_question(): void
    {
        $this->recordingWebChannel();
        $conversation = Conversation::factory()->create(['is_processing' => false, 'channel' => 'web']);
        $id = $this->pendingQuestion($conversation, ['unanswered_policy' => 'stop']);
        $this->forceStopped($id);
        $stoppedAt = $this->row($id)->stopped_at;

        $won = app(AgentQuestionService::class)->withdrawForConversation($conversation);

        $this->assertTrue($won, 'the stopped question is found through its slot and withdrawn');
        $row = $this->row($id);
        $this->assertSame('withdrawn', $row->state);
        $this->assertNotNull($row->resolved_at);
        $this->assertNull($row->pending_conversation_id);
        $this->assertEquals($stoppedAt, $row->stopped_at, 'stopped_at survives, marking a discarded stopped task');
        $this->assertFalse((bool) $conversation->fresh()->is_processing, 'a discard continues nothing');
    }

    #[Test]
    public function expiry_and_undeliverable_lose_on_a_stopped_question(): void
    {
        $web = $this->recordingWebChannel();
        $conversation = Conversation::factory()->create(['is_processing' => false, 'channel' => 'web']);
        $id = $this->pendingQuestion($conversation, ['unanswered_policy' => 'stop']);
        $this->forceStopped($id);
        $before = (array) $this->row($id);

        $service = app(AgentQuestionService::class);

        $this->assertFalse($service->expire(AgentQuestion::findOrFail($id)), 'a stopped question has already expired');
        $this->assertFalse(
            $service->markUndeliverable(AgentQuestion::findOrFail($id), 'telegram account not linked'),
            'a stopped question was delivered long ago',
        );

        $this->assertSame($before, (array) $this->row($id), 'losing transitions must not touch the row');
        $this->assertFalse((bool) $conversation->fresh()->is_processing);
        $this->assertSame([], $this->noticesFor($id));
        $this->assertSame([], $web->deliveries);
    }

    #[Test]
    public function all_four_resolutions_still_win_on_a_pending_question(): void
    {
        $this->recordingWebChannel();
        $service = app(AgentQuestionService::class);

        $conversation = Conversation::factory()->create(['is_processing' => false, 'channel' => 'web']);
        $id = $this->pendingQuestion($conversation);
        $question = AgentQuestion::findOrFail($id);
        $this->assertNotNull($service->answer(
            $question,
            (new AnswerParser())->parse('1', $question),
            (string) $conversation->user_id,
            '1',
        ));
        $this->assertSame('answered', $this->row($id)->state);
        $this->assertNull($this->row($id)->stopped_at, 'an on-time answer is not late');

        $conversation = Conversation::factory()->create(['is_processing' => false, 'channel' => 'web']);
        $id = $this->pendingQuestion($conversation);
        $this->assertTrue($service->withdrawForConversation($conversation));
        $this->assertSame('withdrawn', $this->row($id)->state);
        $this->assertNull($this->row($id)->stopped_at);

        $conversation = Conversation::factory()->create(['is_processing' => false, 'channel' => 'web']);
        $id = $this->pendingQuestion($conversation);
        $this->assertTrue($service->expire(AgentQuestion::findOrFail($id)));
        $this->assertSame('expired', $this->row($id)->state);

        $conversation = Conversation::factory()->create(['is_processing' => false, 'channel' => 'web']);
        $id = $this->pendingQuestion($conversation);
        $this->assertTrue($service->markUndeliverable(AgentQuestion::findOrFail($id), 'no route'));
        $this->assertSame('undeliverable', $this->row($id)->state);
    }

    #[Test]
    public function the_compare_and_set_accepts_only_its_from_states(): void
    {
        $conversation = Conversation::factory()->create(['is_processing' => false]);
        $id = $this->pendingQuestion($conversation);
        $this->forceStopped($id);
        $before = (array) $this->row($id);

        $this->assertFalse($this->transition($id, QuestionState::Answered, ['answer_raw' => '2'], true, from: [QuestionState::Pending]));
        $this->assertSame($before, (array) $this->row($id), 'a stopped row is not in [pending]');

        $this->assertTrue($this->transition($id, QuestionState::Answered, ['answer_raw' => '2'], true, from: [QuestionState::Pending, QuestionState::Stopped]));
        $this->assertSame('answered', $this->row($id)->state);
        $this->assertFalse(
            $this->transition($id, QuestionState::Withdrawn, [], false, from: [QuestionState::Pending, QuestionState::Stopped]),
            'an answered row is in neither waiting state',
        );
    }

    #[Test]
    public function the_stop_transition_keeps_the_slot_and_leaves_the_question_unresolved(): void
    {
        $conversation = Conversation::factory()->create(['is_processing' => false]);
        $id = $this->pendingQuestion($conversation, ['unanswered_policy' => 'stop']);
        $stoppedAt = now();

        $won = $this->transition(
            $id,
            QuestionState::Stopped,
            ['stopped_at' => $stoppedAt],
            false,
            from: [QuestionState::Pending],
            releaseSlot: false,
            resolves: false,
        );

        $this->assertTrue($won);
        $row = $this->row($id);
        $this->assertSame('stopped', $row->state);
        $this->assertSame($conversation->id, $row->pending_conversation_id, 'the slot is kept: the next message is still the answer');
        $this->assertNull($row->resolved_at, 'a stopped question is not resolved');
        $this->assertNotNull($row->stopped_at);
        $this->assertFalse((bool) $conversation->fresh()->is_processing, 'nothing continues while stopped');

        $this->assertFalse(
            $this->transition($id, QuestionState::Stopped, ['stopped_at' => now()], false, from: [QuestionState::Pending], releaseSlot: false, resolves: false),
            'a question stops at most once',
        );
    }

    #[Test]
    public function pending_is_never_the_target_of_a_transition(): void
    {
        $conversation = Conversation::factory()->create(['is_processing' => false]);
        $id = $this->pendingQuestion($conversation);

        $this->expectException(\InvalidArgumentException::class);
        $this->transition($id, QuestionState::Pending, [], false, from: [QuestionState::Stopped]);
    }

    /** @return array<string, array{0: string}> */
    public static function resolutionsFromPending(): array
    {
        return [
            'answer' => ['answer'],
            'withdraw' => ['withdraw'],
            'expire' => ['expire'],
            'undeliverable' => ['undeliverable'],
        ];
    }

    private function resolve(string $how, Conversation $conversation, string $id): void
    {
        $service = app(AgentQuestionService::class);
        $question = AgentQuestion::findOrFail($id);

        $won = match ($how) {
            'answer' => $service->answer(
                $question,
                (new AnswerParser())->parse('2', $question),
                (string) $conversation->user_id,
                '2',
            ) !== null,
            'withdraw' => $service->withdrawForConversation($conversation),
            'expire' => $service->expire($question),
            'undeliverable' => $service->markUndeliverable($question, 'no route'),
        };

        $this->assertTrue($won, "precondition: {$how} wins");
    }

    #[Test]
    #[DataProvider('resolutionsFromPending')]
    public function no_resolution_of_a_pending_question_changes_what_was_decided_at_ask_time(string $how): void
    {
        $this->recordingWebChannel();
        $conversation = Conversation::factory()->create(['is_processing' => false, 'channel' => 'web']);
        $id = $this->policyQuestion($conversation);
        $before = $this->policyColumns($id);
        $this->assertCount(count(self::POLICY_COLUMNS), $before, 'precondition: every policy column exists');
        $this->assertSame('proceed_on_assumption', $before['unanswered_policy'], 'precondition: the policy was stored');

        $this->travel(2)->hours();
        $this->resolve($how, $conversation, $id);

        $this->assertSame($before, $this->policyColumns($id));
    }

    #[Test]
    public function no_resolution_of_a_stopped_question_changes_what_was_decided_at_ask_time(): void
    {
        $this->recordingWebChannel();

        foreach (['answer', 'withdraw'] as $how) {
            $conversation = Conversation::factory()->create(['is_processing' => false, 'channel' => 'web']);
            $id = $this->policyQuestion($conversation, [
                'unanswered_policy' => 'stop',
                'consequential' => true,
                'assumed_answer_raw' => null,
                'assumed_answer_selected' => null,
                'requested_wait_minutes' => 30,
            ]);
            $before = $this->policyColumns($id);
            $this->assertSame('stop', $before['unanswered_policy'], 'precondition: the policy was stored');

            $this->travel(2)->hours();
            $this->assertTrue($this->transition($id, QuestionState::Stopped, ['stopped_at' => now()], false, from: [QuestionState::Pending], releaseSlot: false, resolves: false));
            $this->assertSame($before, $this->policyColumns($id), 'stopping changes nothing decided at ask time');
            $this->assertSame('stopped', $this->row($id)->state);

            $this->travel(1)->days();
            $this->resolve($how, $conversation, $id);

            $this->assertSame($before, $this->policyColumns($id), "a late {$how} changes nothing decided at ask time");
        }
    }
}
