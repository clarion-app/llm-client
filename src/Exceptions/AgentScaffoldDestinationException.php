<?php

namespace ClarionApp\LlmClient\Exceptions;

/**
 * The scaffold write destination is unusable — the directory does not
 * exist, is not writable, or the write itself failed (research.md D8).
 *
 * `$reason` is a plain string tag, not a new enum — this feature's own
 * failure taxonomy is small enough not to warrant one, unlike 086/088's
 * multi-kind exceptions.
 */
final class AgentScaffoldDestinationException extends \RuntimeException
{
    private string $reason;

    public function __construct(string $path, string $reason)
    {
        $this->reason = $reason;

        $message = match ($reason) {
            'not_found' => sprintf('Destination directory does not exist: %s.', $path),
            'not_writable' => sprintf('Destination directory is not writable: %s.', $path),
            'write_failed' => sprintf('Could not write the agent definition: %s.', $path),
            default => sprintf('Could not use destination directory: %s.', $path),
        };

        parent::__construct($message);
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
