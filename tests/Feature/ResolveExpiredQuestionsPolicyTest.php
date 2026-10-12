<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Jobs\ResumeAfterQuestionJob;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\RecordingChannelDelivery;
use Tests\TestCase;

/**
 * The expiry sweep follows each question's unanswered policy.
 *
 * A question asked before unanswered policies existed expires exactly as it
 * always did: it becomes `expired`, frees its conversation's question slot,
 * tells the user, and has its run resumed by a queued job.
 *
 * A `stop` question instead becomes `stopped`: it keeps the slot (so a late
 * reply still reaches it), stays unresolved, leaves the conversation not
 * processing, tells the user that work has stopped, and queues nothing —
 * its run waits for a late answer or a discard.
 */
class ResolveExpiredQuestionsPolicyTest extends TestCase
{
    private const SWEEP = 'llm-client:resolve-expired-questions';

    private const QUESTION = 'Which part should I change first?';

    private const EXPIRED_NOTICE = 'The question "Which part should I change first?" expired without an answer. '
        .'The agent will continue without it; replies sent now are treated as new messages.';

    /** Asked at 09:15 UTC on 12 Oct 2026 with a one-hour period. */
    private const STOPPED_NOTICE = 'No answer to "Which part should I change first?" was received by the deadline '
        .'(10:15 UTC on 12 Oct 2026 (1 hour after this was asked)). Work on this task has stopped. '
        .'It will continue when you answer the question above.';

    private User $user;

    private RecordingChannelDelivery $web;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        config(['llm-client.run_trace.enabled' => true]);
        config(['llm-client.agent_questions.resume_grace_seconds' => 60]);
        config(['llm-client.agent_questions.sweep_batch_size' => 100]);

        Queue::fake();
        Event::fake([NewConversationMessageEvent::class]);

        $this->web = new RecordingChannelDelivery('web');
        app(ConversationChannelRegistry::class)->register($this->web);

