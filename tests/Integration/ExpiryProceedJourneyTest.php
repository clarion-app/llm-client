<?php

namespace Tests\Integration;

use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
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
use ClarionApp\LlmClient\ValueObjects\UnansweredPolicy;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\CapturedPayload;
use Tests\Integration\Harness\RecordingChannelDelivery;
use Tests\Integration\Harness\RecordingRichChannelDelivery;

/**
 * A question with a safe default proceeds on its stated assumption when
 * nobody answers, and the user is told, end to end.
 *
 * The agent asks "Which test framework should I set up? 1. PHPUnit
 * 2. Pest", assuming choice 1 if there is no answer within an hour. The
 * question says so up front — the deadline and "the agent will continue
 * assuming: 1. PHPUnit" — in its stored text, on the channel it is shown
 * on, and in the web screen's view of it. Nobody answers. The every-minute
 * sweep expires it on its assumption: the user is told on the
 * conversation's channel what was assumed, the question stops offering its
 * choices, and the run continues from where it paused with the assumption
 * handed to the agent in the shape of a real answer, flagged as assumed.
 * The agent goes on with PHPUnit and the run completes; the run record
 * shows the `ask_user` call ended without the user's answer.
 *
 * Three ways in, so both places a paused ask is rebuilt are exercised: a
 * web conversation on the streaming path, a messaging conversation on the
 * synchronous path, and a messaging conversation reached through the
 * streaming path (whose continuation is shown on the web screen, a
 * limitation inherited from how streamed runs reply — the notice itself
 * still goes to the conversation's own channel).
 *
 * Real wiring throughout — controllers, the agent loop, the question
 * service, the sweep command, the resumer and its job (worked the way a
 * queue worker does) — with the model scripted at the streaming and HTTP
 * boundaries and recording adapters standing in for both channels.
 */
class ExpiryProceedJourneyTest extends AssembledSystemTestCase
{
    private const API = '/api/clarion-app/llm-client';

    private const MESSAGE_ENDPOINT = self::API.'/message';

    private const AGENT_ENDPOINT = self::API.'/agent';

    private const SWEEP = 'llm-client:resolve-expired-questions';

    private const QUESTION = 'Which test framework should I set up?';

    private const CHOICES = ['PHPUnit', 'Pest'];

    private const WAIT_MINUTES = 60;

    /** The clock is frozen at each of these instants in turn. */
    private const ASKED_AT = '2026-10-12 09:00:00';

    private const JUST_BEFORE_DEADLINE = '2026-10-12 09:59:30';

    private const SWEPT_AT = '2026-10-12 10:01:00';

    private const DEADLINE_TEXT = '10:00 UTC on 12 Oct 2026 (1 hour after this was asked)';

    private const PROCEED_STATEMENT = 'If there is no answer by '.self::DEADLINE_TEXT.', the agent will continue assuming: 1. PHPUnit';

    /** The question as typed replies answer it, before the statement. */
    private const PLAIN = "Which test framework should I set up?\n"
        ."\n"
        ."1. PHPUnit\n"
        ."2. Pest\n"
        ."\n"
        .'Reply with the number of your choice, or answer in your own words.';

    /** The question as shown alongside its choices as buttons, before the statement. */
    private const WITH_OPTIONS = "Which test framework should I set up?\n"
        ."\n"
        ."1. PHPUnit\n"
        ."2. Pest\n"
        ."\n"
        .'Choose an option below, or reply in your own words.';

    private const ASSUMED_NOTICE = 'No answer to "'.self::QUESTION.'" was received by the deadline ('.self::DEADLINE_TEXT.'). '
        .'The agent is continuing on the assumption it stated: 1. PHPUnit. '
        .'Replies sent now are treated as new messages and will not change that assumption.';

    private const ASSUMED_OUTCOME = 'Expired — no answer arrived in time; the agent continued assuming: 1. PHPUnit';

