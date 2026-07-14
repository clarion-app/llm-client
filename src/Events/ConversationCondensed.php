<?php

namespace ClarionApp\LlmClient\Events;

class ConversationCondensed
{
    public function __construct(
        public readonly string $conversationId,
        public readonly int $chunkIndex,
        public readonly int $sourceMessageCount,
        public readonly ?string $condensationModel,
        public readonly string $condensationProvider,
        public readonly int $promptTokens,
        public readonly int $completionTokens,
        public readonly int $totalTokens,
        public readonly bool $synchronous,
        public readonly int $summaryTokens,
    ) {}
}
