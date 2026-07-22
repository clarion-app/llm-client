<?php

namespace Tests\Integration\Harness;

use Closure;

/**
 * T009: TurnPlan — one planned exchange in a conversation script.
 *
 * A turn's responses are enqueued on the agent_turn lane immediately before
 * the turn is played and must be fully consumed by the time it ends.
 */
class TurnPlan
{
    /** @var string|Closure(int $turn): string */
    public readonly string|Closure $userMessage;

    /** @var Closure(ResponseScript): void */
    public readonly Closure $responses;

    public readonly ?string $marker;

    /** @var list<string> */
    public readonly array $expectDegradations;

    public function __construct(
        string|Closure $userMessage,
        Closure $responses,
        ?string $marker = null,
        array $expectDegradations = [],
    ) {
        $this->userMessage = $userMessage;
        $this->responses = $responses;
        $this->marker = $marker;
        $this->expectDegradations = $expectDegradations;
    }

    /**
     * Resolve the user message for a given turn number.
     */
    public function resolveUserMessage(int $turn): string
    {
        return $this->userMessage instanceof Closure
            ? ($this->userMessage)($turn)
            : $this->userMessage;
    }
}
