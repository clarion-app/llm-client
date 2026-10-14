<?php

namespace ClarionApp\LlmClient\Jobs;

use ClarionApp\HttpQueue\HttpRequest;
use ClarionApp\LlmClient\AgentLoopStreamHandler;
use ClarionApp\LlmClient\Contracts\FaultInjector;
use ClarionApp\LlmClient\Exceptions\IncompleteStream;
use ClarionApp\LlmClient\Exceptions\LeaseLost;
use ClarionApp\LlmClient\Models\AgentRun;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Services\ProviderRetryScheduler;
use ClarionApp\LlmClient\Services\QueuedMessagePickup;
use ClarionApp\LlmClient\Services\RunLease;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\Services\TaskNoticePoster;
use ClarionApp\LlmClient\Services\TaskResumeText;
use ClarionApp\LlmClient\ValueObjects\ActionOutcome;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\Services\RunResumer;
use ClarionApp\LlmClient\ValueObjects\ResumeCaller;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The resumable stream step. It claims the run's lease
 * before it sends any request, guards that its payload names the run's open
 * step, streams the model response through the handler (renewing the lease at
 * most every 30 s), and — on a lost claim, a rejected or incomplete stream, or
 * a mid-stream fault — never re-dispatches blindly and never records a model
 * response it did not receive. A redelivered job whose lease has since expired
 * wins a takeover and hands the run to the resumer rather than streaming its
 * own payload.
 */
class AgentStreamStepJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout;

    public array $data;

    /**
     * @param array<string, mixed> $data {conversation_id, iteration, run_id,
     *   step_id, action_id, dispatch_id, partial_message_id?,
     *   repeat_of_claimed_step?}
     */
    public function __construct(
        public HttpRequest $request,
        array $data,
    ) {
        $this->data = $data;
        $this->timeout = (int) config('llm-client.resume.stream_job_timeout_seconds', 600);
        $this->onQueue((string) config('llm-client.resume.stream_queue', 'http'));
    }

    public function handle(): void
    {
        $runId = (string) ($this->data['run_id'] ?? '');
        $dispatchId = (string) ($this->data['dispatch_id'] ?? '');
        $stepId = (string) ($this->data['step_id'] ?? '');

        // A run with no trace record (tracing off) has no lease to claim and
        // no checkpoint to guard: it streams as an iteration always has.
        if ($runId !== '') {
            // 1. Claim the lease before any request. A lost
            // claim sends no request.
            $claim = app(RunLease::class)->claim($runId, $dispatchId);
            if (!$claim->won) {
                // Cancellation wins: the user ended the conversation while
                // this step waited in the queue. Nobody else is working the
                // run, so it is closed as cancelled here rather than left in
                // progress until the abandonment sweep.
                if ($claim->reason === 'cancelled') {
                    app(RunResumer::class)->refuseIfCancelled($runId, $dispatchId);
                }

                Log::debug('AgentStreamStepJob: the claim was lost, so no request is sent', [
                    'run_id' => $runId,
                    'reason' => $claim->reason,
                ]);

                return;
            }

            // A won takeover: this process already holds the claim, so the
            // run goes through the resumer (which does not claim again)
            // rather than this job's own unit of work.
            if ($claim->takeover) {
                app(RunResumer::class)->resumeClaimed($runId, $claim, ResumeCaller::Background);

                return;
            }

            app(FaultInjector::class)->reach('after_claim');

            // 2. Step guard: the payload must name the run's open step (a
            // late duplicate of a finished iteration does nothing).
            $step = DB::table('agent_run_steps')
                ->where('id', $stepId)
                ->where('run_id', $runId)
                ->where('end_state', 'in_progress')
                ->first(['checkpoint_state']);
            $checkpointed = (int) DB::table('agent_runs')->where('id', $runId)->value('checkpoint_version') === 1;
            if ($step === null || ($checkpointed && $step->checkpoint_state !== 'open')) {
                return;
            }
        }

        // 3. Stream with Guzzle (512-byte reads → handler->handle()),
        // renewing the lease at most every 30 s.
        $handler = new AgentLoopStreamHandler();
        $start = time();
        $lastRenewalAt = Carbon::now();

        // A rejected request, an incomplete stream or a mid-stream exception
        // is logged and left for the provider-retry scheduler — never
        // re-dispatched and never finished. The fault injector's reach()
        // calls sit outside these catches: an injected fault must propagate.
        try {
            $response = $this->client()->sendAsync(
                new Request($this->request->method, $this->request->url, $this->request->headers, json_encode($this->request->body)),
                [RequestOptions::STREAM => true],
            )->wait();
            $stream = $response->getBody();
        } catch (\Throwable $e) {
            $this->onStreamFailure($runId, $stepId, $e);

            return;
        }

        // The first chunk is read on its own, so the mid_stream fault point
        // sits between the first and the second read.
        try {
            $this->readChunk($stream, $handler, $runId, $lastRenewalAt, $start);
        } catch (\Throwable $e) {
            $this->onStreamFailure($runId, $stepId, $e);

            return;
        }

        app(FaultInjector::class)->reach('mid_stream');

        try {
            while (!$stream->eof()) {
                $this->readChunk($stream, $handler, $runId, $lastRenewalAt, $start);
            }
        } catch (\Throwable $e) {
            $this->onStreamFailure($runId, $stepId, $e);

            return;
        }

        // 4. A stream that ended without its end marker is incomplete.
        if (!$handler->streamCompleted()) {
            $this->onStreamFailure($runId, $stepId, new IncompleteStream());

            return;
        }

        // 5. Complete: the handler records the response, then runs the tools.
        $handler->finish($this->data, time() - $start);
    }

    /** Read one chunk (when the stream has one) into the handler. */
    private function readChunk($stream, AgentLoopStreamHandler $handler, string $runId, Carbon &$lastRenewalAt, int $start): void
    {
        if ($stream->eof()) {
            return;
        }

        $content = $stream->read(512);
        if ($content !== '') {
            $handler->handle($content, $this->data, time() - $start);
        }
        $this->renewEvery30s($runId, $lastRenewalAt);
    }

    /** Renew the lease at most every 30 s while chunks arrive. */
    private function renewEvery30s(string $runId, Carbon &$lastRenewalAt): void
    {
        if ($runId === '' || Carbon::now()->getTimestamp() - $lastRenewalAt->getTimestamp() < 30) {
            return;
        }

        app(RunLease::class)->renew($runId, (int) config('llm-client.resume.detection_window_seconds', 300));
        $lastRenewalAt = Carbon::now();
    }

    /**
     * A rejected request, an incomplete stream or a mid-stream exception.
     * The run is never finished on a response it did not fully receive and
     * the job never re-dispatches itself: the failure goes to the retry
     * scheduler, which waits for a transient one (a delayed job carries the
     * retry) and, for a request the provider rejects, closes the run failed
     * with a report, instead of leaving the conversation processing for an
     * hour.
     */
    private function onStreamFailure(string $runId, string $stepId, \Throwable $e): void
    {
        Log::info('AgentStreamStepJob: the stream failed; it is not re-dispatched', [
            'run_id' => $runId,
            'step_id' => $stepId,
            'error_class' => get_class($e),
        ]);

        // A run with no trace record has nothing to schedule or close; a
        // process that lost its lease is superseded and writes nothing.
        if ($runId === '' || $e instanceof LeaseLost) {
            return;
        }

        $run = AgentRun::find($runId);
        if ($run === null || $run->end_state !== RunEndState::InProgress) {
            return;
        }

        $actionId = isset($this->data['action_id']) ? (string) $this->data['action_id'] : null;
        $decision = app(ProviderRetryScheduler::class)->onFailure($run, $stepId, $actionId, $e);
        if ($decision->deferredUntil() !== null || $decision->isExhausted() || $decision->isLost()) {
            return;
        }

        $this->failRun($run, $stepId, $actionId);
    }

    /** A rejected request: the run fails, the user is told, and a message queued behind it is picked up. */
    private function failRun(AgentRun $run, string $stepId, ?string $actionId): void
    {
        $text = app(TaskResumeText::class);
        $recorder = app(RunTraceRecorder::class);
        $completedSteps = (int) DB::table('agent_run_steps')
            ->where('run_id', $run->id)
            ->where('end_state', RunEndState::Completed->value)
            ->count();

        $recorder->closeAction($actionId, ActionOutcome::Failure, 'provider rejected the request');
        $recorder->closeStep($stepId, RunEndState::Failed, $text->providerRejectedReason());
        $recorder->closeRun((string) $run->id, RunEndState::Failed, $text->providerRejectedReason());

        $conversation = Conversation::find($run->conversation_id);
        if ($conversation === null) {
            return;
        }
        $conversation->update(['is_processing' => false]);
        app(TaskNoticePoster::class)->post($conversation, $run, 'provider_rejected', $text->providerRejectedReport($completedSteps));

        app(QueuedMessagePickup::class)->startIfAny($conversation, $run);
    }

    /**
     * The Guzzle client, honouring the test-only `llm-client.http_handler`
     * seam (nothing binds it in production).
     */
    private function client(): Client
    {
        $config = ['timeout' => $this->request->http_timeout];
        if (app()->bound('llm-client.http_handler')) {
            $config['handler'] = app('llm-client.http_handler');
        }

        return new Client($config);
    }
}

