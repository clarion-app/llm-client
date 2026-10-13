<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\ManagedTask;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\TaskBudget;
use ClarionApp\LlmClient\Models\TaskBudgetEvent;
use ClarionApp\LlmClient\ValueObjects\CeilingOrigin;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\StatedCeilings;
use ClarionApp\LlmClient\ValueObjects\TaskBudgetStanding;
use ClarionApp\LlmClient\ValueObjects\TaskBudgetState;
use ClarionApp\LlmClient\Support\Decimal;
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

    /** The length of task_budgets.end_reason. */
    private const END_REASON_LENGTH = 256;

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

            // A managed task started before task budgets has none: it gets
            // its managed task's budget now, never one tied to this round's
            // run (which would end with the round and stop every later one).
            if ($channel === self::MANAGED_TASK_CHANNEL) {
                $managedTaskId = ManagedTask::where('conversation_id', $conversation->id)->value('id');

                return $this->open($conversation, TaskBudget::SCOPE_MANAGED_TASK, $stated, true, null, $managedTaskId, true);
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
            // Only finished steps count: a step still open here is the one
            // about to claim (a synchronous continuation opens its step
            // before the boundary), and it claims for itself.
            $steps = DB::table('agent_run_steps')->where('run_id', $runId)->where('end_state', '!=', 'in_progress')->count();
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
                // The request's own reason can be long (a failed sequence run
                // names its stage and the failure in full); the column holds
                // its start, the event below keeps it whole.
                'end_reason' => $completed || $reason === null ? null : mb_substr($reason, 0, self::END_REASON_LENGTH),
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

    /**
     * What continuing would add for each reached ceiling: the task's
     * original ceiling, cut to the installation maximum (0 / zero spend
     * when nothing is left).
     *
     * @return array{steps: ?int, spend: ?string, steps_reduced: bool, spend_reduced: bool}
     */
    public function offeredAllowance(TaskBudget $task, TaskBudgetStanding $standing): array
    {
        $reached = $standing->reached();
        if ($reached === []) {
            return ['steps' => null, 'spend' => null, 'steps_reduced' => false, 'spend_reduced' => false];
        }
        $allowance = $this->limits->clampAllowance(
            (int) $task->max_steps,
            in_array('steps', $reached, true) ? (int) $task->original_max_steps : null,
            (string) $task->max_spend,
            in_array('spend', $reached, true) ? (string) $task->original_max_spend : null,
        );

        return [
            'steps' => $allowance['add_steps'],
            'spend' => $allowance['add_spend'],
            'steps_reduced' => $allowance['steps_reduced'],
            'spend_reduced' => $allowance['spend_reduced'],
        ];
    }

    /**
     * Changes the stated ceilings of a running or paused task. Never resumes
     * a paused task. Returns null when the task is no longer running or
     * paused.
     *
     * @return ?array{standing: TaskBudgetStanding, reduced: array{steps: bool, spend: bool}}
     */
    public function changeCeilings(TaskBudget $task, StatedCeilings $stated, string $actorUserId): ?array
    {
        $c = $this->limits->clamp($stated);
        $stepsStated = $stated->maxSteps !== null && $c['steps_origin'] !== CeilingOrigin::Default;
        $spendStated = $stated->maxSpend !== null && $c['spend_origin'] !== CeilingOrigin::Default;
        $before = TaskBudget::find($task->id);
        if ($before === null || !in_array($before->state, [TaskBudgetState::Running, TaskBudgetState::Paused], true)) {
            return null;
        }
        $threshold = Decimal::toPlainNotation((string) $before->approach_threshold);
        $updates = ['updated_at' => now()->format('Y-m-d H:i:s.u')];
        if ($stepsStated) {
            $updates['max_steps'] = $c['steps'];
            $updates['max_steps_origin'] = $c['steps_origin']->value;
            if (bccomp(bcdiv((string) (int) $before->steps_used, (string) $c['steps'], 10), $threshold, 10) < 0) {
                $updates['steps_approach_noticed_at'] = null;
            }
        }
        if ($spendStated) {
            $updates['max_spend'] = $c['spend'];
            $updates['max_spend_origin'] = $c['spend_origin']->value;
            $spent = $this->costs->taskTotal($before->id)['priced_cost_total'];
            if (bccomp(bcdiv(Decimal::toPlainNotation((string) $spent), $c['spend'], 10), $threshold, 10) < 0) {
                $updates['spend_approach_noticed_at'] = null;
            }
        }
        $won = DB::table('task_budgets')->where('id', $before->id)
            ->whereIn('state', [TaskBudgetState::Running->value, TaskBudgetState::Paused->value])
            ->update($updates);
        if ($won !== 1) {
            return null;
        }
        $after = TaskBudget::findOrFail($before->id);
        $reduced = ['steps' => $stepsStated && $c['steps_reduced'], 'spend' => $spendStated && $c['spend_reduced']];
        $this->appendEvent($after, 'ceiling_changed', [
            'before' => ['max_steps' => (int) $before->max_steps, 'max_steps_origin' => $before->max_steps_origin->value, 'max_spend' => (string) $before->max_spend, 'max_spend_origin' => $before->max_spend_origin->value],
            'after' => ['max_steps' => (int) $after->max_steps, 'max_steps_origin' => $after->max_steps_origin->value, 'max_spend' => (string) $after->max_spend, 'max_spend_origin' => $after->max_spend_origin->value],
            'reduced' => $reduced,
        ], $actorUserId);

        return ['standing' => $this->standing($after), 'reduced' => $reduced];
    }

    /**
     * Changes the ceilings from a "/budget" line sent in the conversation and
     * records the change there, undelivered: the reply carries it. Null when
     * the task is no longer running or paused.
     *
     * @return ?array{standing: TaskBudgetStanding, reduced: array{steps: bool, spend: bool}, content: string}
     */
    public function changeFromConversation(Conversation $conversation, TaskBudget $task, StatedCeilings $stated, string $actorUserId, bool $deliver = false): ?array
    {
        $changed = $this->changeCeilings($task, $stated, $actorUserId);
        if ($changed === null) {
            return null;
        }
        $content = (new TaskBudgetText())->budgetUpdated($changed['standing'], $this->reducedLine($stated, $changed['reduced']));
        $this->postNotice($conversation, $task, 'ceiling_changed', $content, $deliver);

        return $changed + ['content' => $content];
    }

    /**
     * Ceilings stated with a message that is about to be taken as the reply
     * to the conversation's waiting question (an agent's question or a
     * ceiling prompt) are not a new request's: they change the
     * conversation's running or paused task first, exactly as a "/budget"
     * line sent on its own would, so they are never silently dropped. The
     * change notice is delivered on the channel, because the response
     * carries the reply's outcome, not the change. Null — nothing changed —
     * when nothing is stated, no question of this user waits in the
     * conversation, or it has no running or paused task.
     *
     * @return ?array{standing: TaskBudgetStanding, reduced: array{steps: bool, spend: bool}, content: string}
     */
    public function changeWithReply(Conversation $conversation, ?StatedCeilings $stated, string $userId): ?array
    {
        if ($stated === null) {
            return null;
        }
        try {
            $waiting = AgentQuestion::where('pending_conversation_id', $conversation->id)->where('user_id', $userId)->exists();
            $task = $waiting ? $this->currentFor($conversation) : null;
            if ($task === null || $task->state->isTerminal()) {
                return null;
            }

            return $this->changeFromConversation($conversation, $task, $stated, $userId, true);
        } catch (\Throwable $e) {
            Log::warning('Task budget ceilings stated with a reply could not be applied for conversation '.$conversation->id, ['conversation_id' => $conversation->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** The line telling the user a stated ceiling was cut to the installation maximum, or null. */
    public function reducedLine(?StatedCeilings $stated, ?array $reduced = null): ?string
    {
        if ($stated === null) {
            return null;
        }
        $c = $this->limits->clamp($stated);
        $steps = $reduced['steps'] ?? $c['steps_reduced'];
        $spend = $reduced['spend'] ?? $c['spend_reduced'];
        if (!$steps && !$spend) {
            return null;
        }

        return (new TaskBudgetText())->reducedToMaximum(
            $steps ? $stated->maxSteps : null,
            $steps ? $this->limits->maximumSteps() : null,
            $spend ? $stated->maxSpend : null,
            $spend ? $this->limits->maximumSpend() : null,
            (string) config('llm-client.cost.currency', 'USD'),
        );
    }

    /** Writes a task-budget notice into the conversation; delivers it on its channel when asked and reachable. */
    public function postNotice(Conversation $conversation, TaskBudget $task, string $notice, string $text, bool $deliver): ?Message
    {
        try {
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'user' => $conversation->character,
                'content' => $text,
                'responseTime' => 0,
                'tool_data' => ['kind' => AgentQuestionService::KIND_TASK_BUDGET_NOTICE, 'task_budget_id' => $task->id, 'notice' => $notice],
            ]);
            if ($deliver && app(UserReachability::class)->refusal($conversation, (bool) $task->unattended) === null) {
                $delivery = app(AgentQuestionService::class)->deliver($conversation, $text);
                if (!$delivery->delivered) {
                    Log::warning('Task budget notice could not be delivered for task '.$task->id, ['task_budget_id' => $task->id, 'reason' => $delivery->failureReason]);
                }
            }

            return $message;
        } catch (\Throwable $e) {
            Log::warning('Task budget notice could not be posted for task '.$task->id, ['task_budget_id' => $task->id, 'error' => $e->getMessage()]);

            return null;
        }
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

        if (($line = $this->reducedLine($stated)) !== null) {
            $this->postNotice($conversation, $task, 'reduced_to_maximum', $line, true);
        }

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
