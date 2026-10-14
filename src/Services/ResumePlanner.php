<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\AgentRun;
use ClarionApp\LlmClient\ValueObjects\CallDisposition;
use ClarionApp\LlmClient\ValueObjects\CallDispositionKind;
use ClarionApp\LlmClient\ValueObjects\PlanKind;
use ClarionApp\LlmClient\ValueObjects\ResumeMode;
use ClarionApp\LlmClient\ValueObjects\ResumePlan;
use ClarionApp\LlmClient\ValueObjects\Unresumable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The resume planner.
 * It reads only durable records — the run row, its steps, the open step's
 * checkpoint and message, and the run's options — and decides the plan a
 * resume applies, or refuses the run as unresumable. It is pure except
 * reads: it never writes, so planning a run cannot disturb it.
 */
class ResumePlanner
{
    /**
     * Plan the run.
     *
     * @return ResumePlan|Unresumable
     */
    public function plan(AgentRun $run): ResumePlan|Unresumable
    {
        $runId = (string) $run->id;

        // a checkpoint_version = 1 run must carry its options; without
        // them the resume cannot restore the run's original options, so it
        // is refused before anything else is read.
        $options = $this->optionsOf($run);
        if ($options === null) {
            return new Unresumable('checkpoint_incoherent:missing_resume_options');
        }

        $steps = DB::table('agent_run_steps')
            ->where('run_id', $runId)
            ->orderBy('position')
            ->get(['id', 'position', 'iteration', 'end_state', 'checkpoint_state', 'checkpoint', 'message_id']);

        // the step positions must be contiguous — the newest step's
        // position follows the last committed step. A gap means a step was
        // never written, so the record cannot be continued safely.
        $positions = $steps->map(fn ($step) => (int) $step->position)->all();
        sort($positions);
        foreach ($positions as $index => $position) {
            if ($position !== $index + 1) {
                return new Unresumable('checkpoint_incoherent:gap_in_step_positions');
            }
        }

        $openSteps = $steps->filter(fn ($step) => $step->end_state === 'in_progress');

        // more than one open step is incoherent (only one step is ever
        // open at a time).
        if ($openSteps->count() > 1) {
            return new Unresumable('checkpoint_incoherent:two_open_steps');
        }

        // No open step: the run is at a step boundary; the resume starts the
        // next fresh iteration.
        if ($openSteps->count() === 0) {
            $lastIteration = (int) ($steps->max('iteration') ?? 0);

            return new ResumePlan(
                runId: $runId,
                mode: $this->modeOf($run),
                iteration: $lastIteration + 1,
                openStepId: null,
                kind: PlanKind::NextIteration,
                partialMessageId: null,
                assistant: ['content' => null, 'tool_calls' => []],
                dispositions: [],
                options: $this->shiftedOptions($options, $run),
            );
        }

        // Exactly one open step: plan from its checkpoint.
        $step = $openSteps->first();

        $checkpoint = json_decode((string) $step->checkpoint, true);
        if (!is_array($checkpoint)) {
            return new Unresumable('checkpoint_incoherent:undecodable_checkpoint');
        }
        if (($checkpoint['v'] ?? null) !== 1) {
            return new Unresumable('checkpoint_incoherent:unsupported_version');
        }

        // every recorded call must carry an id. The checkpoint keys its
        // calls by id; a list-shaped entry (the incoherent shape) must carry
        // the id itself.
        $callStates = [];
        foreach ($checkpoint['calls'] ?? [] as $key => $call) {
            $call = is_array($call) ? $call : [];
            $id = (is_string($key) && $key !== '') ? $key : ($call['id'] ?? null);
            if (!is_string($id) || $id === '') {
                return new Unresumable('checkpoint_incoherent:tool_call_without_id');
            }
            $callStates[$id] = $call;
        }

        // The model response exactly as recorded at C2: the calls (with
        // their arguments) a finished batch is built from. Each must carry an
        // id, ids must be unique, and every recorded call state must belong
        // to one of them.
        $model = is_array($checkpoint['model'] ?? null) ? $checkpoint['model'] : null;
        $toolCalls = array_values(is_array($model['tool_calls'] ?? null) ? $model['tool_calls'] : []);
        $modelIds = [];
        foreach ($toolCalls as $toolCall) {
            $id = is_array($toolCall) ? ($toolCall['id'] ?? null) : null;
            if (!is_string($id) || $id === '') {
                return new Unresumable('checkpoint_incoherent:tool_call_without_id');
            }
            $modelIds[] = $id;
        }
        if (count($modelIds) !== count(array_unique($modelIds))) {
            return new Unresumable('checkpoint_incoherent:duplicate_tool_call_id');
        }
        foreach (array_keys($callStates) as $id) {
            if ($model !== null && !in_array($id, $modelIds, true)) {
                return new Unresumable('checkpoint_incoherent:tool_call_without_id');
            }
        }

        // the step's message (if any) must not hold duplicate call ids,
        // nor results for ids the model response does not contain.
        $message = null;
        $messageId = $step->message_id !== null
            ? (string) $step->message_id
            : $this->unlinkedStepMessageId($runId, $step, $modelIds);
        if ($messageId !== null) {
            $message = DB::table('messages')
                ->where('id', $messageId)
                ->first(['tool_data']);
            if ($message !== null) {
                $toolData = $this->toolDataOf($message);
                $messageCallIds = array_values(array_filter(
                    array_map(fn ($call) => is_array($call) ? ($call['id'] ?? null) : null, $toolData['tool_calls'] ?? []),
                    fn ($id) => $id !== null,
                ));
                if (count($messageCallIds) !== count(array_unique($messageCallIds))) {
                    return new Unresumable('checkpoint_incoherent:duplicate_tool_call_id');
                }

                $knownIds = array_unique(array_merge($messageCallIds, $modelIds, array_keys($callStates)));
                foreach ($toolData['tool_results'] ?? [] as $result) {
                    $resultId = is_array($result) ? ($result['tool_call_id'] ?? null) : null;
                    if ($resultId !== null && !in_array($resultId, $knownIds, true)) {
                        return new Unresumable('checkpoint_incoherent:message_results_for_unknown_ids');
                    }
                }
            }
        }

        $state = (string) ($step->checkpoint_state ?? 'open');
        $iteration = (int) ($step->iteration ?? 0);
        $partialMessageId = $step->message_id !== null ? (string) $step->message_id : null;

        // The model call was lost — or, for a step paused at the task
        // budget's ceiling, never made: make it in the same step, reusing
        // the stream's partial row when there is one.
        if ($state === 'open' || ($state === 'paused' && $model === null)) {
            return new ResumePlan(
                runId: $runId,
                mode: $this->modeOf($run),
                iteration: $iteration,
                openStepId: (string) $step->id,
                kind: PlanKind::RepeatModelCall,
                partialMessageId: $partialMessageId,
                assistant: ['content' => null, 'tool_calls' => []],
                dispositions: [],
                options: $this->shiftedOptions($options, $run),
            );
        }

        if (!in_array($state, ['model_recorded', 'paused', 'committed'], true)) {
            return new Unresumable('checkpoint_incoherent:unknown_checkpoint_state');
        }
        if ($model === null) {
            return new Unresumable('checkpoint_incoherent:missing_model_response');
        }

        $dispositions = $this->dispositionsFor($toolCalls, $callStates, $message);

        // The step's message already holds a result for every call (C5 ran,
        // or the kill landed between the message write and the commit):
        // close the step and continue with the next iteration.
        $allInMessage = $message !== null && !empty($toolCalls)
            && count(array_filter($toolCalls, fn ($call) => $this->messageResult($message, (string) $call['id']) === null)) === 0;
        if ($state === 'committed' || $allInMessage) {
            return new ResumePlan(
                runId: $runId,
                mode: $this->modeOf($run),
                iteration: $iteration,
                openStepId: (string) $step->id,
                kind: PlanKind::CloseCommittedStep,
                partialMessageId: $messageId,
                assistant: ['content' => $model['content'] ?? null, 'tool_calls' => $toolCalls, 'recorded_at' => $model['recorded_at'] ?? null],
                dispositions: $dispositions,
                options: $this->shiftedOptions($options, $run),
            );
        }

        // The model response is recorded (a paused step too: the call it
        // waited on is answered through its message, or still in flight):
        // finish the batch from the checkpointed response.
        return new ResumePlan(
            runId: $runId,
            mode: $this->modeOf($run),
            iteration: $iteration,
            openStepId: (string) $step->id,
            kind: PlanKind::FinishToolBatch,
            partialMessageId: $partialMessageId,
            assistant: ['content' => $model['content'] ?? null, 'tool_calls' => $toolCalls, 'recorded_at' => $model['recorded_at'] ?? null],
            dispositions: $dispositions,
            options: $this->shiftedOptions($options, $run),
        );
    }

