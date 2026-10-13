<?php

namespace Tests\Integration;

use ClarionApp\LlmClient\Jobs\ResumeAfterQuestionJob;
use ClarionApp\LlmClient\LlmClientServiceProvider;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\AgentQuestionService;
use ClarionApp\LlmClient\Services\InteractiveAnswerService;
use ClarionApp\LlmClient\Services\QuestionResumer;
use ClarionApp\LlmClient\Services\TaskBudgetText;
use ClarionApp\LlmClient\ValueObjects\AnswerMethod;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswer;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswerStatus;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use Illuminate\Cache\ArrayStore;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\CapturedPayload;
use Tests\Integration\Harness\TaskBudgetJourneys;

/**
 * A ceiling prompt nobody answers leaves the task stopped — never
 * continuing on its own, never lost — and a later decision still applies,
 * once.
 *
 * Every journey starts from a task paused at its two-step default ceiling
 * with the prompt waiting for an hour: a Telegram conversation (the
 * synchronous path, answered by typed replies through POST agent) or a web
 * conversation (the streamed path, answered through POST message).
 *
 *  - Just before the deadline the every-minute sweep leaves the prompt
 *    alone; within minutes after it the sweep stops it: nothing runs,
 *    nothing is queued, the question slot is kept, the task stays paused
 *    on the prompt, the run keeps waiting on its user, and the user is told
 *    once, on the conversation's channel, that no answer arrived and work
 *    has stopped at this task's budget ceiling until they decide.
 *  - While it waits nothing is resident: no job is queued, no lock is held,
 *    and a restart, the idle-conversation sweep and the abandoned-run sweep
 *    — however long after — leave the task and its run exactly as they were.
 *  - A late "continue" resumes the same run once from where it paused,
 *    with the raised ceiling; a second late "continue" is not taken. A late
 *    "accept" closes the task instead.
 *  - A prompt the user stopped themselves is left alone when its deadline
 *    passes, and a late "continue" still resumes it once.
 *  - Ending or deleting the conversation while the task is stopped
 *    discards the task for good: the prompt is withdrawn, the task ends,
 *    its run closes, and no later "continue" resumes it.
 *
 * Real wiring throughout: the controllers, the agent loop, the question
 * service, the real scheduled commands, the resumer, with the model scripted
 * at its HTTP and streaming boundaries and recording adapters standing in
 * for both channels.
 */
class CeilingPromptExpiryJourneyTest extends AssembledSystemTestCase
{
    use TaskBudgetJourneys;

    private const FINAL = 'All notes stored.';

    private const SWEEP = 'llm-client:resolve-expired-questions';

    private const ASKED_AT = '2026-10-12 09:00:00';

    private const JUST_BEFORE_DEADLINE = '2026-10-12 09:59:30';

    private const SWEPT_AT = '2026-10-12 10:01:00';

    private const ANSWERED_AT = '2026-10-12 17:30:00';

    private TaskBudgetText $text;

