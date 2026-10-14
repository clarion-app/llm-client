<?php

namespace ClarionApp\LlmClient\Jobs;

use ClarionApp\LlmClient\Models\ConsensusRequest;
use ClarionApp\LlmClient\Services\ConsensusService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Finishes a multi-opinion request whose own process was lost while its
 * contributors were working: the detection sweep hands the request to one
 * of these, which reuses the contributors that finished, resumes the ones
 * that were interrupted, reconciles their answers once and posts the answer
 * to the conversation.
 *
 * `$tries = 1`: a second delivery would be a second, uncounted attempt; the
 * request's own state makes a repeat harmless, and a lost job is re-driven
 * by the sweep once the detection window has passed.
 */
class ResumeConsensusRequestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public readonly string $requestId)
    {
        $this->timeout = (int) config('llm-client.resume.stream_job_timeout_seconds', 600);
        $this->onQueue((string) config('llm-client.resume.queue', 'default'));
    }

    public function handle(ConsensusService $consensus): void
    {
        $request = ConsensusRequest::find($this->requestId);
        if ($request === null) {
            return;
        }

        try {
            $consensus->continueRequest($request);
        } catch (\Throwable $e) {
            Log::error('ResumeConsensusRequestJob: the request could not be finished', [
                'request_id' => $this->requestId,
                'error_class' => get_class($e),
            ]);
        }
    }
}
