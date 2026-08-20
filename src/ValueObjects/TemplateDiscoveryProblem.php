<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * A single file that could not become a CommandTemplate
 * (127-command-packs, data-model.md §3). Kept distinct from any other
 * file's status by always carrying its own relativePath (FR-011) —
 * CommandPackLoader::discover() never short-circuits on the first problem
 * it finds, so a broken file never prevents any other file, in the same
 * workspace or any other, from being discovered.
 */
final class TemplateDiscoveryProblem
{
    public function __construct(
        public readonly string $codingProjectId,
        public readonly string $relativePath,
        public readonly TemplateDiscoveryProblemKind $kind,
        // Human-readable, names the specific file (FR-011).
        public readonly string $message,
        public readonly CommandTemplateConvention $convention,
    ) {
    }
}
