<?php

namespace ClarionApp\LlmClient\Jobs;

use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentRun;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\SchedulerTrigger;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\RoleResolver;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Runs one already-latched trigger event: loads the SchedulerTrigger, gets or
 * lazily creates its one dedicated Conversation, drives the unattended agent
 * loop against it, and records which agent_runs row this firing produced.
 *
 * $tries = 1, mirroring RunManagedTaskStepJob's own reasoning: a worker-level
 * failure here must fail permanently on first attempt, never be silently
 * redelivered by Laravel's own attempts bookkeeping into replaying the same
 * trigger event a second time. The dedup latch this job's own $fireKey
 * already won only ever allows one dispatch per logical event in the first
 * place, so a redelivered retry would not even be a legitimate re-attempt --
 * it would just run the defined work again for free.
 *
 * No bespoke crash recovery lives here: if this job's own process dies
 * between AgentLoopService::run() opening its run row and this job resuming,
 * the already-registered llm-client:resolve-abandoned-runs sweep force-closes
 * that stale in_progress row on its own cadence, with no code change needed
 * here or there.
 */
final class RunSchedulerTriggerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly string $triggerId,
        public readonly string $fireKey,
    ) {
    }

    public function handle(AgentLoopService $loop): void
    {
        $trigger = SchedulerTrigger::find($this->triggerId);

        if ($trigger === null) {
            return;
        }

        $conversation = $this->resolveOrCreateConversation($trigger);

        try {
            $loop->run($conversation, $trigger->defined_work, [
                'unattended' => true,
                'retry_limit' => $trigger->retry_limit,
            ]);
        } finally {
            // run()'s own return value never carries the run id it opened
            // (every one of its return shapes omits it), so the run this
            // call produced is identified the same way as any other read of
            // "this conversation's latest run" elsewhere in this package: by
            // querying agent_runs for the newest row against this
            // conversation. Wrapped in finally so a firing's run_id is still
            // recorded even when run() itself threw, matching
            // scheduler_trigger_firings.run_id's own documented "narrow
            // window" tolerance rather than leaving it null on every
            // exception path.
            $this->recordRunId($conversation);
        }
    }

    private function resolveOrCreateConversation(SchedulerTrigger $trigger): Conversation
    {
        $conversation = Conversation::where('scheduler_trigger_id', $trigger->id)->first();

        if ($conversation !== null) {
            return $conversation;
        }

        $agent = Agent::find($trigger->agent_id);

        // Same RoleResolver/server-model resolution recipe
        // DelegationService::createDelegationRow()/ManagerService::createManagedTask()
        // already use for their own dedicated conversations.
        $resolution = app(RoleResolver::class)->resolve(ModelRole::Inference, $trigger->user_id);
        $serverId = $resolution->hasEffectiveModel() ? $resolution->server->id : null;
        $modelName = $resolution->hasEffectiveModel() ? $resolution->model : null;

        return Conversation::create([
            'user_id' => $trigger->user_id,
            'server_id' => $serverId,
            'model' => $modelName,
            'character' => 'Clarion',
            'channel' => 'scheduler-trigger',
            'agent_id' => $trigger->agent_id,
            'agent_version_id' => $agent?->current_version_id,
            'scheduler_trigger_id' => $trigger->id,
        ]);
    }

    private function recordRunId(Conversation $conversation): void
    {
        $run = AgentRun::where('conversation_id', $conversation->id)
            ->orderByDesc('started_at')
            ->first();

        if ($run === null) {
            return;
        }

        DB::table('scheduler_trigger_firings')
            ->where('trigger_id', $this->triggerId)
            ->where('fire_key', $this->fireKey)
            ->whereNull('run_id')
            ->update(['run_id' => $run->id]);
    }
}
