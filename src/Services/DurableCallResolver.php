<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\AgentRun;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\ManagedTaskPart;
use ClarionApp\LlmClient\ValueObjects\ActionOutcome;
use ClarionApp\LlmClient\ValueObjects\CallDisposition;
use ClarionApp\LlmClient\ValueObjects\CallDispositionKind;
use ClarionApp\LlmClient\ValueObjects\ResumePlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Resolves the calls a resume found started but not finished and whose work
 * is recorded in a row of its own (a delegation, a capability agent's call, a
 * question), instead of reporting their outcome unknown. The delegation row
 * says what became of the helper: a finished helper's result is reused as it
 * stands, an interrupted one is resumed in its own run, a batch is rejoined,
 * and a call whose row was never made did not start and runs normally. A
 * call is never repeated.
 */
class DurableCallResolver
{
    /** The tools whose started call is a delegation. */
    private const DELEGATING = ['delegate_to_helper', 'assign_part'];

    public function __construct(
        private readonly DelegationService $delegations,
        private readonly RunCheckpoint $checkpoint,
        private readonly RunTraceRecorder $recorder,
    ) {
    }

    /**
     * The plan with every durable call resolved. Null when the run is
     * waiting on a question that is still open: the question's own machinery
     * continues it, and this resume ends without doing anything more.
     */
    public function resolve(AgentRun $run, Conversation $conversation, ResumePlan $plan): ?ResumePlan
    {
        $durable = [];
        foreach ($plan->dispositions as $callId => $disposition) {
            if ($disposition->kind === CallDispositionKind::ResolveDurable) {
                $durable[(string) $callId] = $this->callOf($plan, (string) $callId);
            }
        }
        if (empty($durable)) {
            return $plan;
        }

        $rows = Delegation::where('parent_run_id', (string) $run->id)
            ->whereIn('parent_tool_call_id', array_map(fn (string $id) => mb_substr($id, 0, 255), array_keys($durable)))
            ->orderBy('started_at')
            ->get()
            ->keyBy('parent_tool_call_id');

        $dispositions = $plan->dispositions;
        $batch = [];
        $solo = [];

        foreach ($durable as $callId => $call) {
            $name = (string) ($call['function']['name'] ?? '');

            if ($name === 'ask_user') {
                $question = AgentQuestion::where('run_id', (string) $run->id)->where('tool_call_id', $callId)->first();
                if ($question === null) {
                    $dispositions[$callId] = new CallDisposition(CallDispositionKind::Execute);
                } elseif ($question->state->acceptsAnswers()) {
                    return $this->defer($run, $conversation, $plan, $callId);
                }
                continue;
            }

            $delegation = $rows[mb_substr($callId, 0, 255)] ?? null;
            if ($delegation === null) {
                // No delegation was made: the call did not get as far as
                // starting its work, so it runs now.
                $dispositions[$callId] = new CallDisposition(CallDispositionKind::Execute);
                continue;
            }

            if ($delegation->batch_id !== null) {
                $batch[$callId] = $delegation;
            } else {
                $solo[$callId] = $delegation;
            }
        }

        $results = [];
        if (!empty($batch)) {
            $byToolCall = $this->delegations->rejoin(array_values($batch));
            foreach ($batch as $callId => $delegation) {
                $results[$callId] = $byToolCall[mb_substr((string) $callId, 0, 255)] ?? $this->delegations->resumeDelegation($delegation);
            }
        }
        foreach ($solo as $callId => $delegation) {
            $results[$callId] = $this->delegations->resumeDelegation($delegation);
        }

        foreach ($results as $callId => $six) {
            $call = $durable[$callId];
            $delegation = $batch[$callId] ?? $solo[$callId];
            $entry = [
                'tool_call_id' => (string) $callId,
                'content' => json_encode($this->resultFor($call, $six)),
            ];

            if ($delegation->part_id !== null && isset($six['delegation_id'])) {
                ManagedTaskPart::where('id', $delegation->part_id)->update(['current_delegation_id' => $six['delegation_id']]);
            }

            $this->recordResolved($plan, (string) $callId, $entry);
            $dispositions[$callId] = new CallDisposition(CallDispositionKind::Reuse, $entry);
        }

        return new ResumePlan(
            $plan->runId, $plan->mode, $plan->iteration, $plan->openStepId, $plan->kind,
            $plan->partialMessageId, $plan->assistant, $dispositions, $plan->options,
        );
    }

    /**
     * The result the agent is given for a delegating call: the six-field
     * result, or, for an agent called as a capability, the plain shape that
     * call returns.
     *
     * @param array<string, mixed> $call
     * @param array<string, mixed> $six
     * @return array<string, mixed>
     */
    private function resultFor(array $call, array $six): array
    {
        if (($call['function']['name'] ?? '') === 'execute_operation') {
            return $this->delegations->capabilityResultFor($six);
        }

        return $six;
    }

    /** The result is recorded in the call's checkpoint and its interrupted action closed. */
    private function recordResolved(ResumePlan $plan, string $callId, array $entry): void
    {
        if ($plan->openStepId === null) {
            return;
        }

        try {
            $checkpoint = json_decode((string) DB::table('agent_run_steps')->where('id', $plan->openStepId)->value('checkpoint'), true);
            $actionId = is_array($checkpoint) ? ($checkpoint['calls'][$callId]['action_id'] ?? null) : null;
            if (is_string($actionId) && $actionId !== ''
                && DB::table('agent_run_actions')->where('id', $actionId)->where('outcome', ActionOutcome::InProgress->value)->exists()) {
                $this->recorder->closeAction($actionId, ActionOutcome::Success);
            }

            $this->checkpoint->markCompleted($plan->openStepId, $callId, $entry);
        } catch (\Throwable $e) {
            Log::warning('DurableCallResolver: a resolved call could not be recorded', [
                'run_id' => $plan->runId,
                'error' => get_class($e),
            ]);
        }
    }

    /**
     * The run is waiting on its own question: it is left paused for the
     * question's machinery (an answer, or the expiry sweep) to continue.
     */
    private function defer(AgentRun $run, Conversation $conversation, ResumePlan $plan, string $callId): null
    {
        $this->checkpoint->markPaused((string) $run->id, $plan->openStepId, $callId);
        $conversation->update(['is_processing' => false]);

        return null;
    }

    /** @return array<string, mixed> */
    private function callOf(ResumePlan $plan, string $callId): array
    {
        foreach ($plan->assistant['tool_calls'] ?? [] as $call) {
            if (($call['id'] ?? null) === $callId) {
                return $call;
            }
        }

        return [];
    }
}