    private const ASSUMED_MESSAGE = 'No answer arrived within 60 minutes. Continue using the answer you said you would assume. '
        .'This is your own assumption, not the user\'s answer: never treat it as the user\'s confirmation, approval or '
        .'authorization of any action. The user has been told what was assumed.';

    private const FINAL_ANSWER = 'No answer came, so I am setting up PHPUnit.';

    private RecordingChannelDelivery $web;

    private RecordingRichChannelDelivery $telegram;

    /** @var array<int, true> resume jobs already worked, by object id */
    private array $worked = [];

    /** @var list<string> every error or warning logged */
    private array $problemsLogged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::ASKED_AT, 'UTC'));

        config(['llm-client.run_trace.enabled' => true]);
        config(['llm-client.conversation.inactivity_threshold_hours' => 4]);

        // Resume jobs are held on the queue until a worker picks them up, so
        // the test sees exactly what the sweep queued.
        Queue::fake([SendHttpStreamRequest::class, ResumeAfterQuestionJob::class]);

        Event::fake([
            NewConversationMessageEvent::class,
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            ToolExecutionEvent::class,
        ]);

        // The resume job reports a failed continuation only in the log, so
        // every error and warning is collected and checked.
        Event::listen(MessageLogged::class, function (MessageLogged $logged) {
            if (in_array($logged->level, ['error', 'warning', 'critical', 'alert', 'emergency'], true)) {
                $this->problemsLogged[] = $logged->level.': '.$logged->message;
            }
        });

        $this->web = new RecordingChannelDelivery('web');
        $this->telegram = new RecordingRichChannelDelivery('telegram');
        $registry = $this->app->make(ConversationChannelRegistry::class);
        $registry->register($this->web);
        $registry->register($this->telegram);
    }

    // ---------------------------------------------------------------
    // Time and the sweep
    // ---------------------------------------------------------------

    private function at(string $instant): void
    {
        $this->travelTo(Carbon::parse($instant, 'UTC'));
    }

    private function sweep(): void
    {
        $this->assertSame(0, Artisan::call(self::SWEEP), 'the sweep succeeds');
    }

    /** Work every queued resume the way a queue worker does. */
    private function workQueuedResumes(): int
    {
        $worked = 0;
        foreach (Queue::pushed(ResumeAfterQuestionJob::class)->all() as $job) {
            if (isset($this->worked[spl_object_id($job)])) {
                continue;
            }
            $this->worked[spl_object_id($job)] = true;
            $this->app->call([$job, 'handle']);
            $worked++;
        }

        return $worked;
    }

    // ---------------------------------------------------------------
    // The streaming path
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

    private function askArguments(): array
    {
        // A safe default: assume PHPUnit if nobody answers within the hour.
        return ['question' => self::QUESTION, 'choices' => self::CHOICES, 'assumed_choices' => [1], 'wait_minutes' => self::WAIT_MINUTES];
    }

    /** A user message posted through the streaming entry point, and the agent's turn that asks. */
    private function askThroughTheStream(Conversation $conversation): AgentQuestion
    {
        $this->actingAs($this->fixture()->user, 'api')->postJson(self::MESSAGE_ENDPOINT, [
            'conversation_id' => $conversation->id,
            'content' => 'Set up the tests for this project.',
        ])->assertStatus(201);
        $this->playTurn(0, '', [['id' => 'call_ask', 'name' => 'ask_user', 'arguments' => json_encode($this->askArguments())]]);
        $this->assertSame(1, $this->turnCount(), 'precondition: the run waits for the user, no further model request');

        $question = $this->questionIn($conversation);
        $this->assertAskedToProceed($question, 'stream');

        return $question;
    }

    /** The messaging channel's synchronous entry point, and the agent's turn that asks. */
    private function askThroughTheChat(Conversation $conversation): AgentQuestion
    {
        $this->script()
            ->toolRequest('ask_user', $this->askArguments())
            ->finalAnswer(self::FINAL_ANSWER);

        $this->actingAs($this->fixture()->user, 'api')->postJson(self::AGENT_ENDPOINT, [
            'message' => 'Set up the tests for this project.',
            'channel' => 'telegram',
            'conversation_id' => $conversation->id,
        ])->assertStatus(202)->assertJson(['status' => 'awaiting_user']);
        $this->assertCount(1, $this->transport()->capturedChatPayloads(), 'precondition: the run waits for the user, no further model request');

        $question = $this->questionIn($conversation);
        $this->assertAskedToProceed($question, 'sync');

        return $question;
    }

    private function telegramConversation(): Conversation
    {
        $fixture = $this->fixture()->build();
        $fixture->conversation->update(['channel' => 'telegram']);

        return $fixture->conversation->fresh();
    }

    // ---------------------------------------------------------------
    // Shared checks
    // ---------------------------------------------------------------

    private function questionIn(Conversation $conversation): AgentQuestion
    {
        $question = AgentQuestion::where('conversation_id', $conversation->id)->latest('asked_at')->first();
        $this->assertNotNull($question, 'the agent\'s ask must have opened a question');

        return $question;
    }

    /** Asked with a safe default: it proceeds on choice 1, an hour from now. */
    private function assertAskedToProceed(AgentQuestion $question, string $resumeMode): void
    {
        $question = $question->fresh();
        $this->assertSame(QuestionState::Pending, $question->state);
        $this->assertSame($resumeMode, $question->resume_mode);
        $this->assertSame(UnansweredPolicy::ProceedOnAssumption, $question->unanswered_policy, 'an assumed answer means proceed');
        $this->assertSame('1', $question->assumed_answer_raw);
        $this->assertSame([['number' => 1, 'text' => 'PHPUnit']], $question->assumed_answer_selected);
        $this->assertSame(self::WAIT_MINUTES, $question->effective_wait_minutes);
        $this->assertSame('2026-10-12 10:00:00', $question->expires_at->copy()->utc()->format('Y-m-d H:i:s'));
        $this->assertNotNull($question->delivered_at);
    }

    /** @return array<string, mixed> the question view on the question's display message, as its owner's screen loads it */
    private function questionView(AgentQuestion $question): array
    {
        $listed = $this->actingAs($this->fixture()->user, 'api')
            ->getJson(self::API."/conversation/{$question->conversation_id}/message")
            ->assertStatus(200)
            ->json();

        foreach ($listed as $message) {
            if (($message['id'] ?? null) === $question->display_message_id) {
                $this->assertIsArray($message['question'] ?? null, 'the display message carries a question view');

                return $message['question'];
            }
        }
        $this->fail('the question\'s display message is not listed');
    }

    /** @return list<array<string, mixed>> the notices listed in the question's conversation */
    private function listedNotices(AgentQuestion $question): array
    {
        $listed = $this->actingAs($this->fixture()->user, 'api')
            ->getJson(self::API."/conversation/{$question->conversation_id}/message")
            ->assertStatus(200)
            ->json();

        return array_values(array_filter($listed, fn (array $m) => ($m['tool_data']['kind'] ?? null) === 'agent_question_notice'));
    }

    /** The question and its web view say, in advance, what will be assumed and by when. */
    private function assertStatedInAdvance(AgentQuestion $question): void
    {
        $this->assertSame(
            self::PLAIN."\n\n".self::PROCEED_STATEMENT,
            Message::findOrFail($question->display_message_id)->content,
            'the stored question ends with the statement',
        );

        $view = $this->questionView($question);
        $this->assertSame('pending', $view['state']);
        $this->assertTrue($view['answerable']);
        $this->assertSame('proceed_on_assumption', $view['if_unanswered']);
        $this->assertSame(self::PROCEED_STATEMENT, $view['expiry_statement'], 'the web screen shows the same statement');
    }

    /**
     * Nobody answers. Just before the deadline the sweep leaves it alone;
     * just after, it expires on its assumption and one resume is queued.
     */
    private function letTheDeadlinePass(AgentQuestion $question): void
    {
        $this->at(self::JUST_BEFORE_DEADLINE);
        $this->sweep();
        $this->assertSame(QuestionState::Pending, $question->fresh()->state, 'precondition: still waiting inside its period');
        $this->assertCount(0, Queue::pushed(ResumeAfterQuestionJob::class));

        $this->at(self::SWEPT_AT);
        $this->sweep();

        $expired = $question->fresh();
        $this->assertSame(QuestionState::ExpiredAssumed, $expired->state, 'an unanswered proceed question expires on its assumption');
        $this->assertNull($expired->pending_conversation_id, 'the decision is made: the conversation\'s question slot is free');
        $this->assertSame('2026-10-12 10:01:00', $expired->resolved_at?->copy()->utc()->format('Y-m-d H:i:s'));
        $this->assertNull($expired->stopped_at, 'nothing stopped');
        $this->assertNull($expired->answer_raw, 'an assumption is never recorded as the user\'s answer');
        $this->assertCount(1, Queue::pushed(ResumeAfterQuestionJob::class), 'exactly one resume is queued');
    }

    /** The exact result the agent receives for the assumed expiry. */
    private function expiredAssumed(AgentQuestion $question): array
    {
        return [
            'outcome' => 'expired_assumed',
            'question_id' => $question->id,
            'waiting_period_minutes' => self::WAIT_MINUTES,
            'answer_source' => 'assumed',
            'assumed_answer' => ['raw_reply' => '1', 'is_selection' => true, 'selected' => [['number' => 1, 'text' => 'PHPUnit']]],
            'message' => self::ASSUMED_MESSAGE,
        ];
    }

    private function toolResultFor(array $messages, string $toolCallId): ?array
    {
        foreach ($messages as $message) {
            if (($message['role'] ?? null) === 'tool' && ($message['tool_call_id'] ?? null) === $toolCallId) {
                $content = $message['content'] ?? null;

                return json_decode(is_string($content) ? $content : json_encode($content), true);
            }
        }

        return null;
    }

    /** The user was told what was assumed, once, in the conversation. */
    private function assertNoticeListed(AgentQuestion $question): void
    {
        $notices = $this->listedNotices($question);
        $this->assertCount(1, $notices, 'the notice is in the conversation, once');
        $this->assertSame(self::ASSUMED_NOTICE, $notices[0]['content']);
        $this->assertSame(
            ['kind' => 'agent_question_notice', 'question_id' => $question->id, 'state' => 'expired_assumed'],
            array_intersect_key($notices[0]['tool_data'], ['kind' => 1, 'question_id' => 1, 'state' => 1]),
        );
    }

    /** The run continued on the assumption and completed; the run record shows the ask ended without an answer. */
    private function assertProceededAndCompleted(AgentQuestion $question): void
    {
        $question = $question->fresh();
        $this->assertSame(QuestionState::ExpiredAssumed, $question->state);
        $this->assertNotNull($question->resumed_at, 'the run was continued from the paused point');
        $this->assertSame('expired_assumed', Message::findOrFail($question->display_message_id)->tool_data['state'] ?? null, 'the question is shown as expired on its assumption');
        $this->assertSame('completed', DB::table('agent_runs')->where('id', $question->run_id)->value('end_state'), 'the continued run completes');

        $action = DB::table('agent_run_actions')->where('id', $question->action_id)->first(['outcome', 'failure_reason']);
        $this->assertSame('failure', $action->outcome, 'the ask ended without the user\'s answer');
        $this->assertSame('question expired_assumed', $action->failure_reason);

        $this->assertFalse((bool) Conversation::findOrFail($question->conversation_id)->is_processing);

        $view = $this->questionView($question);
        $this->assertSame('expired_assumed', $view['state']);
        $this->assertFalse($view['answerable'], 'the web form no longer takes an answer');
        $this->assertSame(self::ASSUMED_OUTCOME, $view['outcome_text']);

        $this->assertSame([], $this->problemsLogged, 'nothing failed along the way');
    }

    // ---------------------------------------------------------------
    // The web conversation, streaming
    // ---------------------------------------------------------------

    #[Test]
    public function on_the_web_the_agent_proceeds_on_its_assumption_and_the_user_is_told(): void
    {
        $this->scenario = 'expiry_proceed_web_stream';
        $this->entryPath = 'stream';

        $question = $this->askThroughTheStream($this->fixture()->build()->conversation);

        // (1) Said up front, everywhere the question is shown.
        $this->assertStatedInAdvance($question);
        $this->assertSame([self::PLAIN."\n\n".self::PROCEED_STATEMENT], $this->web->textsFor($question->conversation_id), 'delivered with the statement');

        // (2) Nobody answers; the run continues on the assumption.
        $this->letTheDeadlinePass($question);
        $this->assertSame(1, $this->turnCount(), 'nothing is asked of the model until the queued resume runs');
        $this->assertSame(1, $this->workQueuedResumes());

        $this->assertSame(2, $this->turnCount(), 'the resume makes exactly one continuation request');
        $this->assertSame($this->expiredAssumed($question), $this->toolResultFor($this->streamPayloadMessages(1), 'call_ask'));
        $this->playTurn(1, self::FINAL_ANSWER);

        // (3) The user was told on the web, once.
        $texts = $this->web->textsFor($question->conversation_id);
        $this->assertSame([self::PLAIN."\n\n".self::PROCEED_STATEMENT, self::ASSUMED_NOTICE], array_slice($texts, 0, 2));
        $this->assertSame(1, count(array_keys($texts, self::ASSUMED_NOTICE, true)), 'the notice is delivered once');
        $this->assertNoticeListed($question);

        // (4)(5) No longer answerable; the run completed and its record shows the expiry.
        $this->assertProceededAndCompleted($question);
    }

    // ---------------------------------------------------------------
    // The messaging conversation, synchronous
    // ---------------------------------------------------------------

    #[Test]
    public function in_the_chat_the_agent_proceeds_on_its_assumption_and_the_chat_is_told(): void
    {
        $this->scenario = 'expiry_proceed_telegram_sync';
        $this->entryPath = 'sync';

        $question = $this->askThroughTheChat($this->telegramConversation());

        // (1) Said up front: in the stored text, the buttons' message and its plain fallback.
        $this->assertStatedInAdvance($question);
        $presentations = $this->telegram->presentationsFor($question->conversation_id);
        $this->assertCount(1, $presentations, 'the question is shown in the chat with its choices as buttons');
        $this->assertSame(self::WITH_OPTIONS."\n\n".self::PROCEED_STATEMENT, $presentations[0]->bodyText);
        $this->assertSame(self::PLAIN."\n\n".self::PROCEED_STATEMENT, $presentations[0]->plainText);
        $this->assertSame([], $this->telegram->textsFor($question->conversation_id), 'precondition: nothing sent as plain text yet');

        // (2) Nobody answers; the run continues on the assumption.
        $this->letTheDeadlinePass($question);
        $this->assertCount(1, $this->transport()->capturedChatPayloads(), 'nothing is asked of the model until the queued resume runs');
        $this->assertSame(1, $this->workQueuedResumes());

        $this->assertCount(2, $this->transport()->capturedChatPayloads(), 'the resume makes exactly one continuation request');
        $this->assertSame($this->expiredAssumed($question), $this->toolResultFor($this->chatPayloadMessages(1), $question->tool_call_id));

        // (3) The chat was told what was assumed, then got the agent's reply.
        $this->assertSame([self::ASSUMED_NOTICE, self::FINAL_ANSWER], $this->telegram->textsFor($question->conversation_id));
        $this->assertSame([], $this->web->deliveries, 'nothing went to the web');
        $this->assertNoticeListed($question);

        // (4) The buttons are refreshed once: no longer answerable, naming the assumption.
        $resolutions = $this->telegram->resolutionsFor($question->id);
        $this->assertCount(1, $resolutions);
        $this->assertSame(QuestionState::ExpiredAssumed, $resolutions[0]->state);
        $this->assertFalse($resolutions[0]->answerable);
        $this->assertSame(self::ASSUMED_OUTCOME, $resolutions[0]->summary);

        // (5)
        $this->assertProceededAndCompleted($question);
    }

    // ---------------------------------------------------------------
    // The messaging conversation, reached through the streaming path
    // ---------------------------------------------------------------

    #[Test]
    public function a_messaging_conversation_on_the_streaming_path_proceeds_and_its_chat_is_told(): void
    {
        $this->scenario = 'expiry_proceed_telegram_stream';
        $this->entryPath = 'stream';

        $question = $this->askThroughTheStream($this->telegramConversation());

        // (1)
        $this->assertStatedInAdvance($question);
        $presentations = $this->telegram->presentationsFor($question->conversation_id);
        $this->assertCount(1, $presentations, 'the question is shown in the chat with its choices as buttons');
        $this->assertSame(self::WITH_OPTIONS."\n\n".self::PROCEED_STATEMENT, $presentations[0]->bodyText);

        // (2)
        $this->letTheDeadlinePass($question);
        $this->assertSame(1, $this->workQueuedResumes());
        $this->assertSame(2, $this->turnCount(), 'the resume makes exactly one continuation request');
        $this->assertSame($this->expiredAssumed($question), $this->toolResultFor($this->streamPayloadMessages(1), 'call_ask'));
        $this->playTurn(1, self::FINAL_ANSWER);

        // (3) The notice goes to the conversation's own channel, the chat.
        $texts = $this->telegram->textsFor($question->conversation_id);
        $this->assertSame(self::ASSUMED_NOTICE, $texts[0] ?? null, 'the chat is told what was assumed');
        $this->assertSame(1, count(array_keys($texts, self::ASSUMED_NOTICE, true)), 'once');
        $this->assertSame([], $this->web->deliveries, 'nothing is delivered through the web adapter');
        $this->assertNoticeListed($question);

        // (4)
        $resolutions = $this->telegram->resolutionsFor($question->id);
        $this->assertCount(1, $resolutions);
        $this->assertSame(QuestionState::ExpiredAssumed, $resolutions[0]->state);
        $this->assertFalse($resolutions[0]->answerable);
        $this->assertSame(self::ASSUMED_OUTCOME, $resolutions[0]->summary);

        // (5)
        $this->assertProceededAndCompleted($question);
    }

    // ---------------------------------------------------------------
    // An answer in time is unaffected
    // ---------------------------------------------------------------

    #[Test]
    public function an_answer_in_time_is_used_and_nothing_is_assumed(): void
    {
        $this->scenario = 'expiry_proceed_answered_in_time';
        $this->entryPath = 'sync';

        $conversation = $this->telegramConversation();
        $question = $this->askThroughTheChat($conversation);

        $this->at(self::JUST_BEFORE_DEADLINE);
        $this->actingAs($this->fixture()->user, 'api')->postJson(self::AGENT_ENDPOINT, [
            'message' => '2',
            'channel' => 'telegram',
            'conversation_id' => $conversation->id,
        ])->assertStatus(200)->assertJson(['status' => 'completed']);

        $result = $this->toolResultFor($this->chatPayloadMessages(1), $question->tool_call_id);
        $this->assertSame('answered', $result['outcome'] ?? null);
        $this->assertSame([['number' => 2, 'text' => 'Pest']], $result['answer']['selected'] ?? null, 'the user\'s answer, not the assumption');

        $this->at(self::SWEPT_AT);
        $this->sweep();

        $this->assertSame(QuestionState::Answered, $question->fresh()->state, 'the deadline no longer matters');
        $this->assertSame([], $this->listedNotices($question), 'nobody is told anything was assumed');
        $this->assertCount(0, Queue::pushed(ResumeAfterQuestionJob::class));
    }
}
