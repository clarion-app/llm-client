<?php

namespace ClarionApp\LlmClient\Commands;

use ClarionApp\LlmClient\Jobs\RunSchedulerTriggerJob;
use ClarionApp\LlmClient\Models\SchedulerTrigger;
use ClarionApp\LlmClient\Services\SchedulerTriggerEvaluator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ticks every active, non-trashed SchedulerTrigger once and dispatches
 * RunSchedulerTriggerJob for exactly the ones a due evaluation newly wins the
 * dedup latch for.
 *
 * The unique index on scheduler_trigger_firings(trigger_id, fire_key) is the
 * actual dedup guarantee, not this command's own ->withoutOverlapping() —
 * that only stops two invocations of this command from running concurrently
 * as processes. insertOrIgnore() returning 1 means this tick is the one that
 * gets to dispatch the job for that logical event; 0 means some other tick
 * (a late run re-evaluating an already-claimed minute, or a second process
 * on another host) already claimed it, so nothing further happens here.
 */
class EvaluateSchedulerTriggersCommand extends Command
{
    protected $signature = 'llm-client:evaluate-scheduler-triggers';

    protected $description = 'Evaluate every active scheduler trigger and dispatch its run when newly due';

    public function handle(SchedulerTriggerEvaluator $evaluator): int
    {
        $triggers = SchedulerTrigger::where('is_active', true)->get();

        $dispatched = 0;

        foreach ($triggers as $trigger) {
            [$due, $fireKey] = $evaluator->evaluate($trigger);

            if (!$due || $fireKey === null) {
                continue;
            }

            $won = DB::table('scheduler_trigger_firings')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'trigger_id' => $trigger->id,
                'fire_key' => $fireKey,
                'created_at' => now(),
            ]);

            if ($won === 1) {
                RunSchedulerTriggerJob::dispatch($trigger->id, $fireKey);
                $dispatched++;
            }
        }

        $this->info("Scheduler triggers dispatched: {$dispatched}");

        return self::SUCCESS;
    }
}
