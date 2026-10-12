<?php

namespace Tests\Integration;

use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
use ClarionApp\LlmClient\Events\ConversationEnded;
use ClarionApp\LlmClient\Events\FinishOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Events\ToolExecutionEvent;
use ClarionApp\LlmClient\Events\UpdateOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Jobs\ResumeAfterQuestionJob;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Integration\Harness\CapturedPayload;
use Tests\Integration\Harness\RecordingChannelDelivery;

/**
 * Questions asked before unanswered policies existed, and resolutions to
 * which no policy applies, behave exactly as they always did.
 *
 * A question whose row carries no policy (it was asked before the policy
 * columns existed) expires the old way at its deadline: `expired`, the old
 * notice, the old tool result byte for byte, and its run continues without
 * an answer. It never shows an advance statement.
 *
 * A question that does carry a policy is unaffected by it when it is never
 * waited on: one that could not be delivered is resolved at once as
 * undeliverable (no assumption is applied, nothing stops), and one withdrawn
 * while still waiting — before its deadline — is withdrawn exactly as
 * before (no discard wording).
 *
 * Real wiring throughout, with the model scripted at the streaming and HTTP
 * boundaries and a recording adapter standing in for the Telegram chat.
 */
class ExpiryPolicyLegacyJourneyTest extends AssembledSystemTestCase
{
    private const API = '/api/clarion-app/llm-client';

    private const SWEEP = 'llm-client:resolve-expired-questions';

    private const EXPIRED_NOTICE = 'The question "Which part should I change first?" expired without an answer. '
        .'The agent will continue without it; replies sent now are treated as new messages.';

    private const EXPIRED_OUTCOME = 'This question expired and is no longer awaiting a reply.';

    private const WITHDRAWN_NOTICE = 'The question "Which part should I change first?" was withdrawn because the task was stopped. '
        .'It no longer needs a reply.';

