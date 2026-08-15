<?php

namespace ClarionApp\LlmClient\Jobs;

use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\ManagedTask;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\AgentLoopService;
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
 * D5/D7's forced finalize) and the crash-recovery sweep are both Phase
 * 6/US4's own addition -- this phase's handle() only re-dispatches or
 * stops on the model's own terminal call.
 */
class RunManagedTaskStepJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const TERMINAL_STATUSES = ['completed', 'completed_with_shortfalls', 'failed'];

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
