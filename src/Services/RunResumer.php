<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\LlmClient\AgentLoopStreamHandler;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Models\AgentRun;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\ValueObjects\ActionOutcome;
use ClarionApp\LlmClient\ValueObjects\ActionType;
use ClarionApp\LlmClient\ValueObjects\CallDispositionKind;
use ClarionApp\LlmClient\ValueObjects\ClaimResult;
use ClarionApp\LlmClient\ValueObjects\PlanKind;
use ClarionApp\LlmClient\ValueObjects\ResumeCaller;
use ClarionApp\LlmClient\ValueObjects\ResumeMode;
use ClarionApp\LlmClient\ValueObjects\ResumePlan;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\Unresumable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Resumes an interrupted run. It claims the run's lease (or accepts an already-won claim), checks the cancellation
 * (which always wins), the interruption bound, and the planner's viability
 * verdict, then re-enters the run's own path — the sync loop or the stream
 * dispatch — from its newest checkpoint, without re-running completed work.
 *
 * A resumed run is continued in its own run (no new run is minted), the
 * `run_id` and `task_budget_id` Context are set and restored, and
 * `conversations.ended_at` is never touched (only a fresh request does
 * that, because there a user is engaging).
 */
class RunResumer
{
    /**
     * Claim the run's lease and, on a won claim, resume it. A lost claim
     * (another process holds the lease, a stale dispatch, a terminal or
     * cancelled run) returns without any write other than a debug log line.
     *
     * @return array<string, mixed>|null the sync continuation's result, or
     *   null for a stream run (or a lost claim).
     */
    public function resume(string $runId, string $dispatchId, ResumeCaller $caller): ?array
    {
        $claim = app(RunLease::class)->claim($runId, $dispatchId);
        if (!$claim->won) {
            // Cancellation wins: a resume that meets a run the user
            // cancelled while it was interrupted closes it, unless a live
            // worker still holds it (that worker stops at its next step).
            if ($claim->reason === 'cancelled' && ($run = $this->idleCancelledRun($runId, $dispatchId)) !== null) {
                $this->refuseCancelled($run, app(RunCancellation::class)->isCancelled($run) ?? 'conversation_ended');

                return null;
            }

            Log::debug('RunResumer: the claim was lost, so the run is left as-is', [
                'run_id' => $runId,
                'reason' => $claim->reason,
            ]);

            return null;
        }

        return $this->resumeClaimed($runId, $claim, $caller);
    }