    private RecordingChannelDelivery $telegram;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(AgentQuestionWebJourneyTest::ASKED_AT, 'UTC'));

        config(['llm-client.run_trace.enabled' => true]);
        config(['llm-client.conversation.inactivity_threshold_hours' => 4]);

        Queue::fake([SendHttpStreamRequest::class, ResumeAfterQuestionJob::class]);

        Event::fake([
            NewConversationMessageEvent::class,
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            ToolExecutionEvent::class,
            // Ending a conversation captures episodic memory, which needs a
            // model this journey does not script.
            ConversationEnded::class,
        ]);

        $this->telegram = new RecordingChannelDelivery('telegram');
        $this->app->make(ConversationChannelRegistry::class)->register($this->telegram);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /** @return list<string> SSE chunks for one streamed model turn */
    private function turnChunks(string $text, array $calls): array
    {
        $chunks = [];
        if ($text !== '') {
            $chunks[] = 'data: '.json_encode(['choices' => [['delta' => ['content' => $text], 'finish_reason' => null]]])."\n\n";
        }
        foreach (array_values($calls) as $index => $call) {
            $chunks[] = 'data: '.json_encode(['choices' => [[
                'delta' => ['tool_calls' => [[
                    'index' => $index,
                    'id' => $call['id'],
                    'type' => 'function',
                    'function' => ['name' => $call['name'], 'arguments' => $call['arguments']],
                ]]],
                'finish_reason' => null,
            ]]])."\n\n";
        }
        $chunks[] = 'data: '.json_encode(['choices' => [['delta' => [], 'finish_reason' => $calls === [] ? 'stop' : 'tool_calls']]])."\n\n";

        return $chunks;
    }

    private function playTurn(int $slot, string $text, array $calls = []): void
    {
        $stream = $this->stream();
        $stream->extractDispatchedJobs();
        $this->assertGreaterThan($slot, $stream->turnCount(), "a streaming request #{$slot} must have been dispatched");
        $stream->setCurrentSlot($slot);
        $stream->emit($this->turnChunks($text, $calls));
        $stream->finish();
    }

    private function turnCount(): int
    {
        return $this->stream()->extractDispatchedJobs()->turnCount();
    }

    /** @return list<array<string, mixed>> */
    private function streamPayloadMessages(int $slot): array
    {
        $payloads = $this->stream()->extractDispatchedJobs()->capturedPayloads();
        $this->assertArrayHasKey($slot, $payloads, "no streaming request #{$slot} was dispatched");

        /** @var CapturedPayload $payload */
        $payload = $payloads[$slot];

        return json_decode(json_encode($payload->messages), true);
    }

    /** @return list<array<string, mixed>> */
    private function chatPayloadMessages(int $index): array
    {
        $payloads = $this->transport()->capturedChatPayloads();
        $this->assertArrayHasKey($index, $payloads, "no model request #{$index} was made");

        /** @var CapturedPayload $payload */
        $payload = $payloads[$index];

        return json_decode(json_encode($payload->messages), true);
    }

    private function toolResultFor(array $messages, string $toolCallId): ?string
    {
        foreach ($messages as $message) {
            if (($message['role'] ?? null) === 'tool' && ($message['tool_call_id'] ?? null) === $toolCallId) {
                return is_string($message['content'] ?? null) ? $message['content'] : json_encode($message['content']);
            }
        }

        return null;
    }

    private function questionIn(Conversation $conversation): AgentQuestion
    {
        $question = AgentQuestion::where('conversation_id', $conversation->id)->latest('asked_at')->first();
        $this->assertNotNull($question, 'the agent\'s ask must have opened a question');

        return $question;
    }

    /** Make $question one asked before unanswered policies existed. */
    private function markAskedBeforePolicies(AgentQuestion $question): AgentQuestion
    {
        DB::table('agent_questions')->where('id', $question->id)->update(['unanswered_policy' => null, 'effective_wait_minutes' => null]);

        return AgentQuestion::findOrFail($question->id);
    }

    private function sweep(): void
    {
        $this->assertSame(0, Artisan::call(self::SWEEP), 'the sweep succeeds');
    }

    /** @return list<ResumeAfterQuestionJob> */
    private function queuedResumeJobs(): array
    {
        return Queue::pushed(ResumeAfterQuestionJob::class)->values()->all();
    }

    private function work(ResumeAfterQuestionJob $job): void
    {
        $this->app->call([$job, 'handle']);
    }

    /** The `expired` tool result exactly as it was always written. */
    private static function expiredResultBytes(AgentQuestion $question, int $minutes): string
    {
        return '{"outcome":"expired","question_id":"'.$question->id.'","message":"The question expired without an answer after '
            .$minutes.' minutes. No answer will arrive. Decide how to proceed."}';
    }

    /** @return array<string, mixed> the owner's view of $question, as the conversation listing shows it */
    private function viewOf(AgentQuestion $question, Conversation $conversation): array
    {
        $listed = $this->actingAs($this->fixture()->user, 'api')
            ->getJson(self::API."/conversation/{$conversation->id}/message")
            ->assertStatus(200)
            ->json();
        $display = array_values(array_filter($listed, fn (array $m) => ($m['id'] ?? null) === $question->display_message_id));
        $this->assertCount(1, $display, 'the question is listed');
        $this->assertIsArray($display[0]['question'] ?? null, 'the owner sees the question\'s view');

        return $display[0]['question'];
    }

    /** @return list<Message> */
    private function noticesIn(Conversation $conversation): array
    {
        return Message::where('conversation_id', $conversation->id)->orderBy('created_at')->get()
            ->filter(fn (Message $m) => ($m->tool_data['kind'] ?? null) === 'agent_question_notice')
            ->values()
            ->all();
    }

    // ---------------------------------------------------------------
    // A question asked before policies existed
    // ---------------------------------------------------------------

    public function test_a_question_asked_before_policies_expires_on_the_web_exactly_as_before(): void
    {
        $this->scenario = 'legacy_expiry_web';
        $this->entryPath = 'stream';

        $fixture = $this->fixture()->build();
        $conversation = $fixture->conversation;

        $this->actingAs($fixture->user, 'api')->postJson(self::API.'/message', [
            'conversation_id' => $conversation->id,
            'content' => 'Help me tidy up the project.',
        ])->assertStatus(201);
        $this->playTurn(0, '', [[
            'id' => 'call_ask',
            'name' => 'ask_user',
            'arguments' => AgentQuestionWebJourneyTest::threeChoiceAskArguments(),
        ]]);
        $question = $this->markAskedBeforePolicies($this->questionIn($conversation));
        $this->assertSame(1440 * 60, $question->expires_at->getTimestamp() - $question->asked_at->getTimestamp(), 'precondition: the configured period');

        // Its view carries no policy and no advance statement.
        $view = $this->viewOf($question, $conversation);
        $this->assertSame('pending', $view['state']);
        $this->assertNull($view['if_unanswered']);
        $this->assertNull($view['expiry_statement'], 'a question asked before policies states no advance statement');
        $this->assertNull($view['assumed_answer']);
        $this->assertFalse($view['consequential']);
        $this->assertTrue($view['answerable']);

        $this->travel(1440 * 60 + 30)->seconds();
        $this->sweep();

        $fresh = $question->fresh();
        $this->assertSame(QuestionState::Expired, $fresh->state, 'it expires the old way');
        $this->assertNull($fresh->stopped_at, 'it never stops');
        $this->assertNull($fresh->pending_conversation_id);
        $notices = $this->noticesIn($conversation);
        $this->assertCount(1, $notices);
        $this->assertSame(self::EXPIRED_NOTICE, $notices[0]->content, 'the old notice, word for word');
        $this->assertSame('expired', $notices[0]->tool_data['state'] ?? null);

        $jobs = $this->queuedResumeJobs();
        $this->assertCount(1, $jobs, 'the run is resumed, as it always was');
        $this->work($jobs[0]);

        $this->assertSame(2, $this->turnCount(), 'exactly one continuation');
        $this->assertSame(
            self::expiredResultBytes($question, 1440),
            $this->toolResultFor($this->streamPayloadMessages(1), 'call_ask'),
            'the agent receives the old expired result, byte for byte',
        );
        $this->playTurn(1, 'No answer came, so I will start with the backend.');
        $this->assertSame('completed', DB::table('agent_runs')->where('id', $question->run_id)->value('end_state'));
        $this->assertSame('question expired', DB::table('agent_run_actions')->where('id', $question->action_id)->value('failure_reason'));

        $view = $this->viewOf($question, $conversation);
        $this->assertSame('expired', $view['state']);
        $this->assertFalse($view['answerable']);
        $this->assertNull($view['expiry_statement']);
        $this->assertSame(self::EXPIRED_OUTCOME, $view['outcome_text']);
    }

    public function test_a_question_asked_before_policies_expires_in_the_chat_exactly_as_before(): void
    {
        $this->scenario = 'legacy_expiry_telegram';
        $this->entryPath = 'sync';

        $fixture = $this->fixture()->build();
        $conversation = $fixture->conversation;
        $conversation->update(['channel' => 'telegram']);

        $this->script()
            ->toolRequest('ask_user', ['question' => AgentQuestionWebJourneyTest::QUESTION, 'choices' => AgentQuestionWebJourneyTest::CHOICES, 'wait_minutes' => 120])
            ->finalAnswer('No answer came, so I will start with the backend.');

        $this->actingAs($fixture->user, 'api')->postJson(self::API.'/agent', [
            'message' => 'Help me tidy up the project.',
            'channel' => 'telegram',
            'conversation_id' => $conversation->id,
        ])->assertStatus(202)->assertJson(['status' => 'awaiting_user']);

        $question = $this->markAskedBeforePolicies($this->questionIn($conversation));
        $this->assertSame(120 * 60, $question->expires_at->getTimestamp() - $question->asked_at->getTimestamp(), 'precondition: a two-hour period');
        $this->assertCount(1, $this->telegram->textsFor($conversation->id), 'precondition: the question was sent to the chat');

        $this->travel(121)->minutes();
        $this->sweep();

        $this->assertSame(QuestionState::Expired, $question->fresh()->state);
        $this->assertSame(self::EXPIRED_NOTICE, $this->telegram->textsFor($conversation->id)[1] ?? null, 'the chat gets the old notice');

        $jobs = $this->queuedResumeJobs();
        $this->assertCount(1, $jobs);
        $this->work($jobs[0]);

        $this->assertCount(2, $this->transport()->capturedChatPayloads(), 'exactly one continuation');
        $this->assertSame(
            self::expiredResultBytes($question, 120),
            $this->toolResultFor($this->chatPayloadMessages(1), $question->tool_call_id),
            'the agent receives the old expired result, byte for byte, with the period from the deadline',
        );
        $this->assertSame(
            'No answer came, so I will start with the backend.',
            $this->telegram->textsFor($conversation->id)[2] ?? null,
            'the run continued and its reply reached the chat',
        );
        $this->assertSame('completed', DB::table('agent_runs')->where('id', $question->run_id)->value('end_state'));
    }

    // ---------------------------------------------------------------
    // Resolutions no policy applies to
    // ---------------------------------------------------------------

    public function test_an_undeliverable_question_that_would_proceed_is_resolved_as_before_without_its_assumption(): void
    {
        $this->scenario = 'legacy_undeliverable_proceed';
        $this->entryPath = 'sync';
        $this->ledger()->expect('AgentQuestionService:*');

        $fixture = $this->fixture()->build();
        $conversation = $fixture->conversation;
        $conversation->update(['channel' => 'telegram']);
        $this->telegram->failWith('telegram account not linked');

        $this->script()
            ->toolRequest('ask_user', [
                'question' => AgentQuestionWebJourneyTest::QUESTION,
                'choices' => AgentQuestionWebJourneyTest::CHOICES,
                'assumed_choices' => [1],
            ])
            ->finalAnswer('I could not reach you, so I will decide myself.');

        $this->actingAs($fixture->user, 'api')->postJson(self::API.'/agent', [
            'message' => 'Help me tidy up the project.',
            'channel' => 'telegram',
            'conversation_id' => $conversation->id,
        ])->assertStatus(200)->assertJson(['status' => 'completed', 'content' => 'I could not reach you, so I will decide myself.']);

        $question = $this->questionIn($conversation);
        $this->assertSame('proceed_on_assumption', $question->unanswered_policy?->value, 'precondition: it was asked with a safe default');
        $this->assertSame(QuestionState::Undeliverable, $question->state, 'a question nobody could see is undeliverable, not assumed');
        $this->assertNull($question->stopped_at);

        $this->assertSame(
            '{"outcome":"undeliverable","question_id":"'.$question->id.'","message":"The question could not be delivered to the user: telegram account not linked. The user has not seen it."}',
            $this->toolResultFor($this->chatPayloadMessages(1), $question->tool_call_id),
            'the agent is told exactly as before, and nothing is assumed for it',
        );
        $notices = $this->noticesIn($conversation);
        $this->assertCount(1, $notices);
        $this->assertSame(
            'The question "Which part should I change first?" could not be delivered (telegram account not linked). '
                .'The agent was told and will continue without an answer.',
            $notices[0]->content,
        );
        $this->assertSame('question undeliverable', DB::table('agent_run_actions')->where('id', $question->action_id)->value('failure_reason'));
    }

    public function test_a_policy_question_withdrawn_while_still_waiting_is_withdrawn_as_before(): void
    {
        $this->scenario = 'legacy_withdraw_pending';
        $this->entryPath = 'sync';

        $fixture = $this->fixture()->build();
        $conversation = $fixture->conversation;
        $conversation->update(['channel' => 'telegram']);

        $this->script()
            ->toolRequest('ask_user', ['question' => AgentQuestionWebJourneyTest::QUESTION, 'choices' => AgentQuestionWebJourneyTest::CHOICES, 'wait_minutes' => 60]);

        $this->actingAs($fixture->user, 'api')->postJson(self::API.'/agent', [
            'message' => 'Help me tidy up the project.',
            'channel' => 'telegram',
            'conversation_id' => $conversation->id,
        ])->assertStatus(202);
        $question = $this->questionIn($conversation);
        $this->assertSame('stop', $question->unanswered_policy?->value, 'precondition: a stop question');

        // Ended before its deadline: no policy has applied yet.
        $this->travel(30)->minutes();
        $this->actingAs($fixture->user, 'api')->postJson(self::API."/conversation/{$conversation->id}/end")->assertSuccessful();

        $fresh = $question->fresh();
        $this->assertSame(QuestionState::Withdrawn, $fresh->state);
        $this->assertNull($fresh->stopped_at, 'it never stopped');
        $notices = $this->noticesIn($conversation);
        $this->assertCount(1, $notices);
        $this->assertSame(self::WITHDRAWN_NOTICE, $notices[0]->content, 'the old withdrawal notice, not the discard notice');
        $this->assertSame(self::WITHDRAWN_NOTICE, $this->telegram->textsFor($conversation->id)[1] ?? null);

        $run = DB::table('agent_runs')->where('id', $question->run_id)->first();
        $this->assertSame('stopped_early', $run->end_state);
        $this->assertSame('question withdrawn', $run->end_reason, 'the old reason, not the discard reason');
        $this->assertSame(
            '{"outcome":"withdrawn","question_id":"'.$question->id.'","message":"The user stopped this task while the question was pending."}',
            Message::findOrFail($question->paused_message_id)->tool_data['tool_results'][0]['content'] ?? null,
            'the paused call is answered with the old withdrawn result',
        );
        $this->assertCount(1, $this->transport()->capturedChatPayloads(), 'no model request after the withdrawal');

        // Hours later the sweep has nothing to do with it.
        $this->travel(3)->hours();
        $this->sweep();
        $this->assertSame(QuestionState::Withdrawn, $question->fresh()->state);
        $this->assertCount(1, $this->noticesIn($conversation));
    }
}
