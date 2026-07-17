<?php

namespace ClarionApp\LlmClient\Commands;

use ClarionApp\LlmClient\Services\ConversationLifecycleService;
use Illuminate\Console\Command;

/**
 * Ends conversation sessions that have gone idle.
 *
 * This is the inferred half of the session boundary — the explicit half is the
 * end endpoint. Without it running on a schedule, sessions never end: short-term
 * memory is never cleaned and episodic memories are never captured.
 */
class EndIdleConversationsCommand extends Command
{
    protected $signature = 'llm-client:end-idle-conversations
                            {--minutes= : Idle threshold in minutes (defaults to config)}';

    protected $description = 'End conversation sessions idle beyond the configured timeout';

    public function handle(ConversationLifecycleService $lifecycle): int
    {
        $minutes = $this->option('minutes') !== null
            ? (int) $this->option('minutes')
            : (int) config('llm-client.conversation_lifecycle.idle_timeout_minutes', 30);

        $ended = $lifecycle->endIdleConversations($minutes);

        $this->info("Ended {$ended} idle conversation(s) (threshold: {$minutes} minutes).");

        return self::SUCCESS;
    }
}
