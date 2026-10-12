<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
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
    private function stoppedQuestion(?Conversation $conversation = null, bool $stop = true): AgentQuestion
    {
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
            'tool_data' => ['tool_calls' => [[
                'id' => 'call_ask',
                'type' => 'function',
                'function' => ['name' => 'ask_user', 'arguments' => json_encode([
                    'question' => 'Which test framework should I use?',
                    'choices' => ['PHPUnit', 'Pest', 'Codeception'],
                ])],
            ]]],
        ]);

        $opened = app(AgentQuestionService::class)->open(new OpenQuestionRequest(
            conversation: $conversation->fresh(),
            request: new AskUserRequest('Which test framework should I use?', ['PHPUnit', 'Pest', 'Codeception'], false),
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

        Queue::assertNotPushed(SendHttpStreamRequest::class);
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
        Queue::assertNotPushed(SendHttpStreamRequest::class);
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
}