    /**
     * Resume a run whose lease this process already holds (a takeover in
     * `AgentStreamStepJob`, a member job). Does not claim again. Records the
     * takeover events when the claim itself detected the interruption.
     *
     * @return array<string, mixed>|null
     */
    public function resumeClaimed(string $runId, ClaimResult $won, ResumeCaller $caller): ?array
    {
        $run = AgentRun::find($runId);
        if ($run === null) {
            return null;
        }

        $log = app(ResumeEventLog::class);
        if ($won->detectedHere) {
            // The claim's own UPDATE already counted this interruption.
            $log->record($runId, 'interruption_detected', null, (int) $run->consecutive_interruptions, [
                'last_progress_at' => $this->stamp($run->last_progress_at),
                'lease_expired_at' => null,
                'resume_state_before' => 'working',
                'detected_by' => 'takeover',
            ]);
            $log->record($runId, 'takeover', null, (int) $run->consecutive_interruptions, []);
        }

        // Cancellation first: no action starts.
        $cause = app(RunCancellation::class)->isCancelled($run);
        if ($cause !== null) {
            $this->refuseCancelled($run, $cause);

            return null;
        }

        $conversation = Conversation::find($run->conversation_id);
        if ($conversation === null) {
            $this->refuseCancelled($run, 'conversation_deleted');

            return null;
        }

        // The interruption bound.
        $max = (int) config('llm-client.resume.max_consecutive_interruptions', 3);
        if ((int) $run->consecutive_interruptions >= $max) {
            return $this->stopUnresumable($run, 'repeated_interruption', null, $conversation);
        }

        // A newer task in the same conversation supersedes this one (the
        // conversation's own background work, such as a summary, does not).
        $newer = DB::table('agent_runs')
            ->where('conversation_id', $run->conversation_id)
            ->where('id', '!=', $runId)
            ->where('checkpoint_version', 1)
            ->where('resume_driver', \ClarionApp\LlmClient\ValueObjects\ResumeDriver::Root->value)
            ->where('started_at', '>', $this->stamp($run->started_at))
            ->exists();
        if ($newer) {
            return $this->stopUnresumable($run, 'superseded', null, $conversation);
        }

        // Whatever gave up on the task already released the conversation:
        // there is no outcome to continue towards.
        if (!$conversation->is_processing) {
            return $this->stopUnresumable($run, 'ended_without_outcome', null, $conversation);
        }

        // Viability: the planner's verdict, read from the durable records.
        $planned = app(ResumePlanner::class)->plan($run);
        if ($planned instanceof Unresumable) {
            return $this->stopUnresumable($run, $planned->reasonCode, null, $conversation);
        }
        $plan = $planned;

        // The model the run was talking to is still configured.
        if ($conversation->server === null) {
            return $this->stopUnresumable($run, 'model_unavailable', null, $conversation);
        }

        // Every call the resume itself must execute can still be resolved.
        $missing = $this->unavailableTool($plan);
        if ($missing !== null) {
            return $this->stopUnresumable($run, 'tool_unavailable', $missing, $conversation);
        }

        $previousRunId = Context::get('run_id');
        $previousBudgetId = Context::get('task_budget_id');
        Context::add('run_id', $runId);
        if ($conversation->active_task_budget_id !== null) {
            Context::add('task_budget_id', $conversation->active_task_budget_id);
        }

        try {
            // The resumed run is working on the conversation again; the
            // session end marker is left exactly as it is.
            $conversation->update(['is_processing' => true]);

            // A confirmation the user decided before the interruption is
            // carried out now, exactly once, by the continuation that would
            // have carried it out.
            $decided = $this->decidedConfirmation($plan);
            if ($decided !== null) {
                return $this->continueDecidedConfirmation($run, $conversation, $plan, $decided, $caller);
            }

            // A delayed provider retry claiming the run on time is not a
            // recovery from an interruption: it says nothing of "resuming"
            // and counts no resume. Its own events already tell the story.
            $providerRetry = !$won->takeover && (int) $run->provider_attempts > 0 && $run->next_attempt_at !== null;
            if (!$providerRetry) {
                $this->applyOutcomeUnknown($run, $conversation, $plan);
                $this->recordResumeStarted($run, $plan);
                $this->maybeResumedNotice($run, $conversation, $plan);
            }

            return $plan->mode === ResumeMode::Sync
                ? $this->continueSync($run, $conversation, $plan, $caller)
                : $this->continueStream($run, $conversation, $plan);
        } finally {
            if ($previousRunId === null) {
                Context::forget('run_id');
            } else {
                Context::add('run_id', $previousRunId);
            }
            if ($previousBudgetId === null) {
                Context::forget('task_budget_id');
            } else {
                Context::add('task_budget_id', $previousBudgetId);
            }
        }
    }

    /**
     * A continuation (a pause's resume) lost its claim because the user
     * cancelled the run: close the run as cancelled instead of leaving it
     * in progress with nothing to continue it. Returns whether it did.
     */
    public function refuseIfCancelled(string $runId): bool
    {
        $run = $this->idleCancelledRun($runId, null);
        if ($run === null) {
            return false;
        }

        $this->refuseCancelled($run, app(RunCancellation::class)->isCancelled($run) ?? 'conversation_ended');

        return true;
    }

