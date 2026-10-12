<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\TaskBudget;
use ClarionApp\LlmClient\ValueObjects\DeliveryResult;
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

    /**
     * Whether the next step of this conversation's task would be refused,
     * without claiming it: null when it would be allowed (or there is no
     * task yet), the refusal otherwise. Never throws; a failure is
     * STOP(unavailable).
     */
    public function wouldStop(Conversation $conversation): ?TaskBudgetDecision
    {
        try {
            $task = $conversation->active_task_budget_id === null ? null : TaskBudget::find($conversation->active_task_budget_id);
            if ($task === null) {
                return null;
            }
            if ($task->state !== TaskBudgetState::Running) {
                return TaskBudgetDecision::stop(TaskBudgetDecision::REASON_NOT_RUNNING, $this->tasks->standing($task));
            }
            $stepsReached = (int) $task->steps_used >= (int) $task->max_steps;
            $spendReached = bccomp($this->costs->taskTotal($task->id)['priced_cost_total'], $task->max_spend, 10) >= 0;
            if ($stepsReached || $spendReached) {
                $reason = $stepsReached && $spendReached ? TaskBudgetDecision::REASON_BOTH : ($stepsReached ? TaskBudgetDecision::REASON_STEPS : TaskBudgetDecision::REASON_SPEND);

                return TaskBudgetDecision::stop($reason, $this->tasks->standing($task));
            }

            return null;
        } catch (\Throwable $e) {
            Log::error('Task budget could not be checked for conversation '.$conversation->id, ['conversation_id' => $conversation->id, 'error' => $e->getMessage()]);

            return TaskBudgetDecision::unavailable();
        }
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
                $this->noticeApproach($task, $spend);

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

    /**
     * Tells the user, once per ceiling, that the task has used most of it.
     * Runs only after an allowed claim. Whether a ceiling is a candidate is
     * decided from the row read before the claim (its steps_used is the
     * number of completed steps), so the common path adds no query; the
     * notice itself goes out only for ceilings whose latch this call wins
     * with a conditional UPDATE, so concurrent workers post it once. Any
     * failure here is logged and swallowed: a notice never stops a step.
     */
    private function noticeApproach(TaskBudget $before, string $spend): void
    {
        try {
            $threshold = (string) $before->approach_threshold;
            $candidates = [];
            if ($before->steps_approach_noticed_at === null
                && bccomp((string) (int) $before->steps_used, bcmul($threshold, (string) (int) $before->max_steps, 10), 10) >= 0) {
                $candidates[] = 'steps';
            }
            if ($before->spend_approach_noticed_at === null
                && bccomp($spend, bcmul($threshold, (string) $before->max_spend, 10), 10) >= 0) {
                $candidates[] = 'spend';
            }
            if ($candidates === []) {
                return;
            }

            $won = [];
            foreach ($candidates as $ceiling) {
                $column = $ceiling.'_approach_noticed_at';
                $updated = DB::table('task_budgets')
                    ->where('id', $before->id)
                    ->whereNull($column)
                    ->update([$column => now()->format('Y-m-d H:i:s.u')]);
                if ($updated === 1) {
                    $won[] = $ceiling;
                }
            }
            if ($won === []) {
                return;
            }

            $task = TaskBudget::find($before->id);
            $root = $task === null ? null : Conversation::find($task->root_conversation_id);
            if ($task === null || $root === null) {
                return;
            }
            $asOf = $this->tasks->standing($task)->withStepsUsed((int) $task->steps_used - 1);
            $text = app(TaskBudgetText::class)->approachNotice($asOf, $won);
            $message = Message::create([
                'conversation_id' => $root->id,
                'role' => 'assistant',
                'user' => $root->character,
                'content' => $text,
                'responseTime' => 0,
                'tool_data' => ['kind' => AgentQuestionService::KIND_TASK_BUDGET_NOTICE, 'task_budget_id' => $task->id, 'notice' => 'approach'],
            ]);

            // An unreachable root (unattended, no user, a channel nobody
            // listens on) keeps the notice in its history without trying to
            // deliver it; only a delivery that was attempted and failed is
            // worth a warning.
            $refusal = app(UserReachability::class)->refusal($root, (bool) $task->unattended);
            if ($refusal === null) {
                $delivery = app(AgentQuestionService::class)->deliver($root, $text);
                if (!$delivery->delivered) {
                    Log::warning('Task budget approach notice could not be delivered for task '.$task->id, ['task_budget_id' => $task->id, 'reason' => $delivery->failureReason]);
                }
            } else {
                $delivery = DeliveryResult::failed($refusal->reason);
            }

            $this->tasks->appendEvent($task, 'approach_notice', [
                'ceilings' => $won,
                'message_id' => $message->id,
                'delivered' => $delivery->delivered,
                'delivery_failure_reason' => $delivery->delivered ? null : $delivery->failureReason,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Task budget approach notice failed for task '.$before->id, ['task_budget_id' => $before->id, 'error' => $e->getMessage()]);
        }
    }
}
