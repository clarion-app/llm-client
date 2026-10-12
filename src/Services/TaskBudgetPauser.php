<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\TaskBudget;
use ClarionApp\LlmClient\ValueObjects\CeilingPromptRequest;
use ClarionApp\LlmClient\ValueObjects\OpenedQuestion;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\TaskBudgetDecision;
use ClarionApp\LlmClient\ValueObjects\TaskBudgetState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * What a root agent does when the gate refuses its next step: pause the task
 * at that boundary and ask the user what to do, or — when nobody can be
 * asked — halt it with its report. The pause is one conditional UPDATE on
 * the task's state, so of two racing pausers exactly one pauses the task;
 * the other writes nothing to it and only closes its own open step. No step
 * is ever left open, and the conversation is no longer processing on any
 * branch.
 */
class TaskBudgetPauser
{
    public function __construct(
        private TaskBudgetService $tasks,
        private TaskBudgetText $text,
        private TaskBudgetLimits $limits,
        private TaskBudgetReport $report,
    ) {
    }

    /**
     * @return array<string, mixed>|null the synchronous caller's result;
     *   null on the streamed path
     */
    public function pause(
        Conversation $conversation,
        ?TaskBudget $task,
        TaskBudgetDecision $decision,
        ?string $runId,
        ?string $openStepId,
        int $nextIteration,
        string $resumeMode,
    ): ?array {
        $reason = $this->text->ceilingReachedReason();
        $this->closeStep($openStepId, $reason);

        if ($task === null) {
            return $this->stopWithoutTask($conversation, $runId, $resumeMode);
        }

        try {
            return $this->pauseTask($conversation, $task, $decision, $runId, $nextIteration, $resumeMode);
        } catch (\Throwable $e) {
            // The task could not be paused or halted: nothing more runs, and
            // the run says why.
            Log::error("Task budget could not pause task {$task->id}", ['task_budget_id' => $task->id, 'conversation_id' => $conversation->id, 'error' => $e->getMessage()]);

            return $this->stopWithoutTask($conversation, $runId, $resumeMode);
        }
    }

    private function stopWithoutTask(Conversation $conversation, ?string $runId, string $resumeMode): ?array
    {
        $this->closeRun($runId, $this->text->ceilingReachedReason());
        $this->notProcessing($conversation);

        return $resumeMode === 'sync' ? $this->stopped($this->text->unavailable()) : null;
    }

    private function pauseTask(Conversation $conversation, TaskBudget $task, TaskBudgetDecision $decision, ?string $runId, int $nextIteration, string $resumeMode): ?array
    {
        $standing = $this->tasks->standing($task);
        $now = now()->format('Y-m-d H:i:s.u');
        $won = DB::table('task_budgets')
            ->where('id', $task->id)
            ->where('state', TaskBudgetState::Running->value)
            ->update([
                'state' => TaskBudgetState::Paused->value,
                'paused_at' => $now,
                'pause_reason' => $decision->reason === TaskBudgetDecision::REASON_NOT_RUNNING ? TaskBudgetDecision::REASON_UNAVAILABLE : $decision->reason,
                'paused_run_id' => $runId,
                'paused_iteration' => $nextIteration,
                'resume_mode' => $resumeMode,
                'paused_steps_used' => $standing->stepsUsed,
                'paused_spend' => $standing->spendUsed,
                'updated_at' => $now,
            ]);

        if ($won !== 1) {
            return $this->lostThePause($conversation, $task, $runId, $resumeMode);
        }

        $task = TaskBudget::find($task->id);
        $this->tasks->appendEvent($task, 'ceiling_reached', ['reason' => $decision->reason, 'standing' => $standing->toArray()]);

        if (app(UserReachability::class)->refusal($conversation, (bool) $task->unattended) !== null) {
            return $this->halt($conversation, $task, $runId, $resumeMode);
        }

        return $this->raisePrompt($conversation, $task, $resumeMode);
    }

