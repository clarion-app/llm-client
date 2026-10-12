<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Events\ConversationEnded;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\AgentQuestionService;
use ClarionApp\LlmClient\Services\AnswerParser;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\Services\ConversationLifecycleService;
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
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\RecordingChannelDelivery;
use Tests\TestCase;

/**
 * A stopped question is still waiting — for a late answer or a discard —
 * however long ago its period passed. None of the background processes
 * that tidy up old or idle work may treat its run or conversation as
 * finished: the abandoned-run sweep does not abandon the run, the idle
 * sweep does not end the conversation, and the run-trace purge does not
 * delete the run record the paused turn will resume into.
 *
 * The abandoned-run sweep is registered before the expiry sweep and runs
 * first when both are due in the same scheduler tick, so a question whose
 * period has only just passed (the expiry sweep not having stopped it yet)
 * keeps its run exempt for a short grace after its deadline — but not
 * indefinitely, so a stalled expiry sweep cannot pin a run open forever.
 *
 * Once the stopped question is answered late or discarded, every process
 * treats the run and conversation as it would any other.
 */
class StoppedQuestionExemptionsTest extends TestCase
{
    private const QUESTION = 'Which part should I change first?';

    private User $user;

    private RecordingChannelDelivery $web;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        config(['llm-client.run_trace.enabled' => true]);
        config(['llm-client.run_trace.abandonment_minutes' => 60]);
        config(['llm-client.run_trace.retention_days' => 7]);
        config(['llm-client.agent_loop.confirmation_timeout' => 300]);
        config(['llm-client.agent_questions.resume_grace_seconds' => 60]);

        Queue::fake();
        Event::fake([NewConversationMessageEvent::class, ConversationEnded::class]);

