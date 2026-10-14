<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Jobs\ResumeInterruptedRunJob;
use ClarionApp\LlmClient\Models\AgentRun;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\ValueObjects\ActionOutcome;
use ClarionApp\LlmClient\ValueObjects\ProviderFailureKind;
use ClarionApp\LlmClient\ValueObjects\ResumeDriver;
use ClarionApp\LlmClient\ValueObjects\ResumeMode;
use ClarionApp\LlmClient\ValueObjects\RetryDecision;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What a failed model call turns into. A request the provider rejects fails
 * as it always did. A transient failure of a root run defers: the run is
 * marked waiting, its lease released, and a delayed job (the only thing that
 * carries the retry) is dispatched under a fresh dispatch id; the user is
 * told nothing about the first failure and told the task is waiting from the
 * second. A nested run is never deferred (its caller is blocked on its
 * result): it retries in place. The allowance is the configured delays, and a
 * window counted from the first failure; beyond either the run ends with the
 * provider-unavailable report and its completed work intact.
 *
 * Every write is a compare-and-set on the caller's lease nonce: a caller that
 * no longer holds the run decides nothing.
 */
class ProviderRetryScheduler
{
    public function __construct(
        private RunLease $lease,
        private ResumeEventLog $events,
        private TaskResumeText $text,
        private TaskNoticePoster $notices,
    ) {
    }

    public function onFailure(AgentRun $run, string $stepId, ?string $llmActionId, \Throwable $e): RetryDecision
    {
        $kind = ProviderFailure::classify($e);
        if (!$kind->isTransient()) {
            return RetryDecision::fail();
        }

        $row = DB::table('agent_runs')->where('id', $run->id)->first();
        if ($row === null || $row->end_state !== RunEndState::InProgress->value || $row->lease_holder !== $this->lease->nonce()) {
            return RetryDecision::lost();
        }

        $nested = (ResumeDriver::tryFrom((string) ($row->resume_driver ?? ResumeDriver::Root->value)) ?? ResumeDriver::Root) !== ResumeDriver::Root;
        $delays = array_values(array_map('intval', (array) config('llm-client.resume.provider_retry.delays_seconds', [10, 30, 90, 180])));
        $window = (int) config('llm-client.resume.provider_retry.max_window_seconds', 600);

        $attempt = (int) $row->provider_attempts + 1;
        $now = now();
        $firstFailureAt = $row->provider_first_failure_at !== null
            ? Carbon::parse((string) $row->provider_first_failure_at, 'UTC')
            : $now->copy();

        app(RunTraceRecorder::class)->closeAction(
            $llmActionId,
            ActionOutcome::Failure,
            'provider '.str_replace('_', ' ', $kind->value)." (attempt {$attempt})",
        );

        $delay = $this->delayFor($attempt, $delays, $e, $firstFailureAt, $window, $now);
        $stepPosition = $this->stepPosition($stepId);

        if ($delay === null) {
            return $this->exhausted($run, $row, $stepId, $stepPosition, $attempt, $kind, $window, $nested);
        }

        $due = $now->copy()->addSeconds($delay);
        $payload = [
            'reason' => $kind->value,
            'status' => ProviderFailure::status($e),
            'delay_seconds' => $delay,
            'next_attempt_at' => $due->format('Y-m-d H:i:s'),
            'deferred' => !$nested,
        ];

        if ($nested) {
            $won = DB::table('agent_runs')
                ->where('id', $run->id)
                ->where('lease_holder', $this->lease->nonce())
                ->update([
                    'provider_attempts' => $attempt,
                    'provider_first_failure_at' => $firstFailureAt->format('Y-m-d H:i:s.u'),
                ]);
            if ($won === 0) {
                return RetryDecision::lost();
            }

            $this->events->record((string) $run->id, 'provider_retry_scheduled', $stepPosition, $attempt, $payload);

            return RetryDecision::inline($delay);
        }

        // One compare-and-set: the run waits, under a new dispatch id that
        // only the delayed job carries, with no lease held.
        $dispatchId = (string) Str::uuid();
        $won = DB::table('agent_runs')
            ->where('id', $run->id)
            ->where('lease_holder', $this->lease->nonce())
            ->where('end_state', RunEndState::InProgress->value)
            ->update([
                'resume_state' => 'waiting_provider_retry',
                'next_attempt_at' => $due->format('Y-m-d H:i:s.u'),
                'dispatch_id' => $dispatchId,
                'lease_holder' => null,
                'provider_attempts' => $attempt,
                'provider_first_failure_at' => $firstFailureAt->format('Y-m-d H:i:s.u'),
            ]);
        if ($won === 0) {
            return RetryDecision::lost();
        }

        $this->events->record((string) $run->id, 'provider_retry_scheduled', $stepPosition, $attempt, $payload);

        ResumeInterruptedRunJob::dispatch((string) $run->id, $dispatchId)->delay($due);

        // A single blip stays invisible; from the second failure on, the
        // user is told the task is waiting.
        if ($attempt >= 2) {
            $conversation = Conversation::find($row->conversation_id);
            if ($conversation !== null) {
                $this->notices->post($conversation, $run, 'provider_waiting', $this->text->providerWaitingNotice($due));
            }
        }

        return RetryDecision::deferred($due);
    }

