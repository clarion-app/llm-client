<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Jobs\ResumeAfterQuestionJob;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\AgentQuestionService;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\Services\QuestionResumer;
use ClarionApp\LlmClient\ValueObjects\AskUserRequest;
use ClarionApp\LlmClient\ValueObjects\ParsedAnswer;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use ClarionApp\LlmClient\ValueObjects\ResumeCaller;
use ClarionApp\LlmClient\ValueObjects\UnansweredPolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\QuestionChannels\QuestionEndpointHarness;
use Tests\Integration\Harness\RecordingChannelDelivery;
use Tests\TestCase;

/**
 * A reply typed on the conversation screen (`POST message`) after the
 * conversation's question expired on the assumption it stated.
 *
 * The reply is never applied to the question: it is stored as an ordinary
 * message, and the user is then told, once, that it arrived after the
 * deadline and what the agent had already assumed. After that the reply is
 * handled exactly as any reply after an expiry always was — it starts a new
 * run unless the conversation is still busy with the run that continued on
 * the assumption. The question's recorded decision and the paused turn are
 * never touched. A reply after an expiry that assumed nothing (a question
 * asked before unanswered policies existed) gets no notice.
 */
class MessageControllerLateReplyTest extends TestCase
{
    use QuestionEndpointHarness;

    private const ENDPOINT = '/api/clarion-app/llm-client/message';

    private const ASKED_AT = '2026-10-12 09:00:00';

    private const SWEPT_AT = '2026-10-12 10:01:00';

    private const REPLIED_AT = '2026-10-12 14:30:00';

    private const LATE_REPLY_NOTICE = 'Your reply arrived after the question "Which part should I change first?" had expired. '
        .'The agent had already continued assuming: 1. Backend. Your reply is being handled as a new message.';

    private User $user;

    private RecordingChannelDelivery $web;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->setUpQuestionEndpoints([$this->user]);

        Queue::fake();
        Event::fake([NewConversationMessageEvent::class]);

        $this->web = new RecordingChannelDelivery('web');
        app(ConversationChannelRegistry::class)->register($this->web);

