<?php

namespace Tests\Integration;

use ClarionApp\LlmClient\Jobs\AgentStreamStepJob;
use ClarionApp\LlmClient\Events\ConversationEnded;
use ClarionApp\LlmClient\Events\FinishOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Events\ToolExecutionEvent;
use ClarionApp\LlmClient\Events\UpdateOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Jobs\ResumeAfterQuestionJob;
use ClarionApp\LlmClient\LlmClientServiceProvider;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\ValueObjects\AnswerMethod;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswerStatus;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\CapturedPayload;
use Tests\Integration\Harness\RecordingRichChannelDelivery;

/**
 * A question waits for hours, so the user who was asked may come back to it
 * somewhere else: after a reload, on another device, in another browser, or
 * on the web screen for a conversation that lives in the messaging app.
 * Wherever they come back, the question is rebuilt from what the server
 * holds — the same form while it waits, its outcome once it no longer does —
 * and answering it there resumes the task as answering it anywhere else
 * would.
 *
 * "Another session" here is a fresh one: the llm-client services the asking
 * request built are discarded (as a new request in a new PHP process would
 * have none of them), the authentication guard is forgotten, and the second
 * session authenticates with its own API token rather than by being handed
 * the user. Nothing the first session held in memory can reach it.
 *
 * Real wiring throughout — MessageController, QuestionController,
 * AgentController, the question services, the resumer, the after-commit
 * presentation updater — with the model scripted at the HTTP and streaming
 * boundaries and a recording adapter that presents questions registered for
 * the `telegram` channel.
 */
class QuestionReturningUserJourneyTest extends AssembledSystemTestCase
{
    private const API = '/api/clarion-app/llm-client';

    private const SWEEP = 'llm-client:resolve-expired-questions';

    private const QUESTION = 'Which part should I change first?';

    private const CHOICES = ['Backend', 'Frontend', 'Docs'];

    private const PROMPT = 'Help me tidy up the project.';

    private const CONTINUED = 'Starting with the frontend.';

    private RecordingRichChannelDelivery $telegram;

    /** The second session's own API token. */
    private string $secondToken;

