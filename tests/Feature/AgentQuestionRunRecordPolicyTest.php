<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Carbon\Carbon;
use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\AgentLoopStreamHandler;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Events\ApiCallConfirmationRequiredEvent;
use ClarionApp\LlmClient\Events\ConversationEnded;
use ClarionApp\LlmClient\Events\FinishOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Events\RunActionUpdated;
use ClarionApp\LlmClient\Events\RunUpdated;
use ClarionApp\LlmClient\Events\ToolExecutionEvent;
use ClarionApp\LlmClient\Events\UpdateOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Jobs\ResumeAfterQuestionJob;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\Services\RoleAssignmentService;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use Dedoc\Scramble\Generator;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\RecordingChannelDelivery;
use Tests\TestCase;

/**
 * The run record says, for every question the agent asked, what was to
 * happen if nobody answered and what actually happened — visible to the
 * run's owner only, through the existing run endpoints.
 *
 * The `ask_user` action's content (the question snapshot) gains four keys,
 * emitted first, right after `question_id`, so that a snapshot too large to
 * be stored whole (it is cut at the action-content cap and is then no
 * longer JSON) still shows them in its raw text:
 *
 * - `outcome`: pending | answered | answered_late | expired |
 *   expired_assumed | stopped | withdrawn | discarded | undeliverable —
 *   `answered_late` is an answer to a question that had stopped, and
 *   `discarded` a stopped question withdrawn by ending or deleting its
 *   conversation; `resolution` keeps the raw state;
 * - `stopped_at`: when the task stopped, null unless it did;
 * - `stopped_ms`: how long it has been (or was) stopped — `stopped_at` to
 *   `resolved_at`, or to now while still stopped; null unless it stopped;
 * - `policy`: `{if_unanswered, consequential, requested_wait_minutes,
 *   effective_wait_minutes, assumed_answer}` in that order (the scalars
 *   before the possibly long assumption, which is `{raw_reply,
 *   is_selection, selected}` or null), or null for a question asked
 *   before policies existed.
 *
 * The record is rewritten when the task stops (the action is still waiting
 * on the user, so it stays `awaiting_confirmation`), and the stop is pushed
 * to the owner like any other change to the run. Every run summary — the
 * run list, a single run, the live `RunUpdated` payload and the
 * arrangement's runs map — says `awaiting_user` while a question is pending
 * or stopped and, new, `question_stopped` while it is stopped; each flag
 * costs one batched query per list, however many runs are listed.
 */
class AgentQuestionRunRecordPolicyTest extends TestCase
{
    private const QUESTION = 'Which part should I change first?';

    private const CHOICES = ['Backend', 'Frontend', 'Docs'];

    /** The clock is frozen at each of these instants in turn. */
    private const ASKED_AT = '2026-10-12 09:00:00';

    private const ANSWERED_IN_TIME_AT = '2026-10-12 09:30:00';

    private const SWEPT_AT = '2026-10-12 10:01:00';

    private const DISCARDED_AT = '2026-10-12 10:31:00';

    private const ANSWERED_LATE_AT = '2026-10-12 11:30:00';

    private const TWO_HOURS_AFTER_THE_STOP = '2026-10-12 12:01:00';

    /** The snapshot's keys, in the order they are written. */
    private const SNAPSHOT_KEYS = [
        'question_id', 'outcome', 'stopped_at', 'stopped_ms', 'policy',
        'question', 'choices', 'allow_multiple', 'asked_at', 'expires_at',
        'resolution', 'resolved_at', 'waited_ms', 'answer', 'presentation',
    ];

    private const POLICY_KEYS = [
        'if_unanswered', 'consequential', 'requested_wait_minutes', 'effective_wait_minutes', 'assumed_answer',
    ];

    /** Must not be guessed: work stops an hour after the ask if nobody answers. */
    private const STOP_ARGUMENTS = ['question' => self::QUESTION, 'choices' => self::CHOICES, 'wait_minutes' => 60];

    /** A safe default: the agent continues on choice 1 an hour after the ask. */
    private const PROCEED_ARGUMENTS = ['question' => self::QUESTION, 'choices' => self::CHOICES, 'assumed_choices' => [1], 'wait_minutes' => 60];

    private const STOP_POLICY = [
        'if_unanswered' => 'stop',
        'consequential' => false,
        'requested_wait_minutes' => 60,
        'effective_wait_minutes' => 60,
        'assumed_answer' => null,
    ];

