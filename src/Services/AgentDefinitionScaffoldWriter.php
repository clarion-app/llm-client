<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Exceptions\AgentScaffoldCollisionException;
use ClarionApp\LlmClient\Exceptions\AgentScaffoldDestinationException;

/**
 * Writes a generated agent definition's content to disk
 * (089-agent-scaffolding-cli, contracts §4, data-model.md §4, research.md
 * D7/D8). Stateless and filesystem-only — performs no parsing, no
 * validation, no database access, and trusts `$content` completely (that
 * guarantee belongs to AgentDefinitionScaffolder, not here).
 */
final class AgentDefinitionScaffoldWriter
{
    /**
     * @throws AgentScaffoldCollisionException $directory/$filename already exists.
     * @throws AgentScaffoldDestinationException $directory absent/unwritable, or the write failed.
     * @return string The absolute path written.
     */
    public function write(string $directory, string $filename, string $content): string
    {
        $destination = rtrim($directory, '/') . '/' . $filename;

        // Collision check happens before anything else is touched — the
        // existing file at $destination is never opened, read, or
        // truncated (FR-009, research.md D7).
        if (file_exists($destination)) {
            throw new AgentScaffoldCollisionException($destination);
        }

        if (!is_dir($directory)) {
            throw new AgentScaffoldDestinationException($directory, 'not_found');
        }

        if (!is_writable($directory)) {
            throw new AgentScaffoldDestinationException($directory, 'not_writable');
        }

        // Atomic write: the complete content is written to a temp file in
        // the same directory, then rename()d to the final path only once
        // fully successful (research.md D8) — no caller can ever observe a
        // partial file at $destination.
        $tempPath = tempnam($directory, '.agent-scaffold-');

        try {
            if ($tempPath === false
                || file_put_contents($tempPath, $content) === false
                || !rename($tempPath, $destination)
            ) {
                throw new AgentScaffoldDestinationException($directory, 'write_failed');
            }
        } finally {
            if ($tempPath !== false && file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }

        return $destination;
    }
}
