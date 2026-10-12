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
 * A question the agent asked is visible in the run record, to the run's
 * owner, through the existing run endpoints.
 *
 * The `ask_user` call is recorded as a tool-invocation action whose target is
 * `ask_user`. While the question waits it is `awaiting_confirmation` and its
 * content already holds the question snapshot with `resolution: "pending"`;
 * once resolved it is `success` (answered) or `failure` with a reason naming
 * the resolution, and the snapshot is rewritten with the resolution, when it
 * happened, how long the task waited, and the answer. A refused ask leaves a
 * `failure` action whose snapshot has no question id. The time spent waiting
 * counts as the paused step's human wait and so as the run's
 * `confirm_wait_ms`.
 *
 * Snapshot shape: `{question_id, question, choices, allow_multiple,
 * asked_at, expires_at, resolution, resolved_at, waited_ms, answer,
 * presentation}`, where `answer` is `{raw_reply, selected, method}` (this
 * file answers by typed reply) for an answered question and null
 * otherwise, `waited_ms` is `resolved_at - asked_at` in milliseconds (null
 * while pending), and the timestamps denote the same instants as the
 * question row's.
 */
class AgentQuestionRunRecordTest extends TestCase
{
    private const QUESTION = 'Which part should I change first?';

    private const CHOICES = ['Backend', 'Frontend', 'Docs'];

    private const SNAPSHOT_KEYS = [
        'question_id', 'question', 'choices', 'allow_multiple', 'asked_at',
        'expires_at', 'resolution', 'resolved_at', 'waited_ms', 'answer',
        'presentation',
    ];

