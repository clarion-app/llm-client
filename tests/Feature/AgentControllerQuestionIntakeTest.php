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
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use ClarionApp\LlmClient\ValueObjects\RunKind;
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
 * Answers reach a pending question through the existing messaging endpoint,
 * `POST agent` (AgentController) — the one Telegram calls, with a channel
 * and usually without a conversation id.
 *
 * A reply with no conversation id goes to the user's conversation on that
 * channel that holds a pending question, however long ago it was asked —
 * the inactivity threshold and the processing flag that govern the ordinary
 * lookup do not apply. With an explicit conversation id the reply is taken
 * as an answer before the "already processing" check. An answered question
 * resumes the run synchronously and the response is the continuation's own
 * result; a run that asks reports `awaiting_user` with HTTP 202. A blank
 * reply is refused with `answer_required` and the question keeps waiting.
 */
class AgentControllerQuestionIntakeTest extends TestCase
{
    private const ENDPOINT = '/api/clarion-app/llm-client/agent';

    private const QUESTION = 'Which part should I change first?';

    private const CHOICES = ['Backend', 'Frontend', 'Docs'];

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
    private function pendingQuestion(Conversation $conversation, string $question = self::QUESTION, array $choices = self::CHOICES): AgentQuestion
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
            request: new AskUserRequest($question, $choices, false),
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

    /** Make $conversation look idle for $hours, past or within the threshold. */
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

    // ---------------------------------------------------------------
    // Which conversation a reply without a conversation id answers
    // ---------------------------------------------------------------

    #[Test]
    public function a_reply_without_a_conversation_id_answers_the_pending_question_even_past_the_inactivity_threshold(): void
    {
        $waiting = $this->conversation();
        $question = $this->pendingQuestion($waiting);
        $this->lastActive($waiting, 10);

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

        $question->refresh();
        $this->assertSame(QuestionState::Answered, $question->state);
        $this->assertSame('2', $question->answer_raw);
        $this->assertSame([['number' => 2, 'text' => 'Frontend']], $question->answer_selected);
        $this->assertNotNull($question->resumed_at);
        $this->assertSame(0, Message::where('conversation_id', $recent->id)->count(), 'the other conversation is untouched');
        $this->assertSame(2, Conversation::where('user_id', $this->user->id)->count(), 'no new conversation is created');
    }

    #[Test]
    public function the_most_recently_asked_pending_question_on_the_channel_is_answered(): void
    {
        $older = $this->conversation();
        $olderQuestion = $this->pendingQuestion($older, 'Which colour?', ['Red', 'Blue']);
        DB::table('agent_questions')->where('id', $olderQuestion->id)->update(['asked_at' => now()->subHours(3)]);

        $newer = $this->conversation();
        $newerQuestion = $this->pendingQuestion($newer);
        $this->lastActive($newer, 6);
        $this->lastActive($older, 1);

        $this->replies = [$this->textReply('Docs it is.')];

        $this->actingAs($this->user, 'api')->postJson(self::ENDPOINT, [
            'message' => '3',
            'channel' => 'telegram',
        ])->assertStatus(200)->assertJson(['conversation_id' => $newer->id]);

        $this->assertSame(QuestionState::Answered, $newerQuestion->fresh()->state);
        $this->assertSame(QuestionState::Pending, $olderQuestion->fresh()->state);
    }

    #[Test]
    public function a_pending_question_on_another_channel_is_not_answered(): void
    {
        $webConversation = $this->conversation('web');
        $question = $this->pendingQuestion($webConversation);

        $this->replies = [$this->textReply('Hello from Telegram.')];

        $response = $this->actingAs($this->user, 'api')->postJson(self::ENDPOINT, [
            'message' => '2',
            'channel' => 'telegram',
        ]);

        $response->assertStatus(200);
        $this->assertNotSame($webConversation->id, $response->json('conversation_id'));
        $this->assertSame(QuestionState::Pending, $question->fresh()->state, 'a reply on Telegram never answers a web question');
        $this->assertSame(1, $this->userMessageCount($webConversation));
    }

