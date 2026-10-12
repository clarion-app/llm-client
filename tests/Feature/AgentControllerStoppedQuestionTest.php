<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\AgentQuestionService;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\Services\RoleAssignmentService;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\ActionType;
use ClarionApp\LlmClient\ValueObjects\AskUserRequest;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use ClarionApp\LlmClient\ValueObjects\OpenedQuestion;
use ClarionApp\LlmClient\ValueObjects\OpenQuestionRequest;
use ClarionApp\LlmClient\ValueObjects\ParsedAnswer;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use ClarionApp\LlmClient\ValueObjects\UnansweredPolicy;
use Dedoc\Scramble\Generator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\RecordingChannelDelivery;
use Tests\TestCase;

/**
 * A stopped question — its answerable period passed and work stopped —
 * still holds its conversation's slot and still accepts its user's answer
 * through the messaging endpoint, `POST agent`. A reply with no
 * conversation id finds the user's conversation on that channel holding a
 * pending or stopped question; with a conversation id the reply is taken as
 * the answer exactly as for a pending one. Either way the answer is
 * recorded as a late answer and the stopped run continues synchronously in
 * the request. A blank reply is refused with `answer_required` and the
 * question stays stopped; another user's reply never reaches it.
 *
 * A reply after a question expired on the assumption it stated is never an
 * answer: the user is told once, in that conversation, that it arrived
 * after the deadline and what was assumed — even when the run continuing on
 * the assumption still has the conversation and the reply is refused as
 * `processing`. Without a conversation id, such a reply is routed to the
 * conversation that proceeded (however long ago it was last active), unless
 * that conversation's continuing run still has it: then the notice is still
 * posted there, but the reply goes where an ordinary reply would, so a
 * second run never starts beside the first.
 */
class AgentControllerStoppedQuestionTest extends TestCase
{
    private const ENDPOINT = '/api/clarion-app/llm-client/agent';

    private const QUESTION = 'Which part should I change first?';

    private const CHOICES = ['Backend', 'Frontend', 'Docs'];

    private const LATE_REPLY_NOTICE = 'Your reply arrived after the question "Which part should I change first?" had expired. '
        .'The agent had already continued assuming: 1. Backend. Your reply is being handled as a new message.';

    private User $user;

    private User $stranger;

    private Server $server;

    private RecordingChannelDelivery $telegram;

    private RecordingChannelDelivery $web;

    /** @var list<array<string, mixed>> scripted model replies, consumed in order */
    private array $replies = [];

    private int $chatCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->stranger = User::factory()->create();
        $this->server = Server::create([
            'name' => 'Agent Intake LLM Server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'sk-test',
        ]);
        app(RoleAssignmentService::class)->set(ModelRole::Inference, $this->user->id, $this->server->id, 'test-model');
        app(RoleAssignmentService::class)->set(ModelRole::Inference, $this->stranger->id, $this->server->id, 'test-model');

        config(['llm-client.run_trace.enabled' => true]);
        config(['llm-client.conversation.inactivity_threshold_hours' => 4]);

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

        Event::fake([NewConversationMessageEvent::class]);

