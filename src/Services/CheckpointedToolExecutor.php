<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Contracts\FaultInjector;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\ValueObjects\ResumeDriver;
use ClarionApp\LlmClient\ValueObjects\StepRef;
use Closure;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

/**
 * The funnel that brackets every tool execution with the start marker
 * (C3, before the call) and the completion marker (C4, after). The
 * exactly-once contract: the start marker is written before the closure
 * runs (a failed start marker never runs the closure), the call id is
 * stamped into Context and restored, the fault points fire in order, and
 * the lease is extended over the action's bound. The bracketing is a
 * no-op (the tool still runs) when the run is not checkpointed.
 */
class CheckpointedToolExecutor
{
    public function __construct(
        private readonly RunCheckpoint $checkpoint,
        private readonly ToolEffectClassifier $classifier,
        private readonly ToolLivenessBound $bound,
        private readonly FaultInjector $fault,
    ) {
    }

    /**
     * Run one tool call inside the bracket. The start marker (C3) is
     * written before $execute runs; the raw result is returned for the
     * call site to condense, which then records C4 via recordCompleted().
     */
    public function execute(StepRef $step, array $toolCall, Closure $execute, ?string $actionId, Conversation $conversation): string
    {
        [$tool, $arguments] = $this->toolAndArguments($toolCall);
        $callId = (string) ($toolCall['id'] ?? '');

        $this->fault->reach('before_tool_started');
        $effect = $this->classifier->classify($tool, $arguments, $conversation);
        $this->write(fn () => $this->checkpoint->markStarted($step->stepId, $callId, $effect, (string) $actionId, $this->leaseFor($step->runId, $tool, $arguments)));

        return $this->withToolCallId($callId, function () use ($execute) {
            $this->fault->reach('after_tool_started');
            $raw = $execute();
            $this->fault->reach('after_tool_effect');

            return $raw;
        });
    }

    /**
     * The start half of a batch: mark every call started, in order, before
     * the batch is dispatched. The completion half is recordCompleted()
     * per call, once the batch returns.
     */
    public function startBatch(StepRef $step, array $toolCalls, Conversation $conversation): void
    {
        foreach ($toolCalls as $toolCall) {
            [$tool, $arguments] = $this->toolAndArguments($toolCall);
            $callId = (string) ($toolCall['id'] ?? '');

            $effect = $this->classifier->classify($tool, $arguments, $conversation);
            $this->write(fn () => $this->checkpoint->markStarted($step->stepId, $callId, $effect, '', $this->leaseFor($step->runId, $tool, $arguments)));
        }
    }

    /**
     * C4: the call is completed with its result, then the record point.
     * A non-JSON tool result json_decodes to null; it is normalised to a
     * neutral entry rather than letting the call site's json_decode()
     * hand a null into the array-typed markCompleted().
     */
    public function recordCompleted(StepRef $step, string $toolCallId, ?array $resultEntry): void
    {
        $resultEntry ??= ['result' => null];
        $this->write(fn () => $this->checkpoint->markCompleted($step->stepId, $toolCallId, $resultEntry));
        $this->fault->reach('after_tool_recorded');
    }

    /** C6: a pause marker is recorded for the paused call. */
    public function recordPaused(StepRef $step, string $toolCallId): void
    {
        $this->write(fn () => $this->checkpoint->markPaused($step->runId, $step->stepId, $toolCallId));
    }

    /**
     * Run a checkpoint write only outside a transaction: inside one (a
     * test's wrapping transaction) the write is a no-op, the same posture
     * the run's own step writes take — the tool still runs either way.
     */
    private function write(Closure $write): void
    {
        if (DB::transactionLevel() > 0) {
            return;
        }

        $write();
    }

    /**
     * Run $body with the call id stamped into Context, restoring the
     * previous value (or clearing it) in a finally, so a call that throws
     * still leaves Context as it found it.
     */
    private function withToolCallId(string $callId, Closure $body): mixed
    {
        $previous = Context::get('tool_call_id');
        Context::add('tool_call_id', $callId);
        try {
            return $body();
        } finally {
            if ($previous === null) {
                Context::forget('tool_call_id');
            } else {
                Context::add('tool_call_id', $previous);
            }
        }
    }

    /** The lease to extend over the action, for the run's driver. */
    private function leaseFor(string $runId, string $tool, array $arguments): int
    {
        return $this->bound->leaseSeconds($tool, $arguments, $this->driverFor($runId));
    }

    /** The run's driver, defaulting to a root run when it has none. */
    private function driverFor(string $runId): ResumeDriver
    {
        $value = DB::table('agent_runs')->where('id', $runId)->value('resume_driver');
        if ($value === null) {
            return ResumeDriver::Root;
        }

        return ResumeDriver::tryFrom((string) $value) ?? ResumeDriver::Root;
    }

    /** The tool name and decoded arguments of a tool call. */
    private function toolAndArguments(array $toolCall): array
    {
        $tool = (string) ($toolCall['function']['name'] ?? '');
        $arguments = json_decode((string) ($toolCall['function']['arguments'] ?? ''), true);

        return [$tool, is_array($arguments) ? $arguments : []];
    }
}
