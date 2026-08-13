<?php

namespace ClarionApp\LlmClient\Exceptions;

/**
 * A linked definition file could not be read off disk — a missing file,
 * an unreadable path, or an invalid repository path (contracts §12,
 * research.md D8/D11).
 *
 * Thrown only by GitDefinitionFileReader::readWorkingTreeContent();
 * latestCommitFor() never throws, returning null on any failure instead
 * (attribution is best-effort, content is not).
 *
 * A single, simple failure mode — unlike AgentDefinitionParseException/
 * AgentDefinitionResolutionException's multi-kind shape, no `$kind` enum
 * is needed here.
 */
final class AgentFileUnreadableException extends \RuntimeException
{
}
