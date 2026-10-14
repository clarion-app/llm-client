<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Jobs\AgentStreamStepJob;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Exceptions\BudgetExceededException;
use ClarionApp\LlmClient\Exceptions\RateLimitExceededException;
use ClarionApp\LlmClient\Jobs\ResumeAfterQuestionJob;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\AgentQuestionService;
use ClarionApp\LlmClient\Services\AnswerParser;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\Services\QuestionResumer;
use ClarionApp\LlmClient\Services\RoleAssignmentService;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\ActionType;
use ClarionApp\LlmClient\ValueObjects\AskUserRequest;
use ClarionApp\LlmClient\ValueObjects\BudgetWorkKind;
use ClarionApp\LlmClient\ValueObjects\EnforcementDecision;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use ClarionApp\LlmClient\ValueObjects\OpenedQuestion;
use ClarionApp\LlmClient\ValueObjects\OpenQuestionRequest;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use ClarionApp\LlmClient\ValueObjects\RateLimitDecision;
use ClarionApp\LlmClient\ValueObjects\ResumeCaller;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Dedoc\Scramble\Generator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Log\Events\MessageLogged;
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
 * A resolved question continues its run exactly once, whoever asks for the
 * resume and however often: the inline HTTP caller, the queued
 * ResumeAfterQuestionJob, or a re-drive of either.
 *
 * Also covers the two ways a resume can end without a normal continuation:
 * the continuation throws after the paused turn was consumed (the
 * conversation must not stay marked processing), and the question's
 * context — its conversation or its paused message — is gone (the run ends
 * without another model call and nothing is delivered).
 */
class QuestionResumerIdempotencyTest extends TestCase
{
    private User $user;

    private Server $server;

    private Conversation $conversation;

