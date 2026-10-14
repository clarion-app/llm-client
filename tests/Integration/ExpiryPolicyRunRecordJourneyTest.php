<?php

namespace Tests\Integration;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Jobs\AgentStreamStepJob;
use ClarionApp\LlmClient\Events\FinishOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Events\ToolExecutionEvent;
use ClarionApp\LlmClient\Events\UpdateOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Jobs\ResumeAfterQuestionJob;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Services\ConversationChannelRegistry;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\RecordingChannelDelivery;
use Tests\Integration\Harness\RecordingRichChannelDelivery;

/**
 * Anyone reviewing a run can see what the agent's question was to do if
 * nobody answered, and what happened — end to end, through the run
 * endpoints the run screens read.
 *
 * Three tasks, each in its own chat conversation, each asking "Which part
 * should I change first? 1. Backend 2. Frontend 3. Docs" with an hour to
 * answer:
 *
 * - the first may proceed on choice 1 and is answered in time with 2;
 * - the second may proceed on choice 1 and nobody answers, so it proceeds
 *   on its assumption;
 * - the third must not be guessed: nobody answers, so its task stops, and
 *   an hour and a half later the user answers 2 and it resumes.
 *
 * Each run's `ask_user` action shows the policy, the assumption and its own
 * outcome — answered, expired_assumed, answered_late — so an assumed
 * decision is never mistaken for the user's. While the third is stopped, its
 * run is shown as stopped awaiting the user (still in progress, not
 * completed, failed or abandoned); afterwards its record shows when it
 * stopped, for how long, the late answer, and the continuation, which ran in
 * the same run after the stop. Only the run's owner sees any of it.
 *
 * Real wiring throughout — the chat entry point, the agent loop, the
 * question service, the sweep command, the resumer and its job (worked the
 * way a queue worker does), the run trace and its endpoints — with the
 * model scripted at its HTTP boundary and recording adapters standing in
 * for both channels.
 */
class ExpiryPolicyRunRecordJourneyTest extends AssembledSystemTestCase
{
    private const API = '/api/clarion-app/llm-client';

    private const AGENT_ENDPOINT = self::API.'/agent';

    private const SWEEP = 'llm-client:resolve-expired-questions';

    private const QUESTION = 'Which part should I change first?';

    private const CHOICES = ['Backend', 'Frontend', 'Docs'];

    /** The clock is frozen at each of these instants in turn. */
    private const ASKED_AT = '2026-10-12 09:00:00';

    private const ANSWERED_IN_TIME_AT = '2026-10-12 09:30:00';

    private const SWEPT_AT = '2026-10-12 10:01:00';

    private const ANSWERED_LATE_AT = '2026-10-12 11:30:00';

    private const PROCEED_ARGUMENTS = ['question' => self::QUESTION, 'choices' => self::CHOICES, 'assumed_choices' => [1], 'wait_minutes' => 60];

    private const STOP_ARGUMENTS = ['question' => self::QUESTION, 'choices' => self::CHOICES, 'wait_minutes' => 60];

    private const PROCEED_POLICY = [
        'if_unanswered' => 'proceed_on_assumption',
        'consequential' => false,
        'requested_wait_minutes' => 60,
        'effective_wait_minutes' => 60,
        'assumed_answer' => ['raw_reply' => '1', 'is_selection' => true, 'selected' => [['number' => 1, 'text' => 'Backend']]],
    ];

    private const STOP_POLICY = [
        'if_unanswered' => 'stop',
        'consequential' => false,
        'requested_wait_minutes' => 60,
        'effective_wait_minutes' => 60,
        'assumed_answer' => null,
    ];

    private const ANSWER_TWO = [
        'raw_reply' => '2',
        'selected' => [['number' => 2, 'text' => 'Frontend']],
        'method' => 'typed_reply',
    ];

    private RecordingChannelDelivery $web;

    private RecordingRichChannelDelivery $telegram;

