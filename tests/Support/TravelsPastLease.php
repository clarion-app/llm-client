<?php

namespace Tests\Support;

use ClarionApp\LlmClient\Models\Conversation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Travels the faked clock past the run's actual lease expiry so the
 * interruption sweep sees an expired lease.
 *
 * A run killed during a tool has its lease extended by
 * `RunCheckpoint::markStarted()` to `now + ToolLivenessBound +
 * lease_grace_seconds` (checkpoint-ledger), which can be well beyond
 * `now + detection_window_seconds`. Travelling only `window + 1 s` therefore
 * leaves the lease in the future and the sweep correctly skips the run.
 * Travelling to `max(lease_expires_at, now + window) + 1 s` covers both the
 * extended-lease case and the plain `now + window` case (a run killed between
 * steps, whose last lease renew set `now + window`).
 */
trait TravelsPastLease
{
    private function travelPastLease(Conversation $conversation): void
    {
        $window = (int) config('llm-client.resume.detection_window_seconds', 300);
        $target = now()->addSeconds($window);

        $row = DB::table('agent_runs')->where('conversation_id', $conversation->id)
            ->orderByDesc('started_at')->first();
        if ($row !== null && $row->lease_expires_at !== null) {
            $lease = Carbon::parse($row->lease_expires_at);
            if ($lease->greaterThan($target)) {
                $target = $lease;
            }
        }

        Carbon::setTestNow($target->copy()->addSecond());
    }

    /**
     * Travel past the largest lease across every run in the table. Used by
     * the multi-run journey (AC2), where several runs are interrupted at the
     * same instant and each must be detected by the sweep.
     */
    private function travelPastAllLeases(): void
    {
        $window = (int) config('llm-client.resume.detection_window_seconds', 300);
        $target = now()->addSeconds($window);

        $max = DB::table('agent_runs')->max('lease_expires_at');
        if ($max !== null) {
            $lease = Carbon::parse($max);
            if ($lease->greaterThan($target)) {
                $target = $lease;
            }
        }

        Carbon::setTestNow($target->copy()->addSecond());
    }
}
