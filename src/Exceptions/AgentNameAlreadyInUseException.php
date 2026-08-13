<?php

namespace ClarionApp\LlmClient\Exceptions;

/**
 * A clone's requested name collides with a name the calling user already
 * has in use (data-model.md §3, FR-014).
 *
 * A single, simple failure mode — like AgentFileUnreadableException, no
 * `$kind` enum is needed here.
 */
final class AgentNameAlreadyInUseException extends \RuntimeException
{
    public function __construct(public readonly string $name)
    {
        parent::__construct(sprintf("An agent named '%s' already exists. Choose a different name.", $name));
    }
}