        $this->web = new RecordingChannelDelivery('web');
        app(ConversationChannelRegistry::class)->register($this->web);
    }

    protected function tearDown(): void
    {
        try {
            foreach (['agent_questions', 'agent_run_messages', 'agent_run_actions', 'agent_run_steps', 'agent_runs'] as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->delete();
                }
            }
            DB::table('messages')->delete();
            DB::table('conversations')->delete();
            DB::table('users')->delete();
        } finally {
            parent::tearDown();
        }
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    /**
     * A one-hour question opened the way a pause site opens it, asked
     * $askedMinutesAgo minutes ago, with its run, step, ask_user action and
     * the run's trigger-message link. New asks carry the `stop` policy.
     */
    private function askedAgo(int $askedMinutesAgo): AgentQuestion
    {
        $this->travelTo(now()->subMinutes($askedMinutesAgo));

        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'channel' => 'web',
            'is_processing' => true,
        ]);

        $trigger = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'user' => 'User',
            'content' => 'Tidy up the project.',
            'responseTime' => 0,
        ]);

        $recorder = app(RunTraceRecorder::class);
        $runId = $recorder->openRun(RunKind::Interactive, (string) $this->user->id, $conversation->id, streamed: true);
        $stepId = $recorder->openStep($runId);
        $actionId = $recorder->openAction($stepId, ActionType::ToolInvocation, 'ask_user');

        DB::table('agent_run_messages')->insert([
            'id' => (string) Str::uuid(),
            'run_id' => $runId,
            'message_id' => $trigger->id,
            'relation' => 'trigger',
            'created_at' => now()->format('Y-m-d H:i:s'),
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
                    'choices' => ['Backend', 'Frontend', 'Docs'],
                    'wait_minutes' => 60,
                ])],
            ]]],
        ]);

        $opened = app(AgentQuestionService::class)->open(new OpenQuestionRequest(
            conversation: $conversation->fresh(),
            request: new AskUserRequest(self::QUESTION, ['Backend', 'Frontend', 'Docs'], false, requestedWaitMinutes: 60),
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

        $this->travelBack();

        $question = AgentQuestion::findOrFail($opened->question->id);
        $this->assertSame('stop', $question->unanswered_policy?->value, 'precondition: a stop question');
        $this->assertSame(QuestionState::Pending, $question->state);

        return $question;
    }

    /**
     * A stop question whose one-hour period ended 30 days ago and which the
     * expiry sweep stopped then: its run began, and its conversation last
     * changed, 30 days ago too.
     */
    private function stoppedThirtyDaysAgo(): AgentQuestion
    {
        $question = $this->askedAgo(30 * 1440 + 60);

        // The real sweep, a minute after the deadline, stops it.
        $this->travelTo($question->expires_at->copy()->addMinute());
        $this->runCommand('llm-client:resolve-expired-questions');
        $this->web->deliveries = [];
        $this->travelBack();

        $question->refresh();
        $this->assertSame(QuestionState::Stopped, $question->state, 'precondition: stopped');
        $this->assertSame($question->conversation_id, $question->pending_conversation_id, 'precondition: the slot is kept');
        $this->assertNull($question->resolved_at, 'precondition: unresolved');
        $this->assertTrue($question->stopped_at->lessThan(now()->subDays(30)->addMinute()), 'precondition: stopped 30 days ago');
        $this->assertTrue($question->expires_at->lessThan(now()->subDays(30)->addMinute()), 'precondition: its deadline was 30 days ago');
        $this->assertSame('in_progress', $this->runEndState($question), 'precondition: the run is still waiting');
        $this->assertTrue(
            \Illuminate\Support\Carbon::parse(DB::table('agent_runs')->where('id', $question->run_id)->value('started_at'))->lessThan(now()->subDays(30)),
            'precondition: the run began 30 days ago',
        );
        $conversation = Conversation::findOrFail($question->conversation_id);
        $this->assertFalse((bool) $conversation->is_processing, 'precondition: nothing is processing');
        $this->assertTrue($conversation->updated_at->lessThan(now()->subDays(30)), 'precondition: the conversation has been quiet for 30 days');

        return $question;
    }

    /** An ordinary finished run that ended 30 days ago, with no question. */
    private function oldFinishedRun(): string
    {
        $runId = (string) Str::uuid();
        $at = now()->subDays(30)->format('Y-m-d H:i:s.u');
        DB::table('agent_runs')->insert([
            'id' => $runId,
            'kind' => 'interactive',
            'user_id' => (string) $this->user->id,
            'conversation_id' => null,
            'end_state' => 'completed',
            'started_at' => $at,
            'ended_at' => $at,
            'step_count' => 0,
            'created_at' => $at,
        ]);

        return $runId;
    }

    private function runEndState(AgentQuestion $question): ?string
    {
        return DB::table('agent_runs')->where('id', $question->run_id)->value('end_state');
    }

    private function runCommand(string $command, array $options = []): void
    {
        $this->assertSame(0, Artisan::call($command, $options), "{$command} succeeds");
    }

    /** Everything the purge would delete for $question's run, counted. */
    private function runRecordCounts(AgentQuestion $question): array
    {
        return [
            'run' => DB::table('agent_runs')->where('id', $question->run_id)->count(),
            'steps' => DB::table('agent_run_steps')->where('run_id', $question->run_id)->count(),
            'actions' => DB::table('agent_run_actions')->where('run_id', $question->run_id)->count(),
            'messages' => DB::table('agent_run_messages')->where('run_id', $question->run_id)->count(),
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function resolutions(): array
    {
        return [
            'answered late' => ['answer'],
            'discarded' => ['discard'],
        ];
    }

    private function resolve(AgentQuestion $question, string $how): void
    {
        $service = app(AgentQuestionService::class);

        if ($how === 'answer') {
            $this->assertNotNull(
                $service->answer($question, (new AnswerParser())->parse('2', $question), (string) $this->user->id, '2'),
                'precondition: the late answer is accepted',
            );
            $this->assertSame(QuestionState::Answered, $question->fresh()->state, 'precondition: answered');
        } else {
            $this->assertTrue(
                $service->withdrawForConversation(Conversation::findOrFail($question->conversation_id)),
                'precondition: the discard is accepted',
            );
            $this->assertSame(QuestionState::Withdrawn, $question->fresh()->state, 'precondition: discarded');
        }

        $this->assertNull($question->fresh()->pending_conversation_id, 'precondition: the slot is released');
    }

    // ---------------------------------------------------------------
    // A question stopped 30 days ago
    // ---------------------------------------------------------------

    #[Test]
    public function the_abandoned_run_sweep_leaves_a_stopped_questions_run_waiting(): void
    {
        $question = $this->stoppedThirtyDaysAgo();

        $this->runCommand('llm-client:resolve-abandoned-runs');

        $this->assertSame('in_progress', $this->runEndState($question), 'a run waiting on a stopped question is not abandoned');
        $this->assertSame(
            'in_progress',
            DB::table('agent_run_steps')->where('id', $question->step_id)->value('end_state'),
            'its paused step stays open',
        );
        $this->assertSame(QuestionState::Stopped, $question->fresh()->state);
    }

    #[Test]
    public function the_idle_sweep_leaves_a_stopped_questions_conversation_open(): void
    {
        $question = $this->stoppedThirtyDaysAgo();
        $messages = Message::where('conversation_id', $question->conversation_id)->count();

        $this->assertSame(0, app(ConversationLifecycleService::class)->endIdleConversations(30));
        $this->runCommand('llm-client:end-idle-conversations', ['--minutes' => 30]);

        $this->assertNull(Conversation::findOrFail($question->conversation_id)->ended_at, 'the conversation stays open under a stopped question');
        Event::assertNotDispatched(ConversationEnded::class);

        $question->refresh();
        $this->assertSame(QuestionState::Stopped, $question->state, 'the idle timeout is not a discard');
        $this->assertSame($question->conversation_id, $question->pending_conversation_id);
        $this->assertSame($messages, Message::where('conversation_id', $question->conversation_id)->count(), 'no notice is written');
        $this->assertSame([], $this->web->deliveries);
    }

    #[Test]
    public function the_purge_keeps_a_stopped_questions_run_record(): void
    {
        $question = $this->stoppedThirtyDaysAgo();
        $other = $this->oldFinishedRun();
        $before = $this->runRecordCounts($question);
        $this->assertSame(['run' => 1, 'steps' => 1, 'actions' => 1, 'messages' => 1], $before, 'precondition: the run record exists');

        $this->runCommand('llm-client:purge-run-traces');

        $this->assertSame(0, DB::table('agent_runs')->where('id', $other)->count(), 'precondition: the purge ran with a seven-day retention');
        $this->assertSame($before, $this->runRecordCounts($question), 'the run, its steps, actions and messages are kept');
        $this->assertSame('in_progress', $this->runEndState($question));
    }

    // ---------------------------------------------------------------
    // The scheduler-order race
    // ---------------------------------------------------------------

    #[Test]
    public function a_question_just_past_its_deadline_keeps_its_run_until_the_expiry_sweep_stops_it(): void
    {
        // Asked 61 minutes ago with a one-hour period: one minute overdue,
        // and the run quiet for longer than the abandonment threshold.
        $question = $this->askedAgo(61);
        $this->assertTrue($question->expires_at->isPast(), 'precondition: past its deadline');
        $this->assertSame(QuestionState::Pending, $question->fresh()->state, 'precondition: the expiry sweep has not run');

        // The order the scheduler runs them in on a shared tick.
        $this->runCommand('llm-client:resolve-abandoned-runs');
        $this->runCommand('llm-client:resolve-expired-questions');

        $this->assertSame('in_progress', $this->runEndState($question), 'the run is not abandoned in the moment before its question stops');
        $this->assertSame(QuestionState::Stopped, $question->fresh()->state, 'the question then stops');

        // From then on the stopped question holds the run with no time bound.
        $this->travel(2)->hours();
        $this->runCommand('llm-client:resolve-abandoned-runs');
        $this->assertSame('in_progress', $this->runEndState($question));
    }

    #[Test]
    public function a_pending_question_long_past_its_deadline_does_not_exempt_its_run(): void
    {
        // Eleven minutes overdue and still pending: the expiry sweep has
        // stalled, and the run is not held open by it.
        $question = $this->askedAgo(71);
        $this->assertSame(QuestionState::Pending, $question->fresh()->state, 'precondition: the expiry sweep has not run');

        $this->runCommand('llm-client:resolve-abandoned-runs');

        $this->assertSame('abandoned', $this->runEndState($question));
    }

    // ---------------------------------------------------------------
    // Once the stopped question is answered late or discarded
    // ---------------------------------------------------------------

    #[Test]
    #[DataProvider('resolutions')]
    public function the_abandoned_run_sweep_treats_the_run_normally_again(string $how): void
    {
        $question = $this->stoppedThirtyDaysAgo();
        $this->resolve($question, $how);

        // A late answer leaves the run open until its continuation runs; if
        // that never happens within the abandonment threshold, the run is
        // swept like any other. A discard has already ended it, and the
        // sweep leaves an ended run alone.
        $this->travel(61)->minutes();
        $this->runCommand('llm-client:resolve-abandoned-runs');

        $this->assertSame($how === 'answer' ? 'abandoned' : 'stopped_early', $this->runEndState($question));
    }

    // ---------------------------------------------------------------
    // Resolved, its run about to continue
    // ---------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function resolvedAwaitingResume(): array
    {
        return [
            'proceeded on its assumption' => ['proceed'],
            'expired as before policies' => ['legacy'],
            'answered after it stopped' => ['late'],
        ];
    }

    /**
     * A question asked 61 minutes ago with a one-hour period, resolved now
     * the way $how names, its resume queued but not yet worked.
     */
    private function resolvedAwaitingItsResume(string $how): AgentQuestion
    {
        if ($how === 'late') {
            $question = $this->stoppedThirtyDaysAgo();
            $this->resolve($question, 'answer');

            return $question->fresh();
        }

        $question = $this->askedAgo(61);
        DB::table('agent_questions')->where('id', $question->id)->update($how === 'proceed'
            ? ['unanswered_policy' => 'proceed_on_assumption', 'assumed_answer_raw' => '1', 'assumed_answer_selected' => json_encode([['number' => 1, 'text' => 'Backend']])]
            // A question asked before unanswered policies existed: it expires as it always did.
            : ['unanswered_policy' => null, 'effective_wait_minutes' => null]);

        // On a five-minute boundary the abandoned-run sweep runs first; the
        // question is still pending then.
        $this->runCommand('llm-client:resolve-abandoned-runs');
        $this->runCommand('llm-client:resolve-expired-questions');
        $this->assertSame($how === 'proceed' ? QuestionState::ExpiredAssumed : QuestionState::Expired, $question->fresh()->state, 'precondition: resolved by the sweep');
        Queue::assertPushed(\ClarionApp\LlmClient\Jobs\ResumeAfterQuestionJob::class, 1);

        return $question->fresh();
    }

    #[Test]
    #[DataProvider('resolvedAwaitingResume')]
    public function a_run_about_to_continue_is_not_abandoned_while_its_resume_waits_in_the_queue(string $how): void
    {
        $question = $this->resolvedAwaitingItsResume($how);
        $this->assertNull($question->resumed_at, 'precondition: not resumed yet');
        $this->assertSame('in_progress', $this->runEndState($question), 'precondition: the run is waiting');

        // The next abandoned-run sweep comes round before a busy worker
        // reaches the queued resume.
        $this->travel(4)->minutes();
        $this->runCommand('llm-client:resolve-abandoned-runs');

        $this->assertSame('in_progress', $this->runEndState($question), 'a run whose question was just resolved is about to continue, not abandoned');
        $this->assertSame(
            'in_progress',
            DB::table('agent_run_steps')->where('id', $question->step_id)->value('end_state'),
            'its paused step is left for the resume to close',
        );
    }

    #[Test]
    #[DataProvider('resolvedAwaitingResume')]
    public function a_resolution_whose_resume_never_comes_is_abandoned_after_the_threshold(string $how): void
    {
        $question = $this->resolvedAwaitingItsResume($how);

        $this->travel(61)->minutes();
        $this->runCommand('llm-client:resolve-abandoned-runs');

        $this->assertSame('abandoned', $this->runEndState($question), 'a resume that never came does not hold the run open forever');
    }

    #[Test]
    #[DataProvider('resolutions')]
    public function the_idle_sweep_treats_the_conversation_normally_again(string $how): void
    {
        $question = $this->stoppedThirtyDaysAgo();
        $this->resolve($question, $how);

        // The work that followed has finished and the conversation has since
        // been quiet.
        Conversation::findOrFail($question->conversation_id)->update(['is_processing' => false]);
        $this->travel(2)->hours();

        $this->assertSame(1, app(ConversationLifecycleService::class)->endIdleConversations(30));
        $this->assertNotNull(Conversation::findOrFail($question->conversation_id)->ended_at);
    }

    #[Test]
    #[DataProvider('resolutions')]
    public function the_purge_treats_the_run_normally_again(string $how): void
    {
        $question = $this->stoppedThirtyDaysAgo();
        $this->resolve($question, $how);

        // Past the retention window measured from either end of the run.
        $this->travel(8)->days();
        $this->runCommand('llm-client:purge-run-traces');

        $this->assertSame(
            ['run' => 0, 'steps' => 0, 'actions' => 0, 'messages' => 0],
            $this->runRecordCounts($question),
            'the run record ages out like any other',
        );
    }

    #[Test]
    public function the_purge_keeps_the_record_of_a_run_about_to_continue(): void
    {
        // Answered thirty days after it stopped; the purge comes round before
        // a busy worker reaches the queued resume.
        $question = $this->stoppedThirtyDaysAgo();
        $this->resolve($question, 'answer');
        $before = $this->runRecordCounts($question);

        $this->runCommand('llm-client:purge-run-traces');

        $this->assertSame($before, $this->runRecordCounts($question), 'the run the resume continues into is kept');
    }
}
