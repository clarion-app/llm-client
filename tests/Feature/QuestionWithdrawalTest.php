<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Jobs\AgentStreamStepJob;
use ClarionApp\LlmClient\AgentLoopStreamHandler;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Events\ApiCallConfirmationRequiredEvent;
use ClarionApp\LlmClient\Events\ConversationEnded;
use ClarionApp\LlmClient\Events\FinishOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Events\ToolExecutionEvent;
use ClarionApp\LlmClient\Events\UpdateOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\AgentQuestionService;
use ClarionApp\LlmClient\Services\AnswerParser;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\Services\QuestionResumer;
use ClarionApp\LlmClient\Services\RoleAssignmentService;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use ClarionApp\LlmClient\ValueObjects\ResumeCaller;
use Dedoc\Scramble\Generator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
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
 * Stopping a conversation withdraws its pending question and ends the
 * waiting run.
 *
 * Ending a conversation (`POST conversation/{id}/end`) or deleting it
 * (`DELETE conversation/{id}`) while a question waits on the user withdraws
 * the question: the paused `ask_user` call is answered with the `withdrawn`
 * outcome so the stored history stays valid, the run and its step end
 * `stopped_early` (`question withdrawn`) without another model request, and
 * the user is told the question no longer needs a reply. The conversation
 * is not marked processing. A reply sent afterwards is an ordinary message
 * and never revives the cancelled run. A withdrawal that arrives after the
 * question was already answered changes nothing: the answer stands and the
 * run continues.
 */
class QuestionWithdrawalTest extends TestCase
{
    private const QUESTION = 'Which part should I change first?';

    private const CHOICES = ['Backend', 'Frontend', 'Docs'];

    private const GOLDEN_THREE_CHOICES = "Which part should I change first?\n"
        ."\n"
        ."1. Backend\n"
        ."2. Frontend\n"
        ."3. Docs\n"
        ."\n"
        .'Reply with the number of your choice, or answer in your own words.'
        ."\n"
        ."\n"
        // Asked at the frozen 09:00 UTC on 12 Oct 2026 (see setUp()) with the
        // default one-day period and no policy, so the work stops if unanswered.
        .'If there is no answer by 09:00 UTC on 13 Oct 2026 (1 day after this was asked), work on this task will pause until you reply.';

    private const WITHDRAWN_NOTICE = 'The question "Which part should I change first?" was withdrawn because the task was stopped. '
        .'It no longer needs a reply.';

    private const WITHDRAWN_REASON = 'question withdrawn';

    private User $user;

    private Server $server;

    private RecordingChannelDelivery $web;

    private RecordingChannelDelivery $telegram;

    /** @var list<array<string, mixed>> scripted model replies, consumed in order */
    private array $replies = [];

    /** Number of model requests made. */
    private int $chatCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // Frozen, so the deadline every question states is known exactly.
        $this->travelTo(Carbon::parse('2026-10-12 09:00:00', 'UTC'));

        $this->user = User::factory()->create();
        $this->server = Server::create([
            'name' => 'Withdrawal LLM Server',
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

    /** The container's agent loop, with its model replies scripted and counted. */
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

    private function askCall(string $id = 'call_ask'): array
    {
        return [
            'id' => $id,
            'type' => 'function',
            'function' => ['name' => 'ask_user', 'arguments' => json_encode(['question' => self::QUESTION, 'choices' => self::CHOICES])],
        ];
    }

    private function conversation(string $channel): Conversation
    {
        return Conversation::factory()->create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'title' => "Withdrawal on {$channel}",
            'channel' => $channel,
            'is_processing' => false,
        ]);
    }

