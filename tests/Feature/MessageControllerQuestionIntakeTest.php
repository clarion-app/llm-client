<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\AgentQuestionService;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
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
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\RecordingChannelDelivery;
use Tests\TestCase;

/**
 * Answers reach a pending question through the existing web message
 * endpoint, `POST message` (MessageController::store()). No new route.
 *
 * With a pending question in the conversation, the owner's non-blank reply
 * is recorded as the answer (exactly one stored user message, marked as the
 * answer) and the paused run is resumed; a blank reply is refused with
 * `answer_required` and the question keeps waiting. Without a pending
 * question — or when the question was resolved by something else a moment
 * before the reply landed — the endpoint behaves exactly as it always has.
 */
class MessageControllerQuestionIntakeTest extends TestCase
{
    private const ENDPOINT = '/api/clarion-app/llm-client/message';

    private User $user;

    private User $stranger;

    private Server $server;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->stranger = User::factory()->create();
        $this->server = Server::create([
            'name' => 'Intake LLM Server',
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

        // Starting a streamed turn reads condensation state for the
        // conversation; these package tables are not in the shared schema.
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
        Event::fake([NewConversationMessageEvent::class]);
        app(ConversationChannelRegistry::class)->register(new RecordingChannelDelivery('web'));

        $this->conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'title' => 'Intake conversation',
            'channel' => 'web',
            'is_processing' => false,
        ]);
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

    /**
     * A pending three-choice question in the conversation, opened the way a
     * streamed pause site opens it.
     */
    private function pendingQuestion(): AgentQuestion
    {
        $this->conversation->update(['is_processing' => true]);

        $recorder = app(RunTraceRecorder::class);
        $runId = $recorder->openRun(RunKind::Interactive, (string) $this->user->id, $this->conversation->id, streamed: true);
        $stepId = $recorder->openStep($runId);
        $actionId = $recorder->openAction($stepId, ActionType::ToolInvocation, 'ask_user');

        $paused = Message::create([
            'conversation_id' => $this->conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => '',
            'responseTime' => 0,
            'tool_data' => ['tool_calls' => [[
                'id' => 'call_ask',
                'type' => 'function',
                'function' => ['name' => 'ask_user', 'arguments' => json_encode([
                    'question' => 'Which part should I change first?',
                    'choices' => ['Backend', 'Frontend', 'Docs'],
                ])],
            ]]],
        ]);

        $opened = app(AgentQuestionService::class)->open(new OpenQuestionRequest(
            conversation: $this->conversation->fresh(),
            request: new AskUserRequest('Which part should I change first?', ['Backend', 'Frontend', 'Docs'], false),
            runId: $runId,
            stepId: $stepId,
            actionId: $actionId,
            pausedMessage: $paused,
            toolCallId: 'call_ask',
            resumeMode: OpenQuestionRequest::RESUME_STREAM,
            preamble: null,
            pendingToolResults: [],
            iteration: 1,
        ));

        $this->assertInstanceOf(OpenedQuestion::class, $opened, 'precondition: the question opened');
        $this->assertFalse((bool) $this->conversation->fresh()->is_processing, 'precondition: a waiting conversation is not processing');

        return AgentQuestion::findOrFail($opened->question->id);
    }

