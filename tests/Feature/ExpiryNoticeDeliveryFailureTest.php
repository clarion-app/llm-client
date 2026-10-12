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
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\Services\LateReplyNotifier;
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
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
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
 * A notice about a question — that it proceeded on its assumption, that work
 * stopped, that a stopped task was discarded, that it expired the old way,
 * that it could not be delivered, or that a reply came too late — is part of
 * the conversation whether or not the channel could carry it.
 *
 * A channel that reports failure, or that throws, never blocks, delays or
 * reverses the resolution the notice describes: the state, the question
 * slot and the resume are exactly what they would have been. The outcome of
 * the delivery attempt is recorded on the notice itself (`delivered`, and
 * when it failed a bounded `delivery_failure_reason` that never carries a
 * bot token), and a failure is logged.
 */
class ExpiryNoticeDeliveryFailureTest extends TestCase
{
    private const QUESTION = 'Which test framework should I use?';

    private const CHOICES = ['PHPUnit', 'Pest', 'Codeception'];

    /** A bot token in the shape the messaging platform issues them. */
    private const BOT_TOKEN = '7012345678:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw';

    private User $user;

    private Server $server;

    private RecordingChannelDelivery $web;

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->server = Server::create([
            'name' => 'Notice LLM Server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'sk-test',
        ]);
        app(RoleAssignmentService::class)->set(ModelRole::Inference, $this->user->id, $this->server->id, 'test-model');