    /**
     * The cancelled run (entitled to $dispatchId, when given), when no live
     * worker holds it — taken (one conditional UPDATE) so it is refused only
     * once.
     */
    private function idleCancelledRun(string $runId, ?string $dispatchId): ?AgentRun
    {
        $now = now();
        $taken = DB::table('agent_runs')
            ->where('id', $runId)
            ->where('end_state', RunEndState::InProgress->value)
            ->whereNotNull('cancelled_at')
            ->when($dispatchId !== null, fn ($q) => $q->where('dispatch_id', $dispatchId))
            ->where(fn ($q) => $q->whereNull('lease_holder')->orWhere('lease_expires_at', '<', $now->format('Y-m-d H:i:s.u')))
            ->update([
                'lease_holder' => app(RunLease::class)->nonce(),
                'lease_expires_at' => $now->copy()->addSeconds((int) config('llm-client.resume.detection_window_seconds', 300))->format('Y-m-d H:i:s.u'),
            ]);

        return $taken === 1 ? AgentRun::find($runId) : null;
    }

    /**
     * Refuse a cancelled run: close it stopped_early with the cancellation
     * reason (open step too), record resume_refused_cancelled, and release
     * the conversation. No action starts.
     */
    private function refuseCancelled(AgentRun $run, string $cause): void
    {
        $reason = app(TaskResumeText::class)->cancelledReason();

        Conversation::withTrashed()->whereKey($run->conversation_id)->update(['is_processing' => false]);

        $this->closeOpenStep((string) $run->id, RunEndState::StoppedEarly, $reason);
        app(RunTraceRecorder::class)->closeRun((string) $run->id, RunEndState::StoppedEarly, $reason);

        app(ResumeEventLog::class)->record((string) $run->id, 'resume_refused_cancelled', null, null, ['cause' => $cause]);
    }

    /**
     * Stop an unresumable run: close it failed with the reason, the
     * open step closed failed, the conversation released, the report posted
     * and delivered on the channel, a message the user queued meanwhile
     * picked up, and the stopped_unresumable event recorded.
     *
     * @param string|null $label the action the task needed, when the reason names one
     * @return array<string, mixed>
     */
    public function stopUnresumable(AgentRun $run, string $code, ?string $label = null, ?Conversation $conversation = null): array
    {
        $text = app(TaskResumeText::class);
        $runId = (string) $run->id;
        $conversation ??= Conversation::find($run->conversation_id);

        $completedSteps = (int) DB::table('agent_run_steps')
            ->where('run_id', $runId)
            ->where('end_state', RunEndState::Completed->value)
            ->count();

        $openPosition = DB::table('agent_run_steps')->where('run_id', $runId)->where('end_state', RunEndState::InProgress->value)->min('position');
        $stoppedAt = (int) ($openPosition ?? (DB::table('agent_run_steps')->where('run_id', $runId)->max('position') + 1));
        $stoppedDuring = "step {$stoppedAt}".($label !== null && $label !== '' ? ", which needed {$label}" : '');

        $reason = $text->couldNotResumeReason($code);
        $conversation?->update(['is_processing' => false]);
        $this->closeOpenStep($runId, RunEndState::Failed, $reason);
        app(RunTraceRecorder::class)->closeRun($runId, RunEndState::Failed, $reason);

        $report = $text->couldNotResumeReport($code, $completedSteps, $stoppedDuring);

        // A helper's stop travels to the task that delegated to it; the user
        // hears about it from there.
        $helper = app(LegacyRunAssessment::class)->isHelperRun($run);
        $message = $conversation !== null && !$helper ? $this->postNotice($conversation, $run, 'could_not_resume', $report) : null;

        app(ResumeEventLog::class)->record($runId, 'stopped_unresumable', $openPosition !== null ? (int) $openPosition : null, (int) $run->consecutive_interruptions, [
            'reason_code' => $code,
            'completed_steps' => $completedSteps,
        ]);

        if ($conversation !== null && !$helper && ($run->resume_mode ?? ($run->is_streamed ? 'stream' : 'sync')) === ResumeMode::Stream->value) {
            app(QueuedMessagePickup::class)->startIfAny($conversation, $run);
        }

        return ['status' => 'stopped', 'code' => 'unresumable', 'content' => $report, 'message_id' => $message?->id];
    }

