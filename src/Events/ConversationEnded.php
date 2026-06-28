<?php

namespace ClarionApp\LlmClient\Events;

class ConversationEnded
{
    public function __construct(
        public readonly string $conversation_id,
        public readonly string $agent_id
    ) {}
}
