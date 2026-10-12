<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * What a channel adapter reports after trying to deliver text to a
 * conversation's user. Adapters return failures rather than throwing.
 */
final readonly class DeliveryResult
{
    private function __construct(
        public bool $delivered,
        public ?string $failureReason = null,
    ) {
    }

    public static function ok(): self
    {
        return new self(true);
    }

    public static function failed(string $reason): self
    {
        return new self(false, $reason);
    }
}
