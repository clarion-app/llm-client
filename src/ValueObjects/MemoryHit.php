<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * Value object representing a single memory retrieval hit.
 *
 * Immutable — all properties are set via constructor and cannot change.
 */
final class MemoryHit
{
    /**
     * @param string $id Unique memory entry identifier
     * @param string $content The memory content text
     * @param float $score Relevance score (0.0–1.0)
     * @param string $source Memory store origin ('declarative', 'episodic', 'long-term')
     * @param string $type Memory kind ('rule', 'fact', 'preference', 'episodic', 'long-term')
     * @param array<string, mixed> $metadata Additional metadata (e.g., confidence, created_at)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $content,
        public readonly float $score,
        public readonly string $source,
        public readonly string $type,
        public readonly array $metadata = [],
    ) {
        if (!in_array($source, ['declarative', 'episodic', 'long-term'], true)) {
            throw new \InvalidArgumentException("Invalid source '{$source}'. Must be 'declarative', 'episodic', or 'long-term'.");
        }

        if (!in_array($type, ['rule', 'fact', 'preference', 'episodic', 'long-term'], true)) {
            throw new \InvalidArgumentException("Invalid type '{$type}'. Must be 'rule', 'fact', 'preference', 'episodic', or 'long-term'.");
        }

        $clamped = max(0.0, min(1.0, $score));
        if ($clamped !== $score) {
            throw new \InvalidArgumentException("Score must be between 0.0 and 1.0, got {$score}");
        }
    }

    /**
     * Create a MemoryHit from a declarative memory entry (rule type).
     */
    public static function fromRule(string $id, string $content, float $score = 1.0, array $metadata = []): self
    {
        return new self($id, $content, $score, 'declarative', 'rule', $metadata);
    }

    /**
     * Create a MemoryHit from a declarative memory entry (fact or preference type).
     */
    public static function fromDeclarative(string $id, string $content, string $type, float $score, array $metadata = []): self
    {
        if (!in_array($type, ['fact', 'preference'], true)) {
            throw new \InvalidArgumentException("Type must be 'fact' or 'preference' for fromDeclarative(), got '{$type}'");
        }

        return new self($id, $content, $score, 'declarative', $type, $metadata);
    }

    /**
     * Create a MemoryHit from an episodic memory entry.
     */
    public static function fromEpisodic(string $id, string $content, float $score, array $metadata = []): self
    {
        return new self($id, $content, $score, 'episodic', 'episodic', $metadata);
    }

    /**
     * Create a MemoryHit from a long-term memory entry.
     */
    public static function fromLongTerm(string $id, string $content, float $score, array $metadata = []): self
    {
        return new self($id, $content, $score, 'long-term', 'long-term', $metadata);
    }

    /**
     * Get the character length of the content (for budget calculations).
     */
    public function contentLength(): int
    {
        return strlen($this->content);
    }

    /**
     * Get an estimated token count (rough approximation: 4 chars per token).
     */
    public function estimatedTokens(): int
    {
        return (int) ceil(strlen($this->content) / 4);
    }
}
