<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * The most recent commit touching a linked definition file, returned by
 * GitDefinitionFileReader::latestCommitFor() (data-model.md §6). `null`
 * (not this object) when no commit touches the file yet (research.md
 * D8/D11) — an uncommitted-but-readable file is a valid state, not an
 * error.
 */
final readonly class GitCommitInfo
{
    public function __construct(
        public string $hash,
        public string $authorName,
        public \DateTimeInterface $committedAt,
    ) {
    }
}
