<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\AgentQuestionService;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\Services\InteractiveAnswerService;
use ClarionApp\LlmClient\Services\QuestionPresentationUpdater;
use ClarionApp\LlmClient\Services\QuestionRenderer;
use ClarionApp\LlmClient\ValueObjects\AnswerMethod;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswer;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswerStatus;
use ClarionApp\LlmClient\ValueObjects\OpenQuestionRequest;
use ClarionApp\LlmClient\ValueObjects\QuestionOutcomeView;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\QuestionChannels\QuestionEndpointHarness;
use Tests\Fixtures\QuestionChannels\RecordingPresentingDelivery;
use Tests\Integration\Harness\RecordingChannelDelivery;
use Tests\TestCase;

/**
 * Every resolution of a question — whichever route resolved it — refreshes
 * every place it is shown, once, after the resolving transaction commits:
 * an open conversation screen is told to refetch (on any channel), and a
 * messaging channel that presented the question is shown its outcome. A
 * refresh can never fail, undo or delay the resolution itself.
 */
class QuestionPresentationUpdaterTest extends TestCase
{
    use QuestionEndpointHarness;

    private User $user;

    private RecordingPresentingDelivery $telegram;

    private RecordingPresentingDelivery $web;

    /** @var list<MessageLogged> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->setUpQuestionEndpoints([$this->user]);

        Queue::fake();
        Event::fake([NewConversationMessageEvent::class]);
        Event::listen(MessageLogged::class, function (MessageLogged $entry) {
            $this->logged[] = $entry;
        });

        // `web` is served by a presenting adapter here too, to prove the
        // web channel is never asked to present a resolution: its screen
        // is refreshed by the broadcast alone.
        $this->telegram = new RecordingPresentingDelivery('telegram');
        $this->web = new RecordingPresentingDelivery('web');
        app(ConversationChannelRegistry::class)->register($this->telegram);
        app(ConversationChannelRegistry::class)->register($this->web);
    }

    protected function tearDown(): void
    {
        $this->tearDownQuestionEndpoints();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Fixtures and assertions
    // ---------------------------------------------------------------

    private function telegramQuestion(bool $allowMultiple = false): AgentQuestion
    {
        return $this->pendingQuestionIn($this->questionConversation($this->user, 'telegram'), OpenQuestionRequest::RESUME_SYNC, allowMultiple: $allowMultiple);
    }

    private function webQuestion(): AgentQuestion
    {
        return $this->pendingQuestionIn($this->questionConversation($this->user, 'web'), OpenQuestionRequest::RESUME_STREAM);
    }

    /** Refresh broadcasts naming this question's display message. */
    private function refreshesFor(AgentQuestion $question): int
    {
        return Event::dispatched(
            NewConversationMessageEvent::class,
            fn (NewConversationMessageEvent $event) => $event->conversation_id === $question->conversation_id
                && $event->message_id === $question->display_message_id,
        )->count();
    }

    private function assertRefreshedOnce(AgentQuestion $question): void
    {
        $this->assertSame(1, $this->refreshesFor($question), 'exactly one refresh broadcast per resolution');
    }

    private function assertOutcomePresentedOnce(AgentQuestion $question, QuestionState $state, ?AnswerMethod $method, array $selected): QuestionOutcomeView
    {
        $resolutions = $this->telegram->resolutionsFor($question->id);
        $this->assertCount(1, $resolutions, 'the messaging channel is shown the outcome exactly once');
        $this->assertSame(0, $resolutions[0]['transaction_level'], 'shown only after the resolution committed');
        $this->assertSame($state->value, $resolutions[0]['row_state'], 'shown only once the resolution is visible');
        $this->assertSame($question->conversation_id, $resolutions[0]['conversation_id']);

        $outcome = $resolutions[0]['outcome'];
        $this->assertInstanceOf(QuestionOutcomeView::class, $outcome);
        $this->assertSame($question->id, $outcome->questionId);
        $this->assertSame($state, $outcome->state);
        $this->assertSame($method, $outcome->method);
        $this->assertSame($selected, $outcome->selected);
        $this->assertSame($selected !== [], $outcome->isSelection);
        $this->assertSame(app(QuestionRenderer::class)->renderOutcome(AgentQuestion::findOrFail($question->id)), $outcome->summary);

        return $outcome;
    }

    private function assertNothingLoggedAbout(string $questionId): void
    {
        foreach ($this->logged as $entry) {
            if (in_array($entry->level, ['warning', 'error'], true) && ($entry->context['question_id'] ?? null) === $questionId) {
                $this->fail("unexpected {$entry->level} about question {$questionId}: {$entry->message}");
            }
        }
    }

    private function assertLoggedAbout(string $questionId): void
    {
        foreach ($this->logged as $entry) {
            if (in_array($entry->level, ['warning', 'error'], true) && ($entry->context['question_id'] ?? null) === $questionId) {
                $this->assertTrue(true);

                return;
            }
        }

        $this->fail("expected a warning or error logged with question_id {$questionId}");
    }

    // ---------------------------------------------------------------
    // Every route refreshes exactly once
    // ---------------------------------------------------------------

