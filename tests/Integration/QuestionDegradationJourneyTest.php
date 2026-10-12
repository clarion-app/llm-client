<?php

namespace Tests\Integration;

use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
use ClarionApp\LlmClient\Contracts\ConversationChannelDelivery;
use ClarionApp\LlmClient\Jobs\ResumeAfterQuestionJob;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\Services\QuestionRenderer;
use ClarionApp\LlmClient\ValueObjects\AnswerMethod;
use ClarionApp\LlmClient\ValueObjects\ChannelQuestionCapabilities;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswerStatus;
use ClarionApp\LlmClient\ValueObjects\PresentationFallbackReason;
use ClarionApp\LlmClient\ValueObjects\PresentationMode;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\CapturedPayload;
use Tests\Integration\Harness\RecordingChannelDelivery;
use Tests\Integration\Harness\RecordingRichChannelDelivery;

/**
 * A question whose channel cannot show its choices as controls still
 * reaches the user and is still answerable. Whatever stands in the way —
 * a channel that presents no options, more choices than it can show, a
 * rich send the channel rejects — the whole question goes out as the
 * plain-text question with every choice numbered, and a typed "2" means
 * exactly what a tap on option 2 means on a channel that showed buttons.
 * A question is undeliverable only when that plain text also fails. A
 * choice too long for a button keeps its full text in the message while
 * the button carries a shortened label that still names its number.
 *
 * Real wiring throughout — AgentController on the synchronous path the
 * messaging channels use, the agent loop, the question service, the
 * presenter and planner, the interactive-answer service and the resumer —
 * with the model scripted at the HTTP boundary and recording adapters
 * registered for the `telegram` channel.
 */
class QuestionDegradationJourneyTest extends AssembledSystemTestCase
{
    private const AGENT_ENDPOINT = '/api/clarion-app/llm-client/agent';

    private const QUESTION = AgentQuestionWebJourneyTest::QUESTION;

    private const CHOICES = AgentQuestionWebJourneyTest::CHOICES;

    private const LONG_CHOICE = 'Rewrite the persistence layer around an event log';

    private ?Conversation $firstConversation = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entryPath = 'sync';
        config(['llm-client.run_trace.enabled' => true]);

