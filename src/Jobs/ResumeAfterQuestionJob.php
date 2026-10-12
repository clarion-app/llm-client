<?php

namespace ClarionApp\LlmClient\Jobs;

use ClarionApp\LlmClient\Exceptions\BudgetExceededException;
use ClarionApp\LlmClient\Exceptions\RateLimitExceededException;
use ClarionApp\LlmClient\Services\QuestionResumer;
use ClarionApp\LlmClient\ValueObjects\ResumeCaller;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Continues the run a resolved question paused, when no HTTP request is
 * there to do it: a resolution recorded before a crash (re-driven by
 * `llm-client:resolve-expired-questions`) or one the sweep made itself.
 *
 * The resumer's one-time claim is what makes this safe to queue more than
 * once for the same question — every run after the first is a no-op.
 */
class ResumeAfterQuestionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $questionId)
    {
    }

    public function handle(QuestionResumer $resumer): void
    {
        try {
            $resumer->resume($this->questionId, ResumeCaller::Background);
        } catch (BudgetExceededException|RateLimitExceededException $e) {
            // Re-admission refused the continuation. The gate already closed
            // the run and left the conversation usable; the question stays
            // resolved and its claim taken, so there is nothing to retry.
            Log::warning('ResumeAfterQuestionJob: the resumed run was refused admission', [
                'question_id' => $this->questionId,
                'reason' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            // The resumer has already reset the conversation and closed the
            // run as failed. The claim is taken, so a retry would be a no-op.
            Log::error('ResumeAfterQuestionJob: the resumed run failed to continue', [
                'question_id' => $this->questionId,
                'error_class' => get_class($e),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
