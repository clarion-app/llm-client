<?php

namespace ClarionApp\LlmClient\Exceptions;

/**
 * A model response stream whose headers arrived but which ended without its
 * end marker (no finish reason, no `[DONE]`). A cut stream is a failed
 * attempt, never a finished reply, and is retried like any other transient
 * provider failure.
 */
class IncompleteStream extends \RuntimeException
{
    public function __construct(string $message = 'The model response stream ended before it completed.')
    {
        parent::__construct($message);
    }
}