    private function userMessageCount(): int
    {
        return Message::where('conversation_id', $this->conversation->id)->where('role', 'user')->count();
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
    // A reply to a pending question
    // ---------------------------------------------------------------

    #[Test]
    public function the_owners_reply_answers_the_pending_question_and_resumes_the_run(): void
    {
        $question = $this->pendingQuestion();
        Queue::assertNotPushed(SendHttpStreamRequest::class);

        $response = $this->actingAs($this->user, 'api')->postJson(self::ENDPOINT, [
            'conversation_id' => $this->conversation->id,
            'content' => '2',
        ]);

        $response->assertStatus(201);
        $answerMessageId = $response->json('id');
        $this->assertNotNull($answerMessageId);
        $this->assertSame('2', $response->json('content'));
        $this->assertSame('user', strtolower((string) $response->json('role')));
        $this->assertSame('agent_question_answer', $response->json('tool_data.kind'));
        $this->assertSame($question->id, $response->json('tool_data.question_id'));

        $this->assertSame(1, $this->userMessageCount(), 'the answer is stored exactly once');

        $question->refresh();
        $this->assertSame(QuestionState::Answered, $question->state);
        $this->assertNull($question->pending_conversation_id, 'the slot is released');
        $this->assertSame('2', $question->answer_raw);
        $this->assertSame([['number' => 2, 'text' => 'Frontend']], $question->answer_selected);
        $this->assertSame((string) $this->user->id, (string) $question->answered_by);
        $this->assertSame($answerMessageId, $question->answer_message_id);
        $this->assertNotNull($question->resumed_at, 'the paused turn was consumed');

        $results = Message::findOrFail($question->paused_message_id)->tool_data['tool_results'] ?? null;
        $this->assertIsArray($results);
        $this->assertSame('call_ask', $results[0]['tool_call_id'] ?? null);
        $outcome = json_decode((string) $results[0]['content'], true);
        $this->assertSame('answered', $outcome['outcome'] ?? null);
        $this->assertSame([['number' => 2, 'text' => 'Frontend']], $outcome['answer']['selected'] ?? null);

        $this->assertSame('answered', Message::findOrFail($question->display_message_id)->tool_data['state'] ?? null);

        Queue::assertPushed(SendHttpStreamRequest::class, 1);
    }

    #[Test]
    #[DataProvider('blankReplies')]
    public function a_blank_reply_to_a_pending_question_is_refused_with_answer_required(?string $content): void
    {
        $question = $this->pendingQuestion();

        $response = $this->actingAs($this->user, 'api')->postJson(self::ENDPOINT, [
            'conversation_id' => $this->conversation->id,
            'content' => $content,
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'code' => 'answer_required',
            'message' => 'An answer is required to reply to the pending question.',
        ]);

        $question->refresh();
        $this->assertSame(QuestionState::Pending, $question->state, 'the question keeps waiting');
        $this->assertSame($this->conversation->id, $question->pending_conversation_id);
        $this->assertNull($question->answer_raw);
        $this->assertSame(0, $this->userMessageCount(), 'a blank reply is not stored');
        Queue::assertNotPushed(SendHttpStreamRequest::class);
    }

    #[Test]
    #[DataProvider('blankReplies')]
    public function a_blank_message_without_a_pending_question_is_still_a_validation_error(?string $content): void
    {
        $response = $this->actingAs($this->user, 'api')->postJson(self::ENDPOINT, [
            'conversation_id' => $this->conversation->id,
            'content' => $content,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('content');
        $this->assertSame(0, $this->userMessageCount());
        Queue::assertNotPushed(SendHttpStreamRequest::class);
    }

    // ---------------------------------------------------------------
    // Everything else is untouched
    // ---------------------------------------------------------------

    #[Test]
    public function without_a_pending_question_a_message_is_handled_as_before(): void
    {
        $response = $this->actingAs($this->user, 'api')->postJson(self::ENDPOINT, [
            'conversation_id' => $this->conversation->id,
            'content' => 'Hello there',
        ]);

        $response->assertStatus(201);
        $message = Message::findOrFail($response->json('id'));
        $this->assertSame('Hello there', $message->content);
        $this->assertSame('user', strtolower((string) $message->role));
        $this->assertNull($message->tool_data, 'an ordinary message carries no question marker');
        $this->assertSame(0, AgentQuestion::count());
        Queue::assertPushed(SendHttpStreamRequest::class, 1);
    }

    #[Test]
    public function another_user_cannot_answer_and_the_question_is_untouched(): void
    {
        $question = $this->pendingQuestion();
        $before = (array) DB::table('agent_questions')->where('id', $question->id)->first();

        $response = $this->actingAs($this->stranger, 'api')->postJson(self::ENDPOINT, [
            'conversation_id' => $this->conversation->id,
            'content' => '2',
        ]);

        $response->assertStatus(403);
        $this->assertSame($before, (array) DB::table('agent_questions')->where('id', $question->id)->first());
        $this->assertSame(0, $this->userMessageCount());
        Queue::assertNotPushed(SendHttpStreamRequest::class);
    }

    #[Test]
    public function a_reply_that_loses_to_an_earlier_resolution_is_stored_as_an_ordinary_message(): void
    {
        $question = $this->pendingQuestion();

        // Between intake reading the pending question and its answer being
        // recorded, the question expires (as the expiry sweep would: the
        // winning resolution also marks the conversation processing).
        $resolvedElsewhere = false;
        Event::listen('eloquent.retrieved: '.AgentQuestion::class, function (AgentQuestion $retrieved) use (&$resolvedElsewhere, $question) {
            if ($resolvedElsewhere || $retrieved->id !== $question->id) {
                return;
            }
            $resolvedElsewhere = true;
            DB::table('agent_questions')->where('id', $question->id)->update([
                'state' => QuestionState::Expired->value,
                'pending_conversation_id' => null,
                'resolved_at' => now(),
            ]);
            DB::table('conversations')->where('id', $question->conversation_id)->update(['is_processing' => true]);
        });

        $response = $this->actingAs($this->user, 'api')->postJson(self::ENDPOINT, [
            'conversation_id' => $this->conversation->id,
            'content' => '2',
        ]);

        $this->assertTrue($resolvedElsewhere, 'precondition: intake read the pending question');
        $response->assertStatus(201);

        $message = Message::findOrFail($response->json('id'));
        $this->assertSame('2', $message->content);
        $this->assertNull($message->tool_data, 'a reply that lost the race is an ordinary message, not an answer');
        $this->assertSame(1, $this->userMessageCount());

        $question->refresh();
        $this->assertSame(QuestionState::Expired, $question->state, 'the earlier resolution stands');
        $this->assertNull($question->answer_raw);
        $this->assertNull($question->answer_message_id);
        Queue::assertNotPushed(SendHttpStreamRequest::class);
    }
}