        $this->travelTo(Carbon::parse('2026-10-12 09:15:00', 'UTC'));
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
        } finally {
            parent::tearDown();
        }
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    /**
     * A three-choice question with a one-hour period, opened the way a pause
     * site opens it, in a conversation of its own. New asks carry the `stop`
     * policy by default; $legacy marks the row as asked before unanswered
     * policies existed. Deliveries made while opening it are cleared.
     */
    private function openQuestion(bool $legacy = false, string $resumeMode = OpenQuestionRequest::RESUME_STREAM): AgentQuestion
    {
        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'channel' => 'web',
            'is_processing' => true,
        ]);

        $recorder = app(RunTraceRecorder::class);
        $runId = $recorder->openRun(RunKind::Interactive, (string) $this->user->id, $conversation->id, streamed: $resumeMode === OpenQuestionRequest::RESUME_STREAM);
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
            resumeMode: $resumeMode,
            preamble: null,
            pendingToolResults: [],
            iteration: 1,
        ));

        $this->assertInstanceOf(OpenedQuestion::class, $opened, 'precondition: the question opened');
        $this->web->deliveries = [];

        if ($legacy) {
            // A question asked before unanswered policies existed: it expires as it always did.
            DB::table('agent_questions')->where('id', $opened->question->id)->update(['unanswered_policy' => null, 'effective_wait_minutes' => null]);
        }

        $question = AgentQuestion::findOrFail($opened->question->id);
        $this->assertSame($legacy ? null : 'stop', $question->unanswered_policy?->value, 'precondition: the policy the question was asked with');
        $this->assertSame(60 * 60, $question->expires_at->getTimestamp() - $question->asked_at->getTimestamp(), 'precondition: a one-hour period');
        $this->assertFalse((bool) Conversation::findOrFail($question->conversation_id)->is_processing, 'precondition: a waiting conversation is not processing');

        return $question;
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

    /** @return list<string> texts delivered to $question's conversation */
    private function deliveredTo(AgentQuestion $question): array
    {
        return array_values(array_map(
            fn (array $d) => $d['text'],
            array_filter($this->web->deliveries, fn (array $d) => $d['conversation_id'] === $question->conversation_id),
        ));
    }

    private function displayState(AgentQuestion $question): ?string
    {
        return Message::findOrFail($question->display_message_id)->tool_data['state'] ?? null;
    }

    // ---------------------------------------------------------------
    // The sweep
    // ---------------------------------------------------------------

    #[Test]
    public function the_sweep_expires_a_legacy_question_and_stops_a_stop_question(): void
    {
        $legacy = $this->openQuestion(legacy: true);
        $stop = $this->openQuestion();
        $stopSync = $this->openQuestion(resumeMode: OpenQuestionRequest::RESUME_SYNC);

        $this->travel(61)->minutes();
        $this->sweep();

        // The legacy question expires exactly as it always did.
        $legacy->refresh();
        $this->assertSame(QuestionState::Expired, $legacy->state);
        $this->assertNotNull($legacy->resolved_at);
        $this->assertNull($legacy->stopped_at);
        $this->assertNull($legacy->pending_conversation_id, 'the legacy question frees its slot');
        $this->assertTrue((bool) Conversation::findOrFail($legacy->conversation_id)->is_processing, 'the legacy expiry marks the conversation processing until its run resumes');
        $legacyNotices = $this->noticesFor($legacy);
        $this->assertCount(1, $legacyNotices);
        $this->assertSame(self::EXPIRED_NOTICE, $legacyNotices[0]->content);
        $this->assertEquals(
            ['kind' => 'agent_question_notice', 'question_id' => $legacy->id, 'state' => 'expired'],
            $legacyNotices[0]->tool_data,
        );
        $this->assertSame([self::EXPIRED_NOTICE], $this->deliveredTo($legacy));

        // Each stop question stops and keeps waiting.
        foreach ([$stop, $stopSync] as $question) {
            $question->refresh();
            $this->assertSame(QuestionState::Stopped, $question->state, 'a stop question stops when its period passes');
            $this->assertNotNull($question->stopped_at, 'the moment it stopped is recorded');
            $this->assertSame('2026-10-12 10:16:00', $question->stopped_at->copy()->utc()->format('Y-m-d H:i:s'), 'it stopped when the sweep ran');
            $this->assertNull($question->resolved_at, 'a stopped question is not resolved');
            $this->assertNull($question->resumed_at);
            $this->assertSame($question->conversation_id, $question->pending_conversation_id, 'the slot is kept so a late reply still reaches the question');
            $this->assertFalse((bool) Conversation::findOrFail($question->conversation_id)->is_processing, 'nothing is processing while the work is stopped');
            $this->assertSame('stopped', $this->displayState($question), 'the message showing the question records that it stopped');

            $notices = $this->noticesFor($question);
            $this->assertCount(1, $notices, 'the user is told once');
            $this->assertSame('assistant', $notices[0]->role);
            $this->assertSame(self::STOPPED_NOTICE, $notices[0]->content);
            $this->assertEquals(
                ['kind' => 'agent_question_notice', 'question_id' => $question->id, 'state' => 'stopped'],
                $notices[0]->tool_data,
            );
            $this->assertSame([self::STOPPED_NOTICE], $this->deliveredTo($question), 'the stop notice is delivered once on the conversation\'s channel');
        }

        $this->assertSame([$legacy->id], $this->queuedResumes(), 'only the legacy expiry queues a resume; a stopped run waits');
        $this->assertCount(1, Queue::pushed(ResumeAfterQuestionJob::class), 'exactly one job for the three questions');
    }

    #[Test]
    public function a_second_sweep_changes_nothing(): void
    {
        $legacy = $this->openQuestion(legacy: true);
        $stop = $this->openQuestion();

        $this->travel(61)->minutes();
        $this->sweep();

        $stop->refresh();
        $this->assertSame(QuestionState::Stopped, $stop->state, 'precondition: the first sweep stopped it');
        $stoppedAt = $stop->stopped_at;
        $deliveries = $this->web->deliveries;
        $messages = Message::count();

        // Still inside the re-drive grace window, so the legacy question is
        // not re-driven either.
        $this->travel(10)->seconds();
        $output = $this->sweep();

        $stop->refresh();
        $this->assertSame(QuestionState::Stopped, $stop->state);
        $this->assertEquals($stoppedAt, $stop->stopped_at, 'the moment it stopped is never moved');
        $this->assertNull($stop->resolved_at);
        $this->assertSame($stop->conversation_id, $stop->pending_conversation_id);
        $this->assertSame(QuestionState::Expired, $legacy->fresh()->state);
        $this->assertCount(1, $this->noticesFor($stop));
        $this->assertCount(1, $this->noticesFor($legacy));
        $this->assertSame($deliveries, $this->web->deliveries, 'nothing more is delivered');
        $this->assertSame($messages, Message::count(), 'nothing more is written');
        $this->assertSame([$legacy->id], $this->queuedResumes());
        $this->assertStringContainsString('Questions expired: 0', $output);
    }

    #[Test]
    public function a_stop_question_not_yet_due_is_left_alone(): void
    {
        $overdue = $this->openQuestion();

        $this->travel(30)->minutes();
        $notDue = $this->openQuestion();

        $this->travel(31)->minutes();
        $this->sweep();

        $this->assertSame(QuestionState::Stopped, $overdue->fresh()->state, 'precondition: the overdue question stopped in the same sweep');

        $notDue->refresh();
        $this->assertSame(QuestionState::Pending, $notDue->state);
        $this->assertNull($notDue->stopped_at);
        $this->assertNull($notDue->resolved_at);
        $this->assertSame($notDue->conversation_id, $notDue->pending_conversation_id);
        $this->assertSame([], $this->noticesFor($notDue));
        $this->assertSame([], $this->deliveredTo($notDue));
        $this->assertSame('pending', $this->displayState($notDue));
        $this->assertSame([], $this->queuedResumes());
    }

    #[Test]
    public function a_dry_run_counts_every_overdue_question_and_changes_nothing(): void
    {
        $legacy = $this->openQuestion(legacy: true);
        $stop = $this->openQuestion();
        $stopSync = $this->openQuestion(resumeMode: OpenQuestionRequest::RESUME_SYNC);

        $this->travel(61)->minutes();
        $output = $this->sweep(['--dry-run' => true]);

        foreach ([$legacy, $stop, $stopSync] as $question) {
            $fresh = $question->fresh();
            $this->assertSame(QuestionState::Pending, $fresh->state, 'a dry run changes no state');
            $this->assertNull($fresh->stopped_at);
            $this->assertSame([], $this->noticesFor($question));
        }
        $this->assertSame([], $this->web->deliveries);
        $this->assertSame([], $this->queuedResumes());
        $this->assertStringContainsString('Questions that would be expired: 3', $output);
        $this->assertStringContainsString('Resolved questions that would be re-driven: 0', $output);
    }

    // ---------------------------------------------------------------
    // expireWithPolicy() and expire()
    // ---------------------------------------------------------------

    #[Test]
    public function expire_with_policy_returns_the_state_it_won(): void
    {
        $legacy = $this->openQuestion(legacy: true);
        $stop = $this->openQuestion();
        $service = app(AgentQuestionService::class);

        $this->travel(61)->minutes();

        $this->assertSame(QuestionState::Expired, $service->expireWithPolicy($legacy), 'a legacy question expires');
        $this->assertSame(QuestionState::Stopped, $service->expireWithPolicy($stop), 'a stop question stops');

        $this->assertSame(QuestionState::Expired, $legacy->fresh()->state);
        $this->assertSame(QuestionState::Stopped, $stop->fresh()->state);

        $this->assertNull($service->expireWithPolicy($legacy->fresh()), 'an expired question cannot expire again');
        $this->assertNull($service->expireWithPolicy($stop->fresh()), 'a stopped question cannot stop again');
        $this->assertCount(1, $this->noticesFor($legacy));
        $this->assertCount(1, $this->noticesFor($stop));
        $this->assertSame([], $this->queuedResumes(), 'the service queues nothing; resuming is the sweep\'s job');
    }

    #[Test]
    public function expire_with_policy_returns_null_when_it_loses_the_race(): void
    {
        $legacy = $this->openQuestion(legacy: true);
        $stop = $this->openQuestion();
        $service = app(AgentQuestionService::class);

        $this->travel(61)->minutes();

        foreach ([$legacy, $stop] as $question) {
            $this->assertNotNull($service->answer($question, (new AnswerParser())->parse('2', $question), (string) $this->user->id, '2'), 'precondition: the answer won');
        }

        $this->assertNull($service->expireWithPolicy($legacy));
        $this->assertNull($service->expireWithPolicy($stop));

        foreach ([$legacy, $stop] as $question) {
            $fresh = $question->fresh();
            $this->assertSame(QuestionState::Answered, $fresh->state, 'the answer stands');
            $this->assertNull($fresh->stopped_at);
            $this->assertSame([], $this->noticesFor($question), 'the loser writes no notice');
        }
        $this->assertSame([], $this->web->deliveries, 'the loser delivers nothing');
    }

    #[Test]
    public function expire_still_answers_whether_it_won(): void
    {
        $legacy = $this->openQuestion(legacy: true);
        $stop = $this->openQuestion();
        $service = app(AgentQuestionService::class);

        $this->travel(61)->minutes();

        $this->assertTrue($service->expire($legacy));
        $this->assertTrue($service->expire($stop));
        $this->assertSame(QuestionState::Expired, $legacy->fresh()->state);
        $this->assertSame(QuestionState::Stopped, $stop->fresh()->state, 'expire() follows the question\'s policy');

        $this->assertFalse($service->expire($legacy->fresh()));
        $this->assertFalse($service->expire($stop->fresh()));
    }
}