    /**
     * The disposition table, applied to each call of the model response
     * in order. Authoritative order: a call whose result
     * the step's message holds is completed and reused from there; then a
     * checkpoint `completed` with a result is reused; otherwise the
     * checkpoint state decides.
     *
     * @param list<array<string, mixed>> $toolCalls
     * @param array<string, array<string, mixed>> $callStates
     * @return array<string, CallDisposition>
     */
    private function dispositionsFor(array $toolCalls, array $callStates, ?object $message): array
    {
        $dispositions = [];

        foreach ($toolCalls as $toolCall) {
            $id = (string) $toolCall['id'];
            $call = $callStates[$id] ?? [];

            $fromMessage = $this->messageResult($message, $id);
            if ($fromMessage !== null) {
                $dispositions[$id] = new CallDisposition(CallDispositionKind::Reuse, $fromMessage);
                continue;
            }

            $state = (string) ($call['state'] ?? 'not_started');
            if ($state === 'completed' && is_array($call['result'] ?? null)) {
                $dispositions[$id] = new CallDisposition(CallDispositionKind::Reuse, $call['result']);
                continue;
            }

            $dispositions[$id] = match ($state) {
                'not_started' => new CallDisposition(CallDispositionKind::Execute),
                'started', 'paused' => $this->startedDisposition((string) ($call['effect'] ?? 'effectful')),
                'outcome_unknown' => new CallDisposition(CallDispositionKind::OutcomeUnknown),
                // A completed call whose result is gone (stripped by C5, but
                // the message does not hold it): its effect happened, the
                // result is lost — never repeat it.
                default => new CallDisposition(CallDispositionKind::OutcomeUnknown),
            };
        }

        return $dispositions;
    }

