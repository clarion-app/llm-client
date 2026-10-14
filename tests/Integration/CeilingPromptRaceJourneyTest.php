<?php

namespace Tests\Integration;

use ClarionApp\LlmClient\Controllers\ConversationController;
use ClarionApp\LlmClient\Controllers\QuestionController;
use ClarionApp\LlmClient\Jobs\ResumeAfterQuestionJob;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\AgentQuestionService;
use ClarionApp\LlmClient\Services\InteractiveAnswerService;
use ClarionApp\LlmClient\Services\QuestionResumer;
use ClarionApp\LlmClient\Services\TaskBudgetResumer;
use ClarionApp\LlmClient\Services\TaskBudgetText;
use ClarionApp\LlmClient\ValueObjects\AnswerMethod;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswer;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswerStatus;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use ClarionApp\LlmClient\ValueObjects\ResumeCaller;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\Harness\TaskBudgetJourneys;

/**
 * Everything that can decide a ceiling prompt races everything else, and
 * exactly one outcome takes effect.
 *
 * A web conversation's task is paused at its one-step ceiling with the
 * prompt waiting. Two of these then race: the user's decisions through the
 * web form (continue, accept, stop), the every-minute expiry sweep, ending
 * the conversation, and — for a decision recorded by a process that died
 * before resuming it — the re-drive sweep's resume against the resume that
 * was queued when the decision was recorded. One of the two is started
 * through its real entry point; the other arrives at one of three moments
 * of the first one's progress:
 *
 * - before the first one's compare-and-set on the prompt — it has read the
 *   prompt as waiting, and the other decides it first;
 * - between that compare-and-set (for a decision that resumes the task:
 *   the one-time resume claim) and the update of the task;
 * - after the task was updated (for a stop, which never updates the task:
 *   after the stop is complete).
 *
 * Every trial ends with exactly one outcome — the task continued once,
 * concluded, stopped, or discarded — never two, with at most one decision
 * applied, at most one allowance granted, at most one continuation, and
 * what the user is told matching what happened. The decision that lost is
 * refused, never applied.
 *
 * Separately, a resume that reaches the task with an earlier, already
 * applied decision — after the task paused again on a later prompt — never
 * applies it: the task only moves on the decision of the prompt it is
 * paused on.
 *
 * Real wiring throughout, with the model scripted at the streaming boundary
 * and a recording adapter standing in for the web broadcast. An interloper
 * runs the code its request or tick would run (the controller action, the
 * sweep command, the queued job), invoked from a database or model event at
 * the moment being tested, once the first one's transaction has committed.
 */
class CeilingPromptRaceJourneyTest extends AssembledSystemTestCase
{
    use TaskBudgetJourneys;

    private const API = '/api/clarion-app/llm-client';

    private const SWEEP = 'llm-client:resolve-expired-questions';

    private const TRIALS = 20;

    private const CHOICE = ['continue' => 1, 'accept' => 2, 'stop' => 3];

    private TaskBudgetText $text;

    /** The armed interleaving: which moment, of which prompt and task, what runs then. */
    private ?string $hookBoundary = null;

    private ?string $hookQuestionId = null;

    private ?string $hookTaskId = null;

    private ?\Closure $interloper = null;

    private bool $hookFired = false;

    private ?int $interloperStatus = null;

    /** Which of the prompt's updates counts as the first operation's compare-and-set. */
    private string $casPattern = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTaskBudgetJourney();

        $this->scenario = 'ceiling_prompt_races';
        $this->entryPath = 'stream';
        config([
            'llm-client.agent_loop.max_iterations' => 1,
            'llm-client.agent_questions.min_expiry_minutes' => 1,
            'llm-client.task_budget.prompt_wait_minutes' => 1,
            'llm-client.agent_questions.resume_grace_seconds' => 60,
        ]);
        $this->text = new TaskBudgetText();

        // Before the compare-and-set: the first one has read the prompt as
        // a model and has not yet tried to change it.
        Event::listen('eloquent.retrieved: '.AgentQuestion::class, function (AgentQuestion $question) {
            if ($this->hookBoundary === 'before_cas' && $question->id === $this->hookQuestionId) {
                $this->fireInterloper();
            }
        });

