<?php

namespace ClarionApp\LlmClient\Listeners;

use ClarionApp\LlmClient\Contracts\MemoryScope;
use ClarionApp\LlmClient\Events\ConversationEnded;
use ClarionApp\LlmClient\Models\MemoryEntry;

class CleanupShortTermMemory
{
    public function handle(ConversationEnded $event): void
    {
        MemoryEntry::where('scope', MemoryScope::SHORT_TERM->value)
            ->where('conversation_id', $event->conversation_id)
            ->delete();
    }
}
