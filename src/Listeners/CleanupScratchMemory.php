<?php

namespace ClarionApp\LlmClient\Listeners;

use ClarionApp\LlmClient\Contracts\MemoryScope;
use ClarionApp\LlmClient\Events\AgentTurnCompleted;
use ClarionApp\LlmClient\Models\MemoryEntry;

class CleanupScratchMemory
{
    public function handle(AgentTurnCompleted $event): void
    {
        MemoryEntry::where('scope', MemoryScope::SCRATCH->value)
            ->where('turn_id', $event->turn_id)
            ->delete();
    }
}