    /**
     * The label of the first call the resume must execute whose tool can no
     * longer be resolved (an operation removed from the catalogue since the
     * interruption), or null when every one still can. A lookup that fails
     * is not taken as the tool being gone.
     */
    private function unavailableTool(ResumePlan $plan): ?string
    {
        $checkpoint = $plan->openStepId !== null
            ? json_decode((string) DB::table('agent_run_steps')->where('id', $plan->openStepId)->value('checkpoint'), true)
            : null;

        foreach ($plan->dispositions as $callId => $disposition) {
            if ($disposition->kind !== CallDispositionKind::Execute && $disposition->kind !== CallDispositionKind::Rerun) {
                continue;
            }

            $call = $this->callFor($plan, (string) $callId);
            if (($call['function']['name'] ?? null) !== 'execute_operation') {
                continue;
            }
            $arguments = json_decode((string) ($call['function']['arguments'] ?? ''), true);
            $operationId = is_array($arguments) ? (string) ($arguments['operationId'] ?? '') : '';
            if ($operationId === '' || str_starts_with($operationId, 'mcp:')) {
                continue;
            }

            try {
                if (\ClarionApp\LlmClient\Models\CapabilityOffering::where('id', $operationId)->exists()) {
                    continue;
                }
                $details = ApiManager::getOperationDetails($operationId);
            } catch (\Throwable) {
                continue;
            }
            if (is_array($details) && isset($details['method'])) {
                continue;
            }

            $label = $checkpoint['calls'][$callId]['label'] ?? null;

            return is_string($label) && $label !== ''
                ? $label
                : app(TaskResumeText::class)->actionLabel('execute_operation', is_array($arguments) ? $arguments : []);
        }

        return null;
    }

    /**
     * Calls started without an outcome (effectful in-flight, or a durable
     * call whose record cannot be resolved yet): each is marked
     * outcome_unknown, its old action is closed failure, and one notice for
     * the resumed step names every such action. The continuation writes the
     * outcome-unknown result entry the agent receives.
     */
    private function applyOutcomeUnknown(AgentRun $run, Conversation $conversation, ResumePlan $plan): void
    {
        if ($plan->openStepId === null || $plan->kind !== PlanKind::FinishToolBatch) {
            return;
        }

        $checkpoint = json_decode((string) DB::table('agent_run_steps')->where('id', $plan->openStepId)->value('checkpoint'), true);
        $labels = [];
        foreach ($plan->dispositions as $callId => $disposition) {
            if (!$this->isOutcomeUnknown($disposition->kind)) {
                continue;
            }

            $call = $this->callFor($plan, (string) $callId);
            $arguments = json_decode((string) ($call['function']['arguments'] ?? ''), true);
            $labels[] = app(TaskResumeText::class)->actionLabel(
                (string) ($call['function']['name'] ?? ''),
                is_array($arguments) ? $arguments : [],
            );

            app(RunCheckpoint::class)->markOutcomeUnknown($plan->openStepId, (string) $callId);

            $actionId = $checkpoint['calls'][$callId]['action_id'] ?? null;
            $open = is_string($actionId) && $actionId !== '' && DB::table('agent_run_actions')
                ->where('id', $actionId)
                ->where('outcome', ActionOutcome::InProgress->value)
                ->exists();
            if ($open) {
                app(RunTraceRecorder::class)->closeAction($actionId, ActionOutcome::Failure, 'interrupted — outcome unknown');
            }
        }

        if (!empty($labels)) {
            $this->postNotice($conversation, $run, 'outcome_unknown', app(TaskResumeText::class)->outcomeUnknownNotice($labels));
        }
    }

