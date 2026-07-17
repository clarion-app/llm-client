<?php

namespace ClarionApp\LlmClient\Listeners;

use ClarionApp\LlmClient\Contracts\MemoryScope;
use ClarionApp\LlmClient\Events\AgentTurnCompleted;
use ClarionApp\LlmClient\Models\MemoryEntry;

class CleanupScratchMemory
{
    public function handle(AgentTurnCompleted $event): void
    {
        $query = MemoryEntry::where('scope', MemoryScope::SCRATCH->value);

        // turn_id is not unique across conversations — "1" is every
        // conversation's first turn — and MemoryEntry carries no per-user global
        // scope, so without this filter the delete reaches other conversations'
        // and other users' scratch entries.
        if ($event->conversation_id !== null) {
            $query->where('conversation_id', $event->conversation_id);
        }

        $query->where('turn_id', $event->turn_id)->delete();
    }
}
