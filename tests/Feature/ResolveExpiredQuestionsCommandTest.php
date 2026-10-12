<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Jobs\ResumeAfterQuestionJob;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\AgentQuestionService;
use ClarionApp\LlmClient\Services\AnswerParser;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\ActionType;
use ClarionApp\LlmClient\ValueObjects\AskUserRequest;
use ClarionApp\LlmClient\ValueObjects\OpenedQuestion;
use ClarionApp\LlmClient\ValueObjects\OpenQuestionRequest;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use ClarionApp\LlmClient\ValueObjects\RunKind;
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
 * `llm-client:resolve-expired-questions` expires every pending question
 * whose answerable period has passed: the question becomes `expired`, the
 * user is told on the conversation's channel, and a resume of the paused
 * run is queued — once, by whichever caller won the expiry.
 *
 * The period is fixed when the question is asked; the sweep never re-reads
 * the configuration to decide whether, or after how long, a question
 * expired.
 */
class ResolveExpiredQuestionsCommandTest extends TestCase
{
    private const SWEEP = 'llm-client:resolve-expired-questions';

    private const QUESTION = 'Which part should I change first?';

    private const EXPIRED_NOTICE = 'The question "Which part should I change first?" expired without an answer. '
        .'The agent will continue without it; replies sent now are treated as new messages.';

    private User $user;

    private RecordingChannelDelivery $web;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        config(['llm-client.run_trace.enabled' => true]);
        config(['llm-client.agent_questions.expiry_minutes' => 10]);
        // The periods under test here are shorter than the shipped minimum.
        config(['llm-client.agent_questions.min_expiry_minutes' => 1]);
        config(['llm-client.agent_questions.resume_grace_seconds' => 60]);
        config(['llm-client.agent_questions.sweep_batch_size' => 100]);

        Queue::fake();
        Event::fake([NewConversationMessageEvent::class]);