    /** The resume_started event, before the first action runs. */
    private function recordResumeStarted(AgentRun $run, ResumePlan $plan): void
    {
        $lists = ['reused' => [], 'rerun' => [], 'outcome_unknown' => []];
        foreach ($plan->dispositions as $callId => $disposition) {
            $list = match (true) {
                $disposition->kind === CallDispositionKind::Reuse => 'reused',
                $this->isOutcomeUnknown($disposition->kind) => 'outcome_unknown',
                default => 'rerun',
            };
            $call = $this->callFor($plan, (string) $callId);
            $arguments = json_decode((string) ($call['function']['arguments'] ?? ''), true);
            $tool = (string) ($call['function']['name'] ?? '');
            $lists[$list][] = [
                'tool_call_id' => (string) $callId,
                'tool' => $tool,
                'label' => app(TaskResumeText::class)->actionLabel($tool, is_array($arguments) ? $arguments : []),
            ];
        }

        $fromStep = $this->fromStep($plan);

        app(ResumeEventLog::class)->record($plan->runId, 'resume_started', $fromStep, (int) $run->consecutive_interruptions, [
            'from_step' => $fromStep,
            'iteration' => $plan->iteration,
            'mode' => $plan->mode->value,
            'reused' => $lists['reused'],
            'rerun' => $lists['rerun'],
            'outcome_unknown' => $lists['outcome_unknown'],
            'model_call_repeated' => $plan->kind === PlanKind::RepeatModelCall,
        ]);
    }

    /**
     * The resumed notice, only after a user-visible gap (the run made no
     * progress for at least one detection window), then resume_count + 1.
     */
    private function maybeResumedNotice(AgentRun $run, Conversation $conversation, ResumePlan $plan): void
    {
        $window = (int) config('llm-client.resume.detection_window_seconds', 300);

        if ($run->last_progress_at !== null) {
            $gap = now()->getTimestamp() - Carbon::parse($run->last_progress_at)->getTimestamp();
            if ($gap >= $window) {
                $this->postNotice($conversation, $run, 'resumed', app(TaskResumeText::class)->resumedNotice($this->fromStep($plan)));
            }
        }

        DB::table('agent_runs')->where('id', $run->id)->increment('resume_count');
    }

    /**
     * The sync continuation: re-enter the loop from the plan, then deliver
     * the final reply on the channel when the original caller is gone.
     *
     * @return array<string, mixed>|null
     */
    private function continueSync(AgentRun $run, Conversation $conversation, ResumePlan $plan, ResumeCaller $caller): ?array
    {
        $loop = app(AgentLoopService::class);

        if ($plan->kind === PlanKind::CloseCommittedStep) {
            $this->closeCommittedStep($plan);
            $state = $loop->buildResumeLoopState($plan, $conversation, $plan->iteration + 1, null, false);
        } elseif ($plan->kind === PlanKind::NextIteration) {
            $state = $loop->buildResumeLoopState($plan, $conversation, $plan->iteration, null, false);
        } else {
            if ($plan->kind === PlanKind::RepeatModelCall) {
                $this->closeLostModelCall($conversation, $run, (string) $plan->openStepId);
            }
            // A step paused at the ceiling never claimed its step (the gate
            // stopped it), so its model call claims one now.
            $state = $loop->buildResumeLoopState($plan, $conversation, $plan->iteration, $plan->openStepId, !$this->pausedAtCeiling($plan));
        }

        $result = $loop->runLoop($state);

        if ($caller === ResumeCaller::Background && $this->resultNeedsDelivery($result)) {
            $content = (string) ($result['content'] ?? '');
            if (trim($content) !== '') {
                $this->deliver($conversation->fresh() ?? $conversation, $run, $content, $result['message_id'] ?? null);
            }
        }

        return $result;
    }

    /**
     * Whether a sync continuation's result is a message for the user. A
     * superseded worker says nothing; a task waiting on a provider retry is
     * not over (its waiting notice was posted by the scheduler); an
     * exhausted retry was reported by the scheduler already.
     *
     * @param array<string, mixed> $result
     */
    private function resultNeedsDelivery(array $result): bool
    {
        return ($result['status'] ?? null) !== 'superseded'
            && ($result['status'] ?? null) !== 'continuing'
            && ($result['code'] ?? null) !== 'provider_unavailable';
    }

    /**
     * The paused confirmation message of the plan's open step, when the
     * user's decision on it was recorded before the interruption.
     */
    private function decidedConfirmation(ResumePlan $plan): ?Message
    {
        if ($plan->openStepId === null || $plan->kind !== PlanKind::FinishToolBatch) {
            return null;
        }

        return Message::where('run_id', $plan->runId)
            ->whereNotNull('tool_data')
            ->get()
            ->first(fn (Message $m) => ($m->tool_data['step_id'] ?? null) === $plan->openStepId
                && in_array($m->tool_data['pending_confirmation']['decision'] ?? null, ['approved', 'declined'], true));
    }