    #[Test]
    public function an_explicit_conversation_id_is_answered_before_the_processing_check(): void
    {
        $conversation = $this->conversation();
        $question = $this->pendingQuestion($conversation);
        DB::table('conversations')->where('id', $conversation->id)->update(['is_processing' => true]);

        $this->replies = [$this->textReply('Backend first, then.')];

        $response = $this->actingAs($this->user, 'api')->postJson(self::ENDPOINT, [
            'message' => '1',
            'conversation_id' => $conversation->id,
            'channel' => 'telegram',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['conversation_id' => $conversation->id, 'status' => 'completed', 'content' => 'Backend first, then.']);
        $this->assertSame(QuestionState::Answered, $question->fresh()->state);
    }

    #[Test]
    public function without_a_pending_question_a_processing_conversation_still_conflicts(): void
    {
        $conversation = $this->conversation();
        DB::table('conversations')->where('id', $conversation->id)->update(['is_processing' => true]);

        $this->actingAs($this->user, 'api')->postJson(self::ENDPOINT, [
            'message' => 'hello',
            'conversation_id' => $conversation->id,
        ])->assertStatus(409)->assertJson(['code' => 'processing']);

        $this->assertSame(0, $this->chatCalls);
    }

    // ---------------------------------------------------------------
    // Response shapes
    // ---------------------------------------------------------------

    #[Test]
    public function a_run_that_asks_returns_202_awaiting_user(): void
    {
        $conversation = $this->conversation();
        $this->replies = [$this->askReply('call_ask', self::QUESTION, self::CHOICES)];

        $response = $this->actingAs($this->user, 'api')->postJson(self::ENDPOINT, [
            'message' => 'Help me tidy up the project.',
            'conversation_id' => $conversation->id,
            'channel' => 'telegram',
        ]);

        $response->assertStatus(202);
        $question = AgentQuestion::where('conversation_id', $conversation->id)->first();
        $this->assertNotNull($question);
        $response->assertJson([
            'conversation_id' => $conversation->id,
            'message_id' => $question->display_message_id,
            'status' => 'awaiting_user',
            'question_id' => $question->id,
        ]);
        $this->assertSame('', (string) $response->json('content'), 'nothing to show: the question was pushed through the channel');
        $this->assertCount(1, $this->telegram->textsFor($conversation->id));
    }

    #[Test]
    public function an_answer_whose_continuation_asks_again_returns_202_awaiting_user(): void
    {
        $conversation = $this->conversation();
        $first = $this->pendingQuestion($conversation);
        // Opening the fixture's question delivered it; only what the reply
        // causes is under test.
        $this->telegram->deliveries = [];

        $this->replies = [$this->askReply('call_follow_up', 'Should I also update the docs?', ['Yes', 'No'])];

        $response = $this->actingAs($this->user, 'api')->postJson(self::ENDPOINT, [
            'message' => '2',
            'channel' => 'telegram',
        ]);

        $response->assertStatus(202);
        $this->assertSame(QuestionState::Answered, $first->fresh()->state);

        $followUp = AgentQuestion::where('conversation_id', $conversation->id)->where('id', '!=', $first->id)->first();
        $this->assertNotNull($followUp, 'the continuation asked a second question');
        $this->assertSame(QuestionState::Pending, $followUp->state);
        $response->assertJson([
            'conversation_id' => $conversation->id,
            'message_id' => $followUp->display_message_id,
            'status' => 'awaiting_user',
            'question_id' => $followUp->id,
        ]);
        $this->assertSame(
            ["Should I also update the docs?\n\n1. Yes\n2. No\n\nReply with the number of your choice, or answer in your own words."],
            $this->telegram->textsFor($conversation->id),
            'the follow-up question was pushed to the chat',
        );
    }

    // ---------------------------------------------------------------
    // Blank replies
    // ---------------------------------------------------------------

    #[Test]
    #[DataProvider('blankReplies')]
    public function a_blank_reply_to_a_pending_question_is_refused_with_answer_required(?string $message): void
    {
        $conversation = $this->conversation();
        $question = $this->pendingQuestion($conversation);

        $response = $this->actingAs($this->user, 'api')->postJson(self::ENDPOINT, [
            'message' => $message,
            'channel' => 'telegram',
        ]);

        $response->assertStatus(422);
        $response->assertExactJson([
            'code' => 'answer_required',
            'error' => 'An answer is required to reply to the pending question.',
        ]);

        $question->refresh();
        $this->assertSame(QuestionState::Pending, $question->state, 'the question keeps waiting');
        $this->assertSame($conversation->id, $question->pending_conversation_id);
        $this->assertSame(1, $this->userMessageCount($conversation), 'a blank reply is not stored');
        $this->assertSame(0, $this->chatCalls);
    }

    #[Test]
    #[DataProvider('blankReplies')]
    public function a_blank_message_without_a_pending_question_is_still_a_validation_error(?string $message): void
    {
        $this->conversation();

        $response = $this->actingAs($this->user, 'api')->postJson(self::ENDPOINT, [
            'message' => $message,
            'channel' => 'telegram',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('message');
        $this->assertSame(0, $this->chatCalls);
    }

    // ---------------------------------------------------------------
    // Ownership, and everything else untouched
    // ---------------------------------------------------------------

    #[Test]
    public function another_user_cannot_answer_by_conversation_id(): void
    {
        $conversation = $this->conversation();
        $question = $this->pendingQuestion($conversation);
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

    #[Test]
    public function another_users_reply_without_a_conversation_id_never_reaches_the_question(): void
    {
        $conversation = $this->conversation();
        $question = $this->pendingQuestion($conversation);
        $before = (array) DB::table('agent_questions')->where('id', $question->id)->first();

        $this->replies = [$this->textReply('Hello stranger.')];

        $response = $this->actingAs($this->stranger, 'api')->postJson(self::ENDPOINT, [
            'message' => '2',
            'channel' => 'telegram',
        ]);

        $response->assertStatus(200);
        $this->assertNotSame($conversation->id, $response->json('conversation_id'));
        $this->assertSame($before, (array) DB::table('agent_questions')->where('id', $question->id)->first());
    }

    #[Test]
    public function without_a_pending_question_a_message_runs_the_agent_as_before(): void
    {
        $conversation = $this->conversation();
        $this->replies = [$this->textReply('Hi there.')];

        $response = $this->actingAs($this->user, 'api')->postJson(self::ENDPOINT, [
            'message' => 'Hello',
            'channel' => 'telegram',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['conversation_id' => $conversation->id, 'status' => 'completed', 'content' => 'Hi there.']);
        $this->assertSame(1, $this->chatCalls);
        $this->assertSame(0, AgentQuestion::count());
    }

    // ---------------------------------------------------------------
    // Post-implementation reconciliation
    // ---------------------------------------------------------------

    /**
     * Between intake reading the conversation's pending question and the
     * answer's compare-and-set, the question expires the way the expiry
     * sweep expires it (which also marks the conversation processing for
     * the run it is about to resume). Returns a flag set once that happened.
     */
    private function expireWhenIntakeReads(AgentQuestion $question): \ArrayObject
    {
        $state = new \ArrayObject(['expired' => false]);
        Event::listen('eloquent.retrieved: '.AgentQuestion::class, function (AgentQuestion $retrieved) use ($state, $question) {
            if ($state['expired'] || $retrieved->id !== $question->id) {
                return;
            }
            $state['expired'] = true;
            $this->assertTrue(app(AgentQuestionService::class)->expire($retrieved), 'precondition: the expiry won');
        });

        return $state;
    }

    #[Test]
    public function a_reply_without_a_conversation_id_that_loses_to_an_expiry_is_an_ordinary_message_elsewhere(): void
    {
        $waiting = $this->conversation();
        $question = $this->pendingQuestion($waiting);
        // A question asked before unanswered policies existed: it expires as it always did.
        DB::table('agent_questions')->where('id', $question->id)->update(['unanswered_policy' => null, 'effective_wait_minutes' => null]);
        $question->refresh();
        $expired = $this->expireWhenIntakeReads($question);
        $this->replies = [$this->textReply('A fresh start.')];

        $response = $this->actingAs($this->user, 'api')->postJson(self::ENDPOINT, [
            'message' => '2',
            'channel' => 'telegram',
        ]);

        $this->assertTrue($expired['expired'], 'precondition: intake read the pending question');
        $response->assertStatus(200);
        $this->assertNotSame(
            $waiting->id,
            $response->json('conversation_id'),
            'the expired question\'s conversation is resuming its own run; the reply must not start a second one there',
        );
        $this->assertSame(1, $this->userMessageCount($waiting), 'nothing was added to the conversation that is resuming');
        $this->assertSame(QuestionState::Expired, $question->fresh()->state, 'the expiry stands');
        $this->assertNull($question->fresh()->answer_raw);
    }

    #[Test]
    public function an_explicit_reply_that_loses_to_an_expiry_conflicts_instead_of_starting_a_second_run(): void
    {
        $conversation = $this->conversation();
        $question = $this->pendingQuestion($conversation);
        // A question asked before unanswered policies existed: it expires as it always did.
        DB::table('agent_questions')->where('id', $question->id)->update(['unanswered_policy' => null, 'effective_wait_minutes' => null]);
        $question->refresh();
        $expired = $this->expireWhenIntakeReads($question);

        $response = $this->actingAs($this->user, 'api')->postJson(self::ENDPOINT, [
            'message' => '2',
            'conversation_id' => $conversation->id,
            'channel' => 'telegram',
        ]);

        $this->assertTrue($expired['expired'], 'precondition: intake read the pending question');
        $response->assertStatus(409);
        $response->assertJson(['code' => 'processing']);
        $this->assertSame(0, $this->chatCalls, 'no model call: the expired question\'s own resume owns the conversation');
        $this->assertSame(QuestionState::Expired, $question->fresh()->state);
    }

    /**
     * A blank message is let through validation only for the requester's
     * own pending question; otherwise whether someone else's conversation is
     * waiting on its user would show in the status code.
     */
    #[Test]
    #[DataProvider('blankReplies')]
    public function a_blank_message_reveals_nothing_about_another_users_pending_question(?string $message): void
    {
        $conversation = $this->conversation();
        $question = $this->pendingQuestion($conversation);

        foreach (['/api/clarion-app/llm-client/agent' => 'message', '/api/clarion-app/llm-client/message' => 'content'] as $endpoint => $field) {
            $response = $this->actingAs($this->stranger, 'api')->postJson($endpoint, [
                $field => $message,
                'conversation_id' => $conversation->id,
                'channel' => 'telegram',
            ]);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors($field);
        }

        $this->assertSame(QuestionState::Pending, $question->fresh()->state);
        $this->assertSame(0, $this->chatCalls);
    }

    /**
     * A Telegram conversation is listed on the web screen too, and the
     * owner's reply there answers its question. The question paused a
     * synchronous run, whose reply `POST message` does not carry, so it is
     * pushed to the conversation's own channel.
     */
    #[Test]
    public function a_synchronous_question_answered_from_the_web_screen_delivers_the_reply_to_its_channel(): void
    {
        $conversation = $this->conversation('telegram');
        $question = $this->pendingQuestion($conversation);
        $this->telegram->deliveries = [];
        $this->replies = [$this->textReply('Backend first, then.')];

        $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/message', [
            'conversation_id' => $conversation->id,
            'content' => '1',
        ])->assertStatus(201);

        $this->assertSame(QuestionState::Answered, $question->fresh()->state);
        $this->assertSame(1, $this->chatCalls, 'the paused run continued');
        $this->assertContains(
            'Backend first, then.',
            $this->telegram->textsFor($conversation->id),
            'the continuation\'s reply reaches the Telegram chat the conversation lives on',
        );
    }
}
