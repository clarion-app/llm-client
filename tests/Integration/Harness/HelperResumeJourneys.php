<?php

namespace Tests\Integration\Harness;

use ClarionApp\LlmClient\Jobs\RunDelegationBatchMemberJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InjectedFault;

/**
 * Shared fixtures for the journeys that kill a helper (or the work that
 * delegates to it) and have the resume finish the job: the tables a kill
 * leaves behind, a stand-in for the death of a process that no fault point
 * sits inside, and the helper serving of ServesHelpers.
 *
 * For use in an AssembledSystemTestCase.
 */
trait HelperResumeJourneys
{
    use InterruptedRunJourneys;
    use ServesHelpers;

    /** Every table a kill inside a delegation tree leaves as it was at the kill. */
    protected function killTables(): array
    {
        return [
            'agent_runs', 'agent_run_steps', 'agent_run_actions', 'agent_delegations',
            'conversations', 'messages', 'sequence_runs', 'stage_results',
            'consensus_requests', 'scheduler_trigger_firings', 'managed_tasks', 'managed_task_parts',
            'task_budgets',
        ];
    }

    /** killAt() over the whole delegation tree's tables. */
    protected function killTreeAt(callable $drive, string $point, int $occurrence): void
    {
        $this->killAt($drive, $point, $occurrence, $this->killTables());
    }

    /** @return array<string, list<array<string, mixed>>> every table of the tree, as it is now */
    protected function snapshotTree(): array
    {
        $snapshot = [];
        foreach ($this->killTables() as $table) {
            $snapshot[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        }

        return $snapshot;
    }

    /** Put every table of the tree back exactly as $snapshot holds it (a process died at that moment). */
    protected function restoreTree(array $snapshot): void
    {
        foreach ($snapshot as $table => $rows) {
            DB::table($table)->delete();
            if ($rows !== []) {
                DB::table($table)->insert($rows);
            }
        }
    }

    /**
     * Like sweep(), but also keeping the listed job classes from running
     * inline (the work the resume queues is only observed, not done).
     *
     * @param  list<class-string>  $alsoFaked
     * @return list<\ClarionApp\LlmClient\Jobs\ResumeInterruptedRunJob>
     */
    protected function sweepFaking(array $alsoFaked): array
    {
        $this->travelPastAllLeases();
        \Illuminate\Support\Facades\Queue::fake(array_merge([
            \ClarionApp\LlmClient\Jobs\AgentStreamStepJob::class,
            \ClarionApp\LlmClient\Jobs\ResumeInterruptedRunJob::class,
        ], $alsoFaked));
        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('llm-client:resume-interrupted-runs'), 'the sweep ran');

        return \Illuminate\Support\Facades\Queue::pushed(\ClarionApp\LlmClient\Jobs\ResumeInterruptedRunJob::class)->values()->all();
    }

    /** A failure raised inside a query listener (where a thrown assertion could be swallowed by the code under test). */
    protected ?\Throwable $listenerFailure = null;

    /** Run $callback at each poll of a batch's join (the parent's wait for its members), numbered from 1. */
    protected function atEachJoinPoll(callable $callback, int $valve = 14): void
    {
        $polls = 0;
        $busy = false;
        DB::listen(function ($query) use (&$polls, &$busy, $callback, $valve) {
            if ($busy || !str_starts_with($query->sql, 'select "id", "status" from "agent_delegations" where "id" in')) {
                return;
            }
            $busy = true;
            try {
                $polls++;
                if ($polls >= $valve && $this->listenerFailure === null) {
                    $this->listenerFailure = new \RuntimeException("the parent was still waiting after {$valve} polls");
                    throw $this->listenerFailure;
                }
                $callback($polls);
            } catch (InjectedFault $e) {
                throw $e;
            } catch (\Throwable $e) {
                $this->listenerFailure ??= $e;
                throw $e;
            } finally {
                $busy = false;
            }
        });
    }

    protected function assertNoListenerFailure(): void
    {
        if ($this->listenerFailure !== null) {
            throw $this->listenerFailure;
        }
    }

    /** Work one queued batch-member job as its worker would. */
    protected function workMember(object $job): void
    {
        $this->app->forgetScopedInstances();
        $this->app->call([$job, 'handle']);
    }

    /** @return list<RunDelegationBatchMemberJob> the member jobs pushed so far for one delegation */
    protected function memberJobsFor(string $delegationId): array
    {
        return Queue::pushed(RunDelegationBatchMemberJob::class)
            ->filter(fn ($job) => $job->delegationId === $delegationId)
            ->values()
            ->all();
    }

}
