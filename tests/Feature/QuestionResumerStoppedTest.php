<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Jobs\AgentStreamStepJob;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Jobs\ResumeAfterQuestionJob;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\AgentQuestionService;
use ClarionApp\LlmClient\Services\AnswerParser;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\Services\QuestionResumer;
use ClarionApp\LlmClient\Services\RoleAssignmentService;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\ActionType;
use ClarionApp\LlmClient\ValueObjects\AskUserRequest;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use ClarionApp\LlmClient\ValueObjects\OpenedQuestion;
use ClarionApp\LlmClient\ValueObjects\OpenQuestionRequest;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use ClarionApp\LlmClient\ValueObjects\ResumeCaller;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Dedoc\Scramble\Generator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Log\Events\MessageLogged;
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
 * A stopped question — a stop-policy question whose answerable period
 * passed — is still waiting: for a late answer from its user, or for the
 * conversation to be ended. Nothing may continue its run in the meantime.
 *
 * The one-time resume claim is therefore never taken from `stopped`, by any
 * caller (the HTTP path, the queued job, a stray job left over from before
 * a restart), and the expiry sweep's re-drive never queues one — even when
 * the row looks resolved long ago, so it is the state, not the timing, that
 * holds the line.
 */
class QuestionResumerStoppedTest extends TestCase
{
    /** The stored result of a call the paused turn made before asking. */
    private const EARLIER_RESULT = '{"files":["composer.json","src"]}';

    private const LATE_ANSWER_MESSAGE = 'The question expired without an answer after %d minutes and work stopped. '
        .'The user answered later; continue with this answer. Nothing was done while the work was stopped.';

    private const DISCARD_NOTICE = 'The stopped task waiting on "Which test framework should I use?" was discarded '
        .'because the conversation was ended or deleted. It no longer needs a reply.';

