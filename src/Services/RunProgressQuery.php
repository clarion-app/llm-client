<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\AgentRun;
use ClarionApp\LlmClient\Models\TaskBudget;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\TaskBudgetState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The derived progress view of one run: what state its task is in, what it
 * is doing at this moment, how many steps are committed and, only when the
 * task has a known structure, how far through it is.
 *
 * Nothing here is stored. Every value is read from the run row, its newest
 * step and checkpoint, one count, one structure lookup and the task's
 * standing, so the number of queries does not depend on the size of the run.
 * The payload never names an argument or an identifier of a step, call or
 * tool call, and it has no percentage in any shape: a task whose structure is
 * not known says its remaining work is unknown.
 */
class RunProgressQuery
{
    /** Delegation states that mean a helper has finished, successfully or not. */
    private const HELPER_FINISHED = ['completed', 'failed', 'exhausted'];

    public function __construct(private readonly RunTraceQuery $runs)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function forRun(AgentRun $run): array
    {
        $terminal = $run->end_state->isTerminal();

        $stepFacts = $this->stepFacts($run->id);
        $newest = $this->newestStep($run->id);
        $task = $this->taskOf($run);
        $cancelledByEvent = $terminal && $this->hasCancellationEvent($run->id);

        $batch = $terminal ? null : $this->openBatch($run->id);
        $structure = $terminal ? null : $this->structure($run, $batch);

        $state = $this->state($run, $task, $cancelledByEvent);

        $payload = [
            'run_id' => $run->id,
            'state' => $state,
            'activity' => $this->activity($run, $state, $newest, $batch),
            'steps_completed' => $stepFacts['completed'],
            'structure' => $structure,
            'remaining' => $structure === null ? 'unknown' : null,
        ];
        if ($task !== null) {
            $payload['allowance'] = [
                'steps_used' => (int) $task->steps_used,
                'max_steps' => (int) $task->max_steps,
                'label' => 'allowance used',
            ];
        }

        return $payload + [
            'started_at' => $run->started_at?->toJSON(),
            'elapsed_ms' => $this->elapsedMs($run),
            'last_progress_at' => ($run->last_progress_at ?? $stepFacts['latest'])?->toJSON(),
            'resume_count' => (int) $run->resume_count,
            'interrupted_at' => $run->interrupted_at?->toJSON(),
            'next_attempt_at' => $run->next_attempt_at?->toJSON(),
        ];
    }

    /**
     * The state, by one fixed order: cancelled, finished, failed, waiting on
     * the user, waiting to retry the provider, interrupted, resuming, working.
     */
    private function state(AgentRun $run, ?TaskBudget $task, bool $cancelledByEvent): string
    {
        $terminal = $run->end_state->isTerminal();

        if ($run->cancelled_at !== null || $cancelledByEvent || $task?->state === TaskBudgetState::Halted) {
            return 'cancelled';
        }
        if ($run->end_state === RunEndState::Completed || ($terminal && $task?->state === TaskBudgetState::Concluded)) {
            return 'finished';
        }
        if ($terminal) {
            return 'failed';
        }
        if ($run->resume_state === 'paused'
            || $task?->state === TaskBudgetState::Paused
            || isset($this->runs->runIdsAwaitingUser([$run->id])[$run->id])) {
            return 'waiting_on_user';
        }

        return match ($run->resume_state) {
            'waiting_provider_retry' => 'waiting_provider_retry',
            'interrupted' => 'interrupted',
            'resuming' => 'resuming',
            default => 'working',
        };
    }

    /**
     * What the task is doing right now, by label only.
     *
     * @return array{kind: string, label?: string}
     */
    private function activity(AgentRun $run, string $state, ?object $newest, ?array $batch): array
    {
        if ($run->end_state->isTerminal()) {
            return ['kind' => 'none'];
        }
        if ($state === 'waiting_provider_retry') {
            return ['kind' => 'waiting_to_retry'];
        }
        if ($batch !== null && $batch['finished'] < $batch['total']) {
            return ['kind' => 'waiting_on_helpers', 'label' => "{$batch['finished']} of {$batch['total']} helpers finished"];
        }
        if ($newest !== null && $newest->end_state === RunEndState::InProgress->value) {
            $label = $this->startedLabel($newest->checkpoint);
            if ($label !== null) {
                return ['kind' => 'running_tool', 'label' => $label];
            }
            if ($newest->checkpoint_state === 'open') {
                return ['kind' => 'waiting_for_model'];
            }
        }

        return ['kind' => 'between_steps'];
    }

    /** The label of the first call of a checkpoint that has started, or null. */
    private function startedLabel(?string $checkpoint): ?string
    {
        $decoded = $checkpoint === null ? null : json_decode($checkpoint, true);
        foreach (is_array($decoded) ? ($decoded['calls'] ?? []) : [] as $call) {
            if (is_array($call) && ($call['state'] ?? null) === 'started') {
                $label = $call['label'] ?? $call['tool'] ?? null;

                return is_string($label) && $label !== '' ? $label : null;
            }
        }

        return null;
    }

