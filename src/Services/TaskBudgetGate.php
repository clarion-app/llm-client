<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\TaskBudget;
use ClarionApp\LlmClient\ValueObjects\TaskBudgetDecision;
use ClarionApp\LlmClient\ValueObjects\TaskBudgetState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Decides, before every model call of a task, whether that call may happen.
 * An allowed call claims one step with a single conditional UPDATE, so two
 * workers sharing a task can never claim past its step ceiling. Spend is
 * read fresh from the task's usage records on every decision. Any failure
 * fails closed: STOP(unavailable), logged once, never thrown.
 */
class TaskBudgetGate
{
    public function __construct(
        private TaskBudgetService $tasks,
        private CostRollupQuery $costs,
    ) {
    }

    public function beforeStep(Conversation $conversation, ?string $runId): TaskBudgetDecision
    {
        try {
            $task = $conversation->active_task_budget_id === null ? null : TaskBudget::find($conversation->active_task_budget_id);
            // A helper created before its conversation carried a pointer
            // joins its chain root's task rather than opening its own.
            if ($task === null && $conversation->channel === 'agent-delegation') {
                $task = $this->tasks->forNewRun($conversation, null, false, $runId);
            }
            if ($task === null) {
                $task = $this->tasks->openLazily($conversation, $runId);
            }

            if ($task->state !== TaskBudgetState::Running) {
                return TaskBudgetDecision::stop(TaskBudgetDecision::REASON_NOT_RUNNING, $this->tasks->standing($task));
            }

            $spend = $this->costs->taskTotal($task->id)['priced_cost_total'];
            if (bccomp($spend, $task->max_spend, 10) >= 0) {
                $reason = ((int) $task->steps_used >= (int) $task->max_steps) ? TaskBudgetDecision::REASON_BOTH : TaskBudgetDecision::REASON_SPEND;

                return TaskBudgetDecision::stop($reason, $this->tasks->standing($task));
            }

            // The claim is one statement, deliberately outside any
            // transaction: the conditions in the WHERE clause are what make
            // it atomic across workers, and a refused claim writes nothing.
            $claimed = DB::table('task_budgets')
                ->where('id', $task->id)
                ->where('state', TaskBudgetState::Running->value)
                ->whereColumn('steps_used', '<', 'max_steps')
                ->update([
                    'steps_used' => DB::raw('steps_used + 1'),
                    'updated_at' => now()->format('Y-m-d H:i:s.u'),
                ]);

            if ($claimed === 1) {
                return TaskBudgetDecision::allow();
            }

            $task = TaskBudget::find($task->id);
            if ($task === null || $task->state !== TaskBudgetState::Running) {
                return TaskBudgetDecision::stop(TaskBudgetDecision::REASON_NOT_RUNNING, $task === null ? null : $this->tasks->standing($task));
            }

            return TaskBudgetDecision::stop(TaskBudgetDecision::REASON_STEPS, $this->tasks->standing($task));
        } catch (\Throwable $e) {
            Log::error('Task budget could not be checked for conversation '.$conversation->id, ['conversation_id' => $conversation->id, 'error' => $e->getMessage()]);

            return TaskBudgetDecision::unavailable();
        }
    }
}