    /**
     * Open a question the way the conversation's channel does: a streamed
     * model turn on `web`, a synchronous run (as `POST agent` drives it) on
     * `telegram`.
     */
    private function askIn(Conversation $conversation): AgentQuestion
    {
        if ($conversation->channel === 'web') {
            $conversation->update(['is_processing' => true]);

            $handler = new AgentLoopStreamHandler();
            $handler->toolCalls = [$this->askCall()];
            $handler->message = Message::create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'user' => 'Clarion',
                'content' => '',
                'responseTime' => 0,
            ]);
            $handler->finish(json_encode(['conversation_id' => $conversation->id, 'iteration' => 1]), 2);
        } else {
            $this->replies = [['choices' => [['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [$this->askCall()]]]]]];
            $result = app(AgentLoopService::class)->run($conversation->fresh(), 'Help me tidy up the project.');
            $this->assertSame('awaiting_user', $result['status'] ?? null, 'precondition; got: '.json_encode($result));
        }

        $question = AgentQuestion::where('conversation_id', $conversation->id)->first();
        $this->assertNotNull($question, 'precondition: the ask opened a question');
        $this->assertSame(QuestionState::Pending, $question->state);
        $this->assertNotNull($question->run_id, 'precondition: the question belongs to a recorded run');
        $this->assertFalse((bool) $conversation->fresh()->is_processing, 'precondition: a waiting conversation is not processing');

        return $question;
    }

    private function recorderFor(Conversation $conversation): RecordingChannelDelivery
    {
        return $conversation->channel === 'web' ? $this->web : $this->telegram;
    }

    private function stop(string $how, Conversation $conversation): \Illuminate\Testing\TestResponse
    {
        $request = $this->actingAs($this->user, 'api');

        return $how === 'end'
            ? $request->postJson("/api/clarion-app/llm-client/conversation/{$conversation->id}/end")
            : $request->deleteJson("/api/clarion-app/llm-client/conversation/{$conversation->id}");
    }

    private function withdrawnResult(AgentQuestion $question): array
    {
        return [
            'outcome' => 'withdrawn',
            'question_id' => $question->id,
            'message' => 'The user stopped this task while the question was pending.',
        ];
    }

    /** @return array<string, mixed>|null the decoded result stored for $toolCallId */
    private function storedResult(Message $message, string $toolCallId): ?array
    {
        foreach ((array) ($message->tool_data['tool_results'] ?? []) as $result) {
            if (($result['tool_call_id'] ?? null) === $toolCallId) {
                return json_decode((string) ($result['content'] ?? ''), true);
            }
        }

        return null;
    }

    private function streamRequestCount(): int
    {
        return Queue::pushed(AgentStreamStepJob::class)->count();
    }

    /**
     * The question was withdrawn and its run ended without the model being
     * asked anything more.
     */
    private function assertWithdrawnAndEnded(AgentQuestion $question, Conversation $conversation, int $chatCallsBefore, int $streamRequestsBefore): void
    {
        $fresh = AgentQuestion::findOrFail($question->id);
        $this->assertSame(QuestionState::Withdrawn, $fresh->state);
        $this->assertNull($fresh->pending_conversation_id, 'the conversation\'s slot is released');
        $this->assertNotNull($fresh->resolved_at);
        $this->assertNotNull($fresh->resumed_at, 'the paused turn was consumed');
        $this->assertNull($fresh->answer_raw);

        // The paused call is answered, so the stored history stays valid.
        $paused = Message::findOrFail($question->paused_message_id);
        $this->assertSame($this->withdrawnResult($question), $this->storedResult($paused, $question->tool_call_id));
        $this->assertArrayHasKey('pending_question', $paused->tool_data);
        $this->assertNull($paused->tool_data['pending_question'], 'the paused turn no longer waits on the question');

        // The run ends; the model is not asked anything more.
        $this->assertSame($chatCallsBefore, $this->chatCalls, 'no model request after a withdrawal');
        $this->assertSame($streamRequestsBefore, $this->streamRequestCount(), 'no streamed request after a withdrawal');

        $run = DB::table('agent_runs')->where('id', $question->run_id)->first();
        $this->assertSame('stopped_early', $run->end_state);
        $this->assertSame(self::WITHDRAWN_REASON, $run->end_reason);
        $step = DB::table('agent_run_steps')->where('id', $question->step_id)->first();
        $this->assertSame('stopped_early', $step->end_state);
        $this->assertSame(self::WITHDRAWN_REASON, $step->end_reason);
        $action = DB::table('agent_run_actions')->where('id', $question->action_id)->first();
        $this->assertSame('failure', $action->outcome);
        $this->assertSame(self::WITHDRAWN_REASON, $action->failure_reason);

        // The user is told the question no longer needs a reply.
        $this->assertSame(
            'withdrawn',
            Message::findOrFail($question->display_message_id)->tool_data['state'] ?? null,
            'the question is shown as withdrawn',
        );
        $notices = Message::where('conversation_id', $conversation->id)->get()
            ->filter(fn (Message $m) => ($m->tool_data['kind'] ?? null) === 'agent_question_notice')
            ->values();
        $this->assertCount(1, $notices, 'one withdrawal notice is written');
        $this->assertSame(self::WITHDRAWN_NOTICE, $notices[0]->content);
        $this->assertSame('assistant', $notices[0]->role);
        $this->assertSame(['kind' => 'agent_question_notice', 'question_id' => $question->id, 'state' => 'withdrawn', 'delivered' => true], $notices[0]->tool_data);
        $this->assertSame(
            [self::GOLDEN_THREE_CHOICES, self::WITHDRAWN_NOTICE],
            $this->recorderFor($conversation)->textsFor($conversation->id),
            'the withdrawal notice is delivered once, on the conversation\'s channel',
        );
    }

    // ---------------------------------------------------------------
    // Withdrawal
    // ---------------------------------------------------------------

    /** @return array<string, array{string, string}> */
    public static function stopsByChannel(): array
    {
        return [
            'end, web' => ['end', 'web'],
            'end, telegram' => ['end', 'telegram'],
            'delete, web' => ['delete', 'web'],
            'delete, telegram' => ['delete', 'telegram'],
        ];
    }

    #[Test]
    #[DataProvider('stopsByChannel')]
    public function stopping_the_conversation_withdraws_the_question_and_ends_the_run(string $how, string $channel): void
    {
        $conversation = $this->conversation($channel);
        $question = $this->askIn($conversation);
        $chatCallsBefore = $this->chatCalls;
        $streamRequestsBefore = $this->streamRequestCount();

        $response = $this->stop($how, $conversation);

        if ($how === 'end') {
            $response->assertStatus(200)->assertJson(['id' => $conversation->id, 'ended' => true]);
            $this->assertNotNull($conversation->fresh()->ended_at);
            $this->assertFalse((bool) $conversation->fresh()->is_processing, 'a withdrawal does not mark the conversation processing');
            Event::assertDispatchedTimes(ConversationEnded::class, 1);
        } else {
            $response->assertStatus(204);
            $this->assertNull(Conversation::find($conversation->id), 'the conversation is still deleted');
        }

        $this->assertWithdrawnAndEnded($question, $conversation, $chatCallsBefore, $streamRequestsBefore);
    }

    #[Test]
    public function ending_an_already_ended_conversation_still_withdraws_its_question(): void
    {
        $conversation = $this->conversation('telegram');
        $question = $this->askIn($conversation);
        $conversation->forceFill(['ended_at' => now()->subMinute()])->save();
        $chatCallsBefore = $this->chatCalls;

        $this->stop('end', $conversation)->assertStatus(200)->assertJson(['ended' => false]);

        $this->assertWithdrawnAndEnded($question, $conversation, $chatCallsBefore, $this->streamRequestCount());
    }

    #[Test]
    public function another_users_stop_request_does_not_withdraw_the_question(): void
    {
        $conversation = $this->conversation('telegram');
        $question = $this->askIn($conversation);
        $stranger = User::factory()->create();

        $this->actingAs($stranger, 'api')->postJson("/api/clarion-app/llm-client/conversation/{$conversation->id}/end")->assertStatus(403);
        $this->actingAs($stranger, 'api')->deleteJson("/api/clarion-app/llm-client/conversation/{$conversation->id}")->assertStatus(403);

        $fresh = $question->fresh();
        $this->assertSame(QuestionState::Pending, $fresh->state);
        $this->assertSame($conversation->id, $fresh->pending_conversation_id);
        $this->assertSame('in_progress', DB::table('agent_runs')->where('id', $question->run_id)->value('end_state'));
        $this->assertSame([self::GOLDEN_THREE_CHOICES], $this->telegram->textsFor($conversation->id), 'no notice is sent');
    }

    #[Test]
    public function stopping_a_conversation_with_no_pending_question_behaves_as_before(): void
    {
        $conversation = $this->conversation('web');

        $this->stop('end', $conversation)->assertStatus(200)->assertJson(['ended' => true]);

        $this->assertSame([], $this->web->deliveries);
        $this->assertSame(0, Message::where('conversation_id', $conversation->id)->count());
        Event::assertDispatchedTimes(ConversationEnded::class, 1);
    }

    // ---------------------------------------------------------------
    // After the withdrawal
    // ---------------------------------------------------------------

    #[Test]
    public function a_web_reply_after_ending_is_an_ordinary_message_that_does_not_resume_the_cancelled_run(): void
    {
        $conversation = $this->conversation('web');
        $question = $this->askIn($conversation);
        $this->stop('end', $conversation)->assertStatus(200);
        $resumedAt = $question->fresh()->resumed_at;
        $streamRequestsBefore = $this->streamRequestCount();

        $reply = $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/message', [
            'conversation_id' => $conversation->id,
            'content' => '2',
        ]);

        $reply->assertStatus(201);
        $this->assertNull($reply->json('tool_data'), 'the reply is stored as an ordinary message, not an answer');

        $fresh = $question->fresh();
        $this->assertSame(QuestionState::Withdrawn, $fresh->state, 'the reply is never applied to the withdrawn question');
        $this->assertNull($fresh->answer_raw);
        $this->assertEquals($resumedAt, $fresh->resumed_at, 'the cancelled run is not resumed again');
        $this->assertSame('stopped_early', DB::table('agent_runs')->where('id', $question->run_id)->value('end_state'));

        $this->assertSame($streamRequestsBefore + 1, $this->streamRequestCount(), 'the reply starts ordinary new work');
        $newRunIds = DB::table('agent_runs')->where('conversation_id', $conversation->id)->where('id', '<>', $question->run_id)->pluck('id');
        $this->assertCount(1, $newRunIds, 'the new work is a new run, not the cancelled one');
    }

    #[Test]
    public function a_telegram_reply_after_ending_is_an_ordinary_message_that_does_not_resume_the_cancelled_run(): void
    {
        $conversation = $this->conversation('telegram');
        $question = $this->askIn($conversation);
        $this->stop('end', $conversation)->assertStatus(200);
        $resumedAt = $question->fresh()->resumed_at;
        $chatCallsBefore = $this->chatCalls;
        $this->replies = [$this->textReply('Starting a fresh task.')];

        $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/agent', [
            'message' => '2',
            'channel' => 'telegram',
            'conversation_id' => $conversation->id,
        ])->assertStatus(200)->assertJson(['status' => 'completed', 'content' => 'Starting a fresh task.']);

        $stored = Message::where('conversation_id', $conversation->id)->where('role', 'user')->where('content', '2')->get();
        $this->assertCount(1, $stored);
        $this->assertNull($stored[0]->tool_data, 'stored as an ordinary message, not an answer');

        $fresh = $question->fresh();
        $this->assertSame(QuestionState::Withdrawn, $fresh->state);
        $this->assertNull($fresh->answer_raw);
        $this->assertEquals($resumedAt, $fresh->resumed_at, 'the cancelled run is not resumed again');
        $this->assertSame('stopped_early', DB::table('agent_runs')->where('id', $question->run_id)->value('end_state'));
        $this->assertSame($chatCallsBefore + 1, $this->chatCalls, 'the reply is handled as new work');
    }

    // ---------------------------------------------------------------
    // A withdrawal that arrives too late
    // ---------------------------------------------------------------

    #[Test]
    public function a_withdrawal_after_the_answer_was_accepted_keeps_the_answer_but_the_end_cancels_the_run(): void
    {
        $conversation = $this->conversation('telegram');
        $question = $this->askIn($conversation);

        // The answer is accepted; its continuation has not started yet.
        $answer = app(AgentQuestionService::class)->answer(
            $question,
            (new AnswerParser())->parse('1', $question),
            (string) $this->user->id,
            '1',
        );
        $this->assertNotNull($answer, 'precondition: the answer won');

        $this->stop('end', $conversation)->assertStatus(200);

        $fresh = $question->fresh();
        $this->assertSame(QuestionState::Answered, $fresh->state, 'the user\'s own answer took effect first');
        $this->assertSame('1', $fresh->answer_raw);
        $this->assertNull($fresh->resumed_at, 'the withdrawal neither consumed nor closed anything');
        $this->assertSame('in_progress', DB::table('agent_runs')->where('id', $question->run_id)->value('end_state'));
        $this->assertSame('awaiting_confirmation', DB::table('agent_run_actions')->where('id', $question->action_id)->value('outcome'));
        $this->assertSame([self::GOLDEN_THREE_CHOICES], $this->telegram->textsFor($conversation->id), 'no withdrawal notice');
        $this->assertSame(
            0,
            Message::where('conversation_id', $conversation->id)->get()->filter(fn (Message $m) => ($m->tool_data['kind'] ?? null) === 'agent_question_notice')->count(),
        );
        $this->assertTrue((bool) $conversation->fresh()->is_processing, 'the answered run is still about to continue');

        // Ending the conversation cancelled the run, and cancellation wins
        // over the continuation: the answer is recorded with the
        // paused call, but the run is closed instead of continuing.
        $chatCallsBefore = $this->chatCalls;
        $this->replies = [$this->textReply('Starting with the backend.')];
        app(QuestionResumer::class)->resume($question->id, ResumeCaller::Background);

        $this->assertSame($chatCallsBefore, $this->chatCalls, 'no model call after the cancellation');
        $run = DB::table('agent_runs')->where('id', $question->run_id)->first();
        $this->assertSame('stopped_early', $run->end_state);
        $this->assertSame(app(\ClarionApp\LlmClient\Services\TaskResumeText::class)->cancelledReason(), $run->end_reason);
        $this->assertFalse((bool) $conversation->fresh()->is_processing, 'the conversation is released');
        $this->assertSame('answered', $this->storedResult(Message::findOrFail($question->paused_message_id), $question->tool_call_id)['outcome'] ?? null);
    }
}