        DB::listen(function (QueryExecuted $query) {
            if ($this->hookBoundary === 'between_cas_and_task_cas'
                && preg_match($this->casPattern, $query->sql) === 1
                && in_array($this->hookQuestionId, $query->bindings, true)
            ) {
                DB::afterCommit(fn () => $this->fireInterloper());
            }
            if ($this->hookBoundary === 'after_task_cas'
                && preg_match('/^update "task_budgets" set (?:(?! where ).)*"state" = \?/', $query->sql) === 1
                && in_array($this->hookTaskId, $query->bindings, true)
            ) {
                DB::afterCommit(fn () => $this->fireInterloper());
            }
        });

        $this->fixture()->build();
    }

    protected function tearDown(): void
    {
        $this->tearDownTaskBudgetJourney();
        parent::tearDown();
    }

    private function fireInterloper(): void
    {
        if ($this->hookFired || $this->interloper === null) {
            return;
        }

        $this->hookFired = true;
        $this->interloperStatus = ($this->interloper)();
    }

    private function arm(string $boundary, string $first, AgentQuestion $prompt, string $taskId, \Closure $interloper): void
    {
        $this->hookBoundary = $boundary;
        $this->hookQuestionId = $prompt->id;
        $this->hookTaskId = $taskId;
        $this->interloper = $interloper;
        $this->hookFired = false;
        $this->interloperStatus = null;
        // A decision that resumes the task is past its compare-and-set once
        // its one-time resume claim is taken; a stop or an expiry once the
        // prompt's state has changed.
        $this->casPattern = in_array($first, ['continue', 'accept', 'end', 'redrive'], true)
            ? '/^update "agent_questions" set .*"resumed_at" = \?/'
            : '/^update "agent_questions" set (?:(?! where ).)*"state" = \?/';
    }

    private function disarm(): void
    {
        $this->hookBoundary = null;
        $this->hookQuestionId = null;
        $this->hookTaskId = null;
        $this->interloper = null;
    }

    // ---------------------------------------------------------------
    // Driving the system
    // ---------------------------------------------------------------

    private function startTrial(int $trial): void
    {
        $this->travelTo(Carbon::parse('2026-10-12 09:00:00', 'UTC')->addMinutes(10 * $trial));
    }

    private function newConversation(): Conversation
    {
        return Conversation::create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->fixture()->user->id,
            'server_id' => $this->fixture()->server->id,
            'model' => 'gpt-4',
            'title' => 'Race conversation',
            'channel' => 'web',
        ]);
    }

    /** @return list<int> the streamed request slots dispatched for $conversation, in order */
    private function slotsFor(Conversation $conversation): array
    {
        $data = $this->stream()->extractDispatchedJobs()->capturedData();

        return array_keys(array_filter($data, fn (array $d) => ($d['conversation_id'] ?? null) === $conversation->id));
    }

    private function play(int $slot, array $calls, string $text = ''): void
    {
        $stream = $this->stream();
        $stream->extractDispatchedJobs();
        $stream->setCurrentSlot($slot);
        $stream->emit($this->turnChunks($text, $calls));
        $stream->finish();
    }

    /** A request whose one step stores a note: the task is then paused at its one-step ceiling, the prompt waiting. */
    private function pausedAtTheCeiling(Conversation $conversation): array
    {
        $this->actingAs($this->fixture()->user, 'api')->postJson(self::API.'/message', [
            'conversation_id' => $conversation->id,
            'content' => 'Store notes until you are done.',
        ])->assertStatus(201);
        $slots = $this->slotsFor($conversation);
        $this->assertCount(1, $slots, 'precondition: one streamed request');
        $this->play($slots[0], $this->stepCalls(1));

        $prompt = $this->promptOf($conversation);
        $this->assertNotNull($prompt, 'precondition: the prompt is raised');
        $this->assertSame(QuestionState::Pending, $prompt->state);
        $task = $this->taskRowOf($conversation);
        $this->assertSame('paused', $task->state);
        $this->assertSame($prompt->id, $task->prompt_question_id);

        return [$prompt, $task->id, $this->latestRunIdOf($conversation)];
    }

    private function decisionBody(string $decision): string
    {
        return json_encode(['selected' => [self::CHOICE[$decision]]]);
    }

    /** Through the operation's real entry point, as its own request or tick. Returns the response status. */
    private function viaEntryPoint(string $operation, Conversation $conversation, AgentQuestion $prompt): int
    {
        $user = $this->fixture()->user;

        return match ($operation) {
            'continue', 'accept', 'stop' => $this->actingAs($user, 'api')
                ->postJson(self::API."/question/{$prompt->id}/answer", ['selected' => [self::CHOICE[$operation]]])
                ->status(),
            'end' => $this->actingAs($user, 'api')->postJson(self::API."/conversation/{$conversation->id}/end")->status(),
            'expire' => Artisan::call(self::SWEEP) === 0 ? 200 : 500,
        };
    }

    /** From inside another operation's progress, running the code its own request or tick would run. */
    private function asInterloper(string $operation, Conversation $conversation, AgentQuestion $prompt): int
    {
        $this->actingAs($this->fixture()->user, 'api');

        return match ($operation) {
            'continue', 'accept', 'stop' => $this->app->make(QuestionController::class)->answer(
                Request::create(self::API."/question/{$prompt->id}/answer", 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $this->decisionBody($operation)),
                $prompt->id,
            )->getStatusCode(),
            'end' => $this->app->call([$this->app->make(ConversationController::class), 'end'], ['id' => $conversation->id])->getStatusCode(),
            'expire' => Artisan::call(self::SWEEP) === 0 ? 200 : 500,
        };
    }

    /** @return list<array<string, mixed>> the payloads of the task's events of $kind */
    private function events(string $taskId, string $kind): array
    {
        return DB::table('task_budget_events')->where('task_budget_id', $taskId)->where('kind', $kind)->orderBy('created_at')->get()
            ->map(fn ($row) => json_decode((string) $row->payload, true))->all();
    }

    // ---------------------------------------------------------------
    // One outcome
    // ---------------------------------------------------------------

    /**
     * @param  string  $outcome  continued | concluded | stopped | discarded
     * @param  string|null  $stoppedBy  for a stopped task: stop | expire
     * @param  bool  $expiryNoticed  whether the sweep stopped the prompt before it was decided
     */
    private function assertOneOutcome(string $outcome, ?string $stoppedBy, bool $expiryNoticed, Conversation $conversation, AgentQuestion $prompt, string $taskId, ?string $runId, string $label): void
    {
        $fresh = AgentQuestion::findOrFail($prompt->id);
        $task = DB::table('task_budgets')->where('id', $taskId)->first();
        $run = DB::table('agent_runs')->where('id', $runId)->first();
        $continuations = array_values(array_filter(
            array_slice($this->slotsFor($conversation), 1),
            fn (int $slot) => ($this->stream()->capturedData()[$slot]['run_id'] ?? null) === $runId,
        ));
        $display = Message::findOrFail($prompt->display_message_id)->content;
        $delivered = $this->web->textsFor($conversation->id);

        // Never two outcomes.
        $this->assertLessThanOrEqual(1, count($this->events($taskId, 'decision')), "{$label}: at most one decision is applied");
        $this->assertLessThanOrEqual(1, count($this->events($taskId, 'allowance_granted')), "{$label}: at most one allowance is granted");
        $this->assertLessThanOrEqual(1, count($continuations), "{$label}: at most one continuation");

        switch ($outcome) {
            case 'continued':
                if ($run->cancelled_at !== null && $continuations === []) {
                    // The losing end still cancelled the run before the
                    // continuation started; cancellation wins: the
                    // decision stands, but the run is closed, not continued.
                    $this->assertSame(QuestionState::Answered, $fresh->state, "{$label}: the prompt is answered");
                    $this->assertCount(1, $this->events($taskId, 'allowance_granted'), "{$label}: one allowance granted");
                    $this->assertSame('continue', $this->events($taskId, 'decision')[0]['decision'] ?? null);
                    $this->assertSame('stopped_early', $run->end_state, "{$label}: the cancelled run is closed");
                    $this->assertSame(app(\ClarionApp\LlmClient\Services\TaskResumeText::class)->cancelledReason(), $run->end_reason);
                    $this->assertSame(1, (int) $task->steps_used, "{$label}: no step is taken after the cancellation");
                    $this->assertFalse((bool) $conversation->fresh()?->is_processing, "{$label}: the conversation is released");
                    break;
                }
                $this->assertSame(QuestionState::Answered, $fresh->state, "{$label}: the prompt is answered");
                $this->assertNotNull($fresh->resumed_at, "{$label}: and resumed");
                $this->assertNull($fresh->pending_conversation_id);
                $this->assertSame('running', $task->state, "{$label}: the task continues");
                $this->assertNull($task->prompt_question_id);
                $this->assertSame(2, (int) $task->max_steps, "{$label}: raised once, by the original one step");
                $this->assertSame(2, (int) $task->steps_used, "{$label}: the continuation took one step");
                $this->assertCount(1, $this->events($taskId, 'allowance_granted'), "{$label}: one allowance granted");
                $this->assertSame('continue', $this->events($taskId, 'decision')[0]['decision'] ?? null);
                $this->assertCount(1, $continuations, "{$label}: the run is resumed exactly once");
                $this->assertSame('in_progress', $run->end_state, "{$label}: the resumed run carries on");
                $this->assertCount(0, $this->events($taskId, 'prompt_withdrawn'), "{$label}: never both continued and discarded");
                $this->assertSame($expiryNoticed ? [$display, $this->text->expiredStopped()] : [$display], $delivered, "{$label}: what the user was told");
                $this->assertCount($expiryNoticed ? 1 : 0, $this->events($taskId, 'prompt_stopped'));
                $this->assertTrue((bool) $conversation->fresh()?->is_processing, "{$label}: the conversation is processing the continuation");
                break;

            case 'concluded':
                $this->assertSame(QuestionState::Answered, $fresh->state);
                $this->assertSame('concluded', $task->state, "{$label}: the task is concluded");
                $this->assertSame('completed', $run->end_state);
                $this->assertSame($this->text->concludedReason(), $run->end_reason);
                $this->assertCount(0, $continuations, "{$label}: nothing continues");
                $this->assertCount(0, $this->events($taskId, 'allowance_granted'));
                $this->assertSame('accept', $this->events($taskId, 'decision')[0]['decision'] ?? null);
                $reports = Message::where('conversation_id', $conversation->id)->get()
                    ->filter(fn (Message $m) => ($m->tool_data['kind'] ?? null) === AgentQuestionService::KIND_TASK_BUDGET_REPORT);
                $this->assertCount(1, $reports, "{$label}: one report");
                $this->assertSame([$display], $delivered);
                break;

            case 'stopped':
                $this->assertSame(QuestionState::Stopped, $fresh->state, "{$label}: the prompt is stopped");
                $this->assertSame($conversation->id, $fresh->pending_conversation_id, "{$label}: the slot is kept");
                $this->assertNull($fresh->resolved_at);
                $this->assertNull($fresh->resumed_at);
                $this->assertNull($fresh->answer_raw, "{$label}: no decision is recorded as an answer");
                $this->assertSame('paused', $task->state, "{$label}: the task waits");
                $this->assertSame($prompt->id, $task->prompt_question_id);
                $this->assertSame('in_progress', $run->end_state);
                $this->assertCount(0, $continuations);
                $this->assertCount(0, $this->events($taskId, 'allowance_granted'));
                $notice = $stoppedBy === 'stop' ? $this->text->stoppedByChoice() : $this->text->expiredStopped();
                $this->assertSame([$display, $notice], $delivered, "{$label}: one notice, the winner's");
                $this->assertCount($stoppedBy === 'stop' ? 1 : 0, $this->events($taskId, 'decision'));
                $this->assertCount($stoppedBy === 'expire' ? 1 : 0, $this->events($taskId, 'prompt_stopped'));
                $this->assertFalse((bool) $conversation->fresh()->is_processing);
                break;

            case 'discarded':
                $this->assertSame(QuestionState::Withdrawn, $fresh->state, "{$label}: the prompt is withdrawn");
                $this->assertSame('ended', $task->state, "{$label}: the task is discarded");
                $this->assertSame('discarded', $task->end_reason);
                $this->assertSame('stopped_early', $run->end_state, "{$label}: the run is closed");
                $this->assertSame(QuestionResumer::WITHDRAWN_REASON, $run->end_reason);
                $this->assertCount(0, $continuations, "{$label}: never both discarded and continued");
                $this->assertCount(0, $this->events($taskId, 'allowance_granted'));
                $this->assertCount(0, $this->events($taskId, 'decision'));
                $this->assertCount(1, $this->events($taskId, 'prompt_withdrawn'));
                $this->assertNull($fresh->answer_raw);
                break;

            default:
                $this->fail("unknown outcome {$outcome}");
        }
    }

    // ---------------------------------------------------------------
    // Pairwise races
    // ---------------------------------------------------------------

    /** @return array<string, array{string, string, string}> */
    public static function races(): array
    {
        $rows = [];
        foreach ([['continue', 'expire'], ['stop', 'expire'], ['continue', 'continue'], ['continue', 'accept'], ['continue', 'end']] as [$a, $b]) {
            foreach (['before_cas', 'between_cas_and_task_cas', 'after_task_cas'] as $boundary) {
                $rows["{$a} vs {$b}, {$boundary}"] = [$a, $b, $boundary];
            }
        }

        return $rows;
    }

    /**
     * The outcome of a race, from which operation changed the prompt first.
     *
     * @return array{0: string, 1: ?string, 2: bool} outcome, who stopped it, whether the expiry was noticed first
     */
    private static function expectedOutcome(string $first, string $second, string $decidedFirst): array
    {
        $pair = [$first, $second];
        sort($pair);

        return match ($pair) {
            // A decision is taken from a stopped prompt too: it always applies.
            ['continue', 'expire'] => ['continued', null, $decidedFirst === 'expire'],
            ['expire', 'stop'] => ['stopped', $decidedFirst, false],
            ['continue', 'continue'] => ['continued', null, false],
            ['accept', 'continue'] => [$decidedFirst === 'accept' ? 'concluded' : 'continued', null, false],
            ['continue', 'end'] => [$decidedFirst === 'end' ? 'discarded' : 'continued', null, false],
        };
    }

    #[Test]
    #[DataProvider('races')]
    public function exactly_one_outcome_takes_effect_and_matches_what_the_user_is_told(string $a, string $b, string $boundary): void
    {
        for ($trial = 0; $trial < self::TRIALS; $trial++) {
            // Alternate which operation starts first.
            [$first, $second] = $trial % 2 === 0 ? [$a, $b] : [$b, $a];
            $label = "trial {$trial}: {$second} arriving {$boundary} of {$first}";

            $this->startTrial($trial);
            $conversation = $this->newConversation();
            [$prompt, $taskId, $runId] = $this->pausedAtTheCeiling($conversation);

            // Past the prompt's answerable period, not yet swept: every
            // operation is possible.
            $this->travel(2)->minutes();

            // A stop never updates the task, so "after the task was
            // updated" is after the stop or expiry is complete.
            $sequential = $boundary === 'after_task_cas' && in_array($first, ['stop', 'expire'], true);
            if ($sequential) {
                $firstStatus = $this->viaEntryPoint($first, $conversation, $prompt);
                $secondStatus = $this->viaEntryPoint($second, $conversation, $prompt);
            } else {
                $this->arm($boundary, $first, $prompt, $taskId, fn () => $this->asInterloper($second, $conversation, $prompt));
                $firstStatus = $this->viaEntryPoint($first, $conversation, $prompt);
                $this->assertTrue($this->hookFired, "{$label}: the {$second} must have arrived {$boundary} of the {$first}");
                $secondStatus = $this->interloperStatus;
                $this->disarm();
            }
            $this->workQueuedResumes();

            $decidedFirst = $boundary === 'before_cas' ? $second : $first;
            [$outcome, $stoppedBy, $expiryNoticed] = self::expectedOutcome($first, $second, $decidedFirst);
            $this->assertOneOutcome($outcome, $stoppedBy, $expiryNoticed, $conversation, $prompt, $taskId, $runId, $label);

            // A decision that lost is refused, never applied.
            // Before the compare-and-set the arriving operation decides
            // first; otherwise the one that started does.
            $decidedSecond = $boundary === 'before_cas' ? $first : $second;
            $losingStatus = $boundary === 'before_cas' ? $firstStatus : $secondStatus;
            if (in_array($decidedSecond, ['continue', 'accept', 'stop'], true) && !($decidedSecond === 'continue' && $decidedFirst === 'expire')) {
                $this->assertContains($losingStatus, [409, 422], "{$label}: the {$decidedSecond} that lost is refused");
            }
        }
    }

    // ---------------------------------------------------------------
    // A decision re-driven after a crash
    // ---------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function boundaries(): array
    {
        return [
            'before the claim' => ['before_cas'],
            'between the claim and the task update' => ['between_cas_and_task_cas'],
            'after the task update' => ['after_task_cas'],
        ];
    }

    /**
     * The user's continue was recorded and its resume queued, but the
     * worker that would have run it died. Once the grace period has passed
     * the sweep re-drives it; then the original job is delivered again, at
     * the same time as the re-driven one runs. The decision applies once.
     */
    #[Test]
    #[DataProvider('boundaries')]
    public function a_decision_re_driven_after_a_crash_applies_once(string $boundary): void
    {
        for ($trial = 0; $trial < self::TRIALS; $trial++) {
            $label = "trial {$trial}: the original resume arriving {$boundary} of the re-driven one";
            $this->startTrial($trial);
            $conversation = $this->newConversation();
            [$prompt, $taskId, $runId] = $this->pausedAtTheCeiling($conversation);

            $recorded = app(InteractiveAnswerService::class)->submit($prompt->id, (string) $this->fixture()->user->id, InteractiveAnswer::selection([1]), AnswerMethod::MessagingOption);
            $this->assertSame(InteractiveAnswerStatus::Answered, $recorded->status, "{$label}: precondition — the decision is recorded");
            $original = $this->pendingResumeFor($prompt);
            $this->assertNotNull($original, "{$label}: precondition — its resume was queued");
            $this->workedResumes[spl_object_id($original)] = true;

            $this->travel(2)->minutes();
            $this->assertSame(0, Artisan::call(self::SWEEP));
            $redriven = $this->pendingResumeFor($prompt);
            $this->assertNotNull($redriven, "{$label}: precondition — the sweep re-drove the unresumed decision");
            $this->workedResumes[spl_object_id($redriven)] = true;

            $work = fn (ResumeAfterQuestionJob $job) => $this->app->call([$job, 'handle']);
            if ($boundary === 'before_cas') {
                $work($original);
                $work($redriven);
            } else {
                $this->arm($boundary, 'redrive', $prompt, $taskId, function () use ($work, $original) {
                    $work($original);

                    return 200;
                });
                $work($redriven);
                $this->assertTrue($this->hookFired, "{$label}: the original resume must have arrived {$boundary} of the re-driven one");
                $this->disarm();
            }

            $this->assertOneOutcome('continued', null, false, $conversation, $prompt, $taskId, $runId, $label);
        }
    }

    private function pendingResumeFor(AgentQuestion $prompt): ?ResumeAfterQuestionJob
    {
        foreach (Queue::pushed(ResumeAfterQuestionJob::class)->all() as $job) {
            if ($job->questionId === $prompt->id && !isset($this->workedResumes[spl_object_id($job)])) {
                return $job;
            }
        }

        return null;
    }

    // ---------------------------------------------------------------
    // A stale decision
    // ---------------------------------------------------------------

    #[Test]
    public function a_stale_resume_of_an_earlier_decision_never_applies_to_the_task_paused_again_on_a_later_prompt(): void
    {
        $conversation = $this->newConversation();
        [$first, $taskId, $runId] = $this->pausedAtTheCeiling($conversation);

        // Continue: one more step is granted, taken, and the task pauses again.
        $this->viaEntryPoint('continue', $conversation, $first);
        $slots = $this->slotsFor($conversation);
        $this->assertCount(2, $slots, 'precondition: the continuation was dispatched');
        $this->play($slots[1], $this->stepCalls(2));

        $second = $this->promptOf($conversation);
        $this->assertNotSame($first->id, $second->id, 'precondition: a later prompt');
        $this->assertSame(QuestionState::Pending, $second->state);
        $before = (array) DB::table('task_budgets')->where('id', $taskId)->first();
        $this->assertSame('paused', $before['state']);
        $this->assertSame($second->id, $before['prompt_question_id']);
        $this->assertSame(2, (int) $before['max_steps']);
        $events = DB::table('task_budget_events')->where('task_budget_id', $taskId)->count();

        // The earlier decision reaches the task again — as a worker that
        // had taken its claim before stalling would bring it.
        $this->app->forgetScopedInstances();
        $result = app(TaskBudgetResumer::class)->resume($first->fresh(), $conversation->fresh(), ResumeCaller::HttpRequest);

        $this->assertNull($result, 'the stale decision is not applied');
        $this->assertSame($before, (array) DB::table('task_budgets')->where('id', $taskId)->first(), 'the task still waits on the later prompt, its ceiling unchanged');
        $this->assertSame($events, DB::table('task_budget_events')->where('task_budget_id', $taskId)->count(), 'nothing is recorded');
        $this->assertCount(2, $this->slotsFor($conversation), 'nothing runs');
        $this->assertSame(QuestionState::Pending, $second->fresh()->state, 'the later prompt still waits for its decision');
        $this->assertSame('in_progress', DB::table('agent_runs')->where('id', $runId)->value('end_state'));
    }
}
