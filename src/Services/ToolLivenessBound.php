<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\CapabilityOffering;
use ClarionApp\LlmClient\ValueObjects\ResumeDriver;
use Illuminate\Support\Carbon;

/**
 * How long a run's lease must be extended before a slow action, so a live
 * but slow run is never mistaken for dead. Each action type is bounded by
 * its own timeout (a command, a test, an mcp call, a delegation, a
 * capability call); an unknown action is bounded by the detection window.
 * The lease to set is `max(base, bound + lease_grace_seconds)`, the base
 * being the detection window for a root run and the helper base for a
 * helper run. A model call is bounded by the provider's timeout, and the
 * whole thing is capped by the run's own deadline when it has one.
 */
class ToolLivenessBound
{
    /** The coding-workspace test operation id (the command id is the loop's own constant). */
    private const RUN_TESTS_OPERATION_ID = 'clarionApp.llmClient.codingWorkspace.runTests';

    /** The bound for a given action, in seconds. */
    public function for(string $tool, array $arguments): int
    {
        if ($tool === 'delegate_to_helper') {
            return (int) config('llm-client.delegation.max_seconds');
        }

        if ($tool === 'execute_operation') {
            $operationId = (string) ($arguments['operationId'] ?? '');

            if ($operationId === AgentLoopService::CODING_WORKSPACE_RUN_COMMAND_OPERATION_ID) {
                return (int) config('llm-client.coding_agent.command_timeout_seconds');
            }
            if ($operationId === self::RUN_TESTS_OPERATION_ID) {
                return (int) config('llm-client.coding_agent.test_timeout_seconds');
            }
            if (str_starts_with($operationId, 'mcp:')) {
                return (int) config('llm-client.mcp_client.call_timeout_seconds');
            }
            if ($operationId !== '' && CapabilityOffering::where('id', $operationId)->exists()) {
                return (int) config('llm-client.capability_agent.max_seconds');
            }
        }

        // An unknown action (or an unresolvable operation id) is bounded by
        // the detection window: the run is considered dead after that long
        // without progress, so the lease must cover it.
        return (int) config('llm-client.resume.detection_window_seconds');
    }

    /**
     * The lease to set before the action: the larger of the run's base
     * lease and the action's bound plus the grace.
     */
    public function leaseSeconds(string $tool, array $arguments, ResumeDriver $driver): int
    {
        $bound = $this->for($tool, $arguments);

        return max($this->baseFor($driver), $bound + $this->grace());
    }

    /**
     * The lease to set before a model call: the larger of the run's base
     * lease and the provider's timeout plus the grace, capped by the run's
     * deadline when it has one.
     */
    public function modelCallLeaseSeconds(ResumeDriver $driver, ?\DateTimeInterface $deadline = null): int
    {
        $lease = max($this->baseFor($driver), $this->providerTimeout() + $this->grace());

        if ($deadline !== null) {
            $lease = min($lease, $this->secondsUntil($deadline));
        }

        return $lease;
    }

    /**
     * The base lease for a driver: the detection window for a root run,
     * the helper base for every helper kind (and for a non-resumable run).
     */
    private function baseFor(ResumeDriver $driver): int
    {
        return $driver === ResumeDriver::Root
            ? (int) config('llm-client.resume.detection_window_seconds')
            : (int) config('llm-client.resume.helper_lease_seconds');
    }

    private function grace(): int
    {
        return (int) config('llm-client.resume.lease_grace_seconds');
    }

    /**
     * The provider timeout to bound a model call by: the slowest of the
     * configured providers, so the lease covers whichever one is used.
     */
    private function providerTimeout(): int
    {
        return max(
            (int) config('llm-client.providers.openai.timeout', 240),
            (int) config('llm-client.providers.anthropic.timeout', 240),
            (int) config('llm-client.providers.llama_cpp.timeout', 240),
        );
    }

    /** Whole seconds from now until $deadline, floored (never negative). */
    private function secondsUntil(\DateTimeInterface $deadline): int
    {
        $remaining = (int) Carbon::now()->diffInSeconds($deadline, false);

        return max(0, $remaining);
    }
}