    /**
     * Completed steps and the latest step activity, from one aggregate.
     *
     * @return array{completed: int, latest: ?Carbon}
     */
    private function stepFacts(string $runId): array
    {
        $row = DB::table('agent_run_steps')
            ->where('run_id', $runId)
            ->selectRaw("COALESCE(SUM(CASE WHEN end_state = 'completed' THEN 1 ELSE 0 END), 0) AS completed")
            ->selectRaw('MAX(started_at) AS latest_start, MAX(ended_at) AS latest_end')
            ->first();

        $times = array_filter([$row->latest_start ?? null, $row->latest_end ?? null]);
        $latest = $times === [] ? null : Carbon::parse(max($times));

        return ['completed' => (int) ($row->completed ?? 0), 'latest' => $latest];
    }

    private function newestStep(string $runId): ?object
    {
        return DB::table('agent_run_steps')
            ->where('run_id', $runId)
            ->orderByDesc('position')
            ->select('end_state', 'checkpoint_state', 'checkpoint')
            ->first();
    }

    private function taskOf(AgentRun $run): ?TaskBudget
    {
        return $this->runs->taskBudgetsForRuns([$run->id])[$run->id] ?? null;
    }

    private function hasCancellationEvent(string $runId): bool
    {
        return DB::table('agent_run_resume_events')
            ->where('run_id', $runId)
            ->where('kind', 'resume_refused_cancelled')
            ->exists();
    }

    private function elapsedMs(AgentRun $run): ?int
    {
        if ($run->started_at === null) {
            return null;
        }
        if ($run->end_state->isTerminal()) {
            return $run->duration_ms !== null
                ? (int) $run->duration_ms
                : ($run->ended_at !== null ? (int) $run->started_at->diffInMilliseconds($run->ended_at) : null);
        }

        return max(0, (int) $run->started_at->diffInMilliseconds(now()));
    }

    /**
     * The run's newest batch of helpers and how many have finished, or null
     * when it has none, or when its newest batch is entirely finished.
     *
     * @return array{total: int, finished: int}|null
     */
    private function openBatch(string $runId): ?array
    {
        $batchId = DB::table('agent_delegations')
            ->where('parent_run_id', $runId)
            ->whereNotNull('batch_id')
            ->orderByDesc('started_at')
            ->value('batch_id');
        if ($batchId === null) {
            return null;
        }

        $statuses = DB::table('agent_delegations')
            ->where('batch_id', $batchId)
            ->where('parent_run_id', $runId)
            ->selectRaw('status, COUNT(*) AS n')
            ->groupBy('status')
            ->pluck('n', 'status');
        $total = (int) $statuses->sum();
        $finished = (int) $statuses->only(self::HELPER_FINISHED)->sum();

        return $finished < $total ? ['total' => $total, 'finished' => $finished] : null;
    }

    /**
     * The task's known structure, looked up in order and stopping at the
     * first that exists: stages of a sequence, accepted parts of a managed
     * task, helpers of the open batch. Null when none is known.
     *
     * @return array{kind: string, completed: int, total: int, label: string}|null
     */
    private function structure(AgentRun $run, ?array $batch): ?array
    {
        return $this->stages($run->conversation_id)
            ?? $this->parts($run->conversation_id)
            ?? ($batch === null ? null : [
                'kind' => 'helpers',
                'completed' => $batch['finished'],
                'total' => $batch['total'],
                'label' => "{$batch['finished']} of {$batch['total']} helpers finished",
            ]);
    }

    private function stages(?string $conversationId): ?array
    {
        if ($conversationId === null) {
            return null;
        }
        $sequence = DB::table('sequence_runs')
            ->where('conversation_id', $conversationId)
            ->whereIn('status', ['in_progress', 'resumed'])
            ->orderByDesc('started_at')
            ->select('id', 'sequence_definition_id')
            ->first();
        if ($sequence === null) {
            return null;
        }
        $total = DB::table('stages')->where('sequence_definition_id', $sequence->sequence_definition_id)->count();
        if ($total === 0) {
            return null;
        }
        $completed = DB::table('stage_results')
            ->where('sequence_run_id', $sequence->id)
            ->where('status', 'completed')
            ->count();

        return [
            'kind' => 'stages',
            'completed' => $completed,
            'total' => $total,
            'label' => 'stage ' . min($completed + 1, $total) . " of {$total}",
        ];
    }

    private function parts(?string $conversationId): ?array
    {
        if ($conversationId === null) {
            return null;
        }
        $taskId = DB::table('managed_tasks')
            ->where('conversation_id', $conversationId)
            ->where('status', 'in_progress')
            ->orderByDesc('started_at')
            ->value('id');
        if ($taskId === null) {
            return null;
        }
        $row = DB::table('managed_task_parts')
            ->where('managed_task_id', $taskId)
            ->selectRaw("COUNT(*) AS total, COALESCE(SUM(CASE WHEN state = 'accepted' THEN 1 ELSE 0 END), 0) AS accepted")
            ->first();
        $total = (int) ($row->total ?? 0);
        if ($total === 0) {
            return null;
        }
        $accepted = (int) $row->accepted;

        return [
            'kind' => 'parts',
            'completed' => $accepted,
            'total' => $total,
            'label' => "{$accepted} of {$total} parts accepted",
        ];
    }
}
