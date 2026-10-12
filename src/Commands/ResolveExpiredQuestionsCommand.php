<?php

namespace ClarionApp\LlmClient\Commands;

use ClarionApp\LlmClient\Jobs\ResumeAfterQuestionJob;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Artisan command that keeps agent questions from stalling a run forever.
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

    protected $description = 'Resume agent runs whose question was resolved but never resumed';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Dry-run mode — no changes will be made');
        }

        $redriven = $this->redrive($dryRun);

        $this->info(($dryRun ? 'Resolved questions that would be re-driven: ' : 'Resolved questions re-driven: ') . $redriven);

        if ($dryRun) {
            $this->comment('Dry-run complete — no changes were made');
        }

        return self::SUCCESS;
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