    /** @var list<string> every error or warning logged */
    private array $problemsLogged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::ASKED_AT, 'UTC'));

        config(['llm-client.run_trace.enabled' => true]);
        config(['llm-client.conversation.inactivity_threshold_hours' => 4]);

        // Resume jobs are held on the queue until a worker picks them up.
        Queue::fake([AgentStreamStepJob::class, ResumeAfterQuestionJob::class]);

        Event::fake([
            NewConversationMessageEvent::class,
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            ToolExecutionEvent::class,
        ]);

        Event::listen(MessageLogged::class, function (MessageLogged $logged) {
            if (in_array($logged->level, ['error', 'warning', 'critical', 'alert', 'emergency'], true)) {
                $this->problemsLogged[] = $logged->level.': '.$logged->message;
            }
        });

        $this->web = new RecordingChannelDelivery('web');
        $this->telegram = new RecordingRichChannelDelivery('telegram');
        $registry = $this->app->make(ConversationChannelRegistry::class);
        $registry->register($this->web);
        $registry->register($this->telegram);
    }

    // ---------------------------------------------------------------
    // Driving the three tasks
    // ---------------------------------------------------------------

    private function at(string $instant): void
    {
        $this->travelTo(Carbon::parse($instant, 'UTC'));
    }

    private function sweep(): void
    {
        $this->assertSame(0, Artisan::call(self::SWEEP), 'the sweep succeeds');
    }

    private function workQueuedResumes(): void
    {
        foreach (Queue::pushed(ResumeAfterQuestionJob::class)->all() as $job) {
            $this->app->call([$job, 'handle']);
        }
    }

    /** A chat conversation of the fixture's user. */
    private function chatConversation(string $title): Conversation
    {
        $fixture = $this->fixture();

        return Conversation::create([
            'id' => (string) Str::uuid(),
            'user_id' => $fixture->user->id,
            'server_id' => $fixture->server->id,
            'model' => $fixture->conversation->model,
            'title' => $title,
            'channel' => 'telegram',
        ])->fresh();
    }

    private function send(Conversation $conversation, string $message): TestResponse
    {
        return $this->actingAs($this->fixture()->user, 'api')->postJson(self::AGENT_ENDPOINT, [
            'message' => $message,
            'channel' => 'telegram',
            'conversation_id' => $conversation->id,
        ]);
    }

    private function askIn(Conversation $conversation): AgentQuestion
    {
        $this->send($conversation, 'Tidy up the project.')
            ->assertStatus(202)
            ->assertJson(['status' => 'awaiting_user']);

        $question = AgentQuestion::where('conversation_id', $conversation->id)->first();
        $this->assertNotNull($question, 'precondition: the agent\'s ask opened a question');
        $this->assertSame(QuestionState::Pending, $question->state);
        $this->assertNotNull($question->run_id, 'precondition: the question belongs to a recorded run');

        return $question;
    }

    // ---------------------------------------------------------------
    // Reading the record as the owner does
    // ---------------------------------------------------------------

    /** @return array<string, mixed> */
    private function ownerGets(string $path): array
    {
        return $this->actingAs($this->fixture()->user, 'api')
            ->getJson(self::API.$path)
            ->assertStatus(200)
            ->json();
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} the ask_user action and its decoded snapshot */
    private function askRecord(AgentQuestion $question): array
    {
        $action = $this->ownerGets("/agent-runs/{$question->run_id}/actions/{$question->action_id}");
        $this->assertSame('ask_user', $action['target']);
        $snapshot = json_decode((string) $action['content'], true);
        $this->assertIsArray($snapshot, 'the ask_user action records the question snapshot');

        return [$action, $snapshot];
    }

    private function iso(string $instant): string
    {
        return Carbon::parse($instant, 'UTC')->format('Y-m-d\\TH:i:s\\Z');
    }

    private function msBetween(string $from, string $to): int
    {
        return (int) Carbon::parse($from, 'UTC')->diffInMilliseconds(Carbon::parse($to, 'UTC'), true);
    }

    // ---------------------------------------------------------------
    // The journey
    // ---------------------------------------------------------------

    #[Test]
    public function each_run_record_shows_the_policy_the_assumption_and_how_the_question_ended(): void
    {
        $this->scenario = 'expiry_policy_run_record';
        $this->entryPath = 'sync';

        $this->fixture()->build();

        $this->script()
            ->toolRequest('ask_user', self::PROCEED_ARGUMENTS)
            ->toolRequest('ask_user', self::PROCEED_ARGUMENTS)
            ->toolRequest('ask_user', self::STOP_ARGUMENTS)
            ->finalAnswer('Starting with the frontend, as you asked.')
            ->finalAnswer('No answer came, so I am starting with the backend.')
            ->finalAnswer('Starting with the frontend now that you have answered.');

        // 09:00 — all three ask.
        $answeredIn = $this->chatConversation('Answered in time');
        $assumedIn = $this->chatConversation('Proceeded on its assumption');
        $stoppedIn = $this->chatConversation('Stopped, then answered late');
        $answered = $this->askIn($answeredIn);
        $assumed = $this->askIn($assumedIn);
        $stopped = $this->askIn($stoppedIn);

        // 09:30 — the first is answered in time.
        $this->at(self::ANSWERED_IN_TIME_AT);
        $this->send($answeredIn, '2')->assertStatus(200)->assertJson(['status' => 'completed']);

        // 10:01 — the deadline has passed for the other two.
        $this->at(self::SWEPT_AT);
        $this->sweep();
        $this->assertSame(QuestionState::ExpiredAssumed, $assumed->fresh()->state, 'precondition: proceeded on its assumption');
        $this->assertSame(QuestionState::Stopped, $stopped->fresh()->state, 'precondition: its task stopped');
        $this->workQueuedResumes();

        // While the third is stopped, its run is shown as stopped awaiting
        // its user — still in progress, distinct from every ended state.
        $summary = $this->ownerGets("/agent-runs/{$stopped->run_id}");
        $this->assertSame('in_progress', $summary['end_state']);
        $this->assertTrue($summary['awaiting_user']);
        $this->assertTrue($summary['question_stopped'] ?? null, 'the run is shown as stopped on its question');

        $listed = collect($this->ownerGets('/agent-runs')['data'])->keyBy('id');
        $this->assertTrue($listed[$stopped->run_id]['question_stopped'] ?? null);
        $this->assertFalse($listed[$answered->run_id]['question_stopped'] ?? null, 'an answered run is not stopped');
        $this->assertFalse($listed[$assumed->run_id]['question_stopped'] ?? null, 'a run that proceeded is not stopped');
        $this->assertSame('completed', $listed[$answered->run_id]['end_state']);
        $this->assertSame('completed', $listed[$assumed->run_id]['end_state']);

        [$waitingAction, $waitingSnapshot] = $this->askRecord($stopped);
        $this->assertSame('awaiting_confirmation', $waitingAction['outcome'], 'the ask still waits on its user');
        $this->assertSame('stopped', $waitingSnapshot['outcome'] ?? null, 'the record shows the stop');
        $this->assertSame($this->iso(self::SWEPT_AT), $waitingSnapshot['stopped_at'] ?? null);

        // 11:30 — the user answers the stopped question; its task resumes.
        $this->at(self::ANSWERED_LATE_AT);
        $this->send($stoppedIn, '2')->assertStatus(200)->assertJson(['status' => 'completed']);
        $this->assertSame(QuestionState::Answered, $stopped->fresh()->state, 'precondition: the late answer was taken');

        // The first: answered in time, its unused assumption still on record.
        [$action, $snapshot] = $this->askRecord($answered);
        $this->assertSame('success', $action['outcome']);
        $this->assertSame('answered', $snapshot['outcome'] ?? null);
        $this->assertSame(self::PROCEED_POLICY, $snapshot['policy'] ?? null);
        $this->assertSame(self::ANSWER_TWO, $snapshot['answer']);
        $this->assertArrayHasKey('stopped_at', $snapshot);
        $this->assertNull($snapshot['stopped_at'], 'it never stopped');
        $outcomes = [$snapshot['outcome'] ?? null];

        // The second: proceeded on its assumption, never recorded as the user's answer.
        [$action, $snapshot] = $this->askRecord($assumed);
        $this->assertSame('failure', $action['outcome']);
        $this->assertSame('question expired_assumed', $action['failure_reason']);
        $this->assertSame('expired_assumed', $snapshot['outcome'] ?? null);
        $this->assertSame(self::PROCEED_POLICY, $snapshot['policy'] ?? null, 'the record names what was assumed');
        $this->assertNull($snapshot['answer'], 'the assumption is not the user\'s answer');
        $this->assertSame($this->iso(self::SWEPT_AT), $snapshot['resolved_at']);
        $outcomes[] = $snapshot['outcome'] ?? null;

        // The third: the stop, how long it lasted, the late answer.
        [$action, $snapshot] = $this->askRecord($stopped);
        $this->assertSame('success', $action['outcome']);
        $this->assertSame('answered_late', $snapshot['outcome'] ?? null);
        $this->assertSame(self::STOP_POLICY, $snapshot['policy'] ?? null);
        $this->assertSame($this->iso(self::SWEPT_AT), $snapshot['stopped_at'] ?? null);
        $this->assertSame($this->iso(self::ANSWERED_LATE_AT), $snapshot['resolved_at']);
        $this->assertSame($this->msBetween(self::SWEPT_AT, self::ANSWERED_LATE_AT), $snapshot['stopped_ms'] ?? null, 'how long the task was stopped');
        $this->assertSame(self::ANSWER_TWO, $snapshot['answer'], 'the late answer');
        $outcomes[] = $snapshot['outcome'] ?? null;

        $this->assertSame(['answered', 'expired_assumed', 'answered_late'], $outcomes, 'three distinct outcomes');

        // The continuation is traceable to the stop: it ran in the same
        // run, in a step after the paused one, after the late answer.
        $summary = $this->ownerGets("/agent-runs/{$stopped->run_id}");
        $this->assertSame('completed', $summary['end_state']);
        $this->assertFalse($summary['question_stopped'] ?? null);
        $this->assertFalse($summary['awaiting_user']);

        $steps = collect($this->ownerGets("/agent-runs/{$stopped->run_id}/steps")['data']);
        $paused = $steps->firstWhere('id', $stopped->step_id);
        $this->assertIsArray($paused, 'the paused step is in the run');
        $continuation = $steps->first(fn (array $step) => $step['position'] > $paused['position']);
        $this->assertIsArray($continuation, 'the continuation is a later step of the same run');
        $this->assertSame($stopped->run_id, $continuation['run_id']);
        $this->assertGreaterThanOrEqual(
            Carbon::parse(self::ANSWERED_LATE_AT, 'UTC')->getTimestamp(),
            Carbon::parse($continuation['started_at'])->getTimestamp(),
            'the continuation began only after the late answer',
        );
        $this->assertGreaterThanOrEqual(
            $this->msBetween(self::ASKED_AT, self::ANSWERED_LATE_AT),
            (int) $paused['wait_ms'],
            'the paused step\'s wait covers the whole time on the user, the stop included',
        );

        $conversationRuns = collect($this->ownerGets('/agent-runs')['data'])->where('conversation_id', $stoppedIn->id);
        $this->assertCount(1, $conversationRuns, 'no new run was started for the late answer');

        // Only the owner sees any of it.
        $stranger = User::create([
            'id' => (string) Str::uuid(),
            'name' => 'Someone Else',
            'email' => 'someone.else@example.com',
            'password' => 'not-used',
        ]);
        $this->actingAs($stranger, 'api')->getJson(self::API."/agent-runs/{$stopped->run_id}")->assertStatus(404);
        $this->actingAs($stranger, 'api')
            ->getJson(self::API."/agent-runs/{$stopped->run_id}/actions/{$stopped->action_id}")
            ->assertStatus(404);

        $this->assertSame([], $this->problemsLogged, 'nothing failed along the way');
    }
}