    private const PROCEED_POLICY = [
        'if_unanswered' => 'proceed_on_assumption',
        'consequential' => false,
        'requested_wait_minutes' => 60,
        'effective_wait_minutes' => 60,
        'assumed_answer' => ['raw_reply' => '1', 'is_selection' => true, 'selected' => [['number' => 1, 'text' => 'Backend']]],
    ];

    private const ANSWER_TWO = [
        'raw_reply' => '2',
        'selected' => [['number' => 2, 'text' => 'Frontend']],
        'method' => 'typed_reply',
    ];

    private User $user;

    private Server $server;

    private RecordingChannelDelivery $web;

    private RecordingChannelDelivery $telegram;

    /** @var list<array<string, mixed>> scripted model replies, consumed in order */
    private array $replies = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::ASKED_AT, 'UTC'));

        $this->user = User::factory()->create();
        $this->server = Server::create([
            'name' => 'Run Record Policy LLM Server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'sk-test',
        ]);
        app(RoleAssignmentService::class)->set(ModelRole::Inference, $this->user->id, $this->server->id, 'test-model');

        config(['llm-client.run_trace.enabled' => true]);

        if (!Schema::hasTable('mcp_sessions')) {
            Schema::create('mcp_sessions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id');
                $table->string('protocol_version');
                $table->string('client_name')->nullable();
                $table->string('client_version')->nullable();
                $table->json('capabilities')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }
        if (!Schema::hasTable('chunk_summaries')) {
            Schema::create('chunk_summaries', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('conversation_id')->index();
                $table->unsignedInteger('chunk_index');
                $table->string('source_hash', 64);
                $table->unsignedInteger('source_message_count');
                $table->json('summary');
                $table->unsignedInteger('summary_tokens')->nullable();
                $table->string('condensation_model')->nullable();
                $table->string('condensation_provider')->nullable();
                $table->timestamps();

                $table->unique(['conversation_id', 'chunk_index']);
            });
        }
        if (!Schema::hasTable('condensation_states')) {
            Schema::create('condensation_states', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('conversation_id')->unique();
                $table->unsignedInteger('consecutive_failures')->default(0);
                $table->timestamp('cooldown_until')->nullable();
                $table->timestamps();
            });
        }

        $doc = ['paths' => []];
        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, $doc);
        $generator = Mockery::mock(Generator::class);
        $generator->shouldReceive('__invoke')->andReturn($doc);
        $this->app->instance(Generator::class, $generator);

        Queue::fake();
        Event::fake([
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            NewConversationMessageEvent::class,
            ToolExecutionEvent::class,
            ApiCallConfirmationRequiredEvent::class,
            ConversationEnded::class,
        ]);

        $this->web = new RecordingChannelDelivery('web');
        $this->telegram = new RecordingChannelDelivery('telegram');
        app(ConversationChannelRegistry::class)->register($this->web);
        app(ConversationChannelRegistry::class)->register($this->telegram);

        $this->bindScriptedAgentLoop();
    }

    protected function tearDown(): void
    {
        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        Mockery::close();

        foreach (['agent_questions', 'agent_run_actions', 'agent_run_steps', 'agent_runs', 'mcp_sessions', 'chunk_summaries', 'condensation_states'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }
        DB::table('messages')->delete();
        DB::table('conversations')->delete();
        DB::table('llm_role_assignments')->delete();
        DB::table('llm_servers')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    /** The container's agent loop, with its model replies scripted. */
    private function bindScriptedAgentLoop(): void
    {
        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturnUsing(fn () => array_shift($this->replies) ?? $this->textReply('Done.'));
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);

        $this->app->instance(AgentLoopService::class, new AgentLoopService(
            app(McpToolRegistry::class),
            new McpToolExecutor(app(McpToolRegistry::class), null, fn ($user) => 'test-token'),
            app(OperationCache::class),
            $registry,
            runTraceRecorder: app(RunTraceRecorder::class),
        ));
    }

    private function textReply(string $content): array
    {
        return [
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content, 'tool_calls' => []]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10, 'total_tokens' => 20],
        ];
    }

    /** @param array<string, mixed> $arguments */
    private function askCall(array $arguments): array
    {
        return [
            'id' => 'call_ask',
            'type' => 'function',
            'function' => ['name' => 'ask_user', 'arguments' => json_encode($arguments)],
        ];
    }

    private function conversation(string $channel): Conversation
    {
        return Conversation::factory()->create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'title' => "Run record policy on {$channel}",
            'channel' => $channel,
            'is_processing' => false,
        ]);
    }

    /**
     * Have the agent ask in $conversation the way its channel does: a
     * streamed model turn on `web`, a synchronous run (as `POST agent`
     * drives it) on `telegram`. Returns the synchronous result, if any.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>|null
     */
    private function agentAsks(Conversation $conversation, array $arguments): ?array
    {
        if ($conversation->channel === 'web') {
            $conversation->update(['is_processing' => true]);
            $handler = new AgentLoopStreamHandler();
            $handler->toolCalls = [$this->askCall($arguments)];
            $handler->message = Message::create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'user' => 'Clarion',
                'content' => '',
                'responseTime' => 0,
            ]);
            $handler->finish(json_encode(['conversation_id' => $conversation->id, 'iteration' => 1]), 2);

            return null;
        }

        $this->replies = [['choices' => [['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [$this->askCall($arguments)]]]]]];

        return app(AgentLoopService::class)->run($conversation->fresh(), 'Help me tidy up the project.');
    }

    /**
     * Ask in $conversation and return the pending question.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function askIn(Conversation $conversation, array $arguments): AgentQuestion
    {
        $result = $this->agentAsks($conversation, $arguments);
        if ($conversation->channel !== 'web') {
            $this->assertSame('awaiting_user', $result['status'] ?? null, 'precondition; got: '.json_encode($result));
        }

        $question = AgentQuestion::where('conversation_id', $conversation->id)->first();
        $this->assertNotNull($question, 'precondition: the ask opened a question');
        $this->assertSame(QuestionState::Pending, $question->state);
        $this->assertNotNull($question->run_id, 'precondition: the question belongs to a recorded run');
        $this->assertNotNull($question->action_id, 'precondition: the ask is a recorded action');

        return $question;
    }

    private function at(string $instant): void
    {
        $this->travelTo(Carbon::parse($instant, 'UTC'));
    }

    private function sweep(): void
    {
        $this->assertSame(0, Artisan::call('llm-client:resolve-expired-questions'), 'the sweep succeeds');
    }

    /** Nobody answers a stop question: at 10:01 the sweep stops its task. */
    private function stopAtTheDeadline(AgentQuestion $question): void
    {
        $this->at(self::SWEPT_AT);
        $this->sweep();
        $this->assertSame(QuestionState::Stopped, $question->fresh()->state, 'precondition: the unanswered stop question stopped its task');
    }

    /** Work every queued resume the way a queue worker does. */
    private function workQueuedResumes(): void
    {
        foreach (Queue::pushed(ResumeAfterQuestionJob::class)->all() as $job) {
            $this->app->call([$job, 'handle']);
        }
    }

    /** Answer the conversation's question as the user would on its channel. */
    private function answer(Conversation $conversation, string $reply): void
    {
        $request = $this->actingAs($this->user, 'api');

        if ($conversation->channel === 'web') {
            $request->postJson('/api/clarion-app/llm-client/message', [
                'conversation_id' => $conversation->id,
                'content' => $reply,
            ])->assertStatus(201);

            return;
        }

        // As Telegram sends a typed reply: no conversation id.
        $request->postJson('/api/clarion-app/llm-client/agent', [
            'message' => $reply,
            'channel' => 'telegram',
        ])->assertStatus(200);
    }

    private function endConversation(Conversation $conversation): void
    {
        $this->actingAs($this->user, 'api')
            ->postJson("/api/clarion-app/llm-client/conversation/{$conversation->id}/end")
            ->assertStatus(200);
    }

    /** The action as the owner reads it: `GET agent-runs/{runId}/actions/{actionId}`. */
    private function actionDetail(AgentQuestion $question): array
    {
        return $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$question->run_id}/actions/{$question->action_id}")
            ->assertStatus(200)
            ->json();
    }

    /**
     * The decoded snapshot, checked to carry exactly the documented keys in
     * the documented order (the four added keys first, after `question_id`,
     * then every key the record already had).
     *
     * @return array<string, mixed>
     */
    private function snapshotOf(array $detail): array
    {
        $this->assertIsString($detail['content'] ?? null, 'the ask_user action carries the question snapshot as its content');
        $snapshot = json_decode($detail['content'], true);
        $this->assertIsArray($snapshot, 'the content is the JSON snapshot; got: '.$detail['content']);
        $this->assertSame(self::SNAPSHOT_KEYS, array_keys($snapshot), 'the snapshot has exactly the documented keys, in order');
        if (is_array($snapshot['policy'])) {
            $this->assertSame(self::POLICY_KEYS, array_keys($snapshot['policy']), 'the policy\'s scalars come before the assumption');
        }

        return $snapshot;
    }

    private function iso(string $instant): string
    {
        return Carbon::parse($instant, 'UTC')->format('Y-m-d\\TH:i:s\\Z');
    }

    /** Milliseconds between two of this file's instants. */
    private function msBetween(string $from, string $to): int
    {
        return (int) Carbon::parse($from, 'UTC')->diffInMilliseconds(Carbon::parse($to, 'UTC'), true);
    }

    /** @return array<string, array{string}> */
    public static function channels(): array
    {
        return [
            'web (streamed pause)' => ['web'],
            'telegram (synchronous pause)' => ['telegram'],
        ];
    }

    // ---------------------------------------------------------------
    // From the moment the question is asked
    // ---------------------------------------------------------------

    /** @return array<string, array{string, array<string, mixed>, array<string, mixed>}> */
    public static function askedPolicies(): array
    {
        $policies = [
            'no policy named (stop, default period)' => [
                ['question' => self::QUESTION, 'choices' => self::CHOICES],
                [
                    'if_unanswered' => 'stop',
                    'consequential' => false,
                    'requested_wait_minutes' => null,
                    'effective_wait_minutes' => 1440,
                    'assumed_answer' => null,
                ],
            ],
            'consequential, a period below the minimum' => [
                ['question' => self::QUESTION, 'choices' => self::CHOICES, 'consequential' => true, 'wait_minutes' => 30],
                [
                    'if_unanswered' => 'stop',
                    'consequential' => true,
                    'requested_wait_minutes' => 30,
                    'effective_wait_minutes' => 60,
                    'assumed_answer' => null,
                ],
            ],
            'proceed on choice 1' => [self::PROCEED_ARGUMENTS, self::PROCEED_POLICY],
            'proceed on an assumption in its own words' => [
                ['question' => 'What should the release be called?', 'assumed_text' => 'Autumn release', 'wait_minutes' => 120],
                [
                    'if_unanswered' => 'proceed_on_assumption',
                    'consequential' => false,
                    'requested_wait_minutes' => 120,
                    'effective_wait_minutes' => 120,
                    'assumed_answer' => ['raw_reply' => 'Autumn release', 'is_selection' => false, 'selected' => []],
                ],
            ],
        ];

        $cases = [];
        foreach (['web', 'telegram'] as $channel) {
            foreach ($policies as $name => [$arguments, $policy]) {
                $cases["{$channel}: {$name}"] = [$channel, $arguments, $policy];
            }
        }

        return $cases;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $policy
     */
    #[Test]
    #[DataProvider('askedPolicies')]
    public function the_policy_is_in_the_record_from_the_moment_the_question_is_asked(string $channel, array $arguments, array $policy): void
    {
        $question = $this->askIn($this->conversation($channel), $arguments);

        $detail = $this->actionDetail($question);
        $this->assertSame('awaiting_confirmation', $detail['outcome']);
        $this->assertNull($detail['failure_reason']);

        $snapshot = $this->snapshotOf($detail);
        $this->assertSame($question->id, $snapshot['question_id']);
        $this->assertSame('pending', $snapshot['outcome']);
        $this->assertNull($snapshot['stopped_at'], 'nothing has stopped');
        $this->assertNull($snapshot['stopped_ms']);
        $this->assertSame($policy, $snapshot['policy'], 'the record shows the policy decided when the question was asked');
        $this->assertSame('pending', $snapshot['resolution']);
        $this->assertNull($snapshot['resolved_at']);
        $this->assertNull($snapshot['answer']);
    }

    // ---------------------------------------------------------------
    // At the stop
    // ---------------------------------------------------------------

    #[Test]
    #[DataProvider('channels')]
    public function the_stop_rewrites_the_record_while_the_ask_still_waits_on_the_user(string $channel): void
    {
        $question = $this->askIn($this->conversation($channel), self::STOP_ARGUMENTS);

        $this->stopAtTheDeadline($question);

        $detail = $this->actionDetail($question);
        $this->assertSame('awaiting_confirmation', $detail['outcome'], 'a stopped question still waits on its user');
        $this->assertNull($detail['failure_reason']);

        $snapshot = $this->snapshotOf($detail);
        $this->assertSame('stopped', $snapshot['outcome'], 'the record is rewritten at the stop');
        $this->assertSame('stopped', $snapshot['resolution']);
        $this->assertSame($this->iso(self::SWEPT_AT), $snapshot['stopped_at']);
        $this->assertSame(0, $snapshot['stopped_ms'], 'written the moment the task stopped');
        $this->assertSame(self::STOP_POLICY, $snapshot['policy']);
        $this->assertNull($snapshot['resolved_at'], 'stopping is not a resolution');
        $this->assertNull($snapshot['waited_ms']);
        $this->assertNull($snapshot['answer']);

        // How long it has been stopped grows while it stays stopped.
        $this->at(self::TWO_HOURS_AFTER_THE_STOP);
        $later = json_decode($question->fresh()->runRecordSnapshot(), true);
        $this->assertSame('stopped', $later['outcome'] ?? null);
        $this->assertSame($this->msBetween(self::SWEPT_AT, self::TWO_HOURS_AFTER_THE_STOP), $later['stopped_ms'] ?? null);
    }

    #[Test]
    #[DataProvider('channels')]
    public function the_stop_is_pushed_to_the_owner(string $channel): void
    {
        $question = $this->askIn($this->conversation($channel), self::STOP_ARGUMENTS);

        /** @var list<array{string, mixed, mixed}> $runsPushed */
        $runsPushed = [];
        /** @var list<string> $actionsPushed */
        $actionsPushed = [];
        Event::listen(RunUpdated::class, function (RunUpdated $event) use (&$runsPushed) {
            $payload = $event->broadcastWith();
            $runsPushed[] = [$event->runId, $payload['awaiting_user'] ?? null, $payload['question_stopped'] ?? null];
        });
        Event::listen(RunActionUpdated::class, function (RunActionUpdated $event) use (&$actionsPushed) {
            $actionsPushed[] = $event->actionId;
        });

        $this->stopAtTheDeadline($question);

        $this->assertContains(
            [$question->run_id, true, true],
            $runsPushed,
            'a summary showing the run stopped and awaiting its user is pushed when it stops; pushed: '.json_encode($runsPushed),
        );
        $this->assertContains($question->action_id, $actionsPushed, 'the rewritten ask_user action is pushed');
    }

    // ---------------------------------------------------------------
    // How it ended
    // ---------------------------------------------------------------

    #[Test]
    #[DataProvider('channels')]
    public function a_late_answer_closes_the_record_as_answered_late(string $channel): void
    {
        $conversation = $this->conversation($channel);
        $question = $this->askIn($conversation, self::STOP_ARGUMENTS);
        $this->stopAtTheDeadline($question);

        $this->at(self::ANSWERED_LATE_AT);
        $this->answer($conversation, '2');
        $this->assertSame(QuestionState::Answered, $question->fresh()->state, 'precondition: the late reply answered the question');

        $detail = $this->actionDetail($question);
        $this->assertSame('success', $detail['outcome'], 'the user did answer');
        $this->assertNull($detail['failure_reason']);

        $snapshot = $this->snapshotOf($detail);
        $this->assertSame('answered_late', $snapshot['outcome'], 'distinct from an answer in time');
        $this->assertSame('answered', $snapshot['resolution']);
        $this->assertSame($this->iso(self::SWEPT_AT), $snapshot['stopped_at']);
        $this->assertSame($this->iso(self::ANSWERED_LATE_AT), $snapshot['resolved_at']);
        $this->assertSame($this->msBetween(self::SWEPT_AT, self::ANSWERED_LATE_AT), $snapshot['stopped_ms'], 'how long the task was stopped');
        $this->assertSame($this->msBetween(self::ASKED_AT, self::ANSWERED_LATE_AT), $snapshot['waited_ms'], 'the whole wait, stop included');
        $this->assertSame(self::ANSWER_TWO, $snapshot['answer'], 'the late answer');
        $this->assertSame(self::STOP_POLICY, $snapshot['policy']);
    }

    #[Test]
    public function an_answer_in_time_is_recorded_as_answered(): void
    {
        $conversation = $this->conversation('telegram');
        $question = $this->askIn($conversation, self::PROCEED_ARGUMENTS);

        $this->at(self::ANSWERED_IN_TIME_AT);
        $this->answer($conversation, '2');

        $detail = $this->actionDetail($question);
        $this->assertSame('success', $detail['outcome']);

        $snapshot = $this->snapshotOf($detail);
        $this->assertSame('answered', $snapshot['outcome']);
        $this->assertNull($snapshot['stopped_at']);
        $this->assertNull($snapshot['stopped_ms']);
        $this->assertSame(self::ANSWER_TWO, $snapshot['answer'], 'the user\'s answer, not the assumption');
        $this->assertSame(self::PROCEED_POLICY, $snapshot['policy'], 'the unused assumption is still on record');
    }

    #[Test]
    #[DataProvider('channels')]
    public function an_assumed_answer_is_recorded_as_assumed(string $channel): void
    {
        $question = $this->askIn($this->conversation($channel), self::PROCEED_ARGUMENTS);

        $this->at(self::SWEPT_AT);
        $this->sweep();
        $this->assertSame(QuestionState::ExpiredAssumed, $question->fresh()->state, 'precondition: it expired on its assumption');
        $this->workQueuedResumes();

        $detail = $this->actionDetail($question);
        $this->assertSame('failure', $detail['outcome'], 'the ask ended without the user\'s answer');
        $this->assertSame('question expired_assumed', $detail['failure_reason']);

        $snapshot = $this->snapshotOf($detail);
        $this->assertSame('expired_assumed', $snapshot['outcome']);
        $this->assertSame('expired_assumed', $snapshot['resolution']);
        $this->assertNull($snapshot['stopped_at'], 'nothing stopped');
        $this->assertNull($snapshot['stopped_ms']);
        $this->assertSame($this->iso(self::SWEPT_AT), $snapshot['resolved_at']);
        $this->assertNull($snapshot['answer'], 'an assumption is never recorded as the user\'s answer');
        $this->assertSame(self::PROCEED_POLICY, $snapshot['policy'], 'the record names what was assumed');
    }

    #[Test]
    #[DataProvider('channels')]
    public function a_discarded_stopped_task_is_recorded_as_discarded(string $channel): void
    {
        $conversation = $this->conversation($channel);
        $question = $this->askIn($conversation, self::STOP_ARGUMENTS);
        $this->stopAtTheDeadline($question);

        $this->at(self::DISCARDED_AT);
        $this->endConversation($conversation);
        $this->assertSame(QuestionState::Withdrawn, $question->fresh()->state, 'precondition: ending the conversation withdrew the stopped question');

        $detail = $this->actionDetail($question);
        $this->assertSame('failure', $detail['outcome']);
        $this->assertSame('question withdrawn', $detail['failure_reason']);

        $snapshot = $this->snapshotOf($detail);
        $this->assertSame('discarded', $snapshot['outcome'], 'distinct from a question withdrawn while still waiting');
        $this->assertSame('withdrawn', $snapshot['resolution']);
        $this->assertSame($this->iso(self::SWEPT_AT), $snapshot['stopped_at']);
        $this->assertSame($this->iso(self::DISCARDED_AT), $snapshot['resolved_at']);
        $this->assertSame($this->msBetween(self::SWEPT_AT, self::DISCARDED_AT), $snapshot['stopped_ms']);
        $this->assertNull($snapshot['answer']);
    }

    #[Test]
    public function a_question_withdrawn_while_still_waiting_is_recorded_as_withdrawn(): void
    {
        $conversation = $this->conversation('telegram');
        $question = $this->askIn($conversation, self::STOP_ARGUMENTS);

        $this->at(self::ANSWERED_IN_TIME_AT);
        $this->endConversation($conversation);

        $snapshot = $this->snapshotOf($this->actionDetail($question));
        $this->assertSame('withdrawn', $snapshot['outcome']);
        $this->assertNull($snapshot['stopped_at']);
        $this->assertNull($snapshot['stopped_ms']);
    }

    #[Test]
    public function an_undeliverable_question_is_recorded_as_undeliverable(): void
    {
        $this->telegram->failWith('chat unreachable');

        $result = $this->agentAsks($this->conversation('telegram'), self::STOP_ARGUMENTS);
        $this->assertSame('completed', $result['status'] ?? null, 'precondition: the run continued without an answer; got: '.json_encode($result));
        $question = AgentQuestion::firstOrFail();
        $this->assertSame(QuestionState::Undeliverable, $question->state);

        $snapshot = $this->snapshotOf($this->actionDetail($question));
        $this->assertSame('undeliverable', $snapshot['outcome']);
        $this->assertNull($snapshot['stopped_at']);
        $this->assertSame(self::STOP_POLICY, $snapshot['policy']);
    }

    #[Test]
    public function a_question_asked_before_policies_existed_has_no_policy_on_record(): void
    {
        $question = $this->askIn($this->conversation('telegram'), self::STOP_ARGUMENTS);
        // A question asked before unanswered policies existed: it expires as it always did.
        DB::table('agent_questions')->where('id', $question->id)->update(['unanswered_policy' => null, 'effective_wait_minutes' => null]);

        $this->at(self::SWEPT_AT);
        $this->sweep();
        $this->assertSame(QuestionState::Expired, $question->fresh()->state, 'precondition: the original expiry');
        $this->workQueuedResumes();

        $detail = $this->actionDetail($question);
        $this->assertSame('failure', $detail['outcome']);
        $this->assertSame('question expired', $detail['failure_reason']);

        $snapshot = $this->snapshotOf($detail);
        $this->assertSame('expired', $snapshot['outcome']);
        $this->assertNull($snapshot['policy'], 'no policy was decided for it');
        $this->assertNull($snapshot['stopped_at']);
        $this->assertNull($snapshot['stopped_ms']);
    }

    // ---------------------------------------------------------------
    // A record too large to be stored whole
    // ---------------------------------------------------------------

    /**
     * A 4000-character question and a 4000-character assumption, each
     * character four bytes, are far over the action-content cap: the record
     * is cut and is no longer JSON, but its opening still says what was to
     * happen and what did.
     */
    #[Test]
    public function a_record_cut_to_size_still_opens_with_its_outcome_and_policy(): void
    {
        $long = str_repeat('𝄞', 4000);

        $question = $this->askIn($this->conversation('telegram'), ['question' => $long, 'assumed_text' => $long]);

        $detail = $this->actionDetail($question);
        $content = (string) $detail['content'];
        $this->assertStringContainsString('"outcome":"pending"', $content);
        $this->assertStringContainsString('"if_unanswered":"proceed_on_assumption"', $content);
        $this->assertStringContainsString('"consequential":false', $content);
        $this->assertStringContainsString('"effective_wait_minutes":1440', $content);
        $this->assertTrue($detail['content_truncated'], 'precondition: the record was cut to size');
        $this->assertNull(json_decode($content, true), 'precondition: the cut record is not JSON');
    }

    #[Test]
    public function a_record_cut_to_size_shows_the_stop(): void
    {
        $question = $this->askIn($this->conversation('telegram'), ['question' => str_repeat('𝄞', 4000), 'wait_minutes' => 60]);

        $this->stopAtTheDeadline($question);

        $detail = $this->actionDetail($question);
        $content = (string) $detail['content'];
        $this->assertStringContainsString('"outcome":"stopped"', $content);
        $this->assertStringContainsString('"stopped_at":"'.$this->iso(self::SWEPT_AT).'"', $content);
        $this->assertStringContainsString('"if_unanswered":"stop"', $content);
        $this->assertTrue($detail['content_truncated'], 'precondition: the record was cut to size');
        $this->assertSame('awaiting_confirmation', $detail['outcome']);
    }

    // ---------------------------------------------------------------
    // Run summaries
    // ---------------------------------------------------------------

    /**
     * The run as each summary producer describes it to its owner: the run
     * list, a single run, the live push, and the arrangement's runs map.
     *
     * @return array<string, array<string, mixed>>
     */
    private function summariesOf(string $runId): array
    {
        $listed = collect(
            $this->actingAs($this->user, 'api')
                ->getJson('/api/clarion-app/llm-client/agent-runs')
                ->assertStatus(200)
                ->json('data'),
        )->firstWhere('id', $runId);
        $this->assertIsArray($listed, 'the run is in its owner\'s run list');

        $arrangement = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}/arrangement")
            ->assertStatus(200)
            ->json();
        $this->assertIsArray($arrangement['runs'][$runId] ?? null, 'the arrangement describes its root run');

        return [
            'run list' => $listed,
            'single run' => $this->actingAs($this->user, 'api')
                ->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}")
                ->assertStatus(200)
                ->json(),
            'live push' => (new RunUpdated($runId))->broadcastWith(),
            'arrangement' => $arrangement['runs'][$runId],
        ];
    }

    private function assertEverySummary(string $runId, bool $awaitingUser, bool $questionStopped, string $endState, string $when): void
    {
        foreach ($this->summariesOf($runId) as $producer => $summary) {
            $this->assertArrayHasKey('question_stopped', $summary, "{$producer} {$when}: the summary says whether the run is stopped on a question");
            $this->assertSame($questionStopped, $summary['question_stopped'], "{$producer} {$when}: question_stopped");
            $this->assertSame($awaitingUser, $summary['awaiting_user'], "{$producer} {$when}: awaiting_user");
            $this->assertSame($endState, $summary['end_state'], "{$producer} {$when}: end_state");
        }
    }

    #[Test]
    #[DataProvider('channels')]
    public function every_summary_shows_a_run_stopped_on_its_question(string $channel): void
    {
        $conversation = $this->conversation($channel);
        $question = $this->askIn($conversation, self::STOP_ARGUMENTS);

        $this->assertEverySummary($question->run_id, true, false, 'in_progress', 'while the question waits');

        $this->stopAtTheDeadline($question);
        $this->assertEverySummary($question->run_id, true, true, 'in_progress', 'while the task is stopped');

        $this->at(self::ANSWERED_LATE_AT);
        $this->answer($conversation, '2');
        $runId = $question->run_id;
        foreach ($this->summariesOf($runId) as $producer => $summary) {
            $this->assertFalse($summary['question_stopped'] ?? null, "{$producer} after the late answer: question_stopped");
            $this->assertFalse($summary['awaiting_user'], "{$producer} after the late answer: awaiting_user");
        }
    }

    /**
     * Whether each listed run waits on, or is stopped on, a question is
     * looked up once per list for each flag — never once per run.
     */
    #[Test]
    public function each_flag_costs_one_query_per_list_however_many_runs_are_listed(): void
    {
        $stoppedA = $this->askIn($this->conversation('telegram'), self::STOP_ARGUMENTS);
        $stoppedB = $this->askIn($this->conversation('telegram'), self::STOP_ARGUMENTS);
        $waiting = $this->askIn($this->conversation('telegram'), ['question' => self::QUESTION, 'choices' => self::CHOICES, 'wait_minutes' => 120]);
        $answeredIn = $this->conversation('telegram');
        $answered = $this->askIn($answeredIn, ['question' => self::QUESTION, 'choices' => self::CHOICES, 'wait_minutes' => 120]);
        $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/agent', [
            'message' => '1',
            'channel' => 'telegram',
            'conversation_id' => $answeredIn->id,
        ])->assertStatus(200);
        $this->assertSame(QuestionState::Answered, $answered->fresh()->state, 'precondition');

        $this->at(self::SWEPT_AT);
        $this->sweep();
        $this->assertSame(QuestionState::Stopped, $stoppedA->fresh()->state, 'precondition');
        $this->assertSame(QuestionState::Stopped, $stoppedB->fresh()->state, 'precondition');
        $this->assertSame(QuestionState::Pending, $waiting->fresh()->state, 'precondition');

        $counting = false;
        $questionQueries = 0;
        DB::listen(function (QueryExecuted $query) use (&$counting, &$questionQueries) {
            if ($counting && str_contains($query->sql, 'agent_questions')) {
                $questionQueries++;
            }
        });
        $queriesFor = function (string $url) use (&$counting, &$questionQueries): array {
            $questionQueries = 0;
            $counting = true;
            $rows = $this->actingAs($this->user, 'api')->getJson($url)->assertStatus(200)->json('data');
            $counting = false;

            return [$questionQueries, collect($rows)->keyBy('id')->all()];
        };

        [$oneRunQueries, $oneRun] = $queriesFor('/api/clarion-app/llm-client/agent-runs?per_page=1');
        [$allRunsQueries, $allRuns] = $queriesFor('/api/clarion-app/llm-client/agent-runs?per_page=20');

        $this->assertCount(1, $oneRun, 'precondition');
        $this->assertCount(4, $allRuns, 'precondition: four runs are listed');

        $flags = fn (AgentQuestion $q) => [
            $allRuns[$q->run_id]['awaiting_user'] ?? null,
            $allRuns[$q->run_id]['question_stopped'] ?? null,
        ];
        $this->assertSame([true, true], $flags($stoppedA));
        $this->assertSame([true, true], $flags($stoppedB));
        $this->assertSame([true, false], $flags($waiting));
        $this->assertSame([false, false], $flags($answered));

        $this->assertSame(2, $oneRunQueries, 'one query for each flag');
        $this->assertSame(2, $allRunsQueries, 'still one query for each flag with four runs listed');
    }

    // ---------------------------------------------------------------
    // Owner only
    // ---------------------------------------------------------------

    #[Test]
    public function only_the_runs_owner_sees_its_record(): void
    {
        $question = $this->askIn($this->conversation('telegram'), self::STOP_ARGUMENTS);
        $this->stopAtTheDeadline($question);
        $this->assertTrue($this->summariesOf($question->run_id)['single run']['awaiting_user'], 'precondition: the owner sees the stopped run');

        $stranger = User::factory()->create();
        $api = '/api/clarion-app/llm-client/agent-runs';

        $this->actingAs($stranger, 'api')->getJson("{$api}/{$question->run_id}")->assertStatus(404);
        $this->actingAs($stranger, 'api')->getJson("{$api}/{$question->run_id}/actions/{$question->action_id}")->assertStatus(404);
        $this->actingAs($stranger, 'api')->getJson("{$api}/{$question->run_id}/arrangement")->assertStatus(404);
        $listed = $this->actingAs($stranger, 'api')->getJson($api)->assertStatus(200)->json('data');
        $this->assertSame([], $listed, 'none of the owner\'s runs is listed to anyone else');
    }
}
