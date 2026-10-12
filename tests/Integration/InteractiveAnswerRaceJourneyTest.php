<?php

namespace Tests\Integration;

use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
use ClarionApp\LlmClient\Contracts\InteractiveQuestionAnswers;
use ClarionApp\LlmClient\Controllers\AgentController;
use ClarionApp\LlmClient\Controllers\ConversationController;
use ClarionApp\LlmClient\Controllers\QuestionController;
use ClarionApp\LlmClient\Events\ConversationEnded;
use ClarionApp\LlmClient\Jobs\ResumeAfterQuestionJob;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\ValueObjects\AnswerMethod;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswer;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswerOutcome;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswerStatus;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\CapturedPayload;
use Tests\Integration\Harness\RecordingRichChannelDelivery;

/**
 * Late, duplicate, stale and racing interactive answers. Buttons stay
 * tappable in chat history and a form stays submittable on a screen nobody
 * refreshed, so an interactive answer can arrive after its question was
 * resolved, twice, for an older question, or at the same moment as
 * another resolution. Whatever the interleaving:
 *
 * - exactly one resolution takes effect, and the paused run is resumed (or,
 *   for a withdrawal, closed) exactly once;
 * - an answer that lost creates no message, no run and no model request,
 *   and is told the question's actual state;
 * - an answer only ever applies to the question it names;
 * - the outcome the agent receives is the one the conversation screen and
 *   the messaging channel show.
 *
 * The conversations live on a messaging channel that presents options
 * (a recording adapter registered as `telegram`), on the synchronous path
 * messaging channels use, so all four ways a question resolves can meet:
 * the web form (`POST question/{id}/answer`), a tap (the interactive-answer
 * contract the Telegram package calls, its resume queued), a typed reply
 * (`POST agent`), expiry (the sweep and the resume it queues) and
 * withdrawal (`POST conversation/{id}/end`, `DELETE conversation/{id}`).
 *
 * A racing resolution is run from inside the first one, at the moment the
 * first has loaded the question as pending and not yet tried to resolve
 * it — the `eloquent.retrieved` hook AgentQuestionRaceJourneyTest uses — running
 * the same code its own request would (the controller action, the
 * contract, or the sweep command). Real wiring throughout, with the model
 * scripted at the HTTP boundary.
 */
class InteractiveAnswerRaceJourneyTest extends AssembledSystemTestCase
{
    private const AGENT_ENDPOINT = '/api/clarion-app/llm-client/agent';

    private const SWEEP = 'llm-client:resolve-expired-questions';

    private const QUESTION = 'Which part should I change first?';

    private const CHOICES = ['Backend', 'Frontend', 'Docs'];

    private const PROMPT = 'Help me tidy up the project.';

    private const CONTINUED = 'Carrying on with that.';

    private const TRIALS = 3;

    /** The choice each participant of a race picks: the first 2, the second 3. */
    private const PICK = ['first' => 2, 'second' => 3];

    private const STATE_OF = [
        'form' => 'answered',
        'tap' => 'answered',
        'typed' => 'answered',
        'expire' => 'expired',
        'withdraw' => 'withdrawn',
    ];

    private const METHOD_OF = [
        'form' => AnswerMethod::WebForm,
        'tap' => AnswerMethod::MessagingOption,
        'typed' => AnswerMethod::TypedReply,
    ];

    private RecordingRichChannelDelivery $telegram;

    /** Resume jobs already run by the simulated worker. */
    private int $workedJobs = 0;

    private ?string $hookQuestionId = null;

    private ?\Closure $interloper = null;

    private bool $hookFired = false;

    /**
     * What each participant of the current trial was told: a status code
     * (and body) for an HTTP route, an outcome for a tap.
     *
     * @var array<string, mixed>
     */
    private array $told = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->entryPath = 'sync';
        config(['llm-client.run_trace.enabled' => true]);

        // A tap's resume and an expiry's resume are queued; the test works
        // them as a queue worker would, after the request that queued them.
        Queue::fake([SendHttpStreamRequest::class, ResumeAfterQuestionJob::class]);

        // Ending a conversation also captures its episodic memory through a
        // model call of its own, which is not what these races are about.
        Event::fake([ConversationEnded::class]);

