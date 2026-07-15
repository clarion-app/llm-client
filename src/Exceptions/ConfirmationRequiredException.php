<?php

namespace ClarionApp\LlmClient\Exceptions;

use RuntimeException;

/**
 * Thrown when an agent-sourced declarative memory write lacks explicit user confirmation.
 *
 * Carries the attempted type/content for logging purposes but persists nothing to the database.
 * This enforces the hard storage-boundary invariant: SC-004 / FR-003 / FR-003a.
 */
class ConfirmationRequiredException extends RuntimeException
{
    /**
     * The type of the attempted declarative memory entry.
     */
    public readonly string $type;

    /**
     * The content of the attempted declarative memory entry.
     */
    public readonly string $content;

    /**
     * The existing entry ID if this was an inferred update (FR-003a), or null for a new entry.
     */
    public readonly ?string $existingId;

    /**
     * The confidence level (0-100) for the learned pattern, or null if not applicable.
     */
    public readonly ?int $confidenceLevel;

    /**
     * Create a new ConfirmationRequiredException instance.
     *
     * @param string $type The type of the attempted entry (fact, preference, rule)
     * @param string $content The content of the attempted entry
     * @param string|null $existingId The existing entry ID for inferred updates, or null for new entries
     * @param int|null $confidenceLevel The confidence percentage (0-100) for learned patterns
     */
    public function __construct(
        string $type,
        string $content,
        ?string $existingId = null,
        ?int $confidenceLevel = null
    ) {
        $this->type = $type;
        $this->content = $content;
        $this->existingId = $existingId;
        $this->confidenceLevel = $confidenceLevel;

        $action = $existingId ? 'update' : 'create';
        parent::__construct(
            "Agent-sourced declarative memory {$action} requires explicit user confirmation (type: {$type})",
            0
        );
    }
}