    /**
     * Carry out a recorded confirmation decision through the same
     * continuation the user's answer started: the approved call runs (or the
     * declined one is reported) once, then the loop continues.
     *
     * @return array<string, mixed>|null
     */
    private function continueDecidedConfirmation(AgentRun $run, Conversation $conversation, ResumePlan $plan, Message $paused, ResumeCaller $caller): ?array
    {
        $pausedCallId = PausedTurnWriter::pausedToolCallId($paused->tool_data);
        $dispositions = $plan->dispositions;
        if ($pausedCallId !== null && isset($dispositions[$pausedCallId])) {
            $dispositions[$pausedCallId] = new \ClarionApp\LlmClient\ValueObjects\CallDisposition(CallDispositionKind::Execute);
        }
        $plan = new ResumePlan($plan->runId, $plan->mode, $plan->iteration, $plan->openStepId, $plan->kind, $plan->partialMessageId, $plan->assistant, $dispositions, $plan->options);

        $this->recordResumeStarted($run, $plan);
        $this->maybeResumedNotice($run, $conversation, $plan);

        $approved = ($paused->tool_data['pending_confirmation']['decision'] ?? null) === 'approved';
        $loop = app(AgentLoopService::class);

        if ($plan->mode === ResumeMode::Stream) {
            $loop->resume($conversation, $paused, $approved, decisionRecorded: true);

            return null;
        }

        $result = $loop->resumeSync($conversation, $paused, $approved, decisionRecorded: true);
        if ($caller === ResumeCaller::Background && $this->resultNeedsDelivery($result)) {
            $content = (string) ($result['content'] ?? '');
            if (trim($content) !== '') {
                $this->deliver($conversation->fresh() ?? $conversation, $run, $content, $result['message_id'] ?? null);
            }
        }

        return $result;
    }

    /**
     * The stream continuation: the tool phase runs in this process when the
     * batch must be finished; every model call is handed to an
     * `AgentStreamStepJob` with a handed-off dispatch id, exactly as a
     * normal stream iteration is.
     */
    private function continueStream(AgentRun $run, Conversation $conversation, ResumePlan $plan): ?array
    {
        $lease = app(RunLease::class);
        $loop = app(AgentLoopService::class);

        switch ($plan->kind) {
            case PlanKind::FinishToolBatch:
                $handler = new AgentLoopStreamHandler();
                $handler->resumeState($plan->runId, (string) $plan->openStepId, (string) Str::uuid());
                $handler->resumeToolCalls($conversation, $plan->iteration, $plan->dispositions, $plan->assistant);
                break;

            case PlanKind::RepeatModelCall:
                if ($this->pausedAtCeiling($plan)) {
                    // Its model call was never made or claimed: the
                    // continuation starts the step afresh, claiming it.
                    app(RunTraceRecorder::class)->closeStep((string) $plan->openStepId, RunEndState::StoppedEarly, 'interrupted');
                    $lease->handOff($plan->runId);
                    $loop->start($conversation, $plan->iteration, $plan->runId, resumed: true);
                    break;
                }
                $this->closeLostModelCall($conversation, $run, (string) $plan->openStepId);
                $lease->handOff($plan->runId);
                $loop->redispatchOpenStep($conversation, $plan->runId, (string) $plan->openStepId, $plan->iteration, $plan->partialMessageId);
                break;

            case PlanKind::CloseCommittedStep:
                $this->closeCommittedStep($plan);
                $lease->handOff($plan->runId);
                $loop->start($conversation, $plan->iteration + 1, $plan->runId, resumed: true);
                break;

            case PlanKind::NextIteration:
                $lease->handOff($plan->runId);
                $loop->start($conversation, $plan->iteration, $plan->runId, resumed: true);
                break;
        }

        return null;
    }