    /** Resume jobs already run by the simulated worker. */
    private int $workedJobs = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['llm-client.run_trace.enabled' => true]);

        // A tap's or an expiry's resume is queued; the test works it as a
        // queue worker would, after the request that queued it.
        Queue::fake([AgentStreamStepJob::class, ResumeAfterQuestionJob::class]);

        Event::fake([
            NewConversationMessageEvent::class,
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            ToolExecutionEvent::class,
            // Ending a conversation also captures its episodic memory through
            // a model call of its own, which is not what these journeys are about.
            ConversationEnded::class,
        ]);

        // The adapter stands for the deployed Telegram package: every fresh
        // registry has it, as every process that boots the package would.
        $this->telegram = new RecordingRichChannelDelivery('telegram');
        $this->app->make(ConversationChannelRegistry::class)->register($this->telegram);
        $this->app->afterResolving(
            ConversationChannelRegistry::class,
            fn (ConversationChannelRegistry $registry) => $registry->register($this->telegram),
        );

        $this->fixture()->build();

        // The api guard's token driver authenticates a request by the user's
        // `api_token`; the hand-declared users table has no such column, so
        // the second session's credential is added here.
        if (!Schema::hasColumn('users', 'api_token')) {
            Schema::table('users', fn (Blueprint $table) => $table->string('api_token', 80)->nullable());
        }
        $this->secondToken = Str::random(60);
        DB::table('users')->where('id', $this->fixture()->user->id)->update(['api_token' => $this->secondToken]);
    }

    // ---------------------------------------------------------------
    // Sessions
    // ---------------------------------------------------------------

    /** The session the question was asked in: the user, already signed in. */
    private function firstSession(): self
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($this->fixture()->user, 'api');
    }

    /**
     * Begin a second, fresh session for the same user: every llm-client
     * instance the first session's requests built is discarded and the
     * authentication guard forgotten, so the second session starts from what
     * the server stores and nothing else. The container's `forgetInstances()`
     * would also discard the database connection, and with it the in-memory
     * database standing for the durable store, so the package's instances
     * are forgotten one by one instead.
     */
    private function startSecondSession(): void
    {
        $instances = (new \ReflectionProperty($this->app, 'instances'))->getValue($this->app);

        $forgotten = 0;
        foreach ($instances as $abstract => $instance) {
            if (str_starts_with((string) $abstract, 'ClarionApp\\LlmClient\\')
                || (is_object($instance) && str_starts_with(get_class($instance), 'ClarionApp\\LlmClient\\'))) {
                $this->app->forgetInstance($abstract);
                $forgotten++;
            }
        }
        $this->app->forgetScopedInstances();
        Facade::clearResolvedInstances();
        $this->assertGreaterThan(0, $forgotten, 'precondition: the first session held llm-client instances');

        // A fresh process boots the package again, refilling the in-memory
        // registries (model providers, presets, agent kinds, starting points).
        $provider = $this->app->getProvider(LlmClientServiceProvider::class);
        $this->assertNotNull($provider, 'precondition: the package provider is registered');
        (function () {
            $this->registerProviders();
            $this->registerPresets();
            $this->registerAgentKinds();
            $this->registerAgentStartingPoints();
        })->call($provider);

        $this->app['auth']->forgetGuards();

        // The second session is not signed in by anything the first one did.
        $this->getJson(self::API.'/conversation/'.$this->fixture()->conversation->id.'/message')->assertStatus(401);
        $this->app['auth']->forgetGuards();
    }

    private function secondHeaders(): array
    {
        return ['Authorization' => 'Bearer '.$this->secondToken];
    }

    private function secondGet(string $uri): TestResponse
    {
        return $this->getJson($uri, $this->secondHeaders());
    }

    private function secondPost(string $uri, array $body = []): TestResponse
    {
        return $this->postJson($uri, $body, $this->secondHeaders());
    }

    // ---------------------------------------------------------------
    // Reading the conversation screen
    // ---------------------------------------------------------------

    private function messagesUrl(string $conversationId): string
    {
        return self::API."/conversation/{$conversationId}/message";
    }

    private function answerUrl(string $questionId): string
    {
        return self::API."/question/{$questionId}/answer";
    }

    /** @return array<string, mixed> the question view on $question's display message in $list */
    private function viewIn(TestResponse $list, AgentQuestion $question): array
    {
        $list->assertStatus(200);
        foreach ($list->json() as $message) {
            if (($message['id'] ?? null) === $question->display_message_id) {
                $this->assertArrayHasKey('question', $message, 'the question\'s display message carries its view');

                return $message['question'];
            }
        }
        $this->fail('the question\'s display message is not listed');
    }

    private function firstSessionView(AgentQuestion $question): array
    {
        return $this->viewIn($this->firstSession()->getJson($this->messagesUrl($question->conversation_id)), $question);
    }

    private function secondSessionView(AgentQuestion $question): array
    {
        return $this->viewIn($this->secondGet($this->messagesUrl($question->conversation_id)), $question);
    }

    // ---------------------------------------------------------------
    // The web conversation (streaming)
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

    /** The decoded `ask_user` result in streaming request #$slot. */
    private function streamedAskResult(int $slot): array
    {
        $payloads = $this->stream()->extractDispatchedJobs()->capturedPayloads();
        $this->assertArrayHasKey($slot, $payloads, "no streaming request #{$slot} was dispatched");
        /** @var CapturedPayload $payload */
        $payload = $payloads[$slot];

        foreach (json_decode(json_encode($payload->messages), true) as $message) {
            if (($message['role'] ?? null) === 'tool' && ($message['tool_call_id'] ?? null) === 'call_ask') {
                $content = is_string($message['content'] ?? null) ? $message['content'] : json_encode($message['content']);

                return json_decode($content, true);
            }
        }
        $this->fail("streaming request #{$slot} does not answer the ask_user call");
    }

    /** The first session starts a web task whose agent asks the three-choice question. */
    private function askOnTheWeb(): AgentQuestion
    {
        $conversation = $this->fixture()->conversation;
        $this->assertSame('web', (string) ($conversation->fresh()->channel ?? 'web'), 'precondition: a web conversation');

        $turnsBefore = $this->turnCount();
        $this->firstSession()->postJson(self::API.'/message', [
            'conversation_id' => $conversation->id,
            'content' => self::PROMPT,
        ])->assertStatus(201);
        $this->playTurn($turnsBefore, '', [[
            'id' => 'call_ask',
            'name' => 'ask_user',
            'arguments' => json_encode(['question' => self::QUESTION, 'choices' => self::CHOICES]),
        ]]);
        $this->assertSame($turnsBefore + 1, $this->turnCount(), 'the run waits for the user: no further model request');

        $question = AgentQuestion::where('conversation_id', $conversation->id)->where('state', QuestionState::Pending->value)->first();
        $this->assertNotNull($question, 'the agent\'s ask opened a question');

        return $question;
    }

    // ---------------------------------------------------------------
    // The messaging conversation (synchronous)
    // ---------------------------------------------------------------

    private function telegramConversation(): Conversation
    {
        $conversation = Conversation::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->fixture()->user->id,
            'server_id' => $this->fixture()->server->id,
            'model' => 'gpt-4',
            'title' => 'Telegram conversation',
        ]);
        $conversation->update(['channel' => 'telegram']);

        return $conversation->fresh();
    }

    /** A Telegram message from the user's phone, sent the way TelegramChatService sends it; the agent asks. */
    private function askInTheMessagingApp(Conversation $conversation): AgentQuestion
    {
        $this->firstSession()->postJson(self::API.'/agent', [
            'message' => self::PROMPT,
            'channel' => 'telegram',
            'conversation_id' => $conversation->id,
        ])->assertStatus(202)->assertJson(['status' => 'awaiting_user']);

        $question = AgentQuestion::where('conversation_id', $conversation->id)->where('state', QuestionState::Pending->value)->first();
        $this->assertNotNull($question, 'the agent\'s ask opened a question');
        $this->assertSame('sync', $question->resume_mode);
        $this->assertCount(1, $this->telegram->presentationsFor($conversation->id), 'precondition: presented in the chat with buttons');

        return $question;
    }

    /** @return list<array<string, mixed>> every `ask_user` result for $question the model was sent synchronously */
    private function syncAskResults(AgentQuestion $question): array
    {
        $results = [];
        /** @var CapturedPayload $payload */
        foreach ($this->transport()->capturedChatPayloads() as $payload) {
            foreach (json_decode(json_encode($payload->messages), true) as $message) {
                if (($message['role'] ?? null) === 'tool' && ($message['tool_call_id'] ?? null) === $question->tool_call_id) {
                    $results[] = json_decode(is_string($message['content']) ? $message['content'] : json_encode($message['content']), true);
                }
            }
        }

        return $results;
    }

    /** Run every queued resume job not yet run, as a worker would. */
    private function workQueuedResumes(): void
    {
        $jobs = Queue::pushed(ResumeAfterQuestionJob::class)->values()->all();

        while ($this->workedJobs < count($jobs)) {
            $this->app->call([$jobs[$this->workedJobs], 'handle']);
            $this->workedJobs++;
            $jobs = Queue::pushed(ResumeAfterQuestionJob::class)->values()->all();
        }
    }

    /** Refresh broadcasts naming $question's display message on its conversation's channel. */
    private function refreshesOf(AgentQuestion $question): int
    {
        return Event::dispatched(
            NewConversationMessageEvent::class,
            fn (NewConversationMessageEvent $e) => $e->conversation_id === $question->conversation_id
                && $e->message_id === $question->display_message_id,
        )->count();
    }

    // ---------------------------------------------------------------
    // Scenarios
    // ---------------------------------------------------------------

    #[Test]
    public function a_second_session_sees_the_same_form_and_answering_there_resumes_the_task(): void
    {
        $this->scenario = 'returning_user_second_session';
        $this->entryPath = 'stream';

        $question = $this->askOnTheWeb();

        $first = $this->firstSessionView($question);
        $this->assertSame('pending', $first['state']);
        $this->assertSame([
            ['number' => 1, 'text' => 'Backend'],
            ['number' => 2, 'text' => 'Frontend'],
            ['number' => 3, 'text' => 'Docs'],
        ], $first['choices']);
        $this->assertSame($first, $this->firstSessionView($question), 'a reload shows the same form');

        // Another device, another browser: nothing carried over but the user.
        $this->startSecondSession();
        $second = $this->secondSessionView($question);
        $this->assertSame($first, $second, 'the second session is shown exactly the same form, built from server state');

        // Answering there resumes the task.
        $turnsBefore = $this->turnCount();
        $answered = $this->secondPost($this->answerUrl($question->id), ['selected' => [2]]);
        $answered->assertStatus(201);
        $this->assertSame('answered', $answered->json('status'));
        $this->assertSame($turnsBefore + 1, $this->turnCount(), 'the answer resumes the run with exactly one continuation request');
        $this->assertSame([
            'outcome' => 'answered',
            'question_id' => $question->id,
            'answer' => [
                'raw_reply' => '2',
                'is_selection' => true,
                'selected' => [['number' => 2, 'text' => 'Frontend']],
            ],
        ], $this->streamedAskResult($turnsBefore), 'the agent receives the second session\'s choice');
        $this->playTurn($turnsBefore, 'Done.');

        $fresh = $question->fresh();
        $this->assertSame(QuestionState::Answered, $fresh->state);
        $this->assertSame(AnswerMethod::WebForm, $fresh->answer_method);
        $this->assertNotNull($fresh->resumed_at);
        $this->assertSame('completed', DB::table('agent_runs')->where('id', $fresh->run_id)->value('end_state'), 'the resumed run completes');

        // The first session's next load shows it answered, not answerable.
        $expectedAnswer = [
            'is_selection' => true,
            'selected' => [['number' => 2, 'text' => 'Frontend']],
            'raw_reply' => '2',
            'method' => 'web_form',
        ];
        $firstAgain = $this->firstSessionView($question);
        $this->assertSame('answered', $firstAgain['state']);
        $this->assertSame($expectedAnswer, $firstAgain['answer']);
        $this->assertSame($firstAgain, $this->secondSessionView($question), 'both sessions show the same outcome');
        $this->assertSame($answered->json('question'), $firstAgain, 'the outcome shown is the one the answering session was told');

        // The first session's stale form answers nothing.
        $stale = $this->firstSession()->postJson($this->answerUrl($question->id), ['selected' => [1]]);
        $stale->assertStatus(409);
        $this->assertSame('answered', $stale->json('state'));
        $this->assertSame($turnsBefore + 1, $this->turnCount(), 'a stale submission resumes nothing');
        $this->assertSame('2', $question->fresh()->answer_raw, 'the second session\'s answer stands');
    }

    /** @return array<string, array{string}> */
    public static function awayResolutions(): array
    {
        return [
            'answered in the messaging app' => ['tap'],
            'expired' => ['expire'],
            'withdrawn' => ['withdraw'],
        ];
    }

    #[Test]
    #[DataProvider('awayResolutions')]
    public function a_question_resolved_while_the_user_was_away_is_shown_resolved(string $resolution): void
    {
        $this->scenario = 'returning_user_resolved_away_'.$resolution;
        $this->entryPath = 'sync';
        $this->script()->toolRequest('ask_user', ['question' => self::QUESTION, 'choices' => self::CHOICES]);
        if ($resolution !== 'withdraw') {
            $this->script()->finalAnswer(self::CONTINUED);
        }

        $conversation = $this->telegramConversation();
        $question = $this->askInTheMessagingApp($conversation);
        $this->assertSame('pending', $this->firstSessionView($question)['state'], 'precondition: the form was shown before the user left');

        // While the user is away.
        match ($resolution) {
            // The harness tap works its own queued resume, as the worker would.
            'tap' => (function () use ($question) {
                $this->assertSame(
                    InteractiveAnswerStatus::Answered,
                    $this->telegram->tap($question->id, (string) $this->fixture()->user->id, [2])->status,
                );
                $this->workedJobs = Queue::pushed(ResumeAfterQuestionJob::class)->count();
            })(),
            'expire' => (function () use ($question) {
                // A question asked before unanswered policies existed: it expires as it always did.
                DB::table('agent_questions')->where('id', $question->id)->update(['unanswered_policy' => null, 'effective_wait_minutes' => null]);
                DB::table('agent_questions')->where('id', $question->id)->update(['expires_at' => now()->subMinute()]);
                $this->assertSame(0, Artisan::call(self::SWEEP));
            })(),
            'withdraw' => $this->firstSession()
                ->postJson(self::API."/conversation/{$conversation->id}/end")
                ->assertStatus(200),
        };
        $this->workQueuedResumes();

        $expectedState = ['tap' => 'answered', 'expire' => 'expired', 'withdraw' => 'withdrawn'][$resolution];
        $this->assertSame($expectedState, $question->fresh()->state->value, 'precondition: resolved while away');

        // The user returns in a fresh session.
        $this->startSecondSession();
        $view = $this->secondSessionView($question);

        $this->assertSame($question->id, $view['id']);
        $this->assertSame($expectedState, $view['state'], 'shown resolved, not as an answerable form');
        $this->assertNotSame('pending', $view['state']);
        if ($resolution === 'tap') {
            $this->assertSame([
                'is_selection' => true,
                'selected' => [['number' => 2, 'text' => 'Frontend']],
                'raw_reply' => '2',
                'method' => 'messaging_option',
            ], $view['answer'], 'with the answer given elsewhere');
        } else {
            $this->assertNull($view['answer'], 'with no answer');
        }
        $this->assertSame($view, $this->firstSessionView($question), 'every session shows the same outcome');

        // Submitting the returned-to screen's form changes nothing.
        $this->startSecondSession();
        $messages = DB::table('messages')->count();
        $requests = count($this->transport()->capturedChatPayloads());
        $row = (array) DB::table('agent_questions')->where('id', $question->id)->first();

        $late = $this->secondPost($this->answerUrl($question->id), ['selected' => [1]]);
        $late->assertStatus(409);
        $this->assertSame($expectedState, $late->json('state'));
        $this->assertSame($messages, DB::table('messages')->count(), 'no message written');
        $this->assertSame($requests, count($this->transport()->capturedChatPayloads()), 'no model request');
        $this->assertSame($row, (array) DB::table('agent_questions')->where('id', $question->id)->first(), 'the question is untouched');
    }

    #[Test]
    public function a_messaging_conversation_opened_on_the_web_is_answered_with_the_form_and_the_reply_goes_to_the_chat(): void
    {
        $this->scenario = 'returning_user_messaging_on_web';
        $this->entryPath = 'sync';
        $this->script()
            ->toolRequest('ask_user', ['question' => self::QUESTION, 'choices' => self::CHOICES])
            ->finalAnswer(self::CONTINUED);

        $conversation = $this->telegramConversation();
        $question = $this->askInTheMessagingApp($conversation);
        $presentation = $this->telegram->presentationsFor($conversation->id)[0];
        $this->assertCount(1, $this->transport()->capturedChatPayloads(), 'precondition: the run waits on the user');

        // The user opens the conversation on the web screen.
        $this->startSecondSession();
        $view = $this->secondSessionView($question);

        $this->assertSame('pending', $view['state'], 'shown as the web form');
        $this->assertSame($presentation->question, $view['question']);
        $this->assertSame(
            array_map(fn ($option) => ['number' => $option->number, 'text' => $option->text], $presentation->options),
            $view['choices'],
            'the form offers exactly the choices the chat shows as buttons',
        );
        $this->assertSame($presentation->allowMultiple, $view['allow_multiple']);
        $this->assertSame($question->preamble, $view['preamble']);

        $refreshesBefore = $this->refreshesOf($question);

        $answered = $this->secondPost($this->answerUrl($question->id), ['selected' => [2]]);
        $answered->assertStatus(201);
        $this->assertSame('answered', $answered->json('question.state'));
        $this->assertSame('web_form', $answered->json('question.answer.method'));

        // The agent continues at once and its reply goes to the chat, where
        // the conversation lives.
        Queue::assertNotPushed(ResumeAfterQuestionJob::class);
        $this->assertSame([[
            'outcome' => 'answered',
            'question_id' => $question->id,
            'answer' => [
                'raw_reply' => '2',
                'is_selection' => true,
                'selected' => [['number' => 2, 'text' => 'Frontend']],
            ],
        ]], $this->syncAskResults($question), 'the run is resumed exactly once, with the form\'s choice');
        $this->assertSame([self::CONTINUED], $this->telegram->textsFor($conversation->id), 'the agent\'s reply is pushed to the chat');

        $fresh = $question->fresh();
        $this->assertSame(QuestionState::Answered, $fresh->state);
        $this->assertSame(AnswerMethod::WebForm, $fresh->answer_method);
        $this->assertNotNull($fresh->resumed_at);
        $this->assertSame('completed', DB::table('agent_runs')->where('id', $fresh->run_id)->value('end_state'));
        $this->assertFalse((bool) $conversation->fresh()->is_processing);

        // The chat's message is told to show the outcome instead of its buttons, once.
        $resolutions = $this->telegram->resolutionsFor($question->id);
        $this->assertCount(1, $resolutions, 'the chat is told of the resolution exactly once');
        $this->assertSame(QuestionState::Answered, $resolutions[0]->state);
        $this->assertSame([['number' => 2, 'text' => 'Frontend']], $resolutions[0]->selected);
        $this->assertSame(AnswerMethod::WebForm, $resolutions[0]->method);
        $this->assertSame('Answered: 2. Frontend', $resolutions[0]->summary);

        // Every open screen on that conversation is told to refresh.
        $this->assertSame($refreshesBefore + 1, $this->refreshesOf($question), 'exactly one refresh broadcast for the messaging conversation\'s question');

        // A tap on the chat's now-stale button answers nothing.
        $late = $this->telegram->tap($question->id, (string) $this->fixture()->user->id, [3]);
        $this->assertSame(InteractiveAnswerStatus::NotPending, $late->status);
        $this->assertSame(QuestionState::Answered, $late->state);
        $this->assertCount(1, $this->syncAskResults($question), 'the late tap resumed nothing');

        $answers = Message::where('conversation_id', $conversation->id)->get()
            ->filter(fn (Message $m) => ($m->tool_data['kind'] ?? null) === 'agent_question_answer')
            ->values();
        $this->assertCount(1, $answers, 'the answer is stored once');
        $this->assertSame('2', $answers[0]->content);
    }
}
