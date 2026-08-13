<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * The result of AgentDefinitionScaffolder::generate() — the spec's own
 * "Generated Definition" Key Entity, before it has been written to disk
 * (data-model.md §2).
 *
 * There is no way to obtain an instance whose `content` has not already
 * passed AgentDefinitionValidator::check() with `valid === true`:
 * AgentDefinitionScaffolder::generate() throws instead of ever returning
 * a GeneratedScaffold whose content would violate that guarantee
 * (research.md D3/D12).
 */
final readonly class GeneratedScaffold
{
    public function __construct(
        public string $name,
        public ?string $kindSlug,
        public string $filename,
        public string $content,
    ) {
    }
}