    private RecordingChannelDelivery $web;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->server = Server::create([
            'name' => 'Resume LLM Server',
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

        // A resumed streamed turn reads condensation state for the
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
        $this->web = new RecordingChannelDelivery('web');
        app(ConversationChannelRegistry::class)->register($this->web);

        $this->conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'title' => 'Resume conversation',
            'channel' => 'web',
            'is_processing' => false,
        ]);
    }

    protected function tearDown(): void
    {
        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        try {
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

            // Verifies the continuation call-count expectations.
            Mockery::close();
        } finally {
            parent::tearDown();
        }
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    /**
     * A pending three-choice question in the conversation, opened the way
     * the streamed pause site opens it. Deliveries made while opening it
     * are cleared, so later assertions see only what a resume delivers.
     */
    private function pendingQuestion(): AgentQuestion
    {
        $this->conversation->update(['is_processing' => true]);

        $recorder = app(RunTraceRecorder::class);
        $runId = $recorder->openRun(RunKind::Interactive, (string) $this->user->id, $this->conversation->id, streamed: true);
        $stepId = $recorder->openStep($runId);
        $actionId = $recorder->openAction($stepId, ActionType::ToolInvocation, 'ask_user');

        Message::create([
            'conversation_id' => $this->conversation->id,
            'role' => 'user',
            'user' => 'User',
            'content' => 'Help me tidy up the project.',
            'responseTime' => 0,
        ]);
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
        $this->web->deliveries = [];

        return AgentQuestion::findOrFail($opened->question->id);
    }

    /**
     * Record "2" as the answer without resuming — the state a crash right
     * after the answer commit leaves behind.
     */
    private function answered(AgentQuestion $question, string $reply = '2'): AgentQuestion
    {
        $message = app(AgentQuestionService::class)->answer(
            $question,
            (new AnswerParser())->parse($reply, $question),
            (string) $this->user->id,
            $reply,
        );
        $this->assertNotNull($message, 'precondition: the answer won the transition');

        $question->refresh();
        $this->assertSame(QuestionState::Answered, $question->state);
        $this->assertNull($question->resumed_at, 'precondition: not resumed yet');
        $this->assertTrue((bool) $this->conversation->fresh()->is_processing, 'precondition: the resolution marked the conversation processing');

        return $question;
    }

    /** The `ask_user` results stored on the paused turn, by tool call id. */
    private function pausedResults(AgentQuestion $question): array
    {
        $results = Message::findOrFail($question->paused_message_id)->tool_data['tool_results'] ?? [];

        return array_values(array_filter(
            is_array($results) ? $results : [],
            fn ($result) => is_array($result) && ($result['tool_call_id'] ?? null) === 'call_ask',
        ));
    }

    /** An agent loop whose question continuation throws $error, called at most once. */
    private function bindFailingContinuation(\Throwable $error): void
    {
        $loop = Mockery::mock(AgentLoopService::class);
        $loop->shouldReceive('resumeQuestion')->once()->andThrow($error);
        $loop->shouldReceive('resumeQuestionSync')->never();
        $this->app->instance(AgentLoopService::class, $loop);
    }

    private function runRow(AgentQuestion $question): object
    {
        $run = DB::table('agent_runs')->where('id', $question->run_id)->first();
        $this->assertNotNull($run, 'the question belongs to a recorded run');

        return $run;
    }

    private function stepRow(AgentQuestion $question): object
    {
        $step = DB::table('agent_run_steps')->where('id', $question->step_id)->first();
        $this->assertNotNull($step, 'the question belongs to a recorded step');

        return $step;
    }

    /** Run a queued job the way a worker does: through the container. */
    private function runJob(ResumeAfterQuestionJob $job): void
    {
        $this->app->call([$job, 'handle']);
    }

    public static function admissionRefusals(): array
    {
        return [
            'spending ceiling' => [fn () => new BudgetExceededException(
                new EnforcementDecision(EnforcementDecision::STOP, reason: 'Spending ceiling reached.'),
                BudgetWorkKind::Resumed,
            )],
            'rate limit' => [fn () => new RateLimitExceededException(
                new RateLimitDecision(RateLimitDecision::STOP, reason: 'Rate limit reached.'),
                BudgetWorkKind::Resumed,
            )],
        ];
    }

    // ---------------------------------------------------------------
    // Exactly once
    // ---------------------------------------------------------------

    #[Test]
    public function two_resumes_of_one_resolved_question_consume_and_continue_once(): void
    {
        $question = $this->answered($this->pendingQuestion());
        $resumer = app(QuestionResumer::class);

        $resumer->resume($question->id, ResumeCaller::HttpRequest);

        Queue::assertPushed(AgentStreamStepJob::class, 1);
        $firstClaim = $question->fresh()->resumed_at;
        $this->assertNotNull($firstClaim, 'the first resume takes the claim');
        $this->assertCount(1, $this->pausedResults($question), 'the paused ask_user call is answered');

        $this->travel(5)->seconds();
        $this->assertNull($resumer->resume($question->id, ResumeCaller::Background), 'a second resume finds nothing to do');

        Queue::assertPushed(AgentStreamStepJob::class, 1);
        $this->assertCount(1, $this->pausedResults($question), 'the paused call is answered exactly once');
        $this->assertEquals($firstClaim, $question->fresh()->resumed_at, 'the claim is taken once and never moved');
        $this->assertSame([], $this->web->deliveries, 'a streamed continuation delivers nothing through the adapter');
    }

    #[Test]
    public function resuming_a_still_pending_question_does_nothing(): void
    {
        $question = $this->pendingQuestion();
        $this->conversation->refresh();

        $this->assertNull(app(QuestionResumer::class)->resume($question->id, ResumeCaller::Background));

        $question->refresh();
        $this->assertSame(QuestionState::Pending, $question->state);
        $this->assertNull($question->resumed_at);
        $this->assertSame($this->conversation->id, $question->pending_conversation_id);

        $paused = Message::findOrFail($question->paused_message_id)->tool_data;
        $this->assertArrayHasKey('tool_results', $paused);
        $this->assertNull($paused['tool_results'], 'the paused turn is not consumed');
        $this->assertSame($question->id, $paused['pending_question']['question_id'] ?? null);
        $this->assertSame('pending', Message::findOrFail($question->display_message_id)->tool_data['state'] ?? null);
        $this->assertSame('awaiting_confirmation', DB::table('agent_run_actions')->where('id', $question->action_id)->value('outcome'));
        $this->assertSame('in_progress', $this->runRow($question)->end_state);
        $this->assertFalse((bool) $this->conversation->fresh()->is_processing);

        Queue::assertNotPushed(AgentStreamStepJob::class);
        $this->assertSame([], $this->web->deliveries);
    }

    // ---------------------------------------------------------------
    // The queued job
    // ---------------------------------------------------------------

    #[Test]
    public function the_job_is_queued_with_three_tries(): void
    {
        $job = new ResumeAfterQuestionJob('question-id');

        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertSame(3, $job->tries);
    }

    #[Test]
    public function the_job_resumes_through_the_resumer_as_a_background_caller(): void
    {
        $recording = new class extends QuestionResumer {
            /** @var list<array{0: string, 1: ResumeCaller}> */
            public array $calls = [];

            public function resume(string $questionId, ResumeCaller $caller): ?array
            {
                $this->calls[] = [$questionId, $caller];

                return null;
            }
        };
        $this->app->instance(QuestionResumer::class, $recording);

        $this->runJob(new ResumeAfterQuestionJob('question-42'));

        $this->assertSame([['question-42', ResumeCaller::Background]], $recording->calls);
    }

    #[Test]
    public function the_job_resumes_a_resolved_question_end_to_end(): void
    {
        $question = $this->answered($this->pendingQuestion());

        $this->runJob(new ResumeAfterQuestionJob($question->id));
        $this->runJob(new ResumeAfterQuestionJob($question->id));

        Queue::assertPushed(AgentStreamStepJob::class, 1);
        $this->assertNotNull($question->fresh()->resumed_at);
        $this->assertCount(1, $this->pausedResults($question));
        $this->assertSame('answered', Message::findOrFail($question->display_message_id)->tool_data['state'] ?? null);
    }

    #[Test]
    #[DataProvider('admissionRefusals')]
    public function the_job_swallows_an_admission_refusal_and_leaves_the_question_resolved(\Closure $refusal): void
    {
        $question = $this->answered($this->pendingQuestion());
        $this->bindFailingContinuation($refusal());

        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            $logged[] = $event;
        });

        $this->runJob(new ResumeAfterQuestionJob($question->id));

        $question->refresh();
        $this->assertSame(QuestionState::Answered, $question->state, 'the refusal does not undo the resolution');
        $this->assertNotNull($question->resumed_at, 'the claim stays taken, so a retry is a no-op');
        $this->assertNotEmpty($logged, 'the refusal is logged');
        Queue::assertNotPushed(AgentStreamStepJob::class);
    }

    // ---------------------------------------------------------------
    // Continuation failure
    // ---------------------------------------------------------------

    #[Test]
    public function continuation_failure_unwedges_conversation(): void
    {
        $question = $this->answered($this->pendingQuestion());
        $this->bindFailingContinuation(new \RuntimeException('model server unreachable'));

        $caught = null;
        try {
            app(QuestionResumer::class)->resume($question->id, ResumeCaller::HttpRequest);
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'an HTTP caller sees the failure');
        $this->assertSame('model server unreachable', $caught->getMessage());

        $this->assertFalse((bool) $this->conversation->fresh()->is_processing, 'the conversation accepts messages again');
        $run = $this->runRow($question);
        $this->assertSame('failed', $run->end_state);
        $this->assertSame('question resume failed: RuntimeException', $run->end_reason);
        $step = $this->stepRow($question);
        $this->assertSame('failed', $step->end_state);
        $this->assertSame('question resume failed: RuntimeException', $step->end_reason);

        // The claim was taken before the failure: nothing runs again.
        $this->assertNull(app(QuestionResumer::class)->resume($question->id, ResumeCaller::Background));
        $this->assertNotNull($question->fresh()->resumed_at);
        $this->assertCount(1, $this->pausedResults($question));
    }

    #[Test]
    public function the_job_swallows_a_continuation_failure_after_unwedging_the_conversation(): void
    {
        $question = $this->answered($this->pendingQuestion());
        $this->bindFailingContinuation(new \RuntimeException('model server unreachable'));

        $this->runJob(new ResumeAfterQuestionJob($question->id));

        $this->assertFalse((bool) $this->conversation->fresh()->is_processing);
        $this->assertSame('failed', $this->runRow($question)->end_state);

        // A retry of the same job is a no-op: the mocked continuation
        // allows exactly one call.
        $this->runJob(new ResumeAfterQuestionJob($question->id));
    }

    // ---------------------------------------------------------------
    // Context gone
    // ---------------------------------------------------------------

    #[Test]
    public function a_deleted_paused_message_ends_the_run_without_continuing(): void
    {
        $question = $this->pendingQuestion();

        $this->actingAs($this->user, 'api')
            ->deleteJson("/api/clarion-app/llm-client/message/{$question->paused_message_id}")
            ->assertStatus(204);
        $this->assertNull(Message::find($question->paused_message_id), 'precondition: the paused message is gone');

        $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/message', [
            'conversation_id' => $this->conversation->id,
            'content' => '2',
        ])->assertStatus(201);

        $question->refresh();
        $this->assertSame(QuestionState::Answered, $question->state);
        $this->assertNotNull($question->resumed_at, 'the claim commits, so the row is never re-driven');

        $run = $this->runRow($question);
        $this->assertSame('stopped_early', $run->end_state);
        $this->assertSame('question context deleted', $run->end_reason);
        $step = $this->stepRow($question);
        $this->assertSame('stopped_early', $step->end_state);
        $this->assertSame('question context deleted', $step->end_reason);

        Queue::assertNotPushed(AgentStreamStepJob::class);
        $this->assertSame([], $this->web->deliveries, 'nothing is delivered');
        $this->assertFalse((bool) $this->conversation->fresh()->is_processing, 'the conversation stays usable');

        $this->assertNull(app(QuestionResumer::class)->resume($question->id, ResumeCaller::Background));
        Queue::assertNotPushed(AgentStreamStepJob::class);
    }

    #[Test]
    public function a_deleted_conversation_ends_the_run_without_continuing(): void
    {
        $question = $this->answered($this->pendingQuestion());

        // Removed by a path other than the cancellation endpoints.
        $this->conversation->delete();
        $this->assertNull(Conversation::find($this->conversation->id), 'precondition: the conversation is gone');

        $this->assertNull(app(QuestionResumer::class)->resume($question->id, ResumeCaller::Background));

        $question->refresh();
        $this->assertNotNull($question->resumed_at, 'the claim commits, so the row is never re-driven');
        $run = $this->runRow($question);
        $this->assertSame('stopped_early', $run->end_state);
        $this->assertSame('question context deleted', $run->end_reason);
        $this->assertSame('stopped_early', $this->stepRow($question)->end_state);

        Queue::assertNotPushed(AgentStreamStepJob::class);
        $this->assertSame([], $this->web->deliveries, 'nothing is delivered to a conversation that no longer exists');

        $this->runJob(new ResumeAfterQuestionJob($question->id));
        Queue::assertNotPushed(AgentStreamStepJob::class);
        $this->assertSame([], $this->web->deliveries);
    }
}
