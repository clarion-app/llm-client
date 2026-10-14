<?php

namespace ClarionApp\LlmClient\Contracts;

/**
 * The fault-injection seam (research D21). Production binds
 * `NullFaultInjector` (one method call per named point); tests bind
 * `SnapshotFaultInjector` to stage a dead worker at a chosen point and
 * occurrence. The named points are the exact moments a kill would leave
 * the run in a resumable (or outcome-unknown) state.
 */
interface FaultInjector
{
    /** The twelve D21 fault points, nothing else. */
    public const POINTS = [
        'after_model_recorded',
        'before_tool_started',
        'after_tool_started',
        'after_tool_effect',
        'after_tool_recorded',
        'before_step_message',
        'after_step_message',
        'mid_stream',
        'after_claim',
        'during_pause_resume',
        'after_delegation_row',
        'during_join_wait',
    ];

    /**
     * Record that execution reached the named point. A no-op in
     * production; the test injector snapshots and throws when the point
     * and occurrence match its arming.
     */
    public function reach(string $point): void;
}
