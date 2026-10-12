<?php

namespace ClarionApp\LlmClient\Commands;

use ClarionApp\LlmClient\Jobs\ResumeAfterQuestionJob;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Services\AgentQuestionService;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Artisan command that keeps agent questions from stalling a run forever.
 *
 * Expire: a pending question past its `expires_at` (fixed when it was
 * asked) becomes `expired`; the winner of that compare-and-set tells the
 * user on the conversation's channel and a ResumeAfterQuestionJob is queued
 * so the agent learns no answer will come. An expiry that loses to an
 * answer, withdrawal or failed delivery committed a moment earlier does
 * nothing; whoever won resumes.
 *
 * Re-drive: a question resolved more than `resume_grace_seconds` ago whose
 * run never resumed (the process that resolved it died before resuming)
 * gets a ResumeAfterQuestionJob queued. The resumer's one-time claim makes
 * a duplicate job a no-op, so queueing again on every tick until the claim
 * is taken is safe.
 *
 * Usage:
 *   php artisan llm-client:resolve-expired-questions [--dry-run]
 */
class ResolveExpiredQuestionsCommand extends Command
{
    protected $signature = 'llm-client:resolve-expired-questions
                            {--dry-run : Show what would be done without doing it}';

    protected $description = 'Expire overdue agent questions and resume runs whose question was resolved but never resumed';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Dry-run mode — no changes will be made');
        }

        $expired = $this->expire($dryRun);

        $this->info(($dryRun ? 'Questions that would be expired: ' : 'Questions expired: ') . $expired);

        $redriven = $this->redrive($dryRun);

        $this->info(($dryRun ? 'Resolved questions that would be re-driven: ' : 'Resolved questions re-driven: ') . $redriven);

        if ($dryRun) {
            $this->comment('Dry-run complete — no changes were made');
        }

        return self::SUCCESS;
    }

    /**
     * Expire every pending question whose answerable period has passed and
     * queue a resume for each one this tick won.
     *
     * Read by id in chunks, so rows leaving the pending set as they expire
     * never cause a later chunk to skip one.
     */
    private function expire(bool $dryRun): int
    {
        $query = AgentQuestion::query()
            ->where('state', QuestionState::Pending->value)
            ->where('expires_at', '<=', now());

        if ($dryRun) {
            return $query->count();
        }

        $batchSize = max(1, (int) config('llm-client.agent_questions.sweep_batch_size', 100));
        $service = app(AgentQuestionService::class);
        $won = 0;

        $query->chunkById($batchSize, function ($questions) use ($service, &$won) {
            foreach ($questions as $question) {
                if (!$service->expire($question)) {
                    continue;
                }

                $won++;

                Log::info('Agent question expired', ['question_id' => $question->id]);

                try {
                    ResumeAfterQuestionJob::dispatch($question->id);
                } catch (\Throwable $e) {
                    // The question is resolved and unresumed, so the re-drive
                    // step queues it once the grace window has passed.
                    Log::warning('Resume of an expired question could not be queued', [
                        'question_id' => $question->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        return $won;
    }

    /**
     * Queue a resume for every resolved question whose run has not resumed
     * within the grace window.
     */
    private function redrive(bool $dryRun): int
    {
        $graceSeconds = max(0, (int) config('llm-client.agent_questions.resume_grace_seconds', 60));
        $batchSize = max(1, (int) config('llm-client.agent_questions.sweep_batch_size', 100));
        $cutoff = now()->subSeconds($graceSeconds);
        $count = 0;

        DB::table('agent_questions')
            ->select('id')
            ->where('state', '<>', QuestionState::Pending->value)
            ->whereNull('resumed_at')
            ->where('resolved_at', '<', $cutoff)
            ->orderBy('id')
            ->chunk($batchSize, function ($rows) use ($dryRun, &$count) {
                foreach ($rows as $row) {
                    $count++;

                    if ($dryRun) {
                        continue;
                    }

                    ResumeAfterQuestionJob::dispatch((string) $row->id);

                    Log::info('Resolved question re-driven', ['question_id' => (string) $row->id]);
                }
            });

        return $count;
    }
}