        $this->web = new RecordingChannelDelivery('web');
        app(ConversationChannelRegistry::class)->register($this->web);
    }

    protected function tearDown(): void
    {
        try {
            foreach (['agent_questions', 'agent_run_actions', 'agent_run_steps', 'agent_runs'] as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->delete();
                }
            }
            DB::table('messages')->delete();
            DB::table('conversations')->delete();
            DB::table('users')->delete();

            Mockery::close();
        } finally {
            parent::tearDown();
        }
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    private function conversation(): Conversation
    {
        return Conversation::factory()->create([
            'user_id' => $this->user->id,
            'channel' => 'web',
            'is_processing' => false,
        ]);
    }

    /**
     * A pending three-choice question opened the way the streamed pause
     * site opens it. Deliveries made while opening it are cleared, so later
     * assertions see only what the sweep delivers.
     *
     * Every question here is marked as asked before unanswered policies
     * existed: this suite covers the original expiry, which such questions
     * keep.
     */
    private function openQuestion(?Conversation $conversation = null): AgentQuestion
    {
        $conversation ??= $this->conversation();
        $conversation->update(['is_processing' => true]);

        $recorder = app(RunTraceRecorder::class);
        $runId = $recorder->openRun(RunKind::Interactive, (string) $this->user->id, $conversation->id, streamed: true);
        $stepId = $recorder->openStep($runId);
        $actionId = $recorder->openAction($stepId, ActionType::ToolInvocation, 'ask_user');

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
                    'choices' => ['Backend', 'Frontend', 'Docs'],
                ])],
            ]]],
        ]);

        $opened = app(AgentQuestionService::class)->open(new OpenQuestionRequest(
            conversation: $conversation->fresh(),
            request: new AskUserRequest(self::QUESTION, ['Backend', 'Frontend', 'Docs'], false),
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

        // A question asked before unanswered policies existed: it expires as it always did.
        DB::table('agent_questions')->where('id', $opened->question->id)->update(['unanswered_policy' => null, 'effective_wait_minutes' => null]);

        return AgentQuestion::findOrFail($opened->question->id);
    }

    private function sweep(array $options = []): string
    {
        $this->assertSame(0, Artisan::call(self::SWEEP, $options), 'the sweep succeeds');

        return Artisan::output();
    }

    /** @return list<Message> */
    private function noticesFor(AgentQuestion $question): array
    {
        return Message::all()
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

    // ---------------------------------------------------------------
    // Expiry
    // ---------------------------------------------------------------

    #[Test]
    public function an_overdue_question_expires_tells_the_user_and_queues_one_resume(): void
    {
        $question = $this->openQuestion();

        $this->travel(11)->minutes();
        $output = $this->sweep();

        $question->refresh();
        $this->assertSame(QuestionState::Expired, $question->state);
        $this->assertNotNull($question->resolved_at);
        $this->assertNull($question->pending_conversation_id, 'the conversation\'s question slot is free again');
        $this->assertNull($question->resumed_at, 'the sweep queues the resume; it does not resume inline');
        $this->assertTrue((bool) Conversation::findOrFail($question->conversation_id)->is_processing, 'the expiry marks the conversation processing until the run resumes');

        $notices = $this->noticesFor($question);
        $this->assertCount(1, $notices, 'the user is told once');
        $this->assertSame('assistant', $notices[0]->role);
        $this->assertSame(self::EXPIRED_NOTICE, $notices[0]->content);
        $this->assertEquals(
            ['kind' => 'agent_question_notice', 'question_id' => $question->id, 'state' => 'expired'],
            $notices[0]->tool_data,
        );
        $this->assertSame(
            [['conversation_id' => $question->conversation_id, 'text' => self::EXPIRED_NOTICE]],
            $this->web->deliveries,
            'the notice is delivered on the conversation\'s channel',
        );

        $this->assertSame([$question->id], $this->queuedResumes(), 'exactly one resume is queued');
        $this->assertStringContainsString('Questions expired: 1', $output);

        // The next tick, still inside the re-drive grace window, finds
        // nothing to expire and nothing to re-drive.
        $this->travel(10)->seconds();
        $this->sweep();
        $this->assertCount(1, $this->noticesFor($question), 'a question expires once');
        $this->assertCount(1, $this->web->deliveries);
        $this->assertSame([$question->id], $this->queuedResumes());
    }

    #[Test]
    public function a_question_not_yet_due_is_left_alone(): void
    {
        $question = $this->openQuestion();

        $this->travel(9)->minutes();
        $output = $this->sweep();

        $question->refresh();
        $this->assertSame(QuestionState::Pending, $question->state);
        $this->assertNull($question->resolved_at);
        $this->assertSame($question->conversation_id, $question->pending_conversation_id);
        $this->assertSame([], $this->noticesFor($question));
        $this->assertSame([], $this->web->deliveries);
        $this->assertSame([], $this->queuedResumes());
        $this->assertStringContainsString('Questions expired: 0', $output);
    }

    #[Test]
    public function dry_run_reports_what_would_expire_without_writing_anything(): void
    {
        $first = $this->openQuestion();
        $second = $this->openQuestion();

        $this->travel(11)->minutes();
        $notDue = $this->openQuestion();

        $output = $this->sweep(['--dry-run' => true]);

        foreach ([$first, $second, $notDue] as $question) {
            $fresh = $question->fresh();
            $this->assertSame(QuestionState::Pending, $fresh->state, 'a dry run expires nothing');
            $this->assertSame($question->conversation_id, $fresh->pending_conversation_id);
            $this->assertSame([], $this->noticesFor($question), 'a dry run persists no notice');
        }
        $this->assertSame([], $this->web->deliveries, 'a dry run delivers nothing');
        $this->assertSame([], $this->queuedResumes(), 'a dry run queues nothing');
        $this->assertStringContainsString('Questions that would be expired: 2', $output);
    }

    #[Test]
    public function every_overdue_question_is_expired_when_they_span_several_chunks(): void
    {
        config(['llm-client.agent_questions.sweep_batch_size' => 2]);

        $questions = [];
        for ($i = 0; $i < 5; $i++) {
            $questions[] = $this->openQuestion();
        }

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = strtolower($query->sql);
        });

        $this->travel(11)->minutes();
        $output = $this->sweep();

        foreach ($questions as $question) {
            $this->assertSame(QuestionState::Expired, $question->fresh()->state, 'no overdue question is skipped between chunks');
            $this->assertCount(1, $this->noticesFor($question));
        }
        $this->assertCount(5, $this->web->deliveries);
        $this->assertEqualsCanonicalizing(array_map(fn ($q) => $q->id, $questions), $this->queuedResumes());
        $this->assertStringContainsString('Questions expired: 5', $output);

        $chunked = array_filter($queries, fn (string $sql) => str_contains($sql, 'agent_questions') && str_contains($sql, 'limit 2'));
        $this->assertNotEmpty($chunked, 'overdue questions are read sweep_batch_size rows at a time');
    }

    #[Test]
    public function an_expiry_that_loses_to_an_answer_is_a_no_op(): void
    {
        $question = $this->openQuestion();

        // The user's answer commits between the sweep reading the overdue
        // row and its compare-and-set.
        $answered = false;
        Event::listen('eloquent.retrieved: '.AgentQuestion::class, function (AgentQuestion $loaded) use ($question, &$answered) {
            if ($answered || $loaded->id !== $question->id || $loaded->state !== QuestionState::Pending) {
                return;
            }
            $answered = true;
            $message = app(AgentQuestionService::class)->answer(
                $loaded,
                (new AnswerParser())->parse('2', $loaded),
                (string) $this->user->id,
                '2',
            );
            $this->assertNotNull($message, 'precondition: the answer won');
        });

        $this->travel(11)->minutes();
        $output = $this->sweep();

        $this->assertTrue($answered, 'precondition: the sweep loaded the overdue question');
        $question->refresh();
        $this->assertSame(QuestionState::Answered, $question->state, 'the answer stands');
        $this->assertSame('2', $question->answer_raw);
        $this->assertSame([], $this->noticesFor($question), 'the losing expiry shows no notice');
        $this->assertSame([], $this->web->deliveries, 'the losing expiry delivers nothing');
        $this->assertSame([], $this->queuedResumes(), 'the losing expiry queues no resume; the answer\'s caller resumes');
        $this->assertStringContainsString('Questions expired: 0', $output);
    }

    #[Test]
    public function a_question_whose_conversation_was_deleted_expires_without_a_notice(): void
    {
        $question = $this->openQuestion();

        // Removed by a path other than the cancellation endpoints.
        Conversation::findOrFail($question->conversation_id)->delete();
        $this->assertNull(Conversation::find($question->conversation_id), 'precondition: the conversation is gone');

        $this->travel(11)->minutes();
        $this->sweep();

        $question->refresh();
        $this->assertSame(QuestionState::Expired, $question->state);
        $this->assertSame([], $this->noticesFor($question), 'no notice is written to a conversation that no longer exists');
        $this->assertSame([], $this->web->deliveries, 'nothing is delivered');
        $this->assertSame([$question->id], $this->queuedResumes(), 'the resume is still queued so the run is closed');

        // The queued resume ends the run without continuing it.
        $this->app->call([Queue::pushed(ResumeAfterQuestionJob::class)->first(), 'handle']);
        $run = DB::table('agent_runs')->where('id', $question->run_id)->first();
        $this->assertSame('stopped_early', $run->end_state);
        $this->assertSame('question context deleted', $run->end_reason);
        $this->assertSame([], $this->web->deliveries);
    }

    // ---------------------------------------------------------------
    // The answerable period
    // ---------------------------------------------------------------

    #[Test]
    public function the_default_period_is_1440_minutes_when_none_is_configured(): void
    {
        $settings = config('llm-client.agent_questions');
        unset($settings['expiry_minutes']);
        config(['llm-client.agent_questions' => $settings]);
        $this->assertNull(config('llm-client.agent_questions.expiry_minutes'), 'precondition: no period configured');

        $question = $this->openQuestion();

        $this->assertSame(1440 * 60, $question->expires_at->getTimestamp() - $question->asked_at->getTimestamp());

        if (getenv('LLM_CLIENT_QUESTION_EXPIRY_MINUTES') === false) {
            $shipped = require __DIR__.'/../../config/llm-client.php';
            $this->assertSame(1440, $shipped['agent_questions']['expiry_minutes'], 'the shipped default is 24 hours');
        }
        if (getenv('LLM_CLIENT_QUESTION_MIN_EXPIRY_MINUTES') === false) {
            $shipped = require __DIR__.'/../../config/llm-client.php';
            $this->assertSame(60, $shipped['agent_questions']['min_expiry_minutes'], 'the shipped minimum is one hour');
        }
        if (getenv('LLM_CLIENT_QUESTION_MAX_EXPIRY_MINUTES') === false) {
            $shipped = require __DIR__.'/../../config/llm-client.php';
            $this->assertSame(10080, $shipped['agent_questions']['max_expiry_minutes'], 'the shipped maximum is one week');
        }
    }

    public static function nonPositivePeriods(): array
    {
        return ['zero' => [0], 'negative' => [-5]];
    }

    #[Test]
    #[DataProvider('nonPositivePeriods')]
    public function a_non_positive_period_is_clamped_to_one_minute(int $minutes): void
    {
        config(['llm-client.agent_questions.expiry_minutes' => $minutes]);

        $question = $this->openQuestion();

        $this->assertSame(60, $question->expires_at->getTimestamp() - $question->asked_at->getTimestamp());
        $this->assertTrue($question->expires_at->isFuture(), 'a question is never born expired');
    }

    #[Test]
    public function the_period_is_fixed_when_the_question_is_asked(): void
    {
        $question = $this->openQuestion();
        $expiresAt = $question->expires_at;

        // Shortening the configured period later does not move it.
        config(['llm-client.agent_questions.expiry_minutes' => 1]);
        $this->travel(5)->minutes();
        $this->sweep();

        $this->assertSame(QuestionState::Pending, $question->fresh()->state, 'still inside the period it was asked with');
        $this->assertEquals($expiresAt, $question->fresh()->expires_at);
        $this->assertSame([], $this->queuedResumes());

        // Nor does lengthening it.
        config(['llm-client.agent_questions.expiry_minutes' => 600]);
        $this->travel(6)->minutes();
        $this->sweep();

        $this->assertSame(QuestionState::Expired, $question->fresh()->state, 'expired at the time fixed when it was asked');
        $this->assertSame([$question->id], $this->queuedResumes());
    }

    #[Test]
    public function the_agent_is_told_the_questions_own_period_not_the_current_configuration(): void
    {
        $question = $this->openQuestion();

        config(['llm-client.agent_questions.expiry_minutes' => 99]);
        $this->travel(11)->minutes();
        $this->sweep();

        // The resume consumes the paused turn before continuing; the
        // continuation itself is not under test here.
        $loop = Mockery::mock(AgentLoopService::class);
        $loop->shouldReceive('resumeQuestion')->once()->andReturnNull();
        $loop->shouldReceive('resumeQuestionSync')->never();
        $this->app->instance(AgentLoopService::class, $loop);

        $jobs = Queue::pushed(ResumeAfterQuestionJob::class)->values();
        $this->assertCount(1, $jobs);
        $this->app->call([$jobs[0], 'handle']);

        $results = Message::findOrFail($question->paused_message_id)->tool_data['tool_results'] ?? [];
        $ask = array_values(array_filter($results, fn ($r) => ($r['tool_call_id'] ?? null) === 'call_ask'));
        $this->assertCount(1, $ask, 'the paused ask_user call is answered');
        $this->assertSame([
            'outcome' => 'expired',
            'question_id' => $question->id,
            'message' => 'The question expired without an answer after 10 minutes. No answer will arrive. Decide how to proceed.',
        ], json_decode($ask[0]['content'], true));

        $this->assertSame('expired', Message::findOrFail($question->display_message_id)->tool_data['state'] ?? null);
        $action = DB::table('agent_run_actions')->where('id', $question->action_id)->first();
        $this->assertSame('failure', $action->outcome);
        $this->assertSame('question expired', $action->failure_reason);
    }
}