    /**
     * Ask the user about a paused task that has no prompt yet. Returns the
     * synchronous waiting result, or null on the streamed path or when the
     * prompt could not be raised (the conversation's question slot is held
     * by another question; it is raised at the first boundary after that
     * one resolves).
     */
    public function raisePrompt(Conversation $conversation, TaskBudget $task, string $resumeMode): ?array
    {
        $standing = $this->tasks->standing($task);
        $report = $this->report->compose($task, $standing, TaskBudgetReport::OCCASION_PAUSED);
        $reached = $standing->reached();

        $offeredSteps = null;
        $offeredSpend = null;
        $stepsReduced = false;
        $spendReduced = false;
        if ($reached !== []) {
            $allowance = $this->limits->clampAllowance(
                (int) $task->max_steps,
                in_array('steps', $reached, true) ? (int) $task->original_max_steps : null,
                (string) $task->max_spend,
                in_array('spend', $reached, true) ? (string) $task->original_max_spend : null,
            );
            $offeredSteps = $allowance['add_steps'];
            $offeredSpend = $allowance['add_spend'];
            $stepsReduced = $allowance['steps_reduced'];
            $spendReduced = $allowance['spend_reduced'];
        }

        $opened = app(AgentQuestionService::class)->openCeilingPrompt(new CeilingPromptRequest(
            conversation: $conversation,
            task: $task,
            runId: $task->paused_run_id,
            resumeMode: $resumeMode,
            report: $report,
            standing: $standing,
            offeredSteps: $offeredSteps,
            offeredSpend: $offeredSpend,
            stepsReducedToMaximum: $stepsReduced,
            spendReducedToMaximum: $spendReduced,
        ));

        if (!$opened instanceof OpenedQuestion) {
            Log::error("Task budget prompt for task {$task->id} could not be raised: the conversation's question slot is taken", [
                'task_budget_id' => $task->id,
                'conversation_id' => $conversation->id,
            ]);
            $this->notProcessing($conversation);

            return null;
        }

        DB::table('task_budgets')->where('id', $task->id)->where('state', TaskBudgetState::Paused->value)
            ->update(['prompt_question_id' => $opened->question->id, 'updated_at' => now()->format('Y-m-d H:i:s.u')]);

        return $resumeMode === 'sync' ? $this->awaiting($conversation, $opened->question) : null;
    }

    private function lostThePause(Conversation $conversation, TaskBudget $task, ?string $runId, string $resumeMode): ?array
    {
        $current = TaskBudget::find($task->id);

        if ($current !== null && $current->state === TaskBudgetState::Paused) {
            $this->notProcessing($conversation);
            if ($current->prompt_question_id === null) {
                return $this->raisePrompt($conversation, $current, $resumeMode);
            }
            $prompt = AgentQuestion::find($current->prompt_question_id);

            return ($resumeMode === 'sync' && $prompt !== null) ? $this->awaiting($conversation, $prompt) : null;
        }

        $this->closeRun($runId, $this->text->ceilingReachedReason());
        $this->notProcessing($conversation);

        return $resumeMode === 'sync' ? $this->stopped($this->text->unavailable()) : null;
    }

    private function halt(Conversation $conversation, TaskBudget $task, ?string $runId, string $resumeMode): ?array
    {
        $reason = $this->text->ceilingReachedReason();
        $now = now()->format('Y-m-d H:i:s.u');
        DB::table('task_budgets')->where('id', $task->id)->where('state', TaskBudgetState::Paused->value)->update([
            'state' => TaskBudgetState::Halted->value,
            'end_reason' => $reason,
            'ended_at' => $now,
            'updated_at' => $now,
        ]);
        $task = TaskBudget::find($task->id);

        $report = $this->report->compose($task, $this->tasks->standing($task), TaskBudgetReport::OCCASION_HALTED);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => $conversation->character,
            'content' => $report,
            'responseTime' => 0,
            'tool_data' => ['kind' => AgentQuestionService::KIND_TASK_BUDGET_REPORT, 'task_budget_id' => $task->id, 'occasion' => TaskBudgetReport::OCCASION_HALTED],
        ]);
        app(AgentQuestionService::class)->deliver($conversation, $report);

        $this->closeRun($runId, $reason);
        $this->notProcessing($conversation);
        $this->tasks->appendEvent($task, 'halted', ['reason' => $reason, 'report_message_id' => $message->id]);

        return $resumeMode === 'sync' ? $this->stopped($report) : null;
    }

    private function awaiting(Conversation $conversation, AgentQuestion $prompt): array
    {
        return [
            'status' => 'awaiting_user',
            'conversation_id' => $conversation->id,
            'message_id' => $prompt->display_message_id,
            'question_id' => $prompt->id,
            'content' => '',
        ];
    }

    private function stopped(string $content): array
    {
        return ['status' => 'stopped', 'code' => 'task_budget_reached', 'content' => $content];
    }

    private function closeStep(?string $stepId, string $reason): void
    {
        if ($stepId !== null) {
            app(RunTraceRecorder::class)->closeStep($stepId, RunEndState::StoppedEarly, $reason);
        }
    }

    private function closeRun(?string $runId, string $reason): void
    {
        if ($runId !== null) {
            app(RunTraceRecorder::class)->closeRun($runId, RunEndState::StoppedEarly, $reason);
        }
    }

    private function notProcessing(Conversation $conversation): void
    {
        Conversation::find($conversation->id)?->update(['is_processing' => false]);
        $conversation->setAttribute('is_processing', false);
        $conversation->syncOriginalAttribute('is_processing');
    }
}
