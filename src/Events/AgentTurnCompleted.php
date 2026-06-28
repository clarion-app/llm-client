<?php

namespace ClarionApp\LlmClient\Events;

class AgentTurnCompleted
{
    public function __construct(
        public readonly string $turn_id,
        public readonly ?string $conversation_id = null
    ) {}
}