    /** Queue jobs that reached a worker since the listener was attached. */
    private int $jobsProcessed = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTaskBudgetJourney(self::ASKED_AT);
        config([
            'llm-client.agent_loop.max_iterations' => 2,
            'llm-client.task_budget.prompt_wait_minutes' => 60,
            'llm-client.conversation_lifecycle.idle_timeout_minutes' => 30,
            'llm-client.run_trace.abandonment_minutes' => 60,
        ]);
        $this->text = new TaskBudgetText();
        Event::listen(JobProcessing::class, function () {
            $this->jobsProcessed++;
        });
    }

    protected function tearDown(): void
    {
        $this->tearDownTaskBudgetJourney();
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Driving
    // ---------------------------------------------------------------

    private function at(string $instant): void
    {
        $this->travelTo(Carbon::parse($instant, 'UTC'));
    }

    private function sweep(): void
    {
        $this->assertSame(0, Artisan::call(self::SWEEP), 'the sweep succeeds');
    }

    /** A task paused at its two-step default, its prompt waiting for an hour. */
    private function pausedAtTheCeiling(string $path): array
    {
        $conversation = $this->conversationFor($path);
        if ($path === 'sync') {
            $this->scriptSteps(1, 2);
            $this->send('sync', $conversation, 'Store notes until you are done.')->assertStatus(202)->assertJson(['status' => 'awaiting_user']);
        } else {
            $this->send('stream', $conversation, 'Store notes until you are done.')->assertStatus(201);
            $this->playSteps(1, 2);
        }

        $prompt = $this->promptOf($conversation);
        $this->assertNotNull($prompt, 'precondition: the prompt is raised');
        $this->assertSame(QuestionState::Pending, $prompt->state);
        $this->assertSame('2026-10-12 10:00:00', $prompt->expires_at->copy()->utc()->format('Y-m-d H:i:s'), 'precondition: answerable for an hour');
        $this->assertSame(2, $this->modelRequests($path));
        $this->assertCount(2, $this->noteWrites);

        $conversation = Conversation::findOrFail($conversation->id);
        $task = $this->taskRowOf($conversation);
        $this->assertSame('paused', $task->state);
        $this->assertSame($prompt->id, $task->prompt_question_id);

        return [$conversation, $prompt, $this->latestRunIdOf($conversation), $task->id];
    }

    /** A typed reply: in the chat through POST agent (as Telegram sends it), on the web through POST message. */
    private function reply(string $path, Conversation $conversation, string $content): TestResponse
    {
        if ($path === 'sync') {
            return $this->actingAs($this->fixture()->user, 'api')->postJson('/api/clarion-app/llm-client/agent', ['message' => $content, 'channel' => 'telegram']);
        }

        return $this->actingAs($this->fixture()->user, 'api')->postJson('/api/clarion-app/llm-client/message', ['conversation_id' => $conversation->id, 'content' => $content]);
    }

    private function deliveredOn(string $path, Conversation $conversation): array
    {
        return $path === 'sync' ? $this->telegram->textsFor($conversation->id) : $this->web->textsFor($conversation->id);
    }

    /** @return list<array<string, mixed>> the payloads of the task's events of $kind, oldest first */
    private function events(string $taskId, string $kind): array
    {
        return DB::table('task_budget_events')->where('task_budget_id', $taskId)->where('kind', $kind)->orderBy('created_at')->get()
            ->map(fn ($row) => json_decode((string) $row->payload, true))->all();
    }

    /** What the model, the host API and the queue have seen so far, to prove nothing more happens. */
    private function activity(string $path): array
    {
        $pushed = 0;
        foreach (Queue::pushedJobs() as $jobs) {
            $pushed += count($jobs);
        }

        return [
            'model_requests' => $this->modelRequests($path),
            'note_writes' => count($this->noteWrites),
            'jobs_queued' => $pushed,
            'jobs_processed' => $this->jobsProcessed,
        ];
    }

    /** @return array<string, mixed> locks held in the cache right now, by name */
    private function heldLocks(): array
    {
        $store = Cache::getStore();
        $this->assertInstanceOf(ArrayStore::class, $store, 'precondition: the cache is inspectable');

        return array_filter($store->locks, fn (array $lock) => ($lock['expiresAt'] ?? null) === null || $lock['expiresAt']->isFuture());
    }

    /**
     * Discard every in-memory llm-client instance, as a process restart
     * does, keeping the container, its database connection and its
     * configuration (the durable store and the deployed configuration).
     */
    private function restart(): void
    {
        $property = new \ReflectionProperty($this->app, 'instances');
        $instances = $property->getValue($this->app);

        $forgotten = 0;
        foreach ($instances as $abstract => $instance) {
            $isPackageService = str_starts_with((string) $abstract, 'ClarionApp\\LlmClient\\')
                || (is_object($instance) && str_starts_with(get_class($instance), 'ClarionApp\\LlmClient\\'));
            if ($isPackageService) {
                $this->app->forgetInstance($abstract);
                $forgotten++;
            }
        }
        $this->app->forgetScopedInstances();
        Facade::clearResolvedInstances();

        $this->assertGreaterThan(0, $forgotten, 'precondition: the process held llm-client instances');

        $provider = $this->app->getProvider(LlmClientServiceProvider::class);
        $this->assertNotNull($provider, 'precondition: the package provider is registered');
        (function () {
            $this->registerProviders();
            $this->registerPresets();
            $this->registerAgentKinds();
            $this->registerAgentStartingPoints();
        })->call($provider);
    }

    /**
     * Nobody answers. Just before the deadline the sweep leaves the prompt
     * alone; just after it, the task stops — and nothing else happens.
     */
    private function letTheDeadlinePass(string $path, Conversation $conversation, AgentQuestion $prompt, ?string $runId, string $taskId): void
    {
        $this->at(self::JUST_BEFORE_DEADLINE);
        $this->sweep();
        $this->assertSame(QuestionState::Pending, $prompt->fresh()->state, 'still waiting inside its period');

        $before = $this->activity($path);
        $deliveredBefore = count($this->deliveredOn($path, $conversation));

        $this->at(self::SWEPT_AT);
        $this->sweep();

        $stopped = $prompt->fresh();
        $this->assertSame(QuestionState::Stopped, $stopped->state, 'an unanswered ceiling prompt stops the task at its deadline');
        $this->assertSame($conversation->id, $stopped->pending_conversation_id, 'the question slot is kept');
        $this->assertSame('2026-10-12 10:01:00', $stopped->stopped_at?->copy()->utc()->format('Y-m-d H:i:s'), 'within minutes of the deadline');
        $this->assertNull($stopped->resolved_at, 'stopping is not a resolution');
        $this->assertNull($stopped->resumed_at);

        // The user is told, once, on the conversation's channel.
        $this->assertSame([$this->text->expiredStopped()], array_slice($this->deliveredOn($path, $conversation), $deliveredBefore), 'the stop notice names this task\'s budget');
        $notices = Message::where('conversation_id', $conversation->id)->get()
            ->filter(fn (Message $m) => ($m->tool_data['kind'] ?? null) === AgentQuestionService::KIND_NOTICE && ($m->tool_data['question_id'] ?? null) === $prompt->id)
            ->values();
        $this->assertCount(1, $notices, 'the notice is kept in the conversation');
        $this->assertSame($this->text->expiredStopped(), $notices[0]->content);
        $this->assertSame('stopped', $notices[0]->tool_data['state'] ?? null);
        $this->assertSame('stopped', Message::findOrFail($prompt->display_message_id)->tool_data['state'] ?? null, 'the prompt is shown as stopped');

        $stops = $this->events($taskId, 'prompt_stopped');
        $this->assertCount(1, $stops, 'the stop is recorded on the task');
        $this->assertSame('expired', $stops[0]['reason'] ?? null);
        $this->assertSame($prompt->id, $stops[0]['question_id'] ?? null);
        $this->assertCount(0, $this->events($taskId, 'decision'), 'nobody decided anything');

        $task = DB::table('task_budgets')->where('id', $taskId)->first();
        $this->assertSame('paused', $task->state, 'the task is stopped, not ended');
        $this->assertSame($prompt->id, $task->prompt_question_id, 'and still waits on its prompt');
        if ($runId !== null) {
            $this->assertSame('in_progress', DB::table('agent_runs')->where('id', $runId)->value('end_state'), 'the run waits on its user');
        }
        $this->assertFalse((bool) Conversation::findOrFail($conversation->id)->is_processing);
        $this->assertSame($before, $this->activity($path), 'no model request, no tool call, nothing queued, no job run');
    }

    /**
     * While the task waits nothing is resident on its behalf, and nothing
     * that tidies up — a restart, the idle sweep, the abandoned-run sweep,
     * the expiry sweep again — changes it, however long it waits.
     */
    private function nothingChangesWhileItWaits(string $path, Conversation $conversation, AgentQuestion $prompt, ?string $runId, string $taskId): void
    {
        $before = $this->activity($path);
        $task = (array) DB::table('task_budgets')->where('id', $taskId)->first();
        $question = (array) DB::table('agent_questions')->where('id', $prompt->id)->first();

        $this->assertSame([], $this->heldLocks(), 'no lock is held while the task waits');
        $this->assertSame(0, Queue::pushed(ResumeAfterQuestionJob::class)->count(), 'no resume is queued');

        $this->restart();
        $this->travel(6)->hours();
        $this->assertSame(0, Artisan::call('llm-client:end-idle-conversations'));
        $this->assertSame(0, Artisan::call('llm-client:resolve-abandoned-runs'));
        $this->sweep();
        $this->restart();

        $this->assertSame($task, (array) DB::table('task_budgets')->where('id', $taskId)->first(), 'the task is untouched');
        $this->assertSame($question, (array) DB::table('agent_questions')->where('id', $prompt->id)->first(), 'the prompt is untouched');
        if ($runId !== null) {
            $this->assertSame('in_progress', DB::table('agent_runs')->where('id', $runId)->value('end_state'), 'the run is neither abandoned nor closed');
        }
        $this->assertNull(Conversation::findOrFail($conversation->id)->ended_at, 'the idle sweep does not end the conversation');
        $this->assertSame([], $this->heldLocks());
        $this->assertSame($before, $this->activity($path), 'nothing ran and nothing was queued');
    }

    /** A late continue, typed: the same run resumes once and finishes. */
    private function continueLate(string $path, Conversation $conversation): TestResponse
    {
        $this->at(self::ANSWERED_AT);
        if ($path === 'sync') {
            $this->script()->finalAnswer(self::FINAL);
        }
        $response = $this->reply($path, $conversation, 'continue');
        if ($path === 'stream') {
            $response->assertStatus(201);
            $this->playTurn(2, self::FINAL);
        } else {
            $response->assertStatus(200)->assertJson(['status' => 'completed', 'content' => self::FINAL]);
        }

        return $response;
    }

    /** @return list<string> the tool call ids answered in a captured request */
    private function answeredCallIds(CapturedPayload $payload): array
    {
        $messages = json_decode(json_encode($payload->messages), true);

        return array_values(array_filter(array_map(
            fn (array $m) => ($m['role'] ?? null) === 'tool' ? ($m['tool_call_id'] ?? null) : null,
            $messages,
        )));
    }

    private function lastAgentPayload(string $path): CapturedPayload
    {
        $payloads = $path === 'sync' ? $this->transport()->capturedChatPayloads() : $this->stream()->extractDispatchedJobs()->capturedPayloads();

        return $payloads[array_key_last($payloads)];
    }

    /** The task resumed once from where it paused, with the raised ceiling, and finished. */
    private function assertResumedOnceAndFinished(string $path, Conversation $conversation, AgentQuestion $prompt, ?string $runId, string $taskId): void
    {
        $this->assertSame(QuestionState::Answered, $prompt->fresh()->state);
        $this->assertNotNull($prompt->fresh()->resumed_at);
        $this->assertSame(3, $this->modelRequests($path), 'exactly one more model request');
        $this->assertCount(2, $this->noteWrites, 'nothing done before the pause is done again');
        foreach ([1, 2] as $n) {
            $this->assertContains("call_note_{$n}", $this->answeredCallIds($this->lastAgentPayload($path)), 'the continuation sees every stored result');
        }
        if ($runId !== null) {
            $this->assertSame(1, DB::table('agent_runs')->where('conversation_id', $conversation->id)->count(), 'the same run continued');
            $this->assertSame('completed', DB::table('agent_runs')->where('id', $runId)->value('end_state'));
        }
        $task = DB::table('task_budgets')->where('id', $taskId)->first();
        $this->assertSame('completed', $task->state);
        $this->assertSame(4, (int) $task->max_steps, 'raised by the original two steps');
        $this->assertSame(3, (int) $task->steps_used);
        $this->assertCount(1, $this->events($taskId, 'allowance_granted'));
        $this->assertFalse((bool) Conversation::findOrFail($conversation->id)->is_processing);
    }

    // ---------------------------------------------------------------
    // Unanswered, then a late continue
    // ---------------------------------------------------------------

    #[Test]
    #[DataProvider('paths')]
    public function an_unanswered_prompt_stops_the_task_and_a_late_continue_resumes_it_once(string $path): void
    {
        $this->scenario = 'ceiling_prompt_expiry_late_continue_'.$path;
        $this->entryPath = $path;
        [$conversation, $prompt, $runId, $taskId] = $this->pausedAtTheCeiling($path);

        $this->letTheDeadlinePass($path, $conversation, $prompt, $runId, $taskId);
        $this->nothingChangesWhileItWaits($path, $conversation, $prompt, $runId, $taskId);

        $this->continueLate($path, $conversation);
        $this->assertResumedOnceAndFinished($path, $conversation, $prompt, $runId, $taskId);

        // A second late continue is not taken: the prompt was decided.
        $again = app(InteractiveAnswerService::class)->record($prompt->id, (string) $this->fixture()->user->id, InteractiveAnswer::selection([1]), AnswerMethod::WebForm);
        $this->assertSame(InteractiveAnswerStatus::NotPending, $again->status);
        $this->sweep();
        $this->workQueuedResumes();
        $this->assertSame(3, $this->modelRequests($path), 'applied exactly once');
        $this->assertCount(1, $this->events($taskId, 'allowance_granted'));
    }

    #[Test]
    #[DataProvider('paths')]
    public function a_late_accept_closes_the_stopped_task_with_what_has_been_done(string $path): void
    {
        $this->scenario = 'ceiling_prompt_expiry_late_accept_'.$path;
        $this->entryPath = $path;
        [$conversation, $prompt, $runId, $taskId] = $this->pausedAtTheCeiling($path);
        $this->letTheDeadlinePass($path, $conversation, $prompt, $runId, $taskId);

        $this->at(self::ANSWERED_AT);
        $response = $this->reply($path, $conversation, 'accept');

        $report = Message::where('conversation_id', $conversation->id)->get()
            ->first(fn (Message $m) => ($m->tool_data['kind'] ?? null) === AgentQuestionService::KIND_TASK_BUDGET_REPORT);
        $this->assertNotNull($report, 'the report is in the conversation');
        if ($path === 'sync') {
            $response->assertStatus(200)->assertJson(['status' => 'completed', 'content' => $report->content]);
        } else {
            $response->assertStatus(201);
        }
        $task = DB::table('task_budgets')->where('id', $taskId)->first();
        $this->assertSame('concluded', $task->state);
        $this->assertSame($this->text->concludedReason(), $task->end_reason);
        $run = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertSame('completed', $run->end_state);
        $this->assertSame($this->text->concludedReason(), $run->end_reason);
        $this->assertSame(2, $this->modelRequests($path), 'no further step');
        $this->assertSame(QuestionState::Answered, $prompt->fresh()->state);
    }

    // ---------------------------------------------------------------
    // Stopped by the user before the deadline
    // ---------------------------------------------------------------

    #[Test]
    #[DataProvider('paths')]
    public function a_prompt_the_user_stopped_is_left_alone_at_its_deadline_and_a_late_continue_resumes_it_once(string $path): void
    {
        $this->scenario = 'ceiling_prompt_expiry_user_stopped_'.$path;
        $this->entryPath = $path;
        [$conversation, $prompt, $runId, $taskId] = $this->pausedAtTheCeiling($path);

        $this->at('2026-10-12 09:20:00');
        $stop = $this->reply($path, $conversation, 'stop');
        $path === 'sync' ? $stop->assertStatus(202)->assertJson(['status' => 'awaiting_user', 'content' => '']) : $stop->assertStatus(201);
        $this->assertSame(QuestionState::Stopped, $prompt->fresh()->state);
        $this->assertSame($conversation->id, $prompt->fresh()->pending_conversation_id, 'the stop keeps the question slot');
        $delivered = $this->deliveredOn($path, $conversation);
        $before = $this->activity($path);

        // The deadline passes: the sweep has nothing to stop.
        $this->at(self::SWEPT_AT);
        $this->sweep();
        $this->assertSame(QuestionState::Stopped, $prompt->fresh()->state);
        $this->assertSame($delivered, $this->deliveredOn($path, $conversation), 'no second notice');
        $this->assertCount(0, $this->events($taskId, 'prompt_stopped'), 'the user\'s own stop is the only one');
        $this->assertSame('paused', DB::table('task_budgets')->where('id', $taskId)->value('state'));
        $this->assertSame($before, $this->activity($path));

        $this->continueLate($path, $conversation);
        $this->assertResumedOnceAndFinished($path, $conversation, $prompt, $runId, $taskId);
    }

    // ---------------------------------------------------------------
    // Ending or deleting the conversation discards the stopped task
    // ---------------------------------------------------------------

    /** @return array<string, array{0: string, 1: string}> */
    public static function discards(): array
    {
        return [
            'sync, ended' => ['sync', 'end'],
            'sync, deleted' => ['sync', 'delete'],
            'stream, ended' => ['stream', 'end'],
            'stream, deleted' => ['stream', 'delete'],
        ];
    }

    #[Test]
    #[DataProvider('discards')]
    public function ending_or_deleting_the_conversation_discards_the_stopped_task_for_good(string $path, string $how): void
    {
        $this->scenario = 'ceiling_prompt_expiry_discard_'.$how.'_'.$path;
        $this->entryPath = $path;
        [$conversation, $prompt, $runId, $taskId] = $this->pausedAtTheCeiling($path);
        $this->letTheDeadlinePass($path, $conversation, $prompt, $runId, $taskId);

        $this->at(self::ANSWERED_AT);
        $user = $this->fixture()->user;
        if ($how === 'end') {
            $this->actingAs($user, 'api')->postJson("/api/clarion-app/llm-client/conversation/{$conversation->id}/end")->assertStatus(200);
        } else {
            $this->actingAs($user, 'api')->deleteJson("/api/clarion-app/llm-client/conversation/{$conversation->id}")->assertStatus(204);
            $this->assertNull(Conversation::find($conversation->id), 'precondition: the conversation is gone');
        }
        $this->workQueuedResumes();

        $withdrawn = $prompt->fresh();
        $this->assertSame(QuestionState::Withdrawn, $withdrawn->state, 'the prompt is withdrawn');
        $this->assertNull($withdrawn->pending_conversation_id);
        $task = DB::table('task_budgets')->where('id', $taskId)->first();
        $this->assertSame('ended', $task->state, 'the task is discarded');
        $this->assertSame('discarded', $task->end_reason);
        $this->assertNotNull($task->ended_at);
        $run = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertSame('stopped_early', $run->end_state);
        $this->assertSame(QuestionResumer::DISCARDED_REASON, $run->end_reason, 'the run closes with the existing discarded reason');
        $this->assertCount(1, $this->events($taskId, 'prompt_withdrawn'));

        // A later continue never resumes it.
        $later = app(InteractiveAnswerService::class)->record($prompt->id, (string) $user->id, InteractiveAnswer::selection([1]), AnswerMethod::WebForm);
        $this->assertSame(InteractiveAnswerStatus::NotPending, $later->status);
        $this->sweep();
        $this->workQueuedResumes();
        $this->assertSame(2, $this->modelRequests($path), 'the discarded task never runs again');
        $this->assertSame('ended', DB::table('task_budgets')->where('id', $taskId)->value('state'));
        $this->assertCount(0, $this->events($taskId, 'allowance_granted'));
    }
}