    private const WITHDRAWN_RESULT_MESSAGE = 'The user stopped this task while the question was pending.';

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
     * A three-choice question opened the way the streamed pause site opens
     * it (so the paused turn is really written), then forced into
     * `stopped` exactly as the stop transition leaves it: slot kept,
     * unresolved, `stopped_at` set.
     */
    private function stoppedQuestion(
        ?Conversation $conversation = null,
        bool $stop = true,
        bool $withEarlierCall = false,
        ?int $waitMinutes = null,
    ): AgentQuestion {
        $conversation ??= $this->conversation;
        $conversation->update(['is_processing' => true]);

        $recorder = app(RunTraceRecorder::class);
        $runId = $recorder->openRun(RunKind::Interactive, (string) $this->user->id, $conversation->id, streamed: true);
        $stepId = $recorder->openStep($runId);
        $actionId = $recorder->openAction($stepId, ActionType::ToolInvocation, 'ask_user');

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'user' => 'User',
            'content' => 'Set up the test suite.',
            'responseTime' => 0,
        ]);
        $paused = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => '',
            'responseTime' => 0,
            'tool_data' => ['tool_calls' => array_merge($withEarlierCall ? [[
                'id' => 'call_list',
                'type' => 'function',
                'function' => ['name' => 'list_files', 'arguments' => json_encode(['path' => '.'])],
            ]] : [], [[
                'id' => 'call_ask',
                'type' => 'function',
                'function' => ['name' => 'ask_user', 'arguments' => json_encode([
                    'question' => 'Which test framework should I use?',
                    'choices' => ['PHPUnit', 'Pest', 'Codeception'],
                ])],
            ]])],
        ]);

        $opened = app(AgentQuestionService::class)->open(new OpenQuestionRequest(
            conversation: $conversation->fresh(),
            request: new AskUserRequest('Which test framework should I use?', ['PHPUnit', 'Pest', 'Codeception'], false, requestedWaitMinutes: $waitMinutes),
            runId: $runId,
            stepId: $stepId,
            actionId: $actionId,
            pausedMessage: $paused,
            toolCallId: 'call_ask',
            resumeMode: OpenQuestionRequest::RESUME_STREAM,
            preamble: null,
            pendingToolResults: $withEarlierCall ? [['tool_call_id' => 'call_list', 'content' => self::EARLIER_RESULT]] : [],
            iteration: 1,
        ));

        $this->assertInstanceOf(OpenedQuestion::class, $opened, 'precondition: the question opened');
        $this->web->deliveries = [];
        $id = $opened->question->id;

        if ($stop) {
            DB::table('agent_questions')->where('id', $id)->update([
                'state' => 'stopped',
                'stopped_at' => now(),
            ]);
        }

        $question = AgentQuestion::findOrFail($id);
        if ($stop) {
            $this->assertSame(QuestionState::Stopped, $question->state, 'precondition: stopped');
            $this->assertSame($conversation->id, $question->pending_conversation_id, 'precondition: the slot is kept');
            $this->assertNull($question->resolved_at, 'precondition: unresolved');
        }

        return $question;
    }

    /** Run a queued job the way a worker does: through the container. */
    private function runJob(ResumeAfterQuestionJob $job): void
    {
        $this->app->call([$job, 'handle']);
    }

    /** Everything that shows the question's run is still waiting, untouched. */
    private function assertStillWaiting(AgentQuestion $question): void
    {
        $question->refresh();
        $this->assertSame(QuestionState::Stopped, $question->state);
        $this->assertNull($question->resumed_at, 'the resume claim was not taken');
        $this->assertSame($question->conversation_id, $question->pending_conversation_id, 'the slot is still held');

        $paused = Message::findOrFail($question->paused_message_id)->tool_data;
        $this->assertArrayHasKey('tool_results', $paused);
        $this->assertNull($paused['tool_results'], 'the paused turn is not consumed');
        $this->assertSame($question->id, $paused['pending_question']['question_id'] ?? null, 'the paused turn still names its question');

        $this->assertSame(
            'awaiting_confirmation',
            DB::table('agent_run_actions')->where('id', $question->action_id)->value('outcome'),
            'the ask_user action is still waiting',
        );
        $step = DB::table('agent_run_steps')->where('id', $question->step_id)->first();
        $this->assertNotNull($step);
        $this->assertSame('in_progress', $step->end_state, 'no run step was closed');
        $this->assertNull($step->ended_at);
        $this->assertSame('in_progress', DB::table('agent_runs')->where('id', $question->run_id)->value('end_state'));

        $this->assertFalse((bool) Conversation::findOrFail($question->conversation_id)->is_processing, 'nothing is processing');

        Queue::assertNotPushed(AgentStreamStepJob::class);
        $this->assertSame([], $this->web->deliveries, 'nothing reached the user');
    }

    /** @return array<string, array{0: ResumeCaller}> */
    public static function callers(): array
    {
        return [
            'the HTTP path' => [ResumeCaller::HttpRequest],
            'a background caller' => [ResumeCaller::Background],
        ];
    }

    // ---------------------------------------------------------------
    // The claim
    // ---------------------------------------------------------------

    #[Test]
    #[DataProvider('callers')]
    public function resuming_a_stopped_question_does_nothing(ResumeCaller $caller): void
    {
        $question = $this->stoppedQuestion();

        $this->assertNull(app(QuestionResumer::class)->resume($question->id, $caller));

        $this->assertStillWaiting($question);
    }

    #[Test]
    public function resuming_after_an_answer_does_nothing_for_a_stopped_question(): void
    {
        $question = $this->stoppedQuestion();

        $this->assertNull(app(QuestionResumer::class)->resumeAfterAnswer($question));

        $this->assertStillWaiting($question);
    }

    #[Test]
    public function a_stray_resume_job_does_nothing_for_a_stopped_question(): void
    {
        $question = $this->stoppedQuestion();

        // The job swallows a failed resume, and a failed resume rolls its
        // claim back — so a claim taken from `stopped` would leave the row
        // looking untouched. What it cannot hide is the failure it logs.
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            $logged[] = $event->level.': '.$event->message;
        });

        $this->runJob(new ResumeAfterQuestionJob($question->id));
        $this->runJob(new ResumeAfterQuestionJob($question->id));

        $this->assertStillWaiting($question);
        $this->assertSame([], array_values(array_filter(
            $logged,
            fn (string $line) => str_starts_with($line, 'error:') || str_starts_with($line, 'warning:'),
        )), 'the job found nothing to resume; it did not try and fail');
    }

    #[Test]
    public function a_stopped_question_that_looks_long_resolved_still_cannot_be_claimed(): void
    {
        $question = $this->stoppedQuestion();
        DB::table('agent_questions')->where('id', $question->id)->update(['resolved_at' => now()->subDays(2)]);

        $this->assertNull(app(QuestionResumer::class)->resume($question->id, ResumeCaller::Background));
        $this->runJob(new ResumeAfterQuestionJob($question->id));

        $question->refresh();
        $this->assertSame(QuestionState::Stopped, $question->state);
        $this->assertNull($question->resumed_at, 'the state, not resolved_at, refuses the claim');
        $paused = Message::findOrFail($question->paused_message_id)->tool_data;
        $this->assertNull($paused['tool_results'], 'the paused turn is not consumed');
        Queue::assertNotPushed(AgentStreamStepJob::class);
    }

    // ---------------------------------------------------------------
    // The sweep's re-drive
    // ---------------------------------------------------------------

    #[Test]
    public function the_re_drive_never_queues_a_stopped_question(): void
    {
        // Past its deadline and, artificially, "resolved" well before the
        // grace window: only its state keeps it out of the re-drive.
        $question = $this->stoppedQuestion();
        DB::table('agent_questions')->where('id', $question->id)->update([
            'expires_at' => now()->subHours(3),
            'resolved_at' => now()->subHours(2),
        ]);

        // A genuinely resolved, never-resumed question in another
        // conversation proves the re-drive ran in the same sweep.
        $other = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'title' => 'Another conversation',
            'channel' => 'web',
            'is_processing' => false,
        ]);
        $answered = $this->stoppedQuestion($other, stop: false);
        $this->assertNotNull(app(AgentQuestionService::class)->answer(
            $answered,
            (new AnswerParser())->parse('1', $answered),
            (string) $this->user->id,
            '1',
        ));
        DB::table('agent_questions')->where('id', $answered->id)->update(['resolved_at' => now()->subHours(2)]);

        $this->assertSame(0, Artisan::call('llm-client:resolve-expired-questions'), 'the sweep succeeds');

        $queued = Queue::pushed(ResumeAfterQuestionJob::class)
            ->map(fn (ResumeAfterQuestionJob $job) => $job->questionId)
            ->values()
            ->all();
        $this->assertSame([$answered->id], $queued, 'only the resolved question is re-driven');

        $question->refresh();
        $this->assertSame(QuestionState::Stopped, $question->state, 'the expiry half of the sweep leaves it alone too');
        $this->assertNull($question->resumed_at);
    }

    #[Test]
    public function the_re_drive_dry_run_counts_no_stopped_question(): void
    {
        $question = $this->stoppedQuestion();
        DB::table('agent_questions')->where('id', $question->id)->update([
            'expires_at' => now()->subHours(3),
            'resolved_at' => now()->subHours(2),
        ]);

        $this->assertSame(0, Artisan::call('llm-client:resolve-expired-questions', ['--dry-run' => true]));

        Queue::assertNotPushed(ResumeAfterQuestionJob::class);
        $output = Artisan::output();
        $this->assertStringContainsString('Questions that would be expired: 0', $output);
        $this->assertStringContainsString('Resolved questions that would be re-driven: 0', $output, 'nothing is counted for a stopped question');
    }

    // ---------------------------------------------------------------
    // A late answer
    // ---------------------------------------------------------------

    /** @return list<array<string, mixed>>|null the paused turn's answered calls */
    private function toolResults(AgentQuestion $question): ?array
    {
        return Message::findOrFail($question->paused_message_id)->tool_data['tool_results'] ?? null;
    }

    private static function utc(\DateTimeInterface $at): string
    {
        return \Illuminate\Support\Carbon::instance($at)->utc()->format('Y-m-d\TH:i:s\Z');
    }

    #[Test]
    public function a_late_answer_resumes_the_paused_turn_once_with_an_answered_late_result(): void
    {
        $question = $this->stoppedQuestion(withEarlierCall: true);
        $this->assertSame(
            [['tool_call_id' => 'call_list', 'content' => self::EARLIER_RESULT]],
            Message::findOrFail($question->paused_message_id)->tool_data['pending_tool_results'],
            'precondition: the earlier call\'s result is stored with the paused turn',
        );
        $this->assertIsInt($question->effective_wait_minutes, 'precondition: the question records its waiting period');

        $this->travel(3)->hours();
        $this->assertNotNull(app(AgentQuestionService::class)->answer(
            $question,
            (new AnswerParser())->parse('2', $question),
            (string) $this->user->id,
            '2',
        ), 'precondition: the late answer is accepted');
        $question->refresh();
        $this->assertSame(QuestionState::Answered, $question->state, 'precondition: answered');
        $this->assertNotNull($question->stopped_at, 'precondition: the answered question remembers it had stopped');
        $this->assertNotNull($question->resolved_at);

        $resumer = app(QuestionResumer::class);
        $this->assertNull($resumer->resume($question->id, ResumeCaller::HttpRequest), 'a streamed continuation returns nothing');

        Queue::assertPushed(AgentStreamStepJob::class, 1);
        $this->assertNotNull($question->fresh()->resumed_at, 'the resume claim is taken');

        $expected = json_encode([
            'outcome' => 'answered_late',
            'question_id' => $question->id,
            'waiting_period_minutes' => $question->effective_wait_minutes,
            'stopped_at' => self::utc($question->stopped_at),
            'answered_at' => self::utc($question->resolved_at),
            'answer' => [
                'raw_reply' => '2',
                'is_selection' => true,
                'selected' => [['number' => 2, 'text' => 'Pest']],
            ],
            'message' => sprintf(self::LATE_ANSWER_MESSAGE, $question->effective_wait_minutes),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $results = $this->toolResults($question);
        $this->assertSame([
            // Replayed from storage: the earlier call is not run again.
            ['tool_call_id' => 'call_list', 'content' => self::EARLIER_RESULT],
            ['tool_call_id' => 'call_ask', 'content' => $expected],
        ], $results, 'the paused turn is answered with the late answer, beside the earlier call\'s stored result');

        $paused = Message::findOrFail($question->paused_message_id)->tool_data;
        $this->assertNull($paused['pending_tool_results'], 'the stored results were consumed');
        $this->assertSame(
            ['ask_user'],
            DB::table('agent_run_actions')->where('step_id', $question->step_id)->where('action_type', 'tool_invocation')->pluck('target')->all(),
            'no tool call of the paused turn was executed again',
        );

        $action = DB::table('agent_run_actions')->where('id', $question->action_id)->first();
        $this->assertSame('success', $action->outcome, 'a late answer is an answer');
        $this->assertNull($action->failure_reason);
        $this->assertSame('answered', Message::findOrFail($question->display_message_id)->tool_data['state'] ?? null);

        // Exactly once.
        $this->travel(5)->seconds();
        $this->assertNull($resumer->resume($question->id, ResumeCaller::Background), 'a second resume finds nothing to do');
        $this->runJob(new ResumeAfterQuestionJob($question->id));

        Queue::assertPushed(AgentStreamStepJob::class, 1);
        $this->assertSame($results, $this->toolResults($question), 'the paused turn is answered exactly once');
    }

    #[Test]
    public function the_step_wait_covers_the_whole_wait_including_the_stopped_period(): void
    {
        $question = $this->stoppedQuestion(stop: false, waitMinutes: 60);

        // The question stops the way the expiry sweep stops it.
        $this->travel(61)->minutes();
        $this->assertSame(0, Artisan::call('llm-client:resolve-expired-questions'), 'the sweep succeeds');
        $question->refresh();
        $this->assertSame(QuestionState::Stopped, $question->state, 'precondition: the sweep stopped the question');
        Queue::assertNotPushed(ResumeAfterQuestionJob::class);

        $this->travel(2)->days();
        $this->assertNotNull(app(AgentQuestionService::class)->answer(
            $question,
            (new AnswerParser())->parse('1', $question),
            (string) $this->user->id,
            '1',
        ), 'precondition: the late answer is accepted');

        app(QuestionResumer::class)->resume($question->id, ResumeCaller::HttpRequest);
        Queue::assertPushed(AgentStreamStepJob::class, 1);

        $step = DB::table('agent_run_steps')->where('id', $question->step_id)->first();
        $this->assertNotNull($step->ended_at, 'the step that spanned the wait is closed');
        $whole = (61 * 60 + 2 * 86400) * 1000;
        $this->assertGreaterThanOrEqual($whole, (int) $step->wait_ms, 'the wait runs from the pause to the late answer, stopped period included');
        $this->assertLessThan($whole + 60000, (int) $step->wait_ms);
    }

    // ---------------------------------------------------------------
    // A discard
    // ---------------------------------------------------------------

    #[Test]
    public function discarding_a_stopped_question_ends_its_run_as_a_discard(): void
    {
        $question = $this->stoppedQuestion();

        $this->travel(3)->days();
        $this->assertTrue(app(AgentQuestionService::class)->withdrawForConversation($this->conversation), 'precondition: the discard wins');

        $question->refresh();
        $this->assertSame(QuestionState::Withdrawn, $question->state, 'precondition: withdrawn');
        $this->assertNotNull($question->stopped_at, 'precondition: it remembers it had stopped');

        $run = DB::table('agent_runs')->where('id', $question->run_id)->first();
        $this->assertSame('stopped_early', $run->end_state);
        $this->assertSame('stopped question discarded', $run->end_reason);

        $step = DB::table('agent_run_steps')->where('id', $question->step_id)->first();
        $this->assertSame('stopped_early', $step->end_state);
        $this->assertSame('stopped question discarded', $step->end_reason);
        $this->assertGreaterThanOrEqual(3 * 86400 * 1000, (int) $step->wait_ms, 'the wait covers the stopped period');

        $this->assertSame([[
            'tool_call_id' => 'call_ask',
            'content' => json_encode([
                'outcome' => 'withdrawn',
                'question_id' => $question->id,
                'message' => self::WITHDRAWN_RESULT_MESSAGE,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]], $this->toolResults($question), 'the paused call is answered with the withdrawn outcome');

        Queue::assertNotPushed(AgentStreamStepJob::class);
        $this->assertFalse((bool) Conversation::findOrFail($question->conversation_id)->is_processing, 'nothing continues');
    }

    #[Test]
    public function withdrawing_a_merely_pending_question_keeps_the_withdrawn_reason(): void
    {
        $question = $this->stoppedQuestion(stop: false);

        $this->assertTrue(app(AgentQuestionService::class)->withdrawForConversation($this->conversation), 'precondition: the withdrawal wins');

        $question->refresh();
        $this->assertSame(QuestionState::Withdrawn, $question->state);
        $this->assertNull($question->stopped_at, 'precondition: it never stopped');

        $this->assertSame('question withdrawn', DB::table('agent_runs')->where('id', $question->run_id)->value('end_reason'));
        $this->assertSame('question withdrawn', DB::table('agent_run_steps')->where('id', $question->step_id)->value('end_reason'));
    }

    #[Test]
    public function discarding_a_stopped_question_tells_the_user_once(): void
    {
        $question = $this->stoppedQuestion();
        $service = app(AgentQuestionService::class);

        $this->assertTrue($service->withdrawForConversation($this->conversation), 'precondition: the discard wins');
        $this->assertFalse($service->withdrawForConversation($this->conversation->fresh()), 'a second discard finds nothing waiting');

        $notices = Message::all()
            ->filter(fn (Message $m) => is_array($m->tool_data)
                && ($m->tool_data['kind'] ?? null) === AgentQuestionService::KIND_NOTICE
                && ($m->tool_data['question_id'] ?? null) === $question->id)
            ->values();
        $this->assertCount(1, $notices, 'the discard notice is written once');
        $this->assertSame('assistant', $notices[0]->role);
        $this->assertSame(self::DISCARD_NOTICE, $notices[0]->content);
        $this->assertEquals(
            ['kind' => 'agent_question_notice', 'question_id' => $question->id, 'state' => 'withdrawn', 'delivered' => true],
            $notices[0]->tool_data,
        );
        $this->assertSame(
            [['conversation_id' => $question->conversation_id, 'text' => self::DISCARD_NOTICE]],
            $this->web->deliveries,
            'and delivered once on the conversation\'s channel',
        );
    }
}