        Carbon::setTestNow(Carbon::parse(self::ASKED_AT, 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->tearDownQuestionEndpoints();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    /**
     * A web question asked at 09:00 that proceeds assuming choice 1 after an
     * hour, expired by its policy at 10:01 (as the sweep does). The run that
     * continues on the assumption has not been worked yet, so the
     * conversation is still processing.
     *
     * @return array{0: Conversation, 1: AgentQuestion}
     */
    private function proceeded(bool $legacy = false): array
    {
        $conversation = $this->questionConversation($this->user, 'web');
        $question = AgentQuestion::findOrFail($this->openQuestion($conversation, request: new AskUserRequest(
            'Which part should I change first?',
            ['Backend', 'Frontend', 'Docs'],
            false,
            policy: UnansweredPolicy::ProceedOnAssumption,
            assumedAnswer: new ParsedAnswer('1', true, [['number' => 1, 'text' => 'Backend']]),
            requestedWaitMinutes: 60,
        ))->question->id);

        if ($legacy) {
            // A question asked before unanswered policies existed: it expires as it always did.
            DB::table('agent_questions')->where('id', $question->id)->update(['unanswered_policy' => null, 'effective_wait_minutes' => null]);
            $question->refresh();
        }

        Carbon::setTestNow(Carbon::parse(self::SWEPT_AT, 'UTC'));
        $this->assertSame(
            $legacy ? QuestionState::Expired : QuestionState::ExpiredAssumed,
            app(AgentQuestionService::class)->expireWithPolicy($question),
            'precondition: the question expired by its policy',
        );
        $this->assertTrue((bool) $conversation->fresh()->is_processing, 'precondition: the run continuing without an answer has the conversation');
        $this->web->deliveries = [];

        Carbon::setTestNow(Carbon::parse(self::REPLIED_AT, 'UTC'));

        return [$conversation, $question->fresh()];
    }

    /** The run that continued on the assumption has consumed the paused turn and finished. */
    private function continuedAndFinished(AgentQuestion $question): void
    {
        app(QuestionResumer::class)->resume($question->id, ResumeCaller::Background);
        $this->assertNotNull($question->fresh()->resumed_at, 'precondition: the paused turn was consumed by the continuation');
        DB::table('conversations')->where('id', $question->conversation_id)->update(['is_processing' => false]);
    }

    private function reply(Conversation $conversation, string $content): TestResponse
    {
        return $this->actingAs($this->user, 'api')->postJson(self::ENDPOINT, [
            'conversation_id' => $conversation->id,
            'content' => $content,
        ]);
    }

    /** @return list<array<string, mixed>> the conversation as its owner's screen lists it */
    private function listed(Conversation $conversation): array
    {
        return $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/conversation/{$conversation->id}/message")
            ->assertStatus(200)
            ->json();
    }

    /** @return list<array<string, mixed>> */
    private function lateReplyNotices(Conversation $conversation): array
    {
        return array_values(array_filter($this->listed($conversation), fn (array $m) => ($m['tool_data']['notice'] ?? null) === 'late_reply'));
    }

    /** @return array<string, mixed> the question's decision and its paused turn, which no late reply may change */
    private function decided(AgentQuestion $question): array
    {
        return [
            'question' => (array) DB::table('agent_questions')->where('id', $question->id)->first([
                'state', 'assumed_answer_raw', 'assumed_answer_selected', 'answer_raw', 'answer_selected', 'answer_method',
                'answer_message_id', 'answered_by', 'pending_conversation_id', 'resolved_at', 'resumed_at',
            ]),
            'paused_turn' => DB::table('messages')->where('id', $question->paused_message_id)->value('tool_data'),
        ];
    }

    /** The reply was stored as an ordinary message, and the notice follows it. */
    private function assertStoredThenNoticed(TestResponse $response, Conversation $conversation, AgentQuestion $question, string $content): void
    {
        $response->assertStatus(201);
        $this->assertSame($content, $response->json('content'));
        $this->assertNull($response->json('tool_data'), 'the reply is an ordinary message, not an answer');

        $listed = $this->listed($conversation);
        $replyAt = array_search($response->json('id'), array_column($listed, 'id'), true);
        $this->assertIsInt($replyAt, 'the reply is in the conversation');
        $notice = $listed[$replyAt + 1] ?? null;
        $this->assertIsArray($notice, 'something follows the reply');
        $this->assertSame(self::LATE_REPLY_NOTICE, $notice['content'], 'the notice follows the reply');
        $this->assertSame('assistant', $notice['role']);
        $this->assertEquals(
            ['kind' => 'agent_question_notice', 'question_id' => $question->id, 'state' => 'expired_assumed', 'notice' => 'late_reply', 'delivered' => true],
            $notice['tool_data'],
        );
        $this->assertSame([self::LATE_REPLY_NOTICE], $this->web->textsFor($conversation->id), 'delivered once on the conversation\'s channel');
    }

    // ---------------------------------------------------------------
    // The reply after the agent proceeded on its assumption
    // ---------------------------------------------------------------

    #[Test]
    public function a_reply_while_the_assumed_run_is_still_going_is_stored_then_noticed_and_starts_no_second_run(): void
    {
        [$conversation, $question] = $this->proceeded();
        $decided = $this->decided($question);

        $response = $this->reply($conversation, '2');

        $this->assertStoredThenNoticed($response, $conversation, $question, '2');
        $this->assertSame($decided, $this->decided($question), 'the reply changes nothing about what was decided');
        Queue::assertNotPushed(SendHttpStreamRequest::class);
        Queue::assertNotPushed(ResumeAfterQuestionJob::class);
        $this->assertSame(1, DB::table('agent_runs')->where('conversation_id', $conversation->id)->count(), 'no second run beside the one continuing');
    }

    #[Test]
    public function a_reply_after_the_assumed_run_finished_is_stored_then_noticed_and_starts_a_new_run(): void
    {
        [$conversation, $question] = $this->proceeded();
        $this->continuedAndFinished($question);
        $decided = $this->decided($question);
        $streamsBefore = Queue::pushed(SendHttpStreamRequest::class)->count();

        $response = $this->reply($conversation, '2');

        $this->assertStoredThenNoticed($response, $conversation, $question, '2');
        $this->assertSame($decided, $this->decided($question), 'the paused turn is not consumed again and the decision stands');
        $this->assertSame($streamsBefore + 1, Queue::pushed(SendHttpStreamRequest::class)->count(), 'the reply is handled as a new message: one new run');
        Queue::assertNotPushed(ResumeAfterQuestionJob::class);
    }

    #[Test]
    public function a_second_reply_gets_no_second_notice(): void
    {
        [$conversation, $question] = $this->proceeded();

        $this->reply($conversation, '2')->assertStatus(201);
        Carbon::setTestNow(Carbon::parse(self::REPLIED_AT, 'UTC')->addMinute());
        $second = $this->reply($conversation, 'Actually, use the frontend.');

        $second->assertStatus(201);
        $this->assertNull($second->json('tool_data'));
        $this->assertCount(1, $this->lateReplyNotices($conversation), 'told once');
        $this->assertSame([self::LATE_REPLY_NOTICE], $this->web->textsFor($conversation->id));
        $this->assertSame(QuestionState::ExpiredAssumed, $question->fresh()->state);
    }

    #[Test]
    public function a_reply_after_an_expiry_that_assumed_nothing_gets_no_notice(): void
    {
        [$conversation, $question] = $this->proceeded(legacy: true);

        $response = $this->reply($conversation, '2');

        $response->assertStatus(201);
        $this->assertNull($response->json('tool_data'));
        $this->assertSame([], $this->lateReplyNotices($conversation));
        $this->assertSame([], $this->web->textsFor($conversation->id), 'nothing is delivered');
        $this->assertNull(DB::table('agent_questions')->where('id', $question->id)->value('late_reply_noticed_at'));
        Queue::assertNotPushed(SendHttpStreamRequest::class);
    }
}
