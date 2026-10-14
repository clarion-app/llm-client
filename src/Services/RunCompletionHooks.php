<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\SchedulerTriggerFiring;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * What an unattended run's original caller would have done once the run
 * ended, done for a run whose caller is gone (its worker was lost and the run
 * was resumed, or its provider retry ran in a delayed job). The caller
 * names the work in the run's own options (`on_complete`), so the hook
 * survives the caller.
 *
 * A hook runs at most once per run: it is claimed by a compare-and-set of
 * the run's stored options, so a resume that ends, a stop with a report and a
 * second pass over the same run cannot repeat it. An unknown hook name is
 * logged and ignored.
 */
class RunCompletionHooks
{
    /**
     * Run the run's completion hook, if it names one that has not run yet.
     * A run that is still going is left alone.
     */
    public function run(string $runId): void
    {
        $row = DB::table('agent_runs')->where('id', $runId)->first(['end_state', 'resume_options']);
        if ($row === null || $row->end_state === RunEndState::InProgress->value) {
            return;
        }

        $raw = $row->resume_options;
        $options = is_string($raw) ? json_decode($raw, true) : null;
        $hook = is_array($options) && is_array($options['on_complete'] ?? null) ? $options['on_complete'] : null;
        if ($hook === null || isset($hook['ran_at'])) {
            return;
        }

        // The claim: only the process whose compare-and-set lands runs the hook.
        $options['on_complete']['ran_at'] = now()->toIso8601String();
        $claimed = DB::table('agent_runs')
            ->where('id', $runId)
            ->where('resume_options', $raw)
            ->update(['resume_options' => json_encode($options)]);
        if ($claimed !== 1) {
            return;
        }

        try {
            $this->dispatch($runId, $hook);
        } catch (\Throwable $e) {
            Log::warning('RunCompletionHooks: a completion hook failed', [
                'run_id' => $runId,
                'hook' => (string) ($hook['hook'] ?? ''),
                'error' => get_class($e),
            ]);
        }
    }

    /** @param array<string, mixed> $hook */
    private function dispatch(string $runId, array $hook): void
    {
        $name = (string) ($hook['hook'] ?? '');

        switch ($name) {
            case 'managed_task_step':
                app(ManagerService::class)->afterRound((string) ($hook['managed_task_id'] ?? ''));
                break;

            case 'scheduler_firing':
                SchedulerTriggerFiring::recordRunId(
                    (string) ($hook['trigger_id'] ?? ''),
                    (string) ($hook['fire_key'] ?? ''),
                    $runId,
                );
                break;

            default:
                Log::info("RunCompletionHooks: the completion hook '{$name}' is not known, so it was ignored", [
                    'run_id' => $runId,
                    'hook' => $name,
                ]);
        }
    }
}
