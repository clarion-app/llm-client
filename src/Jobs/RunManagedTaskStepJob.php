<?php

namespace ClarionApp\LlmClient\Jobs;

use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\ManagedTask;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\ManagerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * 103-manager-agent (US1, data-model.md §9, research.md D6).
 *
 * The chain-of-short-lived-jobs shape research.md D6 chose over one
 * long-held call: each invocation performs exactly ONE bounded
 * AgentLoopService::run() continuation on the manager's own conversation
 * (max_iterations capped to config('llm-client.manager.step_max_iterations'),
 * not the ordinary agent_loop ceiling), lets that turn's own tool calls
 * (plan_parts/assign_part this phase; accept_part/report_shortfall/
 * finalize_task in later phases) drive ManagerService writes, then either
 * re-dispatches a fresh instance of itself for the same task (still
 * in_progress) or leaves it alone (the model called finalize_task and the
 * task reached a terminal status -- contracts/manager-agent-meta-tools.md
 * §5's "no further tool calls dispatched once terminal").
 *
 * Idempotency guard: a task already terminal by the time handle() runs
 * (e.g. two deliveries of the same dispatch racing, or a
 * ResolveStalledManagedTasksCommand sweep force-finalizing it first) is a
 * clean no-op -- mirrors RunDelegationBatchMemberJob's own
 * no-op-on-non-eligible-status shape.
 *
 * The round/wall-clock ceiling check BEFORE starting a step (research.md
 * D5/D7's forced finalize, tasks.md T050) runs first in handle() below:
 * if ManagedTask.rounds_used has already reached round_ceiling, or the
 * task's own wall-clock max_seconds bound (measured from started_at) has
 * already been exceeded, ManagerService::finalizeWithShortfall() runs
 * directly and AgentLoopService::run() is never called for that
 * invocation -- the model is never given a chance to keep requesting
 * rounds past the bound (contracts/manager-agent-meta-tools.md §6). The
 * crash-recovery sweep itself (ResolveStalledManagedTasksCommand) is a
 * separate class, T051.
 *
 * $tries = 1 (110-delegation-deadlock-timeout, research.md D5, tasks.md
 * T044): a worker-level failure thrown out of handle() (e.g. a nested
 * AgentLoopService::run() call timing out mid-delegation) must fail this
 * job PERMANENTLY on its first attempt, never be silently redelivered by
 * Laravel's own attempts/tries bookkeeping into re-running the same step
 * of the same managed task -- which would replay the exact delegation
 * calls the depth/chain-time bounds (FR-003/FR-004) may have already
 * refused once (FR-012).
 *
 * Deliberately NO retryUntil() override here, unlike
 * RunDelegationBatchMemberJob's own $tries = 1 + retryUntil() pairing.
 * That job needs retryUntil() only because it ALSO calls $this->release()
 * itself (deliberately, for admission-race retries) and needs Laravel's
 * attempts()/tries bookkeeping bypassed so that deliberate release() can
 * genuinely retry admission on a real queue connection -- see that
 * class's own docblock for the full mechanism. This job never calls
 * release(); it only ever completes normally or re-dispatches itself
 * once still in_progress (never itself redelivered). Adding a
 * retryUntil() override here would be actively counterproductive:
 * Worker::markJobAsFailedIfWillExceedMaxAttempts() only fails a job on
 * its `attempts() >= maxTries` branch when `retryUntil()` is unset -- a
 * future retryUntil() suppresses that branch entirely and instead lets
 * Laravel's own exception handler silently release()-with-backoff an
 * exception this job never wants retried (exactly the FR-012 failure
 * mode), while a retryUntil() already in the past risks failing the job
 * via Worker::markJobAsFailedIfAlreadyExceedsMaxAttempts() before
 * handle() ever runs at all. Plain $tries = 1 alone -- with no
 * retryUntil() -- gives exactly the needed "fail on first exception,
 * never redeliver" semantics for a job with no release()-based retry
 * path of its own.
 */
class RunManagedTaskStepJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const TERMINAL_STATUSES = ['completed', 'completed_with_shortfalls', 'failed'];

    public int $tries = 1;

    public function __construct(public readonly string $managedTaskId)
    {
    }

    public function handle(AgentLoopService $agentLoopService): void
    {
        $task = ManagedTask::find($this->managedTaskId);
        if ($task === null || in_array($task->status, self::TERMINAL_STATUSES, true)) {
            return;
        }

        $conversation = Conversation::find($task->conversation_id);
        if ($conversation === null) {
            return;
        }

        // research.md D5/D7, tasks.md T050: checked BEFORE calling
        // AgentLoopService::run() at all -- a task that has already
        // reached either bound is force-finalized directly, never given
        // another turn.
        $roundCeilingReached = $task->rounds_used >= $task->round_ceiling;
        $wallClockCeilingReached = $task->started_at->diffInSeconds(now(), false) >= $task->max_seconds;

        if ($roundCeilingReached || $wallClockCeilingReached) {
            $reason = $roundCeilingReached
                ? "The task's round ceiling was reached before this part could be completed."
                : "The task's time limit was reached before this part could be completed.";

            app(ManagerService::class)->finalizeWithShortfall($task, $reason);

            return;
        }

        // First step: seed with the original request itself, so the
        // manager's very first turn has something to decompose. Every
        // later step continues the SAME conversation -- adding another
        // copy of the original request as a fresh user message would
        // duplicate it in the transcript, so a short, fixed continuation
        // nudge is used instead; the manager's own system prompt already
        // carries the task's current state (buildKnownHelpersSection() and
        // -- once wired -- the task-progress section).
        $hasPriorTurn = Message::where('conversation_id', $conversation->id)->exists();
        $seedMessage = $hasPriorTurn
            ? 'Continue working on the managed task.'
            : $task->original_request;

        $agentLoopService->run($conversation, $seedMessage, [
            'max_iterations' => config('llm-client.manager.step_max_iterations', 4),
        ]);

        $task->refresh();
        $task->last_progress_at = now();
        $task->save();

        if (!in_array($task->status, self::TERMINAL_STATUSES, true)) {
            self::dispatch($this->managedTaskId)->onQueue(config('llm-client.manager.queue', 'managed-tasks'));
        }
    }
}