    /**
     * The seconds to wait before the next attempt, or null when the
     * allowance is spent: more failed attempts than delays, or a retry that
     * would land past the window counted from the first failure. A
     * Retry-After the provider sent is honoured when it fits the window.
     *
     * @param int[] $delays
     */
    private function delayFor(int $attempt, array $delays, \Throwable $e, Carbon $firstFailureAt, int $window, Carbon $now): ?int
    {
        if ($attempt > count($delays)) {
            return null;
        }

        $deadline = $firstFailureAt->copy()->addSeconds($window);
        $delay = $delays[$attempt - 1];

        $retryAfter = ProviderFailure::retryAfterSeconds($e);
        if ($retryAfter !== null && $now->copy()->addSeconds($retryAfter)->lessThanOrEqualTo($deadline)) {
            $delay = $retryAfter;
        }

        return $now->copy()->addSeconds($delay)->greaterThan($deadline) ? null : $delay;
    }

    /**
     * The allowance is spent: the run fails with the provider-unavailable
     * reason, its open step closed failed, the conversation released, and the
     * report (with the count of completed steps) posted and delivered. Work
     * a user queued behind the task is picked up.
     */
    private function exhausted(AgentRun $run, object $row, string $stepId, ?int $stepPosition, int $attempt, ProviderFailureKind $kind, int $window, bool $nested): RetryDecision
    {
        $this->events->record((string) $run->id, 'provider_retry_exhausted', $stepPosition, $attempt, [
            'attempts' => $attempt,
            'reason' => $kind->value,
            'window_seconds' => $window,
        ]);

        $reason = $this->text->providerUnavailableReason();
        $completedSteps = (int) DB::table('agent_run_steps')
            ->where('run_id', $run->id)
            ->where('end_state', RunEndState::Completed->value)
            ->count();

        $recorder = app(RunTraceRecorder::class);
        $recorder->closeStep($stepId, RunEndState::Failed, $reason);
        $recorder->closeRun((string) $run->id, RunEndState::Failed, $reason);

        $conversation = Conversation::find($row->conversation_id);
        if ($conversation === null) {
            return RetryDecision::exhausted();
        }
        $conversation->update(['is_processing' => false]);

        if ($nested) {
            // A helper's failure travels to its delegator as its result; the
            // user hears it from the task that delegated.
            return RetryDecision::exhausted();
        }

        $this->notices->post($conversation, $run, 'provider_unavailable', $this->text->providerUnavailableReport($completedSteps));

        if ($row->resume_mode === ResumeMode::Stream->value) {
            app(QueuedMessagePickup::class)->startIfAny($conversation, $run);
        }

        return RetryDecision::exhausted();
    }

    private function stepPosition(string $stepId): ?int
    {
        $position = DB::table('agent_run_steps')->where('id', $stepId)->value('position');

        return $position === null ? null : (int) $position;
    }
}
