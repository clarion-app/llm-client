<?php

namespace ClarionApp\LlmClient\Commands;

use Illuminate\Console\Command;
use ClarionApp\LlmClient\Jobs\ReindexOperationsJob;

class ReindexOperationsCommand extends Command
{
    protected $signature = 'llm-client:reindex';
    protected $description = 'Rebuild the operations search index';

    public function handle(): int
    {
        $this->info('Dispatching operations reindex job...');
        ReindexOperationsJob::dispatch();
        $this->info('Reindex job dispatched. Check queue worker for progress.');
        return Command::SUCCESS;
    }
}
