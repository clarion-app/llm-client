<?php

namespace ClarionApp\LlmClient\ValueObjects\Messaging;

/**
 * The atomic unit of "content that may or may not be externally sourced"
 * (107-agent-message-protocol, data-model.md §2.1, research.md D4).
 * `external === true` is the External-Content Marker from spec.md's Key
 * Entities — a first-class boolean on structured data, not a text
 * convention. Round-trips through toArray()/fromArray() and through the
 * agent_messages.content/context JSON columns with no loss.
 */
final class MessageContentPart
{
    public function __construct(
        public readonly string $text,
        public readonly bool $external = false,
    ) {
    }

    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'external' => $this->external,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            text: $data['text'],
            external: $data['external'] ?? false,
        );
    }
}
