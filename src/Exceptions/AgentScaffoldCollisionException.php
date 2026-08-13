<?php

namespace ClarionApp\LlmClient\Exceptions;

/**
 * The computed scaffold destination already exists (research.md D7) —
 * thrown before AgentDefinitionScaffoldWriter::write() ever opens,
 * truncates, or otherwise touches the existing file.
 *
 * A single, simple failure mode — like AgentFileUnreadableException, no
 * `$kind` enum is needed here.
 */
final class AgentScaffoldCollisionException extends \RuntimeException
{
    public function __construct(string $path)
    {
        parent::__construct(sprintf(
            'An agent definition already exists at %s. Choose a different name, or remove it first if you intend to replace it.',
            $path
        ));
    }
}
