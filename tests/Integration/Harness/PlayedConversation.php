<?php

namespace Tests\Integration\Harness;

/**
 * T015: PlayedConversation — result of playing a script.
 *
 * The turn ledger and the whole assertion surface for cross-turn claims (FR-004).
 */
class PlayedConversation
{
    /** @var list<TurnRecord> */
    public array $turns = [];

    public function __construct(
        array $turns,
        public readonly string $conversationId,
        public readonly string $stoppedBecause,
    ) {
        $this->turns = $turns;
    }

    /**
     * Get a turn record by 1-based index.
     *
     * Out of range is a scenario error, not null.
     */
    public function turn(int $n): TurnRecord
    {
        if ($n < 1 || $n > count($this->turns)) {
            throw new \OutOfBoundsException(
                "Turn {$n} out of range. This conversation has " . count($this->turns) . ' turns.'
            );
        }
        return $this->turns[$n - 1];
    }

    /**
     * Get payloads delivered during a specific turn only.
     *
     * @return CapturedPayload[]
     */
    public function payloadsForTurn(int $n): array
    {
        return $this->turn($n)->payloads;
    }

    /**
     * Last turn containing a needle in any payload message content.
     */
    public function lastTurnContaining(string $needle): ?int
    {
        for ($i = count($this->turns) - 1; $i >= 0; $i--) {
            $record = $this->turns[$i];
            foreach ($record->payloads as $payload) {
                if ($payload->containsText($needle)) {
                    return $record->index;
                }
            }
            if ($record->assistantContent !== null && str_contains($record->assistantContent, $needle)) {
                return $record->index;
            }
        }
        return null;
    }

    /**
     * First turn from $from where a needle is absent.
     */
    public function firstTurnMissing(string $needle, int $from = 1): ?int
    {
        for ($i = $from - 1; $i < count($this->turns); $i++) {
            $record = $this->turns[$i];
            $found = false;
            foreach ($record->payloads as $payload) {
                if ($payload->containsText($needle)) {
                    $found = true;
                    break;
                }
            }
            if (!$found && ($record->assistantContent === null || !str_contains($record->assistantContent, $needle))) {
                return $record->index;
            }
        }
        return null;
    }

    /**
     * Assert every turn satisfies a condition.
     *
     * @param callable(TurnRecord): bool $condition
     */
    public function everyTurnSatisfies(callable $condition): void
    {
        foreach ($this->turns as $record) {
            if (!$condition($record)) {
                throw new \RuntimeException(
                    "Turn {$record->index} does not satisfy condition. " .
                    "Status: {$record->status}, Payloads: " . count($record->payloads)
                );
            }
        }
    }

    /**
     * User-message bodies of EpisodicSummary-lane requests, in order.
     *
     * Story 4's authoritative signal — the transcript handed to the summarizer.
     *
     * @return list<string>
     */
    public function summarizerTranscripts(): array
    {
        $transcripts = [];
        foreach ($this->turns as $record) {
            foreach ($record->payloads as $payload) {
                if (RequestLane::classify($payload) === RequestLane::EpisodicSummary) {
                    // The transcript is the user message of the episodic summary request
                    foreach ($payload->messages as $msg) {
                        if (($msg['role'] ?? '') === 'user' && !empty($msg['content'])) {
                            $transcripts[] = $msg['content'];
                        }
                    }
                }
            }
        }
        return $transcripts;
    }

    /**
     * search_operations tool calls seen at the boundary (Story 3's rediscovery signal).
     *
     * A CapturedPayload::$messages carries the *whole* conversation history sent
     * to the model, not just what changed this turn — so once a search_operations
     * call happens at turn K, its message stays in every later turn's payload
     * too (it is genuine history, not a new event). Deduping by the tool call's
     * id keeps each discovery attributed to the turn it actually happened on,
     * instead of being re-reported on every subsequent turn that merely still
     * carries it in history.
     *
     * @return list<array{turn: int, query: string}>
     */
    public function discoveryRequests(): array
    {
        $discoveries = [];
        $seenCallIds = [];

        foreach ($this->turns as $record) {
            foreach ($record->payloads as $payload) {
                foreach ($payload->messages as $msg) {
                    if (isset($msg['tool_calls']) && is_array($msg['tool_calls'])) {
                        foreach ($msg['tool_calls'] as $tc) {
                            if (($tc['function']['name'] ?? '') === 'search_operations') {
                                $callId = $tc['id'] ?? null;
                                if ($callId !== null) {
                                    if (isset($seenCallIds[$callId])) {
                                        continue;
                                    }
                                    $seenCallIds[$callId] = true;
                                }

                                $args = json_decode($tc['function']['arguments'] ?? '{}', true);
                                $discoveries[] = [
                                    'turn' => $record->index,
                                    'query' => $args['query'] ?? $args['q'] ?? '',
                                ];
                            }
                        }
                    }
                }
            }
        }
        return $discoveries;
    }

    /**
     * First turn where context management reduced the history.
     *
     * Returns null if no turn was marked as reduced.
     */
    public function firstReducedTurn(): ?int
    {
        foreach ($this->turns as $record) {
            if ($record->reducedHere) {
                return $record->index;
            }
        }
        return null;
    }

    /**
     * Assert that a needle is present in every turn from $from onwards.
     *
     * Checks both payload messages and assistant content. Fails with
     * last-present / first-absent turn information (FR-014).
     *
     * @param string $needle Text to look for.
     * @param int $from 1-based turn index to start from.
     */
    public function assertPresentFromTurn(string $needle, int $from): void
    {
        $lastPresent = null;
        $firstAbsent = null;

        // Find last turn containing the needle (scanning all turns)
        for ($i = count($this->turns) - 1; $i >= 0; $i--) {
            $record = $this->turns[$i];
            if ($this->turnContains($record, $needle)) {
                $lastPresent = $record->index;
                break;
            }
        }

        // Find first turn from $from where needle is absent
        for ($i = $from - 1; $i < count($this->turns); $i++) {
            $record = $this->turns[$i];
            if (!$this->turnContains($record, $needle)) {
                $firstAbsent = $record->index;
                break;
            }
        }

        if ($firstAbsent !== null) {
            $lastInfo = $lastPresent !== null
                ? "Last present at turn {$lastPresent}"
                : 'Never present';
            throw new \RuntimeException(
                "Marker '{$needle}' dropped after turn {$from}. " .
                "{$lastInfo}. First absent at turn {$firstAbsent}."
            );
        }
    }

    /**
     * Check if a single turn record contains the needle.
     */
    private function turnContains(TurnRecord $record, string $needle): bool
    {
        // Check payloads
        foreach ($record->payloads as $payload) {
            if ($payload->containsText($needle)) {
                return true;
            }
        }
        // Check assistant content
        if ($record->assistantContent !== null && str_contains($record->assistantContent, $needle)) {
            return true;
        }
        // Check user message
        if (str_contains($record->userMessage, $needle)) {
            return true;
        }
        return false;
    }
}