        config(['llm-client.run_trace.enabled' => true]);
        config(['llm-client.agent_questions.resume_grace_seconds' => 60]);

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

        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            $this->logged[] = ['level' => $event->level, 'message' => $event->message, 'context' => $event->context];
        });

        $this->travelTo(Carbon::parse('2026-10-12 09:00:00', 'UTC'));
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

            Mockery::close();
        } finally {
            parent::tearDown();
        }
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    /**
     * A three-choice question with a one-hour period, opened the way the
     * streamed pause site opens it, in a conversation of its own.
     * $policy null marks it as asked before unanswered policies existed.
     */
    private function openQuestion(?UnansweredPolicy $policy): AgentQuestion
    {
        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'title' => 'Notice conversation',
            'channel' => 'web',
            'is_processing' => true,
        ]);

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
                    'question' => self::QUESTION,
                    'choices' => self::CHOICES,
                    'wait_minutes' => 60,
                ])],
            ]]],
        ]);

        $request = $policy === UnansweredPolicy::ProceedOnAssumption
            ? new AskUserRequest(
                self::QUESTION,
                self::CHOICES,
                false,
                policy: UnansweredPolicy::ProceedOnAssumption,
                assumedAnswer: new ParsedAnswer('1', true, [['number' => 1, 'text' => 'PHPUnit']]),
                requestedWaitMinutes: 60,
            )
            : new AskUserRequest(self::QUESTION, self::CHOICES, false, requestedWaitMinutes: 60);

        $opened = app(AgentQuestionService::class)->open(new OpenQuestionRequest(
            conversation: $conversation->fresh(),
            request: $request,
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
        $this->assertCount(1, $this->web->textsFor($conversation->id), 'precondition: the question itself was delivered');
        $this->web->deliveries = [];

        if ($policy === null) {
            // A question asked before unanswered policies existed: it expires as it always did.
            DB::table('agent_questions')->where('id', $opened->question->id)->update(['unanswered_policy' => null, 'effective_wait_minutes' => null]);
        }

        $question = AgentQuestion::findOrFail($opened->question->id);
        $this->assertSame($policy?->value, $question->unanswered_policy?->value, 'precondition: the policy it was asked with');

        return $question;
    }

    private function sweep(): void
    {
        $this->assertSame(0, Artisan::call('llm-client:resolve-expired-questions'), 'the sweep succeeds');
    }

    /** @return list<Message> every notice about $question, oldest first */
    private function noticesFor(AgentQuestion $question): array
    {
        return Message::orderBy('created_at')->orderBy('id')->get()
            ->filter(fn (Message $m) => is_array($m->tool_data)
                && ($m->tool_data['kind'] ?? null) === AgentQuestionService::KIND_NOTICE
                && ($m->tool_data['question_id'] ?? null) === $question->id)
            ->values()
            ->all();
    }

    /** @return list<string> question ids of every queued resume */
    private function queuedResumes(): array
    {
        return Queue::pushed(ResumeAfterQuestionJob::class)
            ->map(fn (ResumeAfterQuestionJob $job) => $job->questionId)
            ->values()
            ->all();
    }

    /** Work every queued resume the way a worker does. */
    private function workResumes(): void
    {
        foreach (Queue::pushed(ResumeAfterQuestionJob::class) as $job) {
            $this->app->call([$job, 'handle']);
        }
    }

    /** @return list<string> warning-or-worse log lines */
    private function problemsLogged(): array
    {
        return array_values(array_map(
            fn (array $line) => $line['message'],
            array_filter($this->logged, fn (array $line) => in_array($line['level'], ['warning', 'error', 'critical', 'alert', 'emergency'], true)),
        ));
    }

    /** Everything logged, as one string, so a secret anywhere in it is found. */
    private function everythingLogged(): string
    {
        return (string) json_encode($this->logged, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /**
     * Make the channel fail the way $how names: report failure, or throw.
     * Both carry the reason $reason.
     */
    private function breakChannel(string $how, string $reason): void
    {
        $how === 'throws' ? $this->web->throwWith($reason) : $this->web->failWith($reason);
    }

    /** The reason as it should be stored for a channel that failed as $how. */
    private static function storedReason(string $how, string $reason): string
    {
        return $how === 'throws' ? 'RuntimeException: '.$reason : $reason;
    }

    /** The notice records a failed delivery, with its bounded reason. */
    private function assertFailedDeliveryRecorded(Message $notice, string $expectedReason): void
    {
        $toolData = $notice->fresh()->tool_data;
        $this->assertArrayHasKey('delivered', $toolData, 'the delivery attempt is recorded on the notice');
        $this->assertFalse($toolData['delivered'], 'the notice records that it was not delivered');
        $this->assertArrayHasKey('delivery_failure_reason', $toolData);
        $this->assertSame($expectedReason, $toolData['delivery_failure_reason'], 'the reason is recorded');
        $this->assertLessThanOrEqual(512, mb_strlen($toolData['delivery_failure_reason']));
    }

    /** @return array<string, array{0: string}> */
    public static function failures(): array
    {
        return [
            'a channel that reports failure' => ['fails'],
            'a channel that throws' => ['throws'],
        ];
    }

    // ---------------------------------------------------------------
    // Each notice, on a broken channel
    // ---------------------------------------------------------------

    #[Test]
    #[DataProvider('failures')]
    public function proceeding_on_the_assumption_is_unaffected_by_an_undelivered_notice(string $how): void
    {
        $question = $this->openQuestion(UnansweredPolicy::ProceedOnAssumption);
        $this->breakChannel($how, 'chat unreachable');

        $this->travel(61)->minutes();
        $this->sweep();

        $question->refresh();
        $this->assertSame(QuestionState::ExpiredAssumed, $question->state, 'the question proceeds on its assumption');
        $this->assertNotNull($question->resolved_at);
        $this->assertNull($question->pending_conversation_id, 'the slot is released exactly as on a working channel');
        $this->assertTrue((bool) Conversation::findOrFail($question->conversation_id)->is_processing);
        $this->assertSame([$question->id], $this->queuedResumes(), 'the resume is queued regardless');

        $this->assertCount(1, $this->web->deliveries, 'the notice was attempted once');
        $notices = $this->noticesFor($question);
        $this->assertCount(1, $notices, 'the notice is in the conversation history');
        $this->assertSame('expired_assumed', $notices[0]->tool_data['state']);
        $this->assertFailedDeliveryRecorded($notices[0], self::storedReason($how, 'chat unreachable'));
        $this->assertContains('AgentQuestionService: question notice could not be delivered', $this->problemsLogged(), 'the failure is logged');

        $this->workResumes();
        $this->assertNotNull($question->fresh()->resumed_at, 'the run resumes on the assumption');
        Queue::assertPushed(SendHttpStreamRequest::class, 1);
    }

    #[Test]
    #[DataProvider('failures')]
    public function stopping_is_unaffected_by_an_undelivered_notice(string $how): void
    {
        $question = $this->openQuestion(UnansweredPolicy::Stop);
        $this->breakChannel($how, 'chat unreachable');

        $this->travel(61)->minutes();
        $this->sweep();

        $question->refresh();
        $this->assertSame(QuestionState::Stopped, $question->state, 'the work stops');
        $this->assertNotNull($question->stopped_at);
        $this->assertNull($question->resolved_at);
        $this->assertSame($question->conversation_id, $question->pending_conversation_id, 'the slot is kept for a late answer');
        $this->assertSame([], $this->queuedResumes(), 'nothing is queued for a stop, failure or not');

        $notices = $this->noticesFor($question);
        $this->assertCount(1, $notices, 'the notice is in the conversation history');
        $this->assertSame('stopped', $notices[0]->tool_data['state']);
        $this->assertFailedDeliveryRecorded($notices[0], self::storedReason($how, 'chat unreachable'));
        $this->assertContains('AgentQuestionService: question notice could not be delivered', $this->problemsLogged());
    }

    #[Test]
    #[DataProvider('failures')]
    public function discarding_a_stopped_task_is_unaffected_by_an_undelivered_notice(string $how): void
    {
        $question = $this->openQuestion(UnansweredPolicy::Stop);
        $this->travel(61)->minutes();
        $this->sweep();
        $this->assertSame(QuestionState::Stopped, $question->fresh()->state, 'precondition: stopped');

        $this->breakChannel($how, 'chat unreachable');
        $this->assertTrue(app(AgentQuestionService::class)->withdrawForConversation(Conversation::findOrFail($question->conversation_id)), 'the discard wins');

        $question->refresh();
        $this->assertSame(QuestionState::Withdrawn, $question->state);
        $this->assertNull($question->pending_conversation_id);
        $this->assertNotNull($question->resumed_at, 'the run is closed');
        $run = DB::table('agent_runs')->where('id', $question->run_id)->first();
        $this->assertSame('stopped_early', $run->end_state);
        $this->assertSame('stopped question discarded', $run->end_reason);

        $notices = $this->noticesFor($question);
        $this->assertCount(2, $notices, 'the stop notice, then the discard notice');
        $this->assertTrue($notices[0]->tool_data['delivered'] ?? null, 'the stop notice was delivered');
        $this->assertSame('withdrawn', $notices[1]->tool_data['state']);
        $this->assertFailedDeliveryRecorded($notices[1], self::storedReason($how, 'chat unreachable'));
        $this->assertContains('AgentQuestionService: question notice could not be delivered', $this->problemsLogged());
    }

    #[Test]
    #[DataProvider('failures')]
    public function a_question_asked_before_policies_expires_as_before_on_a_broken_channel(string $how): void
    {
        $question = $this->openQuestion(null);
        $this->breakChannel($how, 'chat unreachable');

        $this->travel(61)->minutes();
        $this->sweep();

        $question->refresh();
        $this->assertSame(QuestionState::Expired, $question->state);
        $this->assertNull($question->pending_conversation_id);
        $this->assertSame([$question->id], $this->queuedResumes());

        $notices = $this->noticesFor($question);
        $this->assertCount(1, $notices);
        $this->assertSame('expired', $notices[0]->tool_data['state']);
        $this->assertFailedDeliveryRecorded($notices[0], self::storedReason($how, 'chat unreachable'));

        $this->workResumes();
        $this->assertNotNull($question->fresh()->resumed_at, 'the run resumes without the answer');
        Queue::assertPushed(SendHttpStreamRequest::class, 1);
    }

    #[Test]
    #[DataProvider('failures')]
    public function the_late_reply_notice_stands_on_a_broken_channel(string $how): void
    {
        $question = $this->openQuestion(UnansweredPolicy::ProceedOnAssumption);
        $this->travel(61)->minutes();
        $this->sweep();
        $this->assertSame(QuestionState::ExpiredAssumed, $question->fresh()->state, 'precondition: proceeded');

        $this->breakChannel($how, 'chat unreachable');
        $notice = app(LateReplyNotifier::class)->noticeFor(Conversation::findOrFail($question->conversation_id), (string) $this->user->id);

        $this->assertNotNull($notice, 'the notice is posted');
        $this->assertNotNull($question->fresh()->late_reply_noticed_at, 'the one notice the question was owed is spent');
        $this->assertSame('late_reply', $notice->fresh()->tool_data['notice']);
        $this->assertFailedDeliveryRecorded($notice, self::storedReason($how, 'chat unreachable'));
        $this->assertContains('AgentQuestionService: late-reply notice could not be delivered', $this->problemsLogged());
    }

    #[Test]
    #[DataProvider('failures')]
    public function the_undeliverable_notice_records_its_own_delivery_attempt(string $how): void
    {
        $question = $this->openQuestion(UnansweredPolicy::Stop);
        $this->breakChannel($how, 'chat unreachable');

        $this->assertTrue(app(AgentQuestionService::class)->markUndeliverable($question, 'the question could not be shown'));

        $notices = $this->noticesFor($question);
        $this->assertCount(1, $notices);
        $this->assertSame('undeliverable', $notices[0]->tool_data['state']);
        $this->assertFailedDeliveryRecorded($notices[0], self::storedReason($how, 'chat unreachable'));
    }

    // ---------------------------------------------------------------
    // What is recorded
    // ---------------------------------------------------------------

    #[Test]
    public function a_delivered_notice_records_that_it_was_delivered(): void
    {
        $proceed = $this->openQuestion(UnansweredPolicy::ProceedOnAssumption);
        $stop = $this->openQuestion(UnansweredPolicy::Stop);
        $legacy = $this->openQuestion(null);

        $this->travel(61)->minutes();
        $this->sweep();
        app(AgentQuestionService::class)->withdrawForConversation(Conversation::findOrFail($stop->conversation_id));
        $late = app(LateReplyNotifier::class)->noticeFor(Conversation::findOrFail($proceed->conversation_id), (string) $this->user->id);
        $this->assertNotNull($late, 'precondition: the late-reply notice was posted');

        $notices = array_merge($this->noticesFor($proceed), $this->noticesFor($stop), $this->noticesFor($legacy));
        $this->assertCount(5, $notices, 'assumed, late reply, stop, discard and legacy notices');

        foreach ($notices as $notice) {
            $toolData = $notice->fresh()->tool_data;
            $this->assertTrue($toolData['delivered'] ?? null, "the {$toolData['state']} notice records its delivery");
            $this->assertArrayNotHasKey('delivery_failure_reason', $toolData, 'a delivered notice has no failure reason');
        }
        $this->assertSame([], $this->problemsLogged(), 'nothing failed');
    }

    #[Test]
    public function an_overlong_failure_reason_is_cut_to_its_bound(): void
    {
        $question = $this->openQuestion(UnansweredPolicy::Stop);
        $this->web->failWith(str_repeat('é', 3000));

        $this->travel(61)->minutes();
        $this->sweep();

        $this->assertSame(QuestionState::Stopped, $question->fresh()->state);
        $reason = $this->noticesFor($question)[0]->fresh()->tool_data['delivery_failure_reason'];
        $this->assertSame(512, mb_strlen($reason), 'cut to 512 characters');
        $this->assertSame(str_repeat('é', 511).'…', $reason, 'and marked as cut');
    }

    #[Test]
    #[DataProvider('failures')]
    public function a_bot_token_never_reaches_the_recorded_reason_or_the_log(string $how): void
    {
        $proceed = $this->openQuestion(UnansweredPolicy::ProceedOnAssumption);
        $stop = $this->openQuestion(UnansweredPolicy::Stop);
        $this->breakChannel(
            $how,
            'cURL error 28: Operation timed out for https://api.telegram.org/bot'.self::BOT_TOKEN.'/sendMessage (token '.self::BOT_TOKEN.')',
        );

        $this->travel(61)->minutes();
        $this->sweep();
        app(LateReplyNotifier::class)->noticeFor(Conversation::findOrFail($proceed->conversation_id), (string) $this->user->id);

        $notices = array_merge($this->noticesFor($proceed), $this->noticesFor($stop));
        $this->assertCount(3, $notices, 'precondition: assumed, late-reply and stop notices');
        foreach ($notices as $notice) {
            $reason = $notice->fresh()->tool_data['delivery_failure_reason'] ?? '';
            $this->assertStringContainsString('Operation timed out', $reason, 'the useful part of the reason is kept');
            $this->assertStringNotContainsString(self::BOT_TOKEN, $reason, 'no bot token in the recorded reason');
            $this->assertStringNotContainsString('AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw', $reason, 'not even the secret half');
        }

        $this->assertNotEmpty($this->problemsLogged(), 'precondition: the failures were logged');
        $this->assertStringNotContainsString('AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw', $this->everythingLogged(), 'no bot token in the log');
    }

    #[Test]
    public function a_throwing_channel_never_hands_its_bot_token_to_any_caller(): void
    {
        // Every delivery through the service (a notice, or a resumed run's
        // reply pushed to the chat) reports a thrown error without the token.
        $conversation = Conversation::factory()->create(['user_id' => $this->user->id, 'channel' => 'web']);
        $this->web->throwWith('Client error: POST https://api.telegram.org/bot'.self::BOT_TOKEN.'/sendMessage resulted in 401');

        $result = app(AgentQuestionService::class)->deliver($conversation, 'Hello.');

        $this->assertFalse($result->delivered);
        $this->assertSame('RuntimeException: Client error: POST https://api.telegram.org/bot[bot token]/sendMessage resulted in 401', $result->failureReason);
    }
}
