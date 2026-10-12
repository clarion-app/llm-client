<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\ConversationChannelDelivery;
use ClarionApp\LlmClient\Contracts\PresentsQuestions;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Services\WebChannelDelivery;
use ClarionApp\LlmClient\ValueObjects\AnswerMethod;
use ClarionApp\LlmClient\ValueObjects\PresentationMode;
use ClarionApp\LlmClient\ValueObjects\QuestionOption;
use ClarionApp\LlmClient\ValueObjects\QuestionOutcomeView;
use ClarionApp\LlmClient\ValueObjects\QuestionPresentation;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\QuestionChannels\QuestionEndpointHarness;
use Tests\TestCase;

/**
 * The built-in `web` adapter. llm-client has already persisted the message
 * it is asked to deliver, so "delivery" on the web is only telling the open
 * conversation screen to refetch: it broadcasts NewConversationMessageEvent
 * for the conversation's latest message and never writes a message itself.
 *
 * It also presents questions: the screen renders a pending question as a
 * form from the server-held question view, so presenting one is the same
 * refetch broadcast, the web has no limit on options or label length, and
 * showing a resolution needs nothing beyond the refresh every resolution
 * already broadcasts.
 */
class WebChannelDeliveryTest extends TestCase
{
    use QuestionEndpointHarness;

    private bool $questionEndpoints = false;

    protected function tearDown(): void
    {
        if ($this->questionEndpoints) {
            $this->tearDownQuestionEndpoints();
        }
        DB::table('messages')->delete();
        DB::table('conversations')->delete();

        parent::tearDown();
    }

    private function message(Conversation $conversation, string $content, string $createdAt): Message
    {
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => $content,
            'responseTime' => 0,
        ]);
        DB::table('messages')->where('id', $message->id)->update(['created_at' => $createdAt, 'updated_at' => $createdAt]);

        return $message;
    }

    #[Test]
    public function it_serves_the_web_channel(): void
    {
        $adapter = app(WebChannelDelivery::class);

        $this->assertInstanceOf(ConversationChannelDelivery::class, $adapter);
        $this->assertSame('web', $adapter->channel());
    }

    #[Test]
    public function deliver_broadcasts_the_latest_message_and_reports_success(): void
    {
        Event::fake([NewConversationMessageEvent::class]);

        $conversation = Conversation::factory()->create(['channel' => 'web']);
        $other = Conversation::factory()->create(['channel' => 'web']);
        $this->message($conversation, 'Earlier reply', '2026-10-11 09:00:00');
        $latest = $this->message($conversation, "Which part should I change first?\n\n1. Backend", '2026-10-11 09:05:00');
        $this->message($other, 'Unrelated, and newer', '2026-10-11 09:10:00');

        $result = app(WebChannelDelivery::class)->deliver($conversation, "Which part should I change first?\n\n1. Backend");

        $this->assertTrue($result->delivered);
        $this->assertNull($result->failureReason);

        Event::assertDispatchedTimes(NewConversationMessageEvent::class, 1);
        Event::assertDispatched(
            NewConversationMessageEvent::class,
            fn (NewConversationMessageEvent $event) => $event->conversation_id === $conversation->id
                && $event->message_id === $latest->id,
        );
    }

    #[Test]
    public function deliver_never_persists_a_message(): void
    {
        Event::fake([NewConversationMessageEvent::class]);

        $conversation = Conversation::factory()->create(['channel' => 'web']);
        $this->message($conversation, 'Already stored', '2026-10-11 09:00:00');
        $before = DB::table('messages')->count();

        app(WebChannelDelivery::class)->deliver($conversation, 'Already stored');

        $this->assertSame($before, DB::table('messages')->count());
    }

    // ---------------------------------------------------------------
    // Presenting questions
    // ---------------------------------------------------------------

    private function presentation(string $questionId = 'question-1'): QuestionPresentation
    {
        return new QuestionPresentation(
            questionId: $questionId,
            question: 'Which part should I change first?',
            preamble: null,
            options: [
                new QuestionOption(1, 'Backend', '1. Backend'),
                new QuestionOption(2, 'Frontend', '2. Frontend'),
            ],
            allowMultiple: false,
            bodyText: "Which part should I change first?\n\n1. Backend\n2. Frontend\n\nChoose an option below, or reply in your own words.",
            plainText: "Which part should I change first?\n\n1. Backend\n2. Frontend\n\nReply with the number of your choice, or answer in your own words.",
        );
    }

    #[Test]
    public function it_presents_questions_with_no_limit_on_options_or_labels(): void
    {
        $adapter = app(WebChannelDelivery::class);

        $this->assertInstanceOf(PresentsQuestions::class, $adapter);

        $capabilities = $adapter->questionCapabilities();
        $this->assertTrue($capabilities->supportsOptions);
        $this->assertNull($capabilities->maxOptions);
        $this->assertNull($capabilities->maxOptionLabelChars);
    }

    #[Test]
    public function presenting_a_question_broadcasts_the_latest_message_exactly_as_deliver_does(): void
    {
        Event::fake([NewConversationMessageEvent::class]);

        $conversation = Conversation::factory()->create(['channel' => 'web']);
        $other = Conversation::factory()->create(['channel' => 'web']);
        $this->message($conversation, 'Earlier reply', '2026-10-11 09:00:00');
        $latest = $this->message($conversation, 'Which part should I change first?', '2026-10-11 09:05:00');
        $this->message($other, 'Unrelated, and newer', '2026-10-11 09:10:00');
        $before = DB::table('messages')->count();

        $result = app(WebChannelDelivery::class)->presentQuestion($conversation, $this->presentation());

        $this->assertTrue($result->delivered);
        $this->assertNull($result->failureReason);
        Event::assertDispatchedTimes(NewConversationMessageEvent::class, 1);
        Event::assertDispatched(
            NewConversationMessageEvent::class,
            fn (NewConversationMessageEvent $event) => $event->conversation_id === $conversation->id
                && $event->message_id === $latest->id,
        );
        $this->assertSame($before, DB::table('messages')->count(), 'presenting never persists a message');
    }

    #[Test]
    public function presenting_a_resolution_does_nothing(): void
    {
        Event::fake([NewConversationMessageEvent::class]);

        $conversation = Conversation::factory()->create(['channel' => 'web']);
        $this->message($conversation, 'Which part should I change first?', '2026-10-11 09:05:00');
        $before = DB::table('messages')->count();

        app(WebChannelDelivery::class)->presentResolution($conversation, new QuestionOutcomeView(
            questionId: 'question-1',
            state: QuestionState::Answered,
            selected: [['number' => 2, 'text' => 'Frontend']],
            isSelection: true,
            method: AnswerMethod::WebForm,
            summary: 'Answered: 2. Frontend',
        ));

        Event::assertNotDispatched(NewConversationMessageEvent::class);
        $this->assertSame($before, DB::table('messages')->count());
    }

    #[Test]
    public function a_question_asked_on_the_web_is_recorded_as_presented_richly(): void
    {
        $this->questionEndpoints = true;
        $user = User::factory()->create();
        $this->setUpQuestionEndpoints([$user]);
        Queue::fake();
        Event::fake([NewConversationMessageEvent::class]);

        // The web adapter is the one the package registers itself.
        $opened = $this->openQuestion($this->questionConversation($user, 'web'));

        $question = AgentQuestion::findOrFail($opened->question->id);
        $this->assertSame(QuestionState::Pending, $question->state);
        $this->assertSame(PresentationMode::Rich, $question->presentation);
        $this->assertNull($question->presentation_fallback_reason);
        $this->assertNotNull($question->delivered_at);
    }
}
