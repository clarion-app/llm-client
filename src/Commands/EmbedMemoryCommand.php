<?php

namespace ClarionApp\LlmClient\Commands;

use ClarionApp\LlmClient\Contracts\MemoryScope;
use ClarionApp\LlmClient\Models\MemoryEntry;
use ClarionApp\LlmClient\Services\EmbeddingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Artisan command to backfill embeddings for pre-existing memory entries.
 *
 * Processes entries where `embedding IS NULL` AND `scope = 'long_term'`.
 * Supports agent-level filtering, dry-run mode, and configurable batch sizes.
 */
class EmbedMemoryCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'llm-client:embed-memory
                            {--agent-id= : Only process entries for a specific agent}
                            {--dry-run : Show what would be processed without making changes}
                            {--batch-size=100 : Number of entries to process per batch}';

    /**
     * The console command description.
     */
    protected $description = 'Backfill embeddings for pre-existing long-term memory entries';

    public function __construct(
        private readonly EmbeddingService $embeddingService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $agentId = $this->option('agent-id');
        $dryRun = $this->option('dry-run');
        $batchSize = (int) $this->option('batch-size');

        // Validate batch size
        if ($batchSize < 1 || $batchSize > 1000) {
            $this->error('Batch size must be between 1 and 1000');
            return self::FAILURE;
        }

        // Check if embedding is enabled
        if (!$this->embeddingService->isEnabled()) {
            $this->warn('Embedding generation is disabled. Enable it in config(llm-client.memory.embedding.enabled).');
            return self::FAILURE;
        }

        // Check provider availability
        if ($this->embeddingService->getProvider() === null) {
            $this->error('No embedding provider available. Configure memory.embedding.server_id.');
            return self::FAILURE;
        }

        // Build query for entries missing embeddings
        $query = MemoryEntry::where('scope', MemoryScope::LONG_TERM->value)
            ->whereNull('embedding');

        if ($agentId !== null && $agentId !== '') {
            $query->where('agent_id', $agentId);
        }

        $totalPending = $query->count();

        if ($totalPending === 0) {
            $this->info('No entries need embedding backfill.');
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info("Dry run: {$totalPending} entries would be processed.");
            if ($agentId) {
                $this->info("Filter: agent_id = {$agentId}");
            }
            return self::SUCCESS;
        }

        // Process entries in batches
        $this->info("Processing {$totalPending} entries in batches of {$batchSize}...");

        $processed = 0;
        $success = 0;
        $failed = 0;

        $bar = $this->output->createProgressBar($totalPending);
        $bar->start();

        $query->cursor()
            ->each(function ($entry) use (&$processed, &$success, &$failed, $bar) {
                try {
                    $result = $this->embeddingService->generateForEntry($entry);
                    if ($result) {
                        $success++;
                    } else {
                        $failed++;
                        Log::warning('Failed to generate embedding for memory entry', [
                            'entry_id' => $entry->id,
                            'agent_id' => $entry->agent_id,
                        ]);
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    Log::error('Exception generating embedding for memory entry', [
                        'entry_id' => $entry->id,
                        'agent_id' => $entry->agent_id,
                        'error' => $e->getMessage(),
                    ]);
                }

                $processed++;
                $bar->advance();

                // Print newline every 10 entries for log visibility
                if ($processed % 10 === 0) {
                    $bar->clear();
                    $bar->display();
                }
            });

        $bar->finish();
        $this->newLine(2);

        $this->info("Complete: {$processed} processed, {$success} succeeded, {$failed} failed.");

        return $failed > 0 && $success === 0 ? self::FAILURE : self::SUCCESS;
    }
}
