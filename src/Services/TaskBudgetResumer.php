<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Exceptions\BudgetExceededException;
use ClarionApp\LlmClient\Exceptions\RateLimitExceededException;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\TaskBudget;
use ClarionApp\LlmClient\Support\Decimal;
use ClarionApp\LlmClient\ValueObjects\CeilingDecision;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use ClarionApp\LlmClient\ValueObjects\ResumeCaller;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\TaskBudgetState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies a decided ceiling prompt to its task, exactly once: continue
 * raises the reached ceilings and continues the same run from the iteration
 * it paused at (streamed or synchronous, as it paused); accept concludes the
 * task with a report and closes its run as completed. A prompt withdrawn
 * because its conversation was ended or deleted, or a decision whose
 * conversation is gone, discards the task and closes its run as stopped.
 */
class TaskBudgetResumer
{
    public function __construct(
        private readonly TaskBudgetService $tasks,
        private readonly CeilingDecisionParser $parser,
        private readonly TaskBudgetText $text,
        private readonly TaskBudgetReport $report,
    ) {
    }

    public function resume(AgentQuestion $question, ?Conversation $conversation, ResumeCaller $caller): ?array
    {
        $recorder = app(RunTraceRecorder::class);
        $task = $question->task_budget_id === null ? null : TaskBudget::find($question->task_budget_id);

        if ($conversation === null || $task === null) {
            if ($task !== null) {
                $this->discard($task, $question, false);
            }
            $recorder->closeRun($question->run_id, RunEndState::StoppedEarly, QuestionResumer::CONTEXT_DELETED_REASON);

            return null;
        }

        if ($question->state === QuestionState::Withdrawn) {
            return $this->withdrawn($question, $task);
        }

        if ($question->state !== QuestionState::Answered) {
            Log::warning("TaskBudgetResumer: ceiling prompt {$question->id} was resumed in state {$question->state->value}");

            return null;
        }

        $decision = $this->parser->parse((string) $question->answer_raw);
        if ($decision->kind === CeilingDecision::ACCEPT) {
            return $this->accept($question, $conversation, $task, $caller);
        }
        if ($decision->kind === CeilingDecision::CONTINUE) {
            return $this->continue($question, $conversation, $task, $decision, $caller);
        }

        Log::warning("TaskBudgetResumer: ceiling prompt {$question->id} was answered with no decision to apply");

        return null;
    }

