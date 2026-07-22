<?php

namespace Tests\Integration\Harness;

/**
 * T014: TurnRecord — record for a single played turn.
 *
 * status !== 'completed' fails the scenario immediately (D5).
 */
class TurnRecord
{
    public function __construct(
        public readonly int $index,
        public readonly string $userMessage,
        /** @var CapturedPayload[] */
        public readonly array $payloads,
        /** @var list<string> LaneRule labels that fired */
        public readonly array $rulesFired,
        public readonly string $status,
        public readonly ?string $assistantContent,
        public readonly ?int $historyTokensBefore,
        public readonly ?int $historyTokensAfter,
        public readonly bool $reducedHere,
    ) {
    }

    /**
     * Create a completed turn record.
     */
    public static function completed(int $index, string $userMessage, array $payloads, array $rulesFired, ?string $assistantContent, ?int $historyTokensBefore = null, ?int $historyTokensAfter = null, bool $reducedHere = false): self
    {
        return new self(
            index: $index,
            userMessage: $userMessage,
            payloads: $payloads,
            rulesFired: $rulesFired,
            status: 'completed',
            assistantContent: $assistantContent,
            historyTokensBefore: $historyTokensBefore,
            historyTokensAfter: $historyTokensAfter,
            reducedHere: $reducedHere,
        );
    }

    /**
     * Create an error turn record.
     */
    public static function error(int $index, string $userMessage, array $payloads, string $reason): self
    {
        return new self(
            index: $index,
            userMessage: $userMessage,
            payloads: $payloads,
            rulesFired: [],
            status: 'error',
            assistantContent: null,
            historyTokensBefore: null,
            historyTokensAfter: null,
            reducedHere: false,
        );
    }

    /**
     * Create an empty turn record (product returned no content).
     */
    public static function empty(int $index, string $userMessage, array $payloads): self
    {
        return new self(
            index: $index,
            userMessage: $userMessage,
            payloads: $payloads,
            rulesFired: [],
            status: 'empty',
            assistantContent: '',
            historyTokensBefore: null,
            historyTokensAfter: null,
            reducedHere: false,
        );
    }
}
