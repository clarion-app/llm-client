<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * The two recognized project-defined command-pack layouts
 * (127-command-packs, data-model.md §1, research.md D1) — a fixed,
 * code-defined, non-configurable set, mirroring LanguageRuntime's own
 * "small, code-defined, non-configurable set" precedent.
 *
 * Each case's own scan root, recognized file suffix, and collision-priority
 * rank live in small private lookups inside CommandPackLoader (and, for
 * name derivation, CommandTemplateParser) rather than on this enum itself,
 * keeping this a pure value carrier.
 */
enum CommandTemplateConvention: string
{
    // <workspace root>/.claude/commands/**/*.md — nested directories
    // allowed, frontmatter optional.
    case ClaudeCommand = 'claude_command';

    // <workspace root>/.github/agents/*.agent.md — flat only, frontmatter
    // optional.
    case CopilotAgent = 'copilot_agent';
}