    #[Test]
    public function a_typed_reply_on_the_message_endpoint(): void
    {
        $question = $this->webQuestion();

        $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/message', [
            'conversation_id' => $question->conversation_id,
            'content' => '2',
        ])->assertStatus(201);

        $this->assertSame(QuestionState::Answered, $question->fresh()->state);
        $this->assertRefreshedOnce($question);
        $this->assertSame([], $this->web->resolutionsFor($question->id), 'the web channel is never asked to present a resolution');
    }

    #[Test]
    public function a_typed_reply_on_the_agent_endpoint(): void
    {
        $question = $this->telegramQuestion();
        $this->scriptedReplies = [$this->scriptedTextReply('Frontend it is.')];

        $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/agent', [
            'message' => '2',
            'conversation_id' => $question->conversation_id,
            'channel' => 'telegram',
        ])->assertSuccessful();

        $this->assertSame(QuestionState::Answered, $question->fresh()->state);
        $this->assertRefreshedOnce($question);
        $this->assertOutcomePresentedOnce($question, QuestionState::Answered, AnswerMethod::TypedReply, [['number' => 2, 'text' => 'Frontend']]);
    }

    #[Test]
    public function a_web_form_answer_on_a_web_conversation(): void
    {
        $question = $this->webQuestion();

        $outcome = app(InteractiveAnswerService::class)->record($question->id, (string) $this->user->id, InteractiveAnswer::selection([2]), AnswerMethod::WebForm);

        $this->assertSame(InteractiveAnswerStatus::Answered, $outcome->status);
        $this->assertRefreshedOnce($question);
        $this->assertSame([], $this->web->resolutionsFor($question->id));
    }

    #[Test]
    public function a_web_form_answer_on_a_messaging_conversation_is_shown_in_the_chat_too(): void
    {
        $question = $this->telegramQuestion();

        app(InteractiveAnswerService::class)->record($question->id, (string) $this->user->id, InteractiveAnswer::freeText('use a fourth approach'), AnswerMethod::WebForm);

        $this->assertRefreshedOnce($question);
        $outcome = $this->assertOutcomePresentedOnce($question, QuestionState::Answered, AnswerMethod::WebForm, []);
        $this->assertSame('Answered in your own words.', $outcome->summary);
    }

    #[Test]
    public function a_tapped_option(): void
    {
        $question = $this->telegramQuestion(allowMultiple: true);

        $outcome = app(InteractiveAnswerService::class)->submit($question->id, (string) $this->user->id, InteractiveAnswer::selection([3, 1]), AnswerMethod::MessagingOption);

        $this->assertSame(InteractiveAnswerStatus::Answered, $outcome->status);
        $this->assertRefreshedOnce($question);
        $view = $this->assertOutcomePresentedOnce(
            $question,
            QuestionState::Answered,
            AnswerMethod::MessagingOption,
            [['number' => 1, 'text' => 'Backend'], ['number' => 3, 'text' => 'Docs']],
        );
        $this->assertSame('Answered: 1. Backend, 3. Docs', $view->summary);
    }

    #[Test]
    public function an_expiry_on_either_channel(): void
    {
        $telegram = $this->telegramQuestion();
        $web = $this->webQuestion();

        $this->assertTrue(app(AgentQuestionService::class)->expire($telegram));
        $this->assertTrue(app(AgentQuestionService::class)->expire($web));

        $this->assertRefreshedOnce($telegram);
        $this->assertRefreshedOnce($web);
        $view = $this->assertOutcomePresentedOnce($telegram, QuestionState::Expired, null, []);
        $this->assertSame('This question expired and is no longer awaiting a reply.', $view->summary);
        $this->assertSame([], $this->web->resolutionsFor($web->id));
    }

    #[Test]
    public function a_withdrawal(): void
    {
        $question = $this->telegramQuestion();

        $this->assertTrue(app(AgentQuestionService::class)->withdrawForConversation(Conversation::findOrFail($question->conversation_id)));

        $this->assertRefreshedOnce($question);
        $this->assertOutcomePresentedOnce($question, QuestionState::Withdrawn, null, []);
    }

    #[Test]
    public function an_undeliverable_resolution(): void
    {
        $question = $this->telegramQuestion();

        $this->assertTrue(app(AgentQuestionService::class)->markUndeliverable($question, 'chat not found'));

        $this->assertRefreshedOnce($question);
        $this->assertOutcomePresentedOnce($question, QuestionState::Undeliverable, null, []);
    }

    #[Test]
    public function the_refresh_is_broadcast_on_the_conversations_private_channel(): void
    {
        $question = $this->webQuestion();

        app(AgentQuestionService::class)->expire($question);

        $event = Event::dispatched(NewConversationMessageEvent::class, fn ($e) => $e->message_id === $question->display_message_id)->first()[0] ?? null;
        $this->assertInstanceOf(NewConversationMessageEvent::class, $event);
        $this->assertSame('private-Conversation.'.$question->conversation_id, $event->broadcastOn()[0]->name);
    }

    // ---------------------------------------------------------------
    // Only the winner, only after commit
    // ---------------------------------------------------------------

    #[Test]
    public function a_losing_resolution_refreshes_nothing(): void
    {
        $question = $this->telegramQuestion();
        app(InteractiveAnswerService::class)->record($question->id, (string) $this->user->id, InteractiveAnswer::selection([2]), AnswerMethod::WebForm);

        $this->assertFalse(app(AgentQuestionService::class)->expire($question), 'precondition: the expiry lost');
        $this->assertFalse(app(AgentQuestionService::class)->markUndeliverable($question, 'late'), 'precondition: this lost too');
        app(InteractiveAnswerService::class)->submit($question->id, (string) $this->user->id, InteractiveAnswer::selection([1]), AnswerMethod::MessagingOption);

        $this->assertRefreshedOnce($question);
        $this->assertCount(1, $this->telegram->resolutionsFor($question->id));
    }

    #[Test]
    public function nothing_is_refreshed_before_the_resolution_commits(): void
    {
        $question = $this->telegramQuestion();

        DB::transaction(function () use ($question) {
            $this->assertTrue(app(AgentQuestionService::class)->expire($question));

            $this->assertSame(0, $this->refreshesFor($question), 'no broadcast while the resolution could still roll back');
            $this->assertSame([], $this->telegram->resolutionsFor($question->id));
        });

        $this->assertRefreshedOnce($question);
        $this->assertCount(1, $this->telegram->resolutionsFor($question->id));
    }

    #[Test]
    public function a_resolution_that_rolls_back_is_never_shown(): void
    {
        $question = $this->telegramQuestion();

        DB::beginTransaction();
        try {
            $this->assertTrue(app(AgentQuestionService::class)->expire($question));
        } finally {
            DB::rollBack();
        }

        $this->assertSame(QuestionState::Pending, $question->fresh()->state, 'precondition: the expiry rolled back');
        $this->assertSame(0, $this->refreshesFor($question));
        $this->assertSame([], $this->telegram->resolutionsFor($question->id));
    }

    // ---------------------------------------------------------------
    // A failed refresh never touches the resolution
    // ---------------------------------------------------------------

    #[Test]
    public function an_adapter_that_throws_while_showing_the_outcome_changes_nothing_for_the_caller(): void
    {
        $this->telegram->throwOnResolution('message to edit not found');
        $question = $this->telegramQuestion();

        $outcome = app(InteractiveAnswerService::class)->submit($question->id, (string) $this->user->id, InteractiveAnswer::selection([2]), AnswerMethod::MessagingOption);

        $this->assertSame(InteractiveAnswerStatus::Answered, $outcome->status, 'the caller sees the answer applied');
        $this->assertSame(QuestionState::Answered, $question->fresh()->state, 'the resolution stands');
        $this->assertCount(1, $this->telegram->resolutionsFor($question->id), 'the outcome was attempted');
        $this->assertRefreshedOnce($question);
        $this->assertLoggedAbout($question->id);
    }

    #[Test]
    public function a_channel_that_declares_nothing_is_only_refreshed_by_broadcast(): void
    {
        app(ConversationChannelRegistry::class)->register(new RecordingChannelDelivery('telegram'));
        $question = $this->telegramQuestion();

        $outcome = app(InteractiveAnswerService::class)->submit($question->id, (string) $this->user->id, InteractiveAnswer::selection([2]), AnswerMethod::MessagingOption);

        $this->assertSame(InteractiveAnswerStatus::Answered, $outcome->status);
        $this->assertRefreshedOnce($question);
        $this->assertNothingLoggedAbout($question->id);
    }

    // ---------------------------------------------------------------
    // resolved() itself
    // ---------------------------------------------------------------

    #[Test]
    public function a_question_without_a_display_message_refreshes_the_conversations_latest_message(): void
    {
        $question = $this->telegramQuestion();
        DB::table('agent_questions')->where('id', $question->id)->update(['display_message_id' => null]);
        $latest = Message::create([
            'conversation_id' => $question->conversation_id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => 'the most recent message',
            'responseTime' => 0,
        ]);
        DB::table('messages')->where('id', $latest->id)->update(['created_at' => now()->addHour()]);

        app(QuestionPresentationUpdater::class)->resolved($question->id);

        $this->assertSame(1, Event::dispatched(
            NewConversationMessageEvent::class,
            fn ($e) => $e->conversation_id === $question->conversation_id && $e->message_id === $latest->id,
        )->count());
    }

    #[Test]
    public function a_deleted_conversation_or_an_unknown_question_is_a_quiet_no_op(): void
    {
        $question = $this->telegramQuestion();
        $this->assertTrue(app(AgentQuestionService::class)->markUndeliverable($question, 'gone'));
        $this->telegram->resolutions = [];
        $before = Event::dispatched(NewConversationMessageEvent::class)->count();

        DB::table('conversations')->where('id', $question->conversation_id)->delete();
        app(QuestionPresentationUpdater::class)->resolved($question->id);
        app(QuestionPresentationUpdater::class)->resolved((string) Str::uuid());

        $this->assertSame($before, Event::dispatched(NewConversationMessageEvent::class)->count());
        $this->assertSame([], $this->telegram->resolutions);
    }
}
