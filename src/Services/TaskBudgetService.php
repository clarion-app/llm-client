<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\TaskBudget;
use ClarionApp\LlmClient\Models\TaskBudgetEvent;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\StatedCeilings;
use ClarionApp\LlmClient\ValueObjects\TaskBudgetStanding;
use ClarionApp\LlmClient\ValueObjects\TaskBudgetState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Opens, joins, supersedes and reports on task budgets, and keeps their
 * event log. A conversation's active_task_budget_id names its current task:
 * a user request opens one; helper and managed-task conversations join the
 * task of the work that created them, whatever its state, and never open
 * one of their own. Besides the gate's step claim, this is the only writer
 * of task_budgets.
 */
class TaskBudgetService
{
    private const DELEGATION_CHANNEL = 'agent-delegation';
    private const MANAGED_TASK_CHANNEL = 'managed-task';

    public function __construct(
        private TaskBudgetLimits $limits,
        private CostRollupQuery $costs,
    ) {
    }

    /**
     * Resolves the task a new run belongs to: a helper or managed-task
     * conversation joins the task its pointer names (or, for a helper with
     * no pointer, its chain root's current task); any other conversation
     * opens a new request task, ending a predecessor that is still running.
     * Never throws: null when the task store is unusable, and the run's
     * first step is then refused.
     */
    public function forNewRun(Conversation $conversation, ?StatedCeilings $stated, bool $unattended, ?string $runId): ?TaskBudget
    {
        try {
            $channel = (string) $conversation->channel;
            $current = $this->currentFor($conversation);

            if ($channel === self::DELEGATION_CHANNEL) {
                if ($current !== null) {
                    return $current;
                }
                $rootId = DelegationService::chainRootConversationId((string) $conversation->id);
                $root = $rootId !== (string) $conversation->id ? Conversation::find($rootId) : null;
                if ($root === null) {
                    return $this->openLazily($conversation, $runId);
                }
                // The helper's run is not the root's, so the root's task is
                // opened with no run of its own: no helper run's close ends it.
                $task = $this->currentFor($root) ?? $this->openLazily($root, null);
                $this->point($conversation, $task->id);

                return $task;
            }

            if ($current !== null && ($channel === self::MANAGED_TASK_CHANNEL || $current->root_conversation_id !== (string) $conversation->id)) {
                return $current;
            }

            if ($current !== null && $current->state === TaskBudgetState::Running) {
                $this->supersede($current);
            }

            return $this->open($conversation, TaskBudget::SCOPE_REQUEST, $stated, $unattended, $runId, null, true);
        } catch (\Throwable $e) {
            Log::error('Task budget could not be opened for conversation '.$conversation->id, ['conversation_id' => $conversation->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Opens the task of a managed task, sequence run or consensus request.
     * Always unattended. $pointConversation is false when the conversation
     * the request came from must keep its own pointer. Never throws.
     */
    public function openForRequest(Conversation $root, string $scope, ?StatedCeilings $stated, ?string $managedTaskId, bool $pointConversation): ?TaskBudget
    {
        try {
            return $this->open($root, $scope, $stated, true, null, $managedTaskId, $pointConversation);
        } catch (\Throwable $e) {
            Log::error('Task budget could not be opened for conversation '.$root->id, ['conversation_id' => $root->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Opens a default task for a conversation that reached a step with none,
     * and brings that run's earlier usage and steps into it. Throws on
     * failure — the caller decides how to fail.
     */
    public function openLazily(Conversation $conversation, ?string $runId): TaskBudget
    {
        $task = $this->open($conversation, TaskBudget::SCOPE_REQUEST, null, false, $runId, null, true);

        if ($runId !== null) {
            DB::table('usage_records')->where('run_id', $runId)->whereNull('task_budget_id')->update(['task_budget_id' => $task->id]);
            $steps = DB::table('agent_run_steps')->where('run_id', $runId)->count();
            if ($steps > 0) {
                DB::table('task_budgets')->where('id', $task->id)->update(['steps_used' => $steps]);
                $task->steps_used = $steps;
            }
        }

        return $task;
    }

    /**
     * The root run of a running task closed: the task ends with it —
     * completed when the run completed, ended (with the run's end state as
     * its reason) otherwise. A task in any other state already decided its
     * own state and is left alone. Best-effort: never throws.
     */
    public function rootRunClosed(string $runId, RunEndState $endState, ?string $reason): void
    {
        try {
            $task = TaskBudget::where('root_run_id', $runId)->where('state', TaskBudgetState::Running->value)->first();
            if ($task === null) {
                return;
            }

            $completed = $endState === RunEndState::Completed;
            $now = now()->format('Y-m-d H:i:s.u');
            $won = DB::table('task_budgets')->where('id', $task->id)->where('state', TaskBudgetState::Running->value)->update([
                'state' => $completed ? TaskBudgetState::Completed->value : TaskBudgetState::Ended->value,
                'end_reason' => $completed ? null : $endState->value,
                'ended_at' => $now,
                'updated_at' => $now,
            ]);

            if ($won === 1) {
                $this->appendEvent($task, 'ended', ['reason' => $completed ? 'completed' : $endState->value, 'run_end_reason' => $reason]);
            }
        } catch (\Throwable $e) {
            Log::warning("Task budget could not be ended for run {$runId}", ['run_id' => $runId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Ends the task of a request that runs without a root agent of its own
     * (a managed task, a sequence run, a consensus request) when that request
     * finishes. Only a running task is changed; a halted one keeps its halt.
     * Never throws.
     */
    public function endRequestTask(string $taskBudgetId, bool $completed, ?string $reason): void
    {
        try {
            $task = TaskBudget::find($taskBudgetId);
            if ($task === null) {
                return;
            }
            $now = now()->format('Y-m-d H:i:s.u');
            $won = DB::table('task_budgets')->where('id', $task->id)->where('state', TaskBudgetState::Running->value)->update([
                'state' => $completed ? TaskBudgetState::Completed->value : TaskBudgetState::Ended->value,
                'end_reason' => $completed ? null : $reason,
                'ended_at' => $now,
                'updated_at' => $now,
            ]);
            if ($won === 1) {
                $this->appendEvent($task, 'ended', ['reason' => $completed ? 'completed' : 'request_ended', 'request_end_reason' => $reason]);
            }
        } catch (\Throwable $e) {
            Log::warning("Task budget {$taskBudgetId} could not be ended", ['task_budget_id' => $taskBudgetId, 'error' => $e->getMessage()]);
        }
    }

    public function supersede(TaskBudget $predecessor): void
    {
        $now = now()->format('Y-m-d H:i:s.u');
        $won = DB::table('task_budgets')->where('id', $predecessor->id)->where('state', TaskBudgetState::Running->value)
            ->update(['state' => TaskBudgetState::Ended->value, 'end_reason' => 'superseded', 'ended_at' => $now, 'updated_at' => $now]);
        if ($won === 1) {
            $this->appendEvent($predecessor, 'ended', ['reason' => 'superseded'], null);
        }
    }

    public function currentFor(Conversation $conversation): ?TaskBudget
    {
        $id = $conversation->active_task_budget_id;

        return $id === null ? null : TaskBudget::find($id);
    }

    /** Reads the task's spend afresh; nothing is memoized. */
    public function standing(TaskBudget $task): TaskBudgetStanding
    {
        $total = $this->costs->taskTotal($task->id);
        $promptState = null;
        if ($task->prompt_question_id !== null) {
            $state = AgentQuestion::where('id', $task->prompt_question_id)->value('state');
            $promptState = $state instanceof QuestionState ? $state : ($state === null ? null : QuestionState::tryFrom($state));
        }

        return new TaskBudgetStanding(
            taskBudgetId: $task->id,
            conversationId: $task->root_conversation_id,
            state: $task->state,
            promptState: $promptState,
            promptQuestionId: $task->prompt_question_id,
            stepsUsed: (int) $task->steps_used,
            maxSteps: (int) $task->max_steps,
            stepsOrigin: $task->max_steps_origin,
            maximumSteps: $this->limits->maximumSteps(),
            stepsApproachNoticed: $task->steps_approach_noticed_at !== null,
            spendUsed: $total['priced_cost_total'],
            maxSpend: $task->max_spend,
            currency: (string) config('llm-client.cost.currency', 'USD'),
            spendOrigin: $task->max_spend_origin,
            maximumSpend: $this->limits->maximumSpend(),
            approximate: $total['has_estimated_cost'],
            unpricedRequestCount: $total['unpriced_request_count'],
            spendApproachNoticed: $task->spend_approach_noticed_at !== null,
            approachThreshold: $task->approach_threshold,
        );
    }

    /** Best-effort: a failed write is logged and never thrown. */
    public function appendEvent(TaskBudget $task, string $kind, array $payload, ?string $actorUserId = null): void
    {
        try {
            TaskBudgetEvent::create([
                'task_budget_id' => $task->id,
                'kind' => $kind,
                'payload' => $payload,
                'actor_user_id' => $actorUserId,
                'created_at' => now()->format('Y-m-d H:i:s.u'),
            ]);
        } catch (\Throwable $e) {
            Log::warning("Task budget event '{$kind}' could not be recorded for task {$task->id}", ['task_budget_id' => $task->id, 'error' => $e->getMessage()]);
        }
    }

    private function open(Conversation $conversation, string $scope, ?StatedCeilings $stated, bool $unattended, ?string $runId, ?string $managedTaskId, bool $point): TaskBudget
    {
        $c = $this->limits->clamp($stated);

        $task = TaskBudget::create([
            'user_id' => ($conversation->user_id === '' ? null : $conversation->user_id),
            'root_conversation_id' => $conversation->id,
            'root_run_id' => $runId,
            'managed_task_id' => $managedTaskId,
            'scope' => $scope,
            'unattended' => $unattended,
            'state' => TaskBudgetState::Running,
            'max_steps' => $c['steps'],
            'max_steps_origin' => $c['steps_origin'],
            'original_max_steps' => $c['steps'],
            'max_spend' => $c['spend'],
            'max_spend_origin' => $c['spend_origin'],
            'original_max_spend' => $c['spend'],
            'steps_used' => 0,
            'approach_threshold' => $this->limits->approachThreshold(),
        ]);

        if ($point) {
            $this->point($conversation, $task->id);
        }

        $this->appendEvent($task, 'opened', [
            'scope' => $scope,
            'max_steps' => $c['steps'],
            'max_steps_origin' => $c['steps_origin']->value,
            'max_spend' => $c['spend'],
            'max_spend_origin' => $c['spend_origin']->value,
        ], null);

        return $task;
    }

    /**
     * Points the conversation at the task in its row and on the instance the
     * caller holds (the gate reads the instance). A direct write: the pointer
     * is operational state, not something to bridge or observe.
     */
    private function point(Conversation $conversation, string $taskId): void
    {
        DB::table('conversations')->where('id', $conversation->id)->update(['active_task_budget_id' => $taskId]);
        $conversation->setAttribute('active_task_budget_id', $taskId);
        $conversation->syncOriginalAttribute('active_task_budget_id');
    }
}
