<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * One changed scalar-shaped setting between two agent versions
 * (research.md D6): formatVersion, name, version, instructions, model, or
 * one memory.<kind> boolean toggle per differing MemoryKind.
 */
final readonly class AgentVersionFieldDifference
{
    public function __construct(
        public string $field,   // e.g. "instructions", "model", "memory.long_term"
        public mixed $from,
        public mixed $to,
    ) {}
}