        $this->telegram = new RecordingRichChannelDelivery('telegram');
        $this->app->make(ConversationChannelRegistry::class)->register($this->telegram);

        // "Before the compare-and-set": the first resolver has read the
        // question as a model and not yet tried to resolve it.
        Event::listen('eloquent.retrieved: '.AgentQuestion::class, function (AgentQuestion $question) {
            if ($this->interloper !== null && !$this->hookFired && $question->id === $this->hookQuestionId) {
                $this->hookFired = true;
                ($this->interloper)();
            }
        });

        $this->fixture()->build();
    }

    private function arm(string $questionId, \Closure $interloper): void
    {
        $this->hookQuestionId = $questionId;
        $this->interloper = $interloper;
        $this->hookFired = false;
    }

    private function disarm(): void
    {
        $this->hookQuestionId = null;
        $this->interloper = null;
    }

    // ---------------------------------------------------------------
    // Driving the system
    // ---------------------------------------------------------------

    private function userId(): string
    {
        return (string) $this->fixture()->user->id;
    }

    private function telegramConversation(): Conversation
    {
        $conversation = Conversation::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->fixture()->user->id,
            'server_id' => $this->fixture()->server->id,
            'model' => 'gpt-4',
            'title' => 'Race conversation',
        ]);
        $conversation->update(['channel' => 'telegram']);

        return $conversation->fresh();
    }

    /** The model asks the question; once answered (or expired) it carries on, if $continues. */
    private function scriptAsk(bool $continues): void
    {
        $this->script()->toolRequest('ask_user', ['question' => self::QUESTION, 'choices' => self::CHOICES]);
        if ($continues) {
            $this->script()->finalAnswer(self::CONTINUED);
        }
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

    /** Start a task; the agent asks, and the run waits on the user. */
    private function ask(Conversation $conversation, string $prompt = self::PROMPT): AgentQuestion
    {
        $requestsBefore = count($this->transport()->capturedChatPayloads());

        $this->send($conversation, $prompt)->assertStatus(202);

        $question = AgentQuestion::where('conversation_id', $conversation->id)
            ->where('state', QuestionState::Pending->value)
            ->first();
        $this->assertNotNull($question, 'precondition: the ask opened a question');
        $this->assertCount($requestsBefore + 1, $this->transport()->capturedChatPayloads(), 'precondition: the run waits on the user');

        return $question;
    }

    /**
     * Move the question's deadline into the past rather than the clock
     * forward, so the run's own timings stay consistent.
     */
    private function pastItsAnswerablePeriod(AgentQuestion $question): void
    {
        DB::table('agent_questions')->where('id', $question->id)->update(['expires_at' => now()->subMinute()]);
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

    private function formUrl(string $questionId): string
    {
        return "/api/clarion-app/llm-client/question/{$questionId}/answer";
    }

    /** The form, posted as its own request. @return array{status: int, body: array<string, mixed>} */
    private function postForm(string $questionId, array $body): array
    {
        $response = $this->actingAs($this->fixture()->user, 'api')->postJson($this->formUrl($questionId), $body);

        return ['status' => $response->getStatusCode(), 'body' => (array) $response->json()];
    }

    /** The form, run as its request's controller action from inside another operation. */
    private function formAction(string $questionId, array $body): array
    {
        $this->actingAs($this->fixture()->user, 'api');
        $request = Request::create($this->formUrl($questionId), 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode($body));
        /** @var JsonResponse $response */
        $response = $this->app->make(QuestionController::class)->answer($request, $questionId);

        return ['status' => $response->getStatusCode(), 'body' => (array) $response->getData(true)];
    }

    /** A tap, as the Telegram package makes it. */
    private function tap(string $questionId, int|string $answer): InteractiveAnswerOutcome
    {
        return $this->app->make(InteractiveQuestionAnswers::class)->submit(
            $questionId,
            $this->userId(),
            is_int($answer) ? InteractiveAnswer::selection([$answer]) : InteractiveAnswer::freeText($answer),
            AnswerMethod::MessagingOption,
        );
    }

    /** A typed reply, run as its request's controller action from inside another operation. */
    private function typedAction(Conversation $conversation, string $text): array
    {
        $this->actingAs($this->fixture()->user, 'api');
        $request = Request::create(self::AGENT_ENDPOINT, 'POST', [
            'message' => $text,
            'channel' => 'telegram',
            'conversation_id' => $conversation->id,
        ]);
        $request->headers->set('Accept', 'application/json');
        /** @var JsonResponse $response */
        $response = $this->app->call([$this->app->make(AgentController::class), '__invoke'], ['request' => $request]);

        return ['status' => $response->getStatusCode(), 'body' => (array) $response->getData(true)];
    }

    /**
     * Run one participant of a race. $asInterloper selects the in-process
     * form of an HTTP route (it runs inside another request).
     */
    private function participate(string $kind, string $role, AgentQuestion $question, Conversation $conversation, bool $asInterloper): void
    {
        $pick = self::PICK[$role];

        $this->told[$role] = match ($kind) {
            'form' => $asInterloper
                ? $this->formAction($question->id, ['selected' => [$pick]])
                : $this->postForm($question->id, ['selected' => [$pick]]),
            'tap' => $this->tap($question->id, $pick),
            'typed' => $asInterloper
                ? $this->typedAction($conversation, (string) $pick)
                : (fn (TestResponse $r) => ['status' => $r->getStatusCode(), 'body' => (array) $r->json()])($this->send($conversation, (string) $pick)),
            'expire' => Artisan::call(self::SWEEP),
            'withdraw' => $asInterloper
                ? $this->app->call([$this->app->make(ConversationController::class), 'end'], ['id' => $conversation->id])->getStatusCode()
                : $this->actingAs($this->fixture()->user, 'api')->postJson("/api/clarion-app/llm-client/conversation/{$conversation->id}/end")->getStatusCode(),
        };
    }

    // ---------------------------------------------------------------
    // Observing one outcome
    // ---------------------------------------------------------------

    /** @return array<string, mixed>|null the decoded result stored for the paused ask_user call */
    private function storedAskResult(AgentQuestion $question): ?array
    {
        $paused = Message::findOrFail($question->paused_message_id);

        foreach ((array) ($paused->tool_data['tool_results'] ?? []) as $result) {
            if (($result['tool_call_id'] ?? null) === $question->tool_call_id) {
                return json_decode((string) ($result['content'] ?? ''), true);
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> every ask_user result for $question the model was sent */
    private function continuationsOf(AgentQuestion $question): array
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

    /** @return array<string, mixed> the question view the conversation screen gets */
    private function viewOf(AgentQuestion $question): array
    {
        $list = $this->actingAs($this->fixture()->user, 'api')
            ->getJson("/api/clarion-app/llm-client/conversation/{$question->conversation_id}/message")
            ->assertStatus(200)
            ->json();

        foreach ($list as $message) {
            if (($message['id'] ?? null) === $question->display_message_id) {
                $this->assertArrayHasKey('question', $message);

                return $message['question'];
            }
        }
        $this->fail('the question\'s display message is not listed');
    }

    /**
     * Everything an answer could have changed, to compare before and after
     * an answer that must change nothing.
     *
     * @return array<string, mixed>
     */
    private function footprint(AgentQuestion $question): array
    {
        return [
            'row' => (array) DB::table('agent_questions')->where('id', $question->id)->first(),
            'messages' => DB::table('messages')->count(),
            'runs' => DB::table('agent_runs')->count(),
            'steps' => DB::table('agent_run_steps')->count(),
            'model_requests' => count($this->transport()->capturedChatPayloads()),
            'resumes_queued' => Queue::pushed(ResumeAfterQuestionJob::class)->count(),
            'channel_resolutions' => count($this->telegram->resolutions),
            'channel_deliveries' => count($this->telegram->deliveries),
            'processing' => (bool) DB::table('conversations')->where('id', $question->conversation_id)->value('is_processing'),
        ];
    }

    /**
     * The question resolved once, to $state, and everything that shows it —
     * the agent's tool result, the conversation screen's view, the
     * messaging channel's resolution — agrees.
     */
    private function assertSettled(AgentQuestion $question, Conversation $conversation, string $state, ?AnswerMethod $method, ?int $choice, string $label): void
    {
        $fresh = AgentQuestion::findOrFail($question->id);

        $this->assertSame($state, $fresh->state->value, "{$label}: exactly the expected resolution takes effect");
        $this->assertNull($fresh->pending_conversation_id, "{$label}: the slot is released");
        $this->assertNotNull($fresh->resumed_at, "{$label}: the paused turn is consumed");
        $this->assertSame($method, $fresh->answer_method, "{$label}: recorded with the winner's method");

        // What the agent receives.
        $stored = $this->storedAskResult($fresh);
        $this->assertNotNull($stored, "{$label}: the paused ask_user call is answered");
        $this->assertSame($state, $stored['outcome'] ?? null, "{$label}: the agent receives the winning outcome");
        $this->assertSame($fresh->id, $stored['question_id'] ?? null);

        $continuations = $this->continuationsOf($fresh);
        if ($state === 'withdrawn') {
            $this->assertSame([], $continuations, "{$label}: a withdrawn run is never continued");
            $this->assertSame('stopped_early', DB::table('agent_runs')->where('id', $fresh->run_id)->value('end_state'));
        } else {
            $this->assertCount(1, $continuations, "{$label}: the run is resumed exactly once");
            $this->assertSame($stored, $continuations[0], "{$label}: the continuation carries the stored outcome");
            $this->assertSame('completed', DB::table('agent_runs')->where('id', $fresh->run_id)->value('end_state'));
        }
        $this->assertSame(1, DB::table('agent_runs')->where('conversation_id', $conversation->id)->count(), "{$label}: one run, never a second");

        $expectedSelected = $choice === null ? [] : [['number' => $choice, 'text' => self::CHOICES[$choice - 1]]];
        if ($state === 'answered') {
            $this->assertSame((string) $choice, $fresh->answer_raw, "{$label}: the winner's answer, and only it, is applied");
            $this->assertSame($expectedSelected, $stored['answer']['selected'] ?? null, "{$label}: the agent receives the winner's choice");
        } else {
            $this->assertNull($fresh->answer_raw, "{$label}: no answer is applied to a question that did not take one");
            $this->assertNull($fresh->answer_message_id);
        }

        // What the conversation screen shows.
        $view = $this->viewOf($fresh);
        $this->assertSame($state, $view['state'], "{$label}: the screen shows the outcome the agent received");
        if ($state === 'answered') {
            $this->assertSame($expectedSelected, $view['answer']['selected'] ?? null);
            $this->assertSame($method?->value, $view['answer']['method'] ?? null);
        } else {
            $this->assertNull($view['answer']);
        }

        // What the messaging channel shows.
        $resolutions = $this->telegram->resolutionsFor($fresh->id);
        $this->assertCount(1, $resolutions, "{$label}: the channel is told of the resolution exactly once");
        $this->assertSame($state, $resolutions[0]->state->value, "{$label}: the channel shows the outcome the agent received");
        $this->assertSame($expectedSelected, $resolutions[0]->selected);
        $this->assertSame($method, $resolutions[0]->method);

        // Messages: the answer exists only if the question was answered,
        // a notice only if it was not — and nothing else was written.
        $messages = Message::where('conversation_id', $conversation->id)->get();
        $kinds = $messages->map(fn (Message $m) => $m->tool_data['kind'] ?? null)->countBy()->all();
        $this->assertSame(1, $kinds['agent_question'] ?? 0, "{$label}: one question shown");
        $this->assertSame($state === 'answered' ? 1 : 0, $kinds['agent_question_answer'] ?? 0, "{$label}: an answer message only for the winning answer");
        $this->assertSame($state === 'answered' ? 0 : 1, $kinds['agent_question_notice'] ?? 0, "{$label}: a notice only when unanswered");
        $this->assertSame(
            $state === 'answered' ? [self::PROMPT, (string) $choice] : [self::PROMPT],
            $messages->where('role', 'user')->sortBy('created_at')->pluck('content')->values()->all(),
            "{$label}: the user's messages are the prompt and the winning answer only",
        );
        $this->assertSame(
            $state === 'withdrawn' ? 0 : 1,
            $messages->filter(fn (Message $m) => $m->role !== 'user' && $m->content === self::CONTINUED)->count(),
            "{$label}: the agent carried on exactly once",
        );
    }

    /**
     * A form submission and a tap on the resolved question are both told its
     * state, and change nothing at all.
     */
    private function assertLateAnswersChangeNothing(AgentQuestion $question, string $state, string $label, bool $workQueue = true): void
    {
        $before = $this->footprint($question);

        $form = $this->postForm($question->id, ['selected' => [1]]);
        $this->assertSame(409, $form['status'], "{$label}: a late form submission is refused");
        $this->assertSame('question_not_pending', $form['body']['code'] ?? null);
        $this->assertSame($state, $form['body']['state'] ?? null, "{$label}: and told the question's state");
        $this->assertSame($state, $form['body']['question']['state'] ?? null);

        $tap = $this->tap($question->id, 1);
        $this->assertSame(InteractiveAnswerStatus::NotPending, $tap->status, "{$label}: a late tap is refused");
        $this->assertSame($state, $tap->state?->value, "{$label}: and told the question's state");

        if ($workQueue) {
            $this->workQueuedResumes();
        }

        $this->assertSame($before, $this->footprint($question), "{$label}: late answers write nothing, resume nothing, start nothing");
    }

    // ---------------------------------------------------------------
    // Interactive answers racing each other and a typed reply
    // ---------------------------------------------------------------

    /**
     * [first, second, when the second arrives]. "before_cas": the second
     * resolves the question after the first loaded it as pending and
     * before the first's compare-and-set, so the second wins. "after": the
     * second arrives once the first's resolution and resume are done, so
     * the first wins.
     *
     * A typed reply only ever arrives before the other's compare-and-set: a
     * typed message after the question resolved is an ordinary message that
     * starts its own task, which is how a typed message behaves, not an answer.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function answerRaces(): array
    {
        return [
            'double form submit, overlapping' => ['form', 'form', 'before_cas'],
            'double form submit, one after the other' => ['form', 'form', 'after'],
            'double tap, overlapping' => ['tap', 'tap', 'before_cas'],
            'double tap, one after the other' => ['tap', 'tap', 'after'],
            'form vs tap, the tap overlapping' => ['form', 'tap', 'before_cas'],
            'form then tap' => ['form', 'tap', 'after'],
            'tap vs form, the form overlapping' => ['tap', 'form', 'before_cas'],
            'tap then form' => ['tap', 'form', 'after'],
            'tap vs typed reply, the reply overlapping' => ['tap', 'typed', 'before_cas'],
            'typed reply vs tap, the tap overlapping' => ['typed', 'tap', 'before_cas'],
            'form vs typed reply, the reply overlapping' => ['form', 'typed', 'before_cas'],
        ];
    }

    #[Test]
    #[DataProvider('answerRaces')]
    public function racing_answers_resolve_the_question_exactly_once(string $first, string $second, string $boundary): void
    {
        $this->scenario = 'interactive_answer_race';

        for ($trial = 0; $trial < self::TRIALS; $trial++) {
            $label = "trial {$trial}: {$second} arriving {$boundary} of {$first}";
            $this->told = [];
            $winner = $boundary === 'before_cas' ? 'second' : 'first';
            $loser = $winner === 'first' ? 'second' : 'first';
            $kinds = ['first' => $first, 'second' => $second];

            $this->scriptAsk(continues: true);
            $conversation = $this->telegramConversation();
            $question = $this->ask($conversation);

            if ($boundary === 'after') {
                $this->participate($first, 'first', $question, $conversation, false);
                $this->workQueuedResumes();
                $this->assertNotNull($question->fresh()->resumed_at, "{$label}: precondition — the first answer was consumed");
                $this->participate($second, 'second', $question, $conversation, false);
                $this->workQueuedResumes();
            } else {
                $this->arm($question->id, fn () => $this->participate($second, 'second', $question, $conversation, true));
                $this->participate($first, 'first', $question, $conversation, false);
                $this->assertTrue($this->hookFired, "{$label}: the {$second} must have arrived before the {$first}'s compare-and-set");
                $this->disarm();
                $this->workQueuedResumes();
            }

            // Each participant was told what happened to it.
            $this->assertToldAnswered($kinds[$winner], $this->told[$winner], "{$label}: the winning {$kinds[$winner]}");
            $this->assertToldLost($kinds[$loser], $this->told[$loser], 'answered', "{$label}: the losing {$kinds[$loser]}");

            $this->assertSettled($question, $conversation, 'answered', self::METHOD_OF[$kinds[$winner]], self::PICK[$winner], $label);
            $this->assertSame(
                $kinds[$winner] === 'tap' ? 1 : 0,
                Queue::pushed(ResumeAfterQuestionJob::class, fn (ResumeAfterQuestionJob $job) => $job->questionId === $question->id)->count(),
                "{$label}: only a winning tap queues a resume",
            );

            $this->assertLateAnswersChangeNothing($question, 'answered', $label);
        }
    }

    private function assertToldAnswered(string $kind, mixed $told, string $label): void
    {
        match ($kind) {
            'form' => $this->assertSame(201, $told['status'] ?? null, "{$label} gets 201"),
            'tap' => $this->assertSame(InteractiveAnswerStatus::Answered, $told->status, "{$label} is Answered"),
            'typed' => $this->assertSame(200, $told['status'] ?? null, "{$label} continues the task"),
        };
    }

    private function assertToldLost(string $kind, mixed $told, string $state, string $label): void
    {
        match ($kind) {
            'form' => (function () use ($told, $state, $label) {
                $this->assertSame(409, $told['status'] ?? null, "{$label} gets 409");
                $this->assertSame('question_not_pending', $told['body']['code'] ?? null);
                $this->assertSame($state, $told['body']['state'] ?? null, "{$label} is told the question's state");
                $this->assertSame($state, $told['body']['question']['state'] ?? null);
            })(),
            'tap' => (function () use ($told, $state, $label) {
                $this->assertSame(InteractiveAnswerStatus::NotPending, $told->status, "{$label} is NotPending");
                $this->assertSame($state, $told->state?->value, "{$label} is told the question's state");
                $this->assertNull($told->message, "{$label} creates no message");
            })(),
            // A typed reply that lost while the winner's resume is pending is
            // refused as any message is while a task resumes: the conversation is busy.
            'typed' => $this->assertSame(409, $told['status'] ?? null, "{$label} is refused while the task resumes"),
        };
    }

    // ---------------------------------------------------------------
    // Interactive answers racing expiry and withdrawal
    // ---------------------------------------------------------------

    /**
     * [first, second]: the second arrives after the first loaded the
     * question as pending and before its compare-and-set, so the second
     * wins. Both orders of every pair.
     *
     * @return array<string, array{string, string}>
     */
    public static function resolutionRaces(): array
    {
        return [
            'form submit, expiry overlapping' => ['form', 'expire'],
            'expiry, form submit overlapping' => ['expire', 'form'],
            'tap, expiry overlapping' => ['tap', 'expire'],
            'expiry, tap overlapping' => ['expire', 'tap'],
            'form submit, withdrawal overlapping' => ['form', 'withdraw'],
            'withdrawal, form submit overlapping' => ['withdraw', 'form'],
            'tap, withdrawal overlapping' => ['tap', 'withdraw'],
            'withdrawal, tap overlapping' => ['withdraw', 'tap'],
        ];
    }

    #[Test]
    #[DataProvider('resolutionRaces')]
    public function an_answer_racing_expiry_or_withdrawal_has_exactly_one_outcome(string $first, string $second): void
    {
        $this->scenario = 'interactive_answer_resolution_race';
        $interactive = in_array($first, ['form', 'tap'], true) ? 'first' : 'second';
        $kinds = ['first' => $first, 'second' => $second];
        $state = self::STATE_OF[$second];

        for ($trial = 0; $trial < self::TRIALS; $trial++) {
            $label = "trial {$trial}: {$second} arriving before the {$first}'s compare-and-set";
            $this->told = [];

            $this->scriptAsk(continues: $state !== 'withdrawn');
            $conversation = $this->telegramConversation();
            $question = $this->ask($conversation);
            if (in_array('expire', [$first, $second], true)) {
                // A question asked before unanswered policies existed: it expires as it always did.
                DB::table('agent_questions')->where('id', $question->id)->update(['unanswered_policy' => null, 'effective_wait_minutes' => null]);
            }
            // Past its answerable period, not yet swept: every resolution is possible.
            $this->pastItsAnswerablePeriod($question);

            $this->arm($question->id, fn () => $this->participate($second, 'second', $question, $conversation, true));
            $this->participate($first, 'first', $question, $conversation, false);
            $this->assertTrue($this->hookFired, "{$label}: the {$second} must have arrived before the {$first}'s compare-and-set");
            $this->disarm();
            $this->workQueuedResumes();

            if ($interactive === 'second') {
                $this->assertToldAnswered($kinds[$interactive], $this->told['second'], "{$label}: the winning {$second}");
                $this->assertSettled($question, $conversation, 'answered', self::METHOD_OF[$second], self::PICK['second'], $label);
            } else {
                $this->assertToldLost($kinds[$interactive], $this->told['first'], $state, "{$label}: the losing {$first}");
                $this->assertSettled($question, $conversation, $state, null, null, $label);
            }

            $this->assertLateAnswersChangeNothing($question, $state, $label);
        }
    }

    // ---------------------------------------------------------------
    // Answers arriving after the question resolved
    // ---------------------------------------------------------------

    #[Test]
    public function a_form_or_tap_after_expiry_is_told_it_expired_and_changes_nothing(): void
    {
        $this->scenario = 'interactive_answer_after_expiry';
        $this->scriptAsk(continues: true);
        $conversation = $this->telegramConversation();
        $question = $this->ask($conversation);
        // A question asked before unanswered policies existed: it expires as it always did.
        DB::table('agent_questions')->where('id', $question->id)->update(['unanswered_policy' => null, 'effective_wait_minutes' => null]);

        $this->pastItsAnswerablePeriod($question);
        $this->assertSame(0, Artisan::call(self::SWEEP));
        $this->assertSame('expired', $question->fresh()->state->value, 'precondition: expired');

        // Before the expiry's resume has run, and after.
        $this->assertLateAnswersChangeNothing($question, 'expired', 'before the resume ran', workQueue: false);
        $this->workQueuedResumes();
        $this->assertSettled($question, $conversation, 'expired', null, null, 'expired');
        $this->assertLateAnswersChangeNothing($question, 'expired', 'after the resume ran');
    }

    #[Test]
    public function a_form_or_tap_after_withdrawal_is_told_it_was_withdrawn_and_changes_nothing(): void
    {
        $this->scenario = 'interactive_answer_after_withdrawal';
        $this->scriptAsk(continues: false);
        $conversation = $this->telegramConversation();
        $question = $this->ask($conversation);

        $this->actingAs($this->fixture()->user, 'api')
            ->postJson("/api/clarion-app/llm-client/conversation/{$conversation->id}/end")
            ->assertStatus(200);

        $this->assertSettled($question, $conversation, 'withdrawn', null, null, 'withdrawn');
        $this->assertLateAnswersChangeNothing($question, 'withdrawn', 'after withdrawal');
    }

    #[Test]
    public function a_tap_after_the_conversation_was_deleted_is_told_the_question_was_withdrawn(): void
    {
        $this->scenario = 'interactive_answer_after_deletion';
        $this->scriptAsk(continues: false);
        $conversation = $this->telegramConversation();
        $question = $this->ask($conversation);

        $this->actingAs($this->fixture()->user, 'api')
            ->deleteJson("/api/clarion-app/llm-client/conversation/{$conversation->id}")
            ->assertStatus(204);

        $this->assertNull(Conversation::find($conversation->id), 'precondition: the conversation is gone');
        $row = AgentQuestion::find($question->id);
        $this->assertNotNull($row, 'the question row outlives its conversation');
        $this->assertSame(QuestionState::Withdrawn, $row->state, 'deleting withdrew the waiting question first');
        $this->assertCount(1, $this->telegram->resolutionsFor($question->id), 'the channel was told of the withdrawal');
        $this->assertSame([], $this->continuationsOf($row), 'the withdrawn run was never continued');

        $before = $this->footprint($question);

        $tap = $this->tap($question->id, 2);
        $this->assertSame(InteractiveAnswerStatus::NotPending, $tap->status);
        $this->assertSame(QuestionState::Withdrawn, $tap->state, 'told the question was withdrawn, not that it does not exist');
        $this->assertNull($tap->message);

        $form = $this->postForm($question->id, ['selected' => [2]]);
        $this->assertSame(409, $form['status']);
        $this->assertSame('withdrawn', $form['body']['state'] ?? null);

        $this->workQueuedResumes();
        $this->assertSame($before, $this->footprint($question), 'nothing is written, resumed or started');
    }

    // ---------------------------------------------------------------
    // A stale control of an older question
    // ---------------------------------------------------------------

    #[Test]
    public function stale_tap_never_applies_to_newer_question(): void
    {
        $this->scenario = 'interactive_answer_stale_control';
        $conversation = $this->telegramConversation();

        // Question A is asked and answered by a tap; the task finishes.
        $this->scriptAsk(continues: true);
        $older = $this->ask($conversation);
        $this->assertSame(InteractiveAnswerStatus::Answered, $this->tap($older->id, 2)->status);
        $this->workQueuedResumes();
        $this->assertSettled($older, $conversation, 'answered', AnswerMethod::MessagingOption, 2, 'question A');

        // A later task in the same conversation asks question B; A's
        // buttons and form are still on screen.
        $this->scriptAsk(continues: true);
        $newer = $this->ask($conversation, 'Now the documentation, please.');
        $this->assertNotSame($older->id, $newer->id);
        $this->assertSame($conversation->id, $newer->fresh()->pending_conversation_id, 'precondition: B is the conversation\'s pending question');

        $newerBefore = (array) DB::table('agent_questions')->where('id', $newer->id)->first();
        $olderBefore = (array) DB::table('agent_questions')->where('id', $older->id)->first();
        $requestsBefore = count($this->transport()->capturedChatPayloads());
        $messagesBefore = DB::table('messages')->count();
        $runsBefore = DB::table('agent_runs')->count();
        $queuedBefore = Queue::pushed(ResumeAfterQuestionJob::class)->count();

        // A tap and a form submission that name A.
        $tap = $this->tap($older->id, 3);
        $this->assertSame(InteractiveAnswerStatus::NotPending, $tap->status, 'a tap naming A is A\'s, and A is answered');
        $this->assertSame(QuestionState::Answered, $tap->state);
        $this->assertSame($older->id, $tap->question?->id, 'the outcome is about A');

        $form = $this->postForm($older->id, ['selected' => [3]]);
        $this->assertSame(409, $form['status']);
        $this->assertSame('answered', $form['body']['state'] ?? null);
        $this->assertSame($older->id, $form['body']['question']['id'] ?? null, 'the form is told about A');

        $free = $this->tap($older->id, 'use the newer approach');
        $this->assertSame(InteractiveAnswerStatus::NotPending, $free->status);

        $this->workQueuedResumes();

        $this->assertSame($newerBefore, (array) DB::table('agent_questions')->where('id', $newer->id)->first(), 'B is untouched: still pending, no answer');
        $this->assertSame($olderBefore, (array) DB::table('agent_questions')->where('id', $older->id)->first(), 'A keeps its own answer');
        $this->assertSame($requestsBefore, count($this->transport()->capturedChatPayloads()), 'no model request');
        $this->assertSame($messagesBefore, DB::table('messages')->count(), 'no message');
        $this->assertSame($runsBefore, DB::table('agent_runs')->count(), 'no run');
        $this->assertSame($queuedBefore, Queue::pushed(ResumeAfterQuestionJob::class)->count(), 'no resume');
        $this->assertSame([], $this->telegram->resolutionsFor($newer->id), 'B is not resolved anywhere');
        $this->assertSame('pending', $this->viewOf($newer->fresh())['state'], 'the screen still offers B');

        // B is still answerable, by its own controls.
        $this->assertSame(InteractiveAnswerStatus::Answered, $this->tap($newer->id, 1)->status);
        $this->workQueuedResumes();
        $newer = $newer->fresh();
        $this->assertSame(QuestionState::Answered, $newer->state);
        $this->assertSame('1', $newer->answer_raw);
        $this->assertSame([['number' => 1, 'text' => 'Backend']], $this->continuationsOf($newer)[0]['answer']['selected'] ?? null);
        $this->assertSame('2', $older->fresh()->answer_raw, 'A still holds its own answer');
    }
}
