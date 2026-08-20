<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * A single, successfully-parsed project-defined command template
 * (127-command-packs, data-model.md §2). A plain, unvalidated readonly
 * value object — every validation rule lives in CommandTemplateParser,
 * never here (matching AgentDefinition's own established "every validation
 * rule lives in the parser" split). Constructed fresh on every
 * CommandPackLoader::discover() call and never persisted.
 */
final class CommandTemplate
{
    public function __construct(
        public readonly string $codingProjectId,
        // Derived, case-folded (research.md D8) — a pure function of
        // relativePath/convention alone, never of raw content.
        public readonly string $name,
        // Frontmatter `description`, else null.
        public readonly ?string $description,
        // Raw body, pre-argument-substitution, trimmed. Never empty — an
        // empty/whitespace-only body is a TemplateDiscoveryProblem
        // (EmptyInstructions), not a CommandTemplate.
        public readonly string $instructions,
        // Whether the body contains the literal token $ARGUMENTS.
        public readonly bool $hasArgumentPlaceholder,
        // The exact $relativePath the parser was called with (e.g.
        // ".claude/commands/speckit.plan.md") — echoed back unmodified.
        public readonly string $relativePath,
        public readonly CommandTemplateConvention $convention,
    ) {
    }
}