    /**
     * A step whose message already holds every result: commit it (naming
     * the message, when the kill landed before C5) and close it.
     */
    private function closeCommittedStep(ResumePlan $plan): void
    {
        $stepId = (string) $plan->openStepId;
        $step = DB::table('agent_run_steps')->where('id', $stepId)->first(['checkpoint_state', 'message_id']);
        if ($step !== null && $step->checkpoint_state !== 'committed' && $plan->partialMessageId !== null) {
            app(RunCheckpoint::class)->commitStep($plan->runId, $stepId, $plan->partialMessageId);
        }

        app(RunTraceRecorder::class)->closeStep($stepId, RunEndState::Completed);
    }

    /** Whether the plan's open step is one the task budget's ceiling paused before its model call. */
    private function pausedAtCeiling(ResumePlan $plan): bool
    {
        return $plan->openStepId !== null
            && DB::table('agent_run_steps')->where('id', $plan->openStepId)->value('checkpoint_state') === 'paused';
    }

    /**
     * The lost model call's in-flight actions are closed unfinished. A call
     * that was made and never answered (its worker died) still cost
     * something the provider will never report: its input is counted once as
     * an estimate, stamped with the run and the task like any other usage.
     * A call the provider answered with a failure was closed already and
     * counts nothing here.
     */
    private function closeLostModelCall(Conversation $conversation, AgentRun $run, string $stepId): void
    {
        $actionIds = DB::table('agent_run_actions')
            ->where('step_id', $stepId)
            ->where('outcome', ActionOutcome::InProgress->value)
            ->pluck('id');

        $lostCall = $actionIds->isNotEmpty() && DB::table('agent_run_actions')
            ->whereIn('id', $actionIds)
            ->where('action_type', ActionType::LlmRequest->value)
            ->exists();
        if ($lostCall) {
            app(AgentLoopService::class)->recordLostModelCall($conversation, (string) $run->id);
        }

        foreach ($actionIds as $actionId) {
            app(RunTraceRecorder::class)->closeAction((string) $actionId, ActionOutcome::Unfinished, 'interrupted');
        }
        app(RunTraceRecorder::class)->recordStepAttempt($stepId);
    }

    /**
     * A resume notice: an assistant message the model never sees, delivered
     * on the conversation's channel when the user can be reached.
     */
    private function postNotice(Conversation $conversation, AgentRun $run, string $notice, string $text): ?Message
    {
        return app(TaskNoticePoster::class)->post($conversation, $run, $notice, $text);
    }

    private function deliver(Conversation $conversation, AgentRun $run, string $text, ?string $messageId): void
    {
        app(TaskNoticePoster::class)->deliver($conversation, $run, $text, $messageId);
    }

    /** The run's open step (if any) is closed with $state. */
    private function closeOpenStep(string $runId, RunEndState $state, string $reason): void
    {
        $stepIds = DB::table('agent_run_steps')
            ->where('run_id', $runId)
            ->where('end_state', RunEndState::InProgress->value)
            ->pluck('id');

        foreach ($stepIds as $stepId) {
            app(RunTraceRecorder::class)->closeStep((string) $stepId, $state, $reason);
        }
    }

    /** The step position the resume continues at. */
    private function fromStep(ResumePlan $plan): int
    {
        if ($plan->openStepId === null) {
            return (int) DB::table('agent_run_steps')->where('run_id', $plan->runId)->max('position') + 1;
        }

        $position = (int) DB::table('agent_run_steps')->where('id', $plan->openStepId)->value('position');

        return $plan->kind === PlanKind::CloseCommittedStep ? $position + 1 : $position;
    }

    private function isOutcomeUnknown(CallDispositionKind $kind): bool
    {
        return $kind === CallDispositionKind::OutcomeUnknown || $kind === CallDispositionKind::ResolveDurable;
    }

    /** The plan's call entry for a tool call id. */
    private function callFor(ResumePlan $plan, string $callId): array
    {
        foreach ($plan->assistant['tool_calls'] ?? [] as $call) {
            if (($call['id'] ?? null) === $callId) {
                return $call;
            }
        }

        return [];
    }

    private function stamp(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s.u') : (string) $value;
    }
}