    private function continue(AgentQuestion $question, Conversation $conversation, TaskBudget $task, CeilingDecision $decision, ResumeCaller $caller): ?array
    {
        $limits = app(TaskBudgetLimits::class);
        $grant = $limits->clampAllowance((int) $task->max_steps, $decision->addSteps, (string) $task->max_spend, $decision->addSpend);
        $newSteps = $grant['steps'];
        $newSpend = $grant['spend'];
        $standing = $this->tasks->standing($task);
        $threshold = Decimal::toPlainNotation((string) $task->approach_threshold);

        $updates = [
            'state' => TaskBudgetState::Running->value,
            'max_steps' => $newSteps,
            'max_spend' => $newSpend,
            'paused_at' => null, 'pause_reason' => null, 'paused_run_id' => null, 'paused_iteration' => null,
            'resume_mode' => null, 'paused_steps_used' => null, 'paused_spend' => null, 'prompt_question_id' => null,
            'updated_at' => now()->format('Y-m-d H:i:s.u'),
        ];
        if (bccomp(bcdiv((string) $standing->stepsUsed, (string) $newSteps, 10), $threshold, 10) < 0) {
            $updates['steps_approach_noticed_at'] = null;
        }
        if (bccomp(bcdiv(Decimal::toPlainNotation($standing->spendUsed), $newSpend, 10), $threshold, 10) < 0) {
            $updates['spend_approach_noticed_at'] = null;
        }

        $won = DB::table('task_budgets')->where('id', $task->id)
            ->where('state', TaskBudgetState::Paused->value)
            ->where('prompt_question_id', $question->id)
            ->update($updates);
        if ($won !== 1) {
            return null;
        }

        $runId = $task->paused_run_id;
        $iteration = (int) ($task->paused_iteration ?? 1);
        $mode = (string) ($task->resume_mode ?? $question->resume_mode);
        $task->refresh();
        $this->tasks->appendEvent($task, 'decision', ['decision' => 'continue', 'question_id' => $question->id], $question->answered_by);
        $this->tasks->appendEvent($task, 'allowance_granted', [
            'add_steps' => $grant['add_steps'], 'add_spend' => $grant['add_spend'],
            'max_steps' => $newSteps, 'max_spend' => $newSpend,
            'steps_reduced' => $grant['steps_reduced'], 'spend_reduced' => $grant['spend_reduced'],
        ], $question->answered_by);

        Conversation::find($conversation->id)?->update(['is_processing' => true]);
        $conversation->setAttribute('is_processing', true);
        $loop = app(AgentLoopService::class);

        try {
            if (($stop = $loop->resumeTaskBudgetPrologue($conversation, $runId)) !== null) {
                return $stop;
            }
            if ($mode === 'stream') {
                $loop->resumeAfterTaskBudget($conversation, $runId, $iteration);

                return null;
            }
            $result = $loop->resumeAfterTaskBudgetSync($conversation, $runId, $iteration);
            if ($caller === ResumeCaller::Background && ($result['status'] ?? null) !== 'awaiting_user') {
                $this->deliver($conversation, (string) ($result['content'] ?? ''));
            }

            return $result;
        } catch (BudgetExceededException|RateLimitExceededException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Conversation::find($conversation->id)?->update(['is_processing' => false]);
            app(RunTraceRecorder::class)->closeRun($runId, RunEndState::Failed, 'failed');
            Log::error('TaskBudgetResumer: the resumed task failed to continue', [
                'question_id' => $question->id,
                'task_budget_id' => $task->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function accept(AgentQuestion $question, Conversation $conversation, TaskBudget $task, ResumeCaller $caller): ?array
    {
        $reason = $this->text->concludedReason();
        $runId = $task->paused_run_id;
        $mode = (string) ($task->resume_mode ?? $question->resume_mode);
        $standing = $this->tasks->standing($task);
        $now = now()->format('Y-m-d H:i:s.u');
        $won = DB::table('task_budgets')->where('id', $task->id)
            ->where('state', TaskBudgetState::Paused->value)
            ->where('prompt_question_id', $question->id)
            ->update(['state' => TaskBudgetState::Concluded->value, 'end_reason' => $reason, 'ended_at' => $now, 'updated_at' => $now]);
        if ($won !== 1) {
            return null;
        }
        $task->refresh();
        $this->tasks->appendEvent($task, 'decision', ['decision' => 'accept', 'question_id' => $question->id], $question->answered_by);

        $content = $this->report->compose($task, $standing, TaskBudgetReport::OCCASION_ACCEPTED);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => $conversation->character,
            'content' => $content,
            'responseTime' => 0,
            'tool_data' => ['kind' => AgentQuestionService::KIND_TASK_BUDGET_REPORT, 'task_budget_id' => $task->id, 'occasion' => TaskBudgetReport::OCCASION_ACCEPTED],
        ]);
        $this->tasks->appendEvent($task, 'report', ['occasion' => 'accepted', 'message_id' => $message->id]);
        Conversation::find($conversation->id)?->update(['is_processing' => false]);
        app(RunTraceRecorder::class)->closeRun($runId, RunEndState::Completed, $reason);

        if ($mode === 'sync' && $caller === ResumeCaller::Background) {
            $this->deliver($conversation, $content);
        }

        return ['status' => 'completed', 'content' => $content];
    }

    /**
     * The conversation was ended or deleted while the task waited on its
     * prompt: the task is discarded and its run closed.
     */
    public function withdrawn(AgentQuestion $question, TaskBudget $task): ?array
    {
        $this->discard($task, $question, true);
        $reason = $question->stopped_at !== null ? QuestionResumer::DISCARDED_REASON : QuestionResumer::WITHDRAWN_REASON;
        app(RunTraceRecorder::class)->closeRun($question->run_id, RunEndState::StoppedEarly, $reason);

        return null;
    }

    private function discard(TaskBudget $task, AgentQuestion $question, bool $withdrawn): void
    {
        $now = now()->format('Y-m-d H:i:s.u');
        $won = DB::table('task_budgets')->where('id', $task->id)
            ->where('state', TaskBudgetState::Paused->value)
            ->where('prompt_question_id', $question->id)
            ->update(['state' => TaskBudgetState::Ended->value, 'end_reason' => 'discarded', 'ended_at' => $now, 'updated_at' => $now]);
        if ($won !== 1) {
            return;
        }
        $task->refresh();
        if ($withdrawn) {
            $this->tasks->appendEvent($task, 'prompt_withdrawn', ['question_id' => $question->id]);
        }
        $this->tasks->appendEvent($task, 'ended', ['reason' => 'discarded']);
    }

    private function deliver(Conversation $conversation, string $content): void
    {
        if (trim($content) === '') {
            return;
        }
        $delivery = app(AgentQuestionService::class)->deliver($conversation, $content);
        if (!$delivery->delivered) {
            Log::warning('TaskBudgetResumer: the reply could not be delivered', ['conversation_id' => $conversation->id, 'reason' => $delivery->failureReason]);
        }
    }
}