        // A tap's resume is queued; the harness adapter works it after
        // submit() returns, as a queue worker would.
        Queue::fake([SendHttpStreamRequest::class, ResumeAfterQuestionJob::class]);
    }

    // ---------------------------------------------------------------
    // Driving the conversation
    // ---------------------------------------------------------------

    private function useAdapter(ConversationChannelDelivery $adapter): void
    {
        $this->app->make(ConversationChannelRegistry::class)->register($adapter);
    }

    /** A fresh `telegram` conversation of the same user. */
    private function telegramConversation(): Conversation
    {
        if ($this->firstConversation === null) {
            $fixture = $this->fixture()->build();
            $fixture->conversation->update(['channel' => 'telegram']);

            return $this->firstConversation = $fixture->conversation->fresh();
        }

        $conversation = Conversation::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->fixture()->user->id,
            'server_id' => $this->fixture()->server->id,
            'model' => $this->firstConversation->model,
            'title' => 'A second conversation',
        ]);
        $conversation->update(['channel' => 'telegram']);

        return $conversation->fresh();
    }

    private function userId(): string
    {
        return (string) $this->fixture()->user->id;
    }

    /** A Telegram message, sent the way TelegramChatService sends it. */
    private function send(Conversation $conversation, string $message): TestResponse
    {
        return $this->actingAs($this->fixture()->user, 'api')->postJson(self::AGENT_ENDPOINT, [
            'message' => $message,
            'channel' => 'telegram',
            'conversation_id' => $conversation->id,
        ]);
    }

    /** The model asks $choices, then answers $reply once it has the user's answer. */
    private function scriptAsk(array $choices, string $reply): void
    {
        $this->script()
            ->toolRequest('ask_user', ['question' => self::QUESTION, 'choices' => $choices])
            ->finalAnswer($reply);
    }

    /** Start the task; the agent asks, and the run waits on the user. */
    private function ask(Conversation $conversation): AgentQuestion
    {
        $requestsBefore = count($this->transport()->capturedChatPayloads());

        $response = $this->send($conversation, 'Help me tidy up the project.');

        $response->assertStatus(202);
        $question = AgentQuestion::where('conversation_id', $conversation->id)->latest('asked_at')->first();
        $this->assertNotNull($question, 'the agent\'s ask must have opened a question');
        $response->assertJson(['status' => 'awaiting_user', 'question_id' => $question->id]);
        $this->assertSame(QuestionState::Pending, $question->state);
        $this->assertNotNull($question->delivered_at, 'the question reached the user');
        $this->assertNull($question->delivery_failure_reason);
        $this->assertCount($requestsBefore + 1, $this->transport()->capturedChatPayloads(), 'the run waits: no further model request');

        return $question;
    }

    /**
     * The `ask_user` tool result the continuation sent the model, without
     * its question id (which differs between conversations by nature).
     *
     * @return array<string, mixed>|null
     */
    private function toolResult(AgentQuestion $question): ?array
    {
        /** @var CapturedPayload $payload */
        foreach ($this->transport()->capturedChatPayloads() as $payload) {
            foreach (json_decode(json_encode($payload->messages), true) as $message) {
                if (($message['role'] ?? null) === 'tool' && ($message['tool_call_id'] ?? null) === $question->tool_call_id) {
                    $result = json_decode(is_string($message['content']) ? $message['content'] : json_encode($message['content']), true);
                    $this->assertSame($question->id, $result['question_id'] ?? null, 'the result names its own question');
                    unset($result['question_id']);

                    return $result;
                }
            }
        }

        return null;
    }

    /** The user types "2" in reply; the agent continues within the request. */
    private function typeTwo(Conversation $conversation, AgentQuestion $question, string $expectedReply): array
    {
        $reply = $this->send($conversation, '2');

        $reply->assertStatus(200);
        $reply->assertJson(['conversation_id' => $conversation->id, 'status' => 'completed', 'content' => $expectedReply]);

        $question->refresh();
        $this->assertSame(QuestionState::Answered, $question->state);
        $this->assertSame(AnswerMethod::TypedReply, $question->answer_method);
        $this->assertNotNull($question->resumed_at);

        $result = $this->toolResult($question);
        $this->assertNotNull($result, 'the continuation answers the ask_user call');

        return $result;
    }

    /**
     * The same question asked in a twin conversation on a channel that does
     * show buttons, answered by tapping option 2. What the agent receives
     * there is what a degraded question's typed "2" must mean.
     *
     * The twin's model steps must already be scripted (scriptAsk()).
     */
    private function richTapOfTwo(): array
    {
        $rich = new RecordingRichChannelDelivery('telegram');
        $this->useAdapter($rich);
        $conversation = $this->telegramConversation();

        $question = $this->ask($conversation);
        $this->assertCount(1, $rich->presentationsFor($conversation->id), 'the twin is presented with buttons');
        $this->assertSame(PresentationMode::Rich, $question->fresh()->presentation);

        $outcome = $rich->tap($question->id, $this->userId(), [2]);
        $this->assertSame(InteractiveAnswerStatus::Answered, $outcome->status);

        $result = $this->toolResult($question->fresh());
        $this->assertNotNull($result, 'the twin\'s continuation answers the ask_user call');

        return $result;
    }

    private function assertPresentedAs(AgentQuestion $question, PresentationMode $mode, ?PresentationFallbackReason $reason): void
    {
        $question->refresh();
        $this->assertSame($mode, $question->presentation);
        $this->assertSame($reason, $question->presentation_fallback_reason);
    }

    /** The conversation stores the plain-text question, exactly as before. */
    private function assertStoredAsPlainText(AgentQuestion $question): void
    {
        $this->assertSame(
            AgentQuestionWebJourneyTest::GOLDEN_THREE_CHOICES,
            Message::findOrFail($question->display_message_id)->content,
        );
    }

    /** @return array<string, mixed> */
    private function choiceTwoSelected(string $text = 'Frontend'): array
    {
        return [
            'outcome' => 'answered',
            'answer' => [
                'raw_reply' => '2',
                'is_selection' => true,
                'selected' => [['number' => 2, 'text' => $text]],
            ],
        ];
    }

    // ---------------------------------------------------------------
    // Scenarios
    // ---------------------------------------------------------------

    #[Test]
    public function a_channel_that_declares_nothing_receives_the_plain_text_question(): void
    {
        $this->scenario = 'question_degraded_plain_only_channel';
        $plain = new RecordingChannelDelivery('telegram');
        $this->useAdapter($plain);
        $this->scriptAsk(self::CHOICES, 'Frontend first.');
        $this->scriptAsk(self::CHOICES, 'Frontend first.');
        $conversation = $this->telegramConversation();

        $question = $this->ask($conversation);

        $this->assertSame([AgentQuestionWebJourneyTest::GOLDEN_THREE_CHOICES], $plain->textsFor($conversation->id), 'exactly the plain-text question, sent once');
        $this->assertPresentedAs($question, PresentationMode::PlainText, PresentationFallbackReason::ChannelLacksOptions);
        $this->assertStoredAsPlainText($question);

        $typed = $this->typeTwo($conversation, $question, 'Frontend first.');

        $this->assertSame($this->choiceTwoSelected(), $typed);
        $this->assertSame($this->richTapOfTwo(), $typed, 'a typed "2" means what a tap on option 2 means');
    }

    #[Test]
    public function more_choices_than_the_channel_can_show_degrade_the_whole_question_never_a_subset(): void
    {
        $this->scenario = 'question_degraded_too_many_options';
        $limited = new RecordingRichChannelDelivery('telegram', new ChannelQuestionCapabilities(supportsOptions: true, maxOptions: 2));
        $this->useAdapter($limited);
        $this->scriptAsk(self::CHOICES, 'Frontend first.');
        $this->scriptAsk(self::CHOICES, 'Frontend first.');
        $conversation = $this->telegramConversation();

        $question = $this->ask($conversation);

        $this->assertSame([], $limited->presentationsFor($conversation->id), 'no buttons at all — not the first two choices as buttons');
        $texts = $limited->textsFor($conversation->id);
        $this->assertSame([AgentQuestionWebJourneyTest::GOLDEN_THREE_CHOICES], $texts);
        foreach (self::CHOICES as $index => $choice) {
            $this->assertStringContainsString(($index + 1).'. '.$choice, $texts[0], 'every choice is numbered and reachable');
        }
        $this->assertPresentedAs($question, PresentationMode::PlainText, PresentationFallbackReason::TooManyOptions);
        $this->assertStoredAsPlainText($question);

        $typed = $this->typeTwo($conversation, $question, 'Frontend first.');

        $this->assertSame($this->choiceTwoSelected(), $typed);
        $this->assertSame($this->richTapOfTwo(), $typed, 'a typed "2" means what a tap on option 2 means');
    }

    #[Test]
    public function a_channel_that_cannot_show_options_receives_the_plain_text_question(): void
    {
        $this->scenario = 'question_degraded_options_unsupported';
        $unsupported = new RecordingRichChannelDelivery('telegram', ChannelQuestionCapabilities::none());
        $this->useAdapter($unsupported);
        $this->scriptAsk(self::CHOICES, 'Frontend first.');
        $this->scriptAsk(self::CHOICES, 'Frontend first.');
        $conversation = $this->telegramConversation();

        $question = $this->ask($conversation);

        $this->assertSame([], $unsupported->presentationsFor($conversation->id), 'never asked to show buttons');
        $this->assertSame([AgentQuestionWebJourneyTest::GOLDEN_THREE_CHOICES], $unsupported->textsFor($conversation->id));
        $this->assertPresentedAs($question, PresentationMode::PlainText, PresentationFallbackReason::ChannelLacksOptions);

        $typed = $this->typeTwo($conversation, $question, 'Frontend first.');

        $this->assertSame($this->choiceTwoSelected(), $typed);
        $this->assertSame($this->richTapOfTwo(), $typed, 'a typed "2" means what a tap on option 2 means');
    }

    #[Test]
    public function a_rejected_rich_send_is_followed_by_the_plain_text_question_which_stays_answerable(): void
    {
        $this->scenario = 'question_degraded_rich_rejected';
        // The rejected rich send is logged; nothing else may degrade.
        $this->ledger()->expect('QuestionPresenter:*');
        $rejecting = (new RecordingRichChannelDelivery('telegram'))->rejectRich('telegram send failed: Bad Request: reply markup is invalid');
        $this->useAdapter($rejecting);
        $this->scriptAsk(self::CHOICES, 'Frontend first.');
        $this->scriptAsk(self::CHOICES, 'Frontend first.');
        $conversation = $this->telegramConversation();

        $question = $this->ask($conversation);

        $this->assertCount(1, $rejecting->presentationsFor($conversation->id), 'the rich send was attempted once');
        $this->assertSame(
            [AgentQuestionWebJourneyTest::GOLDEN_THREE_CHOICES],
            $rejecting->textsFor($conversation->id),
            'then the same question went out once as plain text',
        );
        $this->assertSame(QuestionState::Pending, $question->fresh()->state, 'still awaiting the user, not undeliverable');
        $this->assertPresentedAs($question, PresentationMode::PlainText, PresentationFallbackReason::RichSendRejected);
        $this->assertStoredAsPlainText($question);

        $typed = $this->typeTwo($conversation, $question, 'Frontend first.');

        $this->assertSame($this->choiceTwoSelected(), $typed);
        $this->assertSame($this->richTapOfTwo(), $typed, 'a typed "2" means what a tap on option 2 means');
    }

    #[Test]
    public function the_question_is_undeliverable_only_when_the_plain_text_also_fails(): void
    {
        $this->scenario = 'question_degraded_rich_and_plain_failed';
        // The rejected rich send, the failed question and the failed
        // follow-up notice are logged; nothing else may degrade.
        $this->ledger()->expect('QuestionPresenter:*');
        $this->ledger()->expect('AgentQuestionService:*');
        $failing = (new RecordingRichChannelDelivery('telegram'))
            ->rejectRich('telegram send failed: Bad Request: reply markup is invalid')
            ->failPlain('telegram send failed: Forbidden: bot was blocked by the user');
        $this->useAdapter($failing);
        $this->scriptAsk(self::CHOICES, 'I could not reach you, so I will start with the backend.');
        $conversation = $this->telegramConversation();

        $response = $this->send($conversation, 'Help me tidy up the project.');

        // The run did not wait for an answer that cannot come.
        $response->assertStatus(200);
        $response->assertJson([
            'conversation_id' => $conversation->id,
            'status' => 'completed',
            'content' => 'I could not reach you, so I will start with the backend.',
        ]);

        $question = AgentQuestion::where('conversation_id', $conversation->id)->firstOrFail();
        $this->assertCount(1, $failing->presentationsFor($conversation->id), 'the rich send was attempted');
        $this->assertSame(AgentQuestionWebJourneyTest::GOLDEN_THREE_CHOICES, $failing->textsFor($conversation->id)[0] ?? null, 'then the plain text was attempted');
        $this->assertSame(QuestionState::Undeliverable, $question->state);
        $this->assertSame(
            'telegram send failed: Forbidden: bot was blocked by the user',
            $question->delivery_failure_reason,
            'the plain attempt\'s reason, not the rich one\'s',
        );
        $this->assertNull($question->delivered_at);
        $this->assertNull($question->pending_conversation_id, 'the conversation\'s question slot is free');
        $this->assertNotNull($question->resumed_at);
        $this->assertPresentedAs($question, PresentationMode::PlainText, PresentationFallbackReason::RichSendRejected);

        $this->assertCount(2, $this->transport()->capturedChatPayloads(), 'the ask and exactly one continuation');
        $this->assertSame([
            'outcome' => 'undeliverable',
            'message' => 'The question could not be delivered to the user: telegram send failed: Forbidden: bot was blocked by the user. The user has not seen it.',
        ], $this->toolResult($question));
        $this->assertSame('completed', DB::table('agent_runs')->where('id', $question->run_id)->value('end_state'));
    }

    #[Test]
    public function a_choice_too_long_for_a_button_keeps_its_full_text_in_the_message_and_its_number_on_the_button(): void
    {
        $this->scenario = 'question_degraded_long_label';
        $choices = ['Keep it', self::LONG_CHOICE, 'Docs'];
        $narrow = new RecordingRichChannelDelivery('telegram', new ChannelQuestionCapabilities(supportsOptions: true, maxOptionLabelChars: 12));
        $this->useAdapter($narrow);
        $this->scriptAsk($choices, 'Rewriting it.');
        $this->scriptAsk($choices, 'Rewriting it.');
        $conversation = $this->telegramConversation();

        $question = $this->ask($conversation);

        $presentations = $narrow->presentationsFor($conversation->id);
        $this->assertCount(1, $presentations, 'still presented with buttons');
        $this->assertSame([], $narrow->textsFor($conversation->id), 'nothing sent as plain text');
        $this->assertPresentedAs($question, PresentationMode::Rich, null);

        $option = $presentations[0]->options[1] ?? null;
        $this->assertNotNull($option);
        $this->assertSame(2, $option->number);
        $this->assertSame(self::LONG_CHOICE, $option->text, 'the option keeps the choice\'s full text');
        $this->assertSame($this->app->make(QuestionRenderer::class)->optionLabel(2, self::LONG_CHOICE, 12), $option->label);
        $this->assertStringStartsWith('2. ', $option->label, 'the shortened label still names its choice');
        $this->assertStringEndsWith('…', $option->label);
        $this->assertSame(12, mb_strlen($option->label));
        $this->assertSame('1. Keep it', $presentations[0]->options[0]->label, 'a label that fits is untouched');
        $this->assertStringContainsString('2. '.self::LONG_CHOICE, $presentations[0]->bodyText, 'the message shows the full text');
        $this->assertSame(
            $this->app->make(QuestionRenderer::class)->renderWithOptions($question->fresh(), $question->preamble),
            $presentations[0]->bodyText,
        );

        $typed = $this->typeTwo($conversation, $question, 'Rewriting it.');

        $this->assertSame($this->choiceTwoSelected(self::LONG_CHOICE), $typed);
        $this->assertSame($this->richTapOfTwo(), $typed, 'a typed "2" means what a tap on option 2 means');
    }
}
