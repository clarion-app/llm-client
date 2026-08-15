<?php

namespace ClarionApp\LlmClient\ValueObjects\Messaging;

/**
 * Constructed by the caller (today: only this feature's own tests) and
 * passed to AgentMessageService::send() (107-agent-message-protocol,
 * data-model.md §2.2). fromAgentId/toAgentId/expectedResponse are typed
 * nullable specifically so a deliberately malformed envelope (missing a
 * required part, US4) can be constructed and handed to send() without a PHP
 * type error masking the very condition the service is supposed to detect
 * and refuse.
 *
 * `runId` is deliberately ABSENT from this constructor — send() reads it
 * from ambient Context::get('run_id') at the moment of sending, never from
 * a caller-supplied value (research.md D3, standing rule 6), so no caller
 * can accidentally stamp a stale or wrong run id.
 */
final class MessageEnvelope
{
    /**
     * @param MessageContentPart[] $content
     * @param MessageContentPart[] $context
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly ?string $fromAgentId,
        public readonly ?string $toAgentId,
        public readonly array $content,
        public readonly array $context,
        public readonly ?string $expectedResponse,
        public readonly string $ownerUserId,
        public readonly ?string $conversationId,
        public readonly array $metadata = [],
    ) {
    }
}