    /** A refused ask presented nothing to anyone, so it has no `presentation`. */
    private const REFUSED_SNAPSHOT_KEYS = [
        'question_id', 'question', 'choices', 'allow_multiple', 'asked_at',
        'expires_at', 'resolution', 'resolved_at', 'waited_ms', 'answer',
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

        $this->user = User::factory()->create();
        $this->server = Server::create([
            'name' => 'Run Record LLM Server',
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
    private function askCall(array $arguments = [], string $id = 'call_ask'): array
    {
        return [
            'id' => $id,
            'type' => 'function',
            'function' => [
                'name' => 'ask_user',
                'arguments' => json_encode($arguments ?: ['question' => self::QUESTION, 'choices' => self::CHOICES]),
            ],
        ];
    }

    private function conversation(string $channel): Conversation
    {
        return Conversation::factory()->create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'title' => "Run record on {$channel}",
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
    private function agentAsks(Conversation $conversation, array $arguments = []): ?array
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

    /** Ask the three-choice question and return its pending row. */
    private function askIn(Conversation $conversation): AgentQuestion
    {
        $result = $this->agentAsks($conversation);
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

    /** Answer the conversation's pending question as the user would on its channel. */
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

        $request->postJson('/api/clarion-app/llm-client/agent', [
            'message' => $reply,
            'channel' => 'telegram',
        ])->assertStatus(200);
    }

    /** The action as the owner reads it: `GET agent-runs/{runId}/actions/{actionId}`. */
    private function actionDetail(string $runId, string $actionId): array
    {
        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}/actions/{$actionId}");
        $response->assertStatus(200);

        return $response->json();
    }

    /**
     * @param  list<string>  $keys  the snapshot's exact field set
     * @return array<string, mixed> the decoded snapshot
     */
    private function snapshotOf(array $detail, array $keys = self::SNAPSHOT_KEYS): array
    {
        $this->assertIsString($detail['content'] ?? null, 'the ask_user action carries the question snapshot as its content');
        $snapshot = json_decode($detail['content'], true);
        $this->assertIsArray($snapshot, 'the content is the JSON snapshot; got: '.$detail['content']);
        $this->assertEqualsCanonicalizing($keys, array_keys($snapshot), 'the snapshot has exactly the documented fields');

        return $snapshot;
    }

    private function assertSameInstant(?Carbon $expected, mixed $actual, string $field): void
    {
        $this->assertNotNull($expected, "precondition: the question row has {$field}");
        $this->assertIsString($actual, "the snapshot's {$field} is a timestamp");
        $this->assertSame(
            $expected->copy()->utc()->format('Y-m-d H:i:s'),
            Carbon::parse($actual)->utc()->format('Y-m-d H:i:s'),
            "the snapshot's {$field} is the question's {$field}",
        );
    }

    /**
     * The snapshot describes $question (read fresh) with the given
     * resolution and answer.
     *
     * @param  array<string, mixed>|null  $answer
     */
    private function assertSnapshotOf(AgentQuestion $question, array $snapshot, string $resolution, ?array $answer): void
    {
        $question = $question->fresh();

        $this->assertSame($question->id, $snapshot['question_id']);
        $this->assertSame(self::QUESTION, $snapshot['question']);
        $this->assertSame(self::CHOICES, $snapshot['choices']);
        $this->assertFalse($snapshot['allow_multiple']);
        $this->assertSameInstant($question->asked_at, $snapshot['asked_at'], 'asked_at');
        $this->assertSameInstant($question->expires_at, $snapshot['expires_at'], 'expires_at');
        $this->assertSame($resolution, $snapshot['resolution']);
        $this->assertSame($answer, $snapshot['answer']);

        if ($resolution === 'pending') {
            $this->assertNull($snapshot['resolved_at'], 'a pending question has not been resolved');
            $this->assertNull($snapshot['waited_ms'], 'a pending question has no final wait');

            return;
        }

        $this->assertSameInstant($question->resolved_at, $snapshot['resolved_at'], 'resolved_at');
        $this->assertIsInt($snapshot['waited_ms'], 'a resolved question records how long the task waited');
        $expectedWait = (int) $question->asked_at->diffInMilliseconds($question->resolved_at, true);
        $this->assertEqualsWithDelta($expectedWait, $snapshot['waited_ms'], 1000, 'waited_ms is the time from asking to resolution');
    }

    // ---------------------------------------------------------------
    // While the question waits
    // ---------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function channels(): array
    {
        return [
            'web (streamed pause)' => ['web'],
            'telegram (synchronous pause)' => ['telegram'],
        ];
    }

    #[Test]
    #[DataProvider('channels')]
    public function snapshot_visible_while_pending(string $channel): void
    {
        $conversation = $this->conversation($channel);
        $question = $this->askIn($conversation);

        // The step's action list shows the ask as waiting on a human.
        $actions = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$question->run_id}/steps/{$question->step_id}/actions")
            ->assertStatus(200)
            ->json('data');
        $asks = array_values(array_filter($actions, fn (array $a) => ($a['target'] ?? null) === 'ask_user'));
        $this->assertCount(1, $asks, 'the ask_user call is listed under its step');
        $this->assertSame($question->action_id, $asks[0]['id']);
        $this->assertSame('tool_invocation', $asks[0]['action_type']);
        $this->assertSame('awaiting_confirmation', $asks[0]['outcome']);

        // Its detail already carries the question, before anyone answers.
        $detail = $this->actionDetail($question->run_id, $question->action_id);
        $this->assertSame('ask_user', $detail['target']);
        $this->assertSame('awaiting_confirmation', $detail['outcome']);
        $this->assertNull($detail['failure_reason']);
        $this->assertSnapshotOf($question, $this->snapshotOf($detail), 'pending', null);
    }

    // ---------------------------------------------------------------
    // After the question is resolved
    // ---------------------------------------------------------------

    #[Test]
    #[DataProvider('channels')]
    public function an_answered_question_shows_the_answer_and_the_wait(string $channel): void
    {
        $conversation = $this->conversation($channel);
        $question = $this->askIn($conversation);

        $this->travel(90)->seconds();
        $this->answer($conversation, '2');

        $this->assertSame(QuestionState::Answered, $question->fresh()->state, 'precondition: the reply answered the question');
        $detail = $this->actionDetail($question->run_id, $question->action_id);
        $this->assertSame('success', $detail['outcome']);
        $this->assertNull($detail['failure_reason']);

        $snapshot = $this->snapshotOf($detail);
        $this->assertSnapshotOf($question, $snapshot, 'answered', [
            'raw_reply' => '2',
            'selected' => [['number' => 2, 'text' => 'Frontend']],
            'method' => 'typed_reply',
        ]);
        $this->assertGreaterThanOrEqual(90000, $snapshot['waited_ms'], 'the snapshot shows the 90 s the task waited');
    }

    /**
     * Opening and resolving a question changes no column of the run row, so
     * nothing else would push a fresh summary; an open run diagram would
     * otherwise never show (or never clear) its "Waiting for you" badge.
     */
    #[Test]
    #[DataProvider('channels')]
    public function the_run_summary_is_pushed_when_the_question_opens_and_when_it_resolves(string $channel): void
    {
        /** @var list<array{string, mixed}> $pushed */
        $pushed = [];
        Event::listen(RunUpdated::class, function (RunUpdated $event) use (&$pushed) {
            $pushed[] = [$event->runId, $event->broadcastWith()['awaiting_user'] ?? null];
        });

        $conversation = $this->conversation($channel);
        $question = $this->askIn($conversation);

        $this->assertContains(
            [$question->run_id, true],
            $pushed,
            'a summary showing the run awaiting its user is pushed when the question opens',
        );

        $pushed = [];
        $this->answer($conversation, '2');

        $this->assertNotEmpty($pushed, 'a summary is pushed once the question is answered');
        $this->assertSame($question->run_id, $pushed[0][0]);
        $this->assertFalse($pushed[0][1], 'the first summary pushed after the answer no longer shows the run awaiting its user');
    }

    #[Test]
    public function the_question_wait_is_the_paused_steps_human_wait_and_counts_toward_the_run(): void
    {
        $conversation = $this->conversation('telegram');
        $question = $this->askIn($conversation);

        $this->travel(90)->seconds();
        $this->answer($conversation, '1');

        $this->assertSame('completed', DB::table('agent_runs')->where('id', $question->run_id)->value('end_state'), 'precondition: the run finished');

        $steps = collect(
            $this->actingAs($this->user, 'api')
                ->getJson("/api/clarion-app/llm-client/agent-runs/{$question->run_id}/steps")
                ->assertStatus(200)
                ->json('data'),
        )->keyBy('id');
        $this->assertTrue($steps->has($question->step_id), 'the paused step is listed');
        $stepWait = $steps[$question->step_id]['wait_ms'];
        $this->assertIsInt($stepWait, 'the paused step records its human wait');
        $this->assertGreaterThanOrEqual(90000, $stepWait, 'the question wait is the paused step\'s wait');

        $run = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$question->run_id}")
            ->assertStatus(200)
            ->json();
        $this->assertGreaterThanOrEqual($stepWait, $run['confirm_wait_ms'], 'the run\'s human wait includes the question wait');
    }

    /**
     * A withdrawn question ends its run instead of continuing it, but the
     * user's wait was just as real: the paused step records it and the run's
     * human wait includes it.
     */
    #[Test]
    #[DataProvider('channels')]
    public function a_withdrawn_questions_wait_is_the_paused_steps_human_wait(string $channel): void
    {
        $conversation = $this->conversation($channel);
        $question = $this->askIn($conversation);

        $this->travel(90)->seconds();
        $this->actingAs($this->user, 'api')
            ->postJson("/api/clarion-app/llm-client/conversation/{$conversation->id}/end")
            ->assertStatus(200);
        $this->assertSame(QuestionState::Withdrawn, $question->fresh()->state, 'precondition: ending the conversation withdrew the question');
        $this->assertSame('stopped_early', DB::table('agent_runs')->where('id', $question->run_id)->value('end_state'), 'precondition: the run ended');

        $stepWait = DB::table('agent_run_steps')->where('id', $question->step_id)->value('wait_ms');
        $this->assertNotNull($stepWait, 'the paused step records its human wait');
        $this->assertGreaterThanOrEqual(90000, (int) $stepWait, 'the question wait is the paused step\'s wait');
        $this->assertGreaterThanOrEqual(
            (int) $stepWait,
            (int) DB::table('agent_runs')->where('id', $question->run_id)->value('confirm_wait_ms'),
            'the run\'s human wait includes the question wait',
        );
    }

    #[Test]
    public function an_expired_question_is_shown_as_expired(): void
    {
        config(['llm-client.agent_questions.expiry_minutes' => 2]);
        $conversation = $this->conversation('telegram');
        $question = $this->askIn($conversation);

        $this->travel(3)->minutes();
        Artisan::call('llm-client:resolve-expired-questions');
        $this->assertSame(QuestionState::Expired, $question->fresh()->state, 'precondition: the sweep expired the question');
        Queue::assertPushed(ResumeAfterQuestionJob::class);
        $this->app->call([new ResumeAfterQuestionJob($question->id), 'handle']);

        $detail = $this->actionDetail($question->run_id, $question->action_id);
        $this->assertSame('failure', $detail['outcome']);
        $this->assertSame('question expired', $detail['failure_reason']);

        $snapshot = $this->snapshotOf($detail);
        $this->assertSnapshotOf($question, $snapshot, 'expired', null);
        $this->assertGreaterThanOrEqual(180000, $snapshot['waited_ms']);
    }

    #[Test]
    public function a_withdrawn_question_is_shown_as_withdrawn(): void
    {
        $conversation = $this->conversation('telegram');
        $question = $this->askIn($conversation);

        $this->travel(30)->seconds();
        $this->actingAs($this->user, 'api')
            ->postJson("/api/clarion-app/llm-client/conversation/{$conversation->id}/end")
            ->assertStatus(200);
        $this->assertSame(QuestionState::Withdrawn, $question->fresh()->state, 'precondition: ending the conversation withdrew the question');

        $detail = $this->actionDetail($question->run_id, $question->action_id);
        $this->assertSame('failure', $detail['outcome']);
        $this->assertSame('question withdrawn', $detail['failure_reason']);
        $this->assertSnapshotOf($question, $this->snapshotOf($detail), 'withdrawn', null);
    }

    #[Test]
    public function an_undeliverable_question_is_shown_as_undeliverable(): void
    {
        $this->telegram->failWith('chat unreachable');
        $conversation = $this->conversation('telegram');

        $result = $this->agentAsks($conversation);

        $this->assertSame('completed', $result['status'] ?? null, 'precondition: the run continued without an answer; got: '.json_encode($result));
        $question = AgentQuestion::where('conversation_id', $conversation->id)->firstOrFail();
        $this->assertSame(QuestionState::Undeliverable, $question->state);

        $detail = $this->actionDetail($question->run_id, $question->action_id);
        $this->assertSame('failure', $detail['outcome']);
        $this->assertSame('question undeliverable', $detail['failure_reason']);
        $this->assertSnapshotOf($question, $this->snapshotOf($detail), 'undeliverable', null);
    }

    #[Test]
    public function every_resolution_is_distinguishable_in_the_record(): void
    {
        $seen = [];

        foreach (['answered', 'withdrawn'] as $how) {
            $conversation = $this->conversation('telegram');
            $question = $this->askIn($conversation);

            if ($how === 'answered') {
                $this->answer($conversation, '3');
            } else {
                $this->actingAs($this->user, 'api')
                    ->postJson("/api/clarion-app/llm-client/conversation/{$conversation->id}/end")
                    ->assertStatus(200);
            }

            $detail = $this->actionDetail($question->run_id, $question->action_id);
            $seen[$how] = [$detail['outcome'], $detail['failure_reason'], $this->snapshotOf($detail)['resolution']];
        }

        $this->assertSame(['success', null, 'answered'], $seen['answered']);
        $this->assertSame(['failure', 'question withdrawn', 'withdrawn'], $seen['withdrawn']);
    }

    // ---------------------------------------------------------------
    // A refused ask
    // ---------------------------------------------------------------

    #[Test]
    public function a_refused_ask_is_recorded_with_its_refusal_and_no_question_id(): void
    {
        $conversation = $this->conversation('telegram');
        $arguments = ['question' => self::QUESTION, 'choices' => ['Backend', 'backend']];

        $result = $this->agentAsks($conversation, $arguments);

        $this->assertSame('completed', $result['status'] ?? null, 'precondition: the refusal let the run continue');
        $this->assertSame(0, AgentQuestion::count(), 'precondition: a refused ask opens no question');

        $action = DB::table('agent_run_actions')->where('target', 'ask_user')->first();
        $this->assertNotNull($action, 'the refused ask is still a recorded action');

        $detail = $this->actionDetail($action->run_id, $action->id);
        $this->assertSame('failure', $detail['outcome']);
        $this->assertSame('ask refused: invalid_question', $detail['failure_reason']);

        $snapshot = $this->snapshotOf($detail, self::REFUSED_SNAPSHOT_KEYS);
        $this->assertNull($snapshot['question_id'], 'no question row exists for a refused ask');
        $this->assertSame(self::QUESTION, $snapshot['question']);
        $this->assertSame(['Backend', 'backend'], $snapshot['choices'], 'the choices are recorded as the agent submitted them');
        $this->assertFalse($snapshot['allow_multiple']);
        $this->assertSame('refused:invalid_question', $snapshot['resolution']);
        $this->assertIsString($snapshot['asked_at']);
        $this->assertNull($snapshot['expires_at']);
        $this->assertNull($snapshot['waited_ms']);
        $this->assertNull($snapshot['answer']);
    }
}
