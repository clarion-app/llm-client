<?php

namespace ClarionApp\LlmClient\Jobs;

use ClarionApp\LlmClient\Services\RunResumer;
use ClarionApp\LlmClient\ValueObjects\ResumeCaller;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Resumes an interrupted run. The detection
 * sweep's CAS has already marked the run `interrupted` and minted its
 * dispatch id; this job hands it to the resumer, which claims the lease and
 * re-enters the run's own path.
 *
 * `$tries = 1`: a retry would be a second, uncounted resume. A job whose
 * worker was killed is failed by Laravel's worker before `handle()` runs on
 * redelivery; recovery is the detection sweep running again once the
 * scheduler is back. Exceptions are caught and the run closed `failed` with
 * the plain report (never left `resuming`); the job does not rethrow.
 */
class ResumeInterruptedRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout;

    public function __construct(
        public readonly string $runId,
        public readonly string $dispatchId,
    ) {
        $this->timeout = (int) config('llm-client.resume.stream_job_timeout_seconds', 600);
        $this->onQueue((string) config('llm-client.resume.queue', 'default'));
    }

    public function handle(RunResumer $resumer): void
    {
        try {
            $resumer->resume($this->runId, $this->dispatchId, ResumeCaller::Background);
        } catch (\Throwable $e) {
            // The resumer itself threw (its own catch path did not run, or
            // could not close the run). The run must still be left terminal,
            // never `resuming`: close it `failed` with the plain report.
            // `closeRun` is a no-op on an already-terminal run, so this is
            // safe even if the resumer already closed it. The job does not
            // rethrow (no Laravel retry — a retry would be a second resume).
            try {
                $reason = app(\ClarionApp\LlmClient\Services\TaskResumeText::class)->couldNotResumeReason('ended_without_outcome');
                $recorder = app(\ClarionApp\LlmClient\Services\RunTraceRecorder::class);
                foreach (DB::table('agent_run_steps')->where('run_id', $this->runId)->where('end_state', 'in_progress')->pluck('id') as $stepId) {
                    $recorder->closeStep((string) $stepId, \ClarionApp\LlmClient\ValueObjects\RunEndState::Failed, $reason);
                }
                $recorder->closeRun($this->runId, \ClarionApp\LlmClient\ValueObjects\RunEndState::Failed, $reason);
                $conversationId = DB::table('agent_runs')->where('id', $this->runId)->value('conversation_id');
                if ($conversationId !== null) {
                    DB::table('conversations')->where('id', $conversationId)->update(['is_processing' => false]);
                }
            } catch (\Throwable $closeError) {
                Log::error('ResumeInterruptedRunJob: the run could not be closed after the resume failed', [
                    'run_id' => $this->runId,
                    'error' => $closeError->getMessage(),
                ]);
            }

            Log::error('ResumeInterruptedRunJob: the resume failed', [
                'run_id' => $this->runId,
                'error_class' => get_class($e),
            ]);
        }
    }
}