    /**
     * The step's assistant message when it was written but the step was not
     * yet committed (the kill landed between the message write and C5, so
     * the step does not name it yet): the run's assistant message written
     * since the step opened whose tool calls are this step's recorded calls.
     *
     * @param list<string> $modelIds
     */
    private function unlinkedStepMessageId(string $runId, object $step, array $modelIds): ?string
    {
        if (empty($modelIds)) {
            return null;
        }

        $candidates = DB::table('messages')
            ->where('run_id', $runId)
            ->where('role', 'assistant')
            ->whereNotNull('tool_data')
            ->orderBy('created_at')
            ->get(['id', 'tool_data']);

        foreach ($candidates as $candidate) {
            $ids = array_values(array_filter(array_map(
                fn ($call) => is_array($call) ? ($call['id'] ?? null) : null,
                $this->toolDataOf($candidate)['tool_calls'] ?? [],
            )));
            if (!empty($ids) && $ids === $modelIds) {
                return (string) $candidate->id;
            }
        }

        return null;
    }

    /** A started, result-less call is dispositioned by its effect class. */
    private function startedDisposition(string $effect): CallDisposition
    {
        return match ($effect) {
            'effect_free' => new CallDisposition(CallDispositionKind::Rerun),
            'durable' => new CallDisposition(CallDispositionKind::ResolveDurable),
            default => new CallDisposition(CallDispositionKind::OutcomeUnknown),
        };
    }

    /** The step's message entry for the call, or null when it holds none. */
    private function messageResult(?object $message, string $id): ?array
    {
        if ($message === null) {
            return null;
        }

        foreach ($this->toolDataOf($message)['tool_results'] ?? [] as $result) {
            if (($result['tool_call_id'] ?? null) === $id) {
                return $result;
            }
        }

        return null;
    }

    /** The message's `tool_data`, decoded. */
    private function toolDataOf(object $message): array
    {
        $toolData = $message->tool_data;
        if (is_string($toolData)) {
            $toolData = json_decode($toolData, true);
        }

        return is_array($toolData) ? $toolData : [];
    }

    /** The run's resume_options, or null when it is missing. */
    private function optionsOf(AgentRun $run): ?array
    {
        $raw = $run->resume_options;
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_array($raw)) {
            return $raw;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** The run's resume mode (a checkpointed run always carries one). */
    private function modeOf(AgentRun $run): ResumeMode
    {
        if ($run->resume_mode === null) {
            return ResumeMode::Sync;
        }

        return ResumeMode::from($run->resume_mode);
    }

    /**
     * The options with `deadline_at` shifted by the interruption gap:
     * the run sat from its last progress until now, so its deadline moves by
     * that much and it keeps its full allotted working time.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function shiftedOptions(array $options, AgentRun $run): array
    {
        if (!isset($options['deadline_at']) || $run->last_progress_at === null) {
            return $options;
        }

        $last = Carbon::parse($run->last_progress_at);
        $gapSeconds = max(0, Carbon::now()->timestamp - $last->timestamp);

        $options['deadline_at'] = Carbon::parse((string) $options['deadline_at'])
            ->addSeconds($gapSeconds)
            ->toIso8601String();

        return $options;
    }
}