        $this->telegram = new RecordingChannelDelivery('telegram');
        $this->web = new RecordingChannelDelivery('web');
        app(ConversationChannelRegistry::class)->register($this->telegram);
        app(ConversationChannelRegistry::class)->register($this->web);

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
        $provider->shouldReceive('chat')->andReturnUsing(function () {
            $this->chatCalls++;

            return array_shift($this->replies) ?? $this->textReply('Done.');
        });
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);

        $this->app->instance(AgentLoopService::class, new AgentLoopService(
            app(McpToolRegistry::class),
            new McpToolExecutor(app(McpToolRegistry::class), null, fn ($user) => 'test-token'),
            app(OperationCache::class),
            $registry,
        ));
    }

    private function textReply(string $content): array
    {
        return [
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content, 'tool_calls' => []]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10, 'total_tokens' => 20],
        ];
    }

    private function askReply(string $callId, string $question, array $choices = []): array
    {
        $arguments = ['question' => $question] + ($choices === [] ? [] : ['choices' => $choices]);

        return ['choices' => [['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [[
            'id' => $callId,
            'type' => 'function',
            'function' => ['name' => 'ask_user', 'arguments' => json_encode($arguments)],
        ]]]]]];
    }

    private function conversation(string $channel = 'telegram', ?User $owner = null): Conversation
    {
        return Conversation::factory()->create([
            'user_id' => ($owner ?? $this->user)->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'title' => 'Agent intake conversation',
            'channel' => $channel,
            'is_processing' => false,
        ]);
    }

    /**
     * A pending question in $conversation, opened the way the synchronous
     * pause site opens it (resume mode `sync`).
     */
    private function pendingQuestion(Conversation $conversation, string $question = self::QUESTION, array $choices = self::CHOICES, ?AskUserRequest $request = null): AgentQuestion
    {
        $conversation->update(['is_processing' => true]);

        $recorder = app(RunTraceRecorder::class);
        $runId = $recorder->openRun(RunKind::Interactive, (string) $conversation->user_id, $conversation->id, streamed: false);
        $stepId = $recorder->openStep($runId);
        $actionId = $recorder->openAction($stepId, ActionType::ToolInvocation, 'ask_user');

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'user' => 'User',
            'content' => 'Help me tidy up the project.',
            'responseTime' => 0,
        ]);
        $paused = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => '',
            'responseTime' => 0,
            'tool_data' => ['tool_calls' => [[
                'id' => 'call_ask',
                'type' => 'function',
                'function' => ['name' => 'ask_user', 'arguments' => json_encode(['question' => $question, 'choices' => $choices])],
            ]]],
        ]);

        $opened = app(AgentQuestionService::class)->open(new OpenQuestionRequest(
            conversation: $conversation->fresh(),
            request: $request ?? new AskUserRequest($question, $choices, false),
            runId: $runId,
            stepId: $stepId,
            actionId: $actionId,
            pausedMessage: $paused,
            toolCallId: 'call_ask',
            resumeMode: OpenQuestionRequest::RESUME_SYNC,
            preamble: null,
            pendingToolResults: [],
            iteration: 1,
        ));

        $this->assertInstanceOf(OpenedQuestion::class, $opened, 'precondition: the question opened');
        $this->assertFalse((bool) $conversation->fresh()->is_processing, 'precondition: a waiting conversation is not processing');

        return AgentQuestion::findOrFail($opened->question->id);
    }

    /**
     * A question opened the way the synchronous pause site opens it, then
     * put into `stopped` exactly as the stop transition leaves it: slot
     * kept, unresolved, `stopped_at` set, the conversation not processing.
     */
    private function stoppedQuestion(Conversation $conversation, string $question = self::QUESTION, array $choices = self::CHOICES): AgentQuestion
    {
        $pending = $this->pendingQuestion($conversation, $question, $choices);
        DB::table('agent_questions')->where('id', $pending->id)->update([
            'state' => 'stopped',
            'stopped_at' => now()->subHour(),
        ]);
        // Opening the fixture's question delivered it; only what the reply
        // causes is under test.
        $this->telegram->deliveries = [];

        $stopped = AgentQuestion::findOrFail($pending->id);
        $this->assertSame(QuestionState::Stopped, $stopped->state, 'precondition: stopped');
        $this->assertSame($conversation->id, $stopped->pending_conversation_id, 'precondition: the slot is kept');
        $this->assertNull($stopped->resolved_at, 'precondition: unresolved');
        $this->assertFalse((bool) $conversation->fresh()->is_processing, 'precondition: nothing is processing');

        return $stopped;
    }

    /**
     * A question asked an hour ago that proceeds assuming choice 1, expired
     * by its policy as the sweep does. Unless $stillProcessing, the run that
     * continued on the assumption has finished and the conversation is free.
     */
    private function assumedQuestion(Conversation $conversation, bool $stillProcessing = false): AgentQuestion
    {
        $this->travel(-61)->minutes();
        $pending = $this->pendingQuestion($conversation, request: new AskUserRequest(
            self::QUESTION,
            self::CHOICES,
            false,
            policy: UnansweredPolicy::ProceedOnAssumption,
            assumedAnswer: new ParsedAnswer('1', true, [['number' => 1, 'text' => 'Backend']]),
            requestedWaitMinutes: 60,
        ));
        $this->travelBack();

        $this->assertSame(QuestionState::ExpiredAssumed, app(AgentQuestionService::class)->expireWithPolicy($pending), 'precondition: proceeded on its assumption');
        if (!$stillProcessing) {
            $conversation->update(['is_processing' => false]);
        }
        $this->telegram->deliveries = [];

        $assumed = AgentQuestion::findOrFail($pending->id);
        $this->assertNull($assumed->pending_conversation_id, 'precondition: the slot is free');
        $this->assertNull($assumed->late_reply_noticed_at, 'precondition: no late reply noticed yet');
        $this->assertSame($stillProcessing, (bool) $conversation->fresh()->is_processing, 'precondition');

        return $assumed;
    }

    /** @return array<string, mixed> what a late reply must never change about the question */
    private function decision(AgentQuestion $question): array
    {
        return (array) DB::table('agent_questions')->where('id', $question->id)->first([
            'state', 'assumed_answer_raw', 'assumed_answer_selected', 'answer_raw', 'answer_selected', 'answer_method',
            'answer_message_id', 'pending_conversation_id', 'resolved_at', 'resumed_at',
        ]);
    }

    /** @return list<Message> */
    private function lateReplyNotices(Conversation $conversation): array
    {
        return Message::where('conversation_id', $conversation->id)->get()
            ->filter(fn (Message $m) => ($m->tool_data['notice'] ?? null) === 'late_reply')
            ->values()
            ->all();
    }

    private function assertNoticedOnce(Conversation $conversation, AgentQuestion $question): void
    {
        $notices = $this->lateReplyNotices($conversation);
        $this->assertCount(1, $notices, 'the late reply is noticed once, in the conversation that proceeded');
        $this->assertSame(self::LATE_REPLY_NOTICE, $notices[0]->content);
        $this->assertSame($question->id, $notices[0]->tool_data['question_id'] ?? null);
        $this->assertSame([self::LATE_REPLY_NOTICE], $this->telegram->textsFor($conversation->id), 'delivered on the conversation\'s channel');
    }

    /** Make $conversation look idle for $hours. */
    private function lastActive(Conversation $conversation, int $hours): void
    {
        DB::table('conversations')->where('id', $conversation->id)->update(['updated_at' => now()->subHours($hours)]);
    }

    private function userMessageCount(Conversation $conversation): int
    {
        return Message::where('conversation_id', $conversation->id)->where('role', 'user')->count();
    }

    public static function blankReplies(): array
    {
        return [
            'empty string' => [''],
            'whitespace' => ['   '],
            'null' => [null],
        ];
    }

    /**
     * The late answer was recorded and the stopped run continued in this
     * request: the continuation's own reply is the response, the resume
     * claim was taken, and the waiting ask_user call was closed.
     */
    private function assertAnsweredLateAndResumed(AgentQuestion $question, string $stoppedAtBefore): void
    {
        $question->refresh();
        $this->assertSame(QuestionState::Answered, $question->state);
        $this->assertSame('2', $question->answer_raw);
        $this->assertSame([['number' => 2, 'text' => 'Frontend']], $question->answer_selected);
        $this->assertSame('typed_reply', DB::table('agent_questions')->where('id', $question->id)->value('answer_method'));
        $this->assertSame(
            $stoppedAtBefore,
            DB::table('agent_questions')->where('id', $question->id)->value('stopped_at'),
            'the answer is late: when work stopped is kept',
        );
        $this->assertNull($question->pending_conversation_id, 'the slot is released');
        $this->assertNotNull($question->resumed_at, 'the paused run was resumed');
        $this->assertSame(1, $this->chatCalls, 'the stopped run continued with one model call');
        $this->assertSame(
            'success',
            DB::table('agent_run_actions')->where('id', $question->action_id)->value('outcome'),
            'the waiting ask_user call was closed as answered',
        );
    }

    // ---------------------------------------------------------------
    // A typed reply answers a stopped question
    // ---------------------------------------------------------------

    #[Test]
    public function a_reply_without_a_conversation_id_answers_the_stopped_question_late_and_resumes_the_run(): void
    {
        $waiting = $this->conversation();
        $question = $this->stoppedQuestion($waiting);
        $stoppedAt = DB::table('agent_questions')->where('id', $question->id)->value('stopped_at');
        $this->lastActive($waiting, 30);

        // A more recently active conversation on the same channel, which the
        // ordinary lookup would otherwise pick.
        $recent = $this->conversation();
        $this->lastActive($recent, 1);

        $this->replies = [$this->textReply('Starting with the frontend.')];

        $response = $this->actingAs($this->user, 'api')->postJson(self::ENDPOINT, [
            'message' => '2',
            'channel' => 'telegram',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'conversation_id' => $waiting->id,
            'status' => 'completed',
            'content' => 'Starting with the frontend.',
        ]);
        $this->assertAnsweredLateAndResumed($question, $stoppedAt);
        $this->assertSame(0, Message::where('conversation_id', $recent->id)->count(), 'the other conversation is untouched');
        $this->assertSame(2, Conversation::where('user_id', $this->user->id)->count(), 'no new conversation is created');
    }

    #[Test]
    public function a_reply_with_the_conversation_id_answers_the_stopped_question_late_and_resumes_the_run(): void
    {
        $conversation = $this->conversation();
        $question = $this->stoppedQuestion($conversation);
        $stoppedAt = DB::table('agent_questions')->where('id', $question->id)->value('stopped_at');
        $this->replies = [$this->textReply('Starting with the frontend.')];

        $response = $this->actingAs($this->user, 'api')->postJson(self::ENDPOINT, [
            'message' => '2',
            'conversation_id' => $conversation->id,
            'channel' => 'telegram',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'conversation_id' => $conversation->id,
            'status' => 'completed',
            'content' => 'Starting with the frontend.',
        ]);
        $this->assertAnsweredLateAndResumed($question, $stoppedAt);
    }

    // ---------------------------------------------------------------
    // Blank replies
    // ---------------------------------------------------------------

    #[Test]
    #[DataProvider('blankReplies')]
    public function a_blank_reply_without_a_conversation_id_is_refused_and_the_question_stays_stopped(?string $message): void
    {
        $conversation = $this->conversation();
        $question = $this->stoppedQuestion($conversation);
        $before = (array) DB::table('agent_questions')->where('id', $question->id)->first();

        $response = $this->actingAs($this->user, 'api')->postJson(self::ENDPOINT, [
            'message' => $message,
            'channel' => 'telegram',
        ]);

        $response->assertStatus(422);
        $response->assertExactJson([
            'code' => 'answer_required',
            'error' => 'An answer is required to reply to the pending question.',
        ]);
        $this->assertSame($before, (array) DB::table('agent_questions')->where('id', $question->id)->first(), 'the question stays stopped, untouched');
        $this->assertSame(1, $this->userMessageCount($conversation), 'a blank reply is not stored');
        $this->assertSame(0, $this->chatCalls);
    }

    #[Test]
    #[DataProvider('blankReplies')]
    public function a_blank_reply_with_the_conversation_id_is_refused_and_the_question_stays_stopped(?string $message): void
    {
        $conversation = $this->conversation();
        $question = $this->stoppedQuestion($conversation);
        $before = (array) DB::table('agent_questions')->where('id', $question->id)->first();

        $response = $this->actingAs($this->user, 'api')->postJson(self::ENDPOINT, [
            'message' => $message,
            'conversation_id' => $conversation->id,
            'channel' => 'telegram',
        ]);

        $response->assertStatus(422);
        $response->assertExactJson([
            'code' => 'answer_required',
            'error' => 'An answer is required to reply to the pending question.',
        ]);
        $this->assertSame($before, (array) DB::table('agent_questions')->where('id', $question->id)->first(), 'the question stays stopped, untouched');
        $this->assertSame(0, $this->chatCalls);
    }

    // ---------------------------------------------------------------
    // Ownership
    // ---------------------------------------------------------------

    #[Test]
    public function another_users_reply_without_a_conversation_id_never_answers_the_stopped_question(): void
    {
        $conversation = $this->conversation();
        $question = $this->stoppedQuestion($conversation);
        $before = (array) DB::table('agent_questions')->where('id', $question->id)->first();
        $this->replies = [$this->textReply('Hello stranger.')];

        $response = $this->actingAs($this->stranger, 'api')->postJson(self::ENDPOINT, [
            'message' => '2',
            'channel' => 'telegram',
        ]);

        $response->assertStatus(200);
        $this->assertNotSame($conversation->id, $response->json('conversation_id'));
        $this->assertSame($before, (array) DB::table('agent_questions')->where('id', $question->id)->first());
        $this->assertSame(1, $this->userMessageCount($conversation));
    }

    #[Test]
    public function another_user_cannot_answer_the_stopped_question_by_conversation_id(): void
    {
        $conversation = $this->conversation();
        $question = $this->stoppedQuestion($conversation);
        $before = (array) DB::table('agent_questions')->where('id', $question->id)->first();

        $response = $this->actingAs($this->stranger, 'api')->postJson(self::ENDPOINT, [
            'message' => '2',
            'conversation_id' => $conversation->id,
            'channel' => 'telegram',
        ]);

        $response->assertStatus(403);
        $response->assertJson(['code' => 'forbidden']);
        $this->assertSame($before, (array) DB::table('agent_questions')->where('id', $question->id)->first());
        $this->assertSame(1, $this->userMessageCount($conversation));
        $this->assertSame(0, $this->chatCalls);
    }

    // ---------------------------------------------------------------
    // A reply after the question proceeded on its assumption
    // ---------------------------------------------------------------

    #[Test]
    public function with_the_conversation_id_the_late_reply_is_noticed_before_the_busy_conversation_refuses_it(): void
    {
        $conversation = $this->conversation();
        $question = $this->assumedQuestion($conversation, stillProcessing: true);
        $decision = $this->decision($question);

        $response = $this->actingAs($this->user, 'api')->postJson(self::ENDPOINT, [
            'message' => '2',
            'conversation_id' => $conversation->id,
            'channel' => 'telegram',
        ]);

        $response->assertStatus(409);
        $response->assertJson(['code' => 'processing']);
        $this->assertNoticedOnce($conversation, $question);
        $this->assertSame($decision, $this->decision($question), 'the reply is not applied to the question');
        $this->assertSame(0, $this->chatCalls, 'no run is started beside the one continuing');
    }

    #[Test]
    public function with_the_conversation_id_a_late_reply_is_noticed_and_handled_as_a_new_message(): void
    {
        $conversation = $this->conversation();
        $question = $this->assumedQuestion($conversation);
        $decision = $this->decision($question);
        $this->replies = [$this->textReply('Noted, switching to the frontend.')];

        $response = $this->actingAs($this->user, 'api')->postJson(self::ENDPOINT, [
            'message' => '2',
            'conversation_id' => $conversation->id,
            'channel' => 'telegram',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['conversation_id' => $conversation->id, 'status' => 'completed', 'content' => 'Noted, switching to the frontend.']);
        $this->assertNoticedOnce($conversation, $question);
        $this->assertSame($decision, $this->decision($question));
        $this->assertSame(1, $this->chatCalls, 'the reply starts a new run');
        $reply = Message::where('conversation_id', $conversation->id)->where('role', 'user')->where('content', '2')->first();
        $this->assertNotNull($reply, 'the reply is in the conversation');
        $this->assertNull($reply->tool_data, 'as an ordinary message');
    }

    #[Test]
    public function without_a_conversation_id_a_late_reply_goes_to_the_conversation_that_proceeded_and_is_noticed_there(): void
    {
        $assumedIn = $this->conversation();
        $question = $this->assumedQuestion($assumedIn);
        $decision = $this->decision($question);
        $this->lastActive($assumedIn, 30);

        // A more recently active conversation on the same channel, which the
        // ordinary lookup would otherwise pick.
        $recent = $this->conversation();
        $this->lastActive($recent, 1);
        $this->replies = [$this->textReply('Noted, switching to the frontend.')];

        $response = $this->actingAs($this->user, 'api')->postJson(self::ENDPOINT, [
            'message' => '2',
            'channel' => 'telegram',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['conversation_id' => $assumedIn->id, 'status' => 'completed']);
        $this->assertNoticedOnce($assumedIn, $question);
        $this->assertSame($decision, $this->decision($question), 'the reply is not applied to the question');
        $this->assertSame(1, $this->chatCalls, 'handled as a new message in that conversation');
        $this->assertSame(0, Message::where('conversation_id', $recent->id)->count(), 'the other conversation is untouched');
        $this->assertSame(2, Conversation::where('user_id', $this->user->id)->count(), 'no new conversation is created');
    }

    #[Test]
    public function once_noticed_a_reply_without_a_conversation_id_is_routed_as_before(): void
    {
        $assumedIn = $this->conversation();
        $question = $this->assumedQuestion($assumedIn);
        $this->lastActive($assumedIn, 30);
        $recent = $this->conversation();
        $this->lastActive($recent, 2);

        $this->actingAs($this->user, 'api')->postJson(self::ENDPOINT, ['message' => '2', 'channel' => 'telegram'])
            ->assertStatus(200)
            ->assertJson(['conversation_id' => $assumedIn->id]);
        $this->lastActive($assumedIn, 30);

        $second = $this->actingAs($this->user, 'api')->postJson(self::ENDPOINT, ['message' => 'And the docs too.', 'channel' => 'telegram']);

        $second->assertStatus(200);
        $second->assertJson(['conversation_id' => $recent->id], 'the ordinary lookup picks the recently active conversation');
        $this->assertNoticedOnce($assumedIn, $question);
        $this->assertSame([], $this->lateReplyNotices($recent));
        $this->assertSame(2, $this->chatCalls);
    }

    #[Test]
    public function without_a_conversation_id_a_busy_conversation_that_proceeded_gets_the_notice_but_not_the_reply(): void
    {
        $assumedIn = $this->conversation();
        $question = $this->assumedQuestion($assumedIn, stillProcessing: true);
        $decision = $this->decision($question);
        $runsBefore = DB::table('agent_runs')->where('conversation_id', $assumedIn->id)->count();
        $userMessagesBefore = $this->userMessageCount($assumedIn);

        $recent = $this->conversation();
        $this->lastActive($recent, 1);
        $this->replies = [$this->textReply('Hello again.')];

        $response = $this->actingAs($this->user, 'api')->postJson(self::ENDPOINT, [
            'message' => '2',
            'channel' => 'telegram',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['conversation_id' => $recent->id], 'the reply goes where an ordinary reply would');
        $this->assertNoticedOnce($assumedIn, $question);
        $this->assertSame($decision, $this->decision($question));
        $this->assertSame($userMessagesBefore, $this->userMessageCount($assumedIn), 'the reply is not put into the busy conversation');
        $this->assertSame($runsBefore, DB::table('agent_runs')->where('conversation_id', $assumedIn->id)->count(), 'no second run starts beside the continuing one');
        $this->assertTrue((bool) $assumedIn->fresh()->is_processing, 'the continuing run still has its conversation');
        $this->assertSame(1, $this->chatCalls, 'one run, in the other conversation');
    }

    public static function closedConversations(): array
    {
        return [
            'ended' => ['ended'],
            'deleted' => ['deleted'],
        ];
    }

    #[Test]
    #[DataProvider('closedConversations')]
    public function without_a_conversation_id_an_ended_or_deleted_conversation_that_proceeded_is_left_alone(string $how): void
    {
        $assumedIn = $this->conversation();
        $question = $this->assumedQuestion($assumedIn);
        $this->lastActive($assumedIn, 30);
        DB::table('conversations')->where('id', $assumedIn->id)->update($how === 'ended' ? ['ended_at' => now()->subHours(29)] : ['deleted_at' => now()->subHours(29)]);

        $recent = $this->conversation();
        $this->lastActive($recent, 1);

        $response = $this->actingAs($this->user, 'api')->postJson(self::ENDPOINT, [
            'message' => '2',
            'channel' => 'telegram',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['conversation_id' => $recent->id]);
        $this->assertSame([], $this->lateReplyNotices($assumedIn), "an {$how} conversation is not told");
        $this->assertSame([], $this->telegram->deliveries, 'nothing is delivered');
        $this->assertNull(DB::table('agent_questions')->where('id', $question->id)->value('late_reply_noticed_at'));
    }

    #[Test]
    public function another_users_reply_without_a_conversation_id_never_reaches_a_conversation_that_proceeded(): void
    {
        $assumedIn = $this->conversation();
        $question = $this->assumedQuestion($assumedIn);
        $this->lastActive($assumedIn, 30);

        $response = $this->actingAs($this->stranger, 'api')->postJson(self::ENDPOINT, [
            'message' => '2',
            'channel' => 'telegram',
        ]);

        $response->assertStatus(200);
        $this->assertNotSame($assumedIn->id, $response->json('conversation_id'));
        $this->assertSame([], $this->lateReplyNotices($assumedIn));
        $this->assertNull(DB::table('agent_questions')->where('id', $question->id)->value('late_reply_noticed_at'));
    }
}
