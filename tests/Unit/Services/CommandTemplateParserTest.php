<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Services\CommandTemplateParser;
use ClarionApp\LlmClient\ValueObjects\CommandTemplate;
use ClarionApp\LlmClient\ValueObjects\CommandTemplateConvention;
use ClarionApp\LlmClient\ValueObjects\TemplateDiscoveryProblem;
use ClarionApp\LlmClient\ValueObjects\TemplateDiscoveryProblemKind;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for CommandTemplateParser (127-command-packs, Phase 2,
 * contracts/command-template-parser.md §1, research.md D1/D2/D8).
 *
 * parse() is a pure function of its four arguments -- no filesystem, no
 * database, no config beyond the fixed convention/extension table -- so
 * every case below is exercised directly against raw strings, mirroring
 * AgentDefinitionParser's own collecting-not-throwing posture
 * (llm-client/src/Services/AgentDefinitionParser.php) but with a
 * deliberately more permissive frontmatter rule set: an unrecognized
 * frontmatter key is never a problem (research.md D2 -- the one
 * deliberate divergence from AgentDefinitionParser's strict unknown-key
 * rejection), since a command template's frontmatter is authored by, and
 * meaningful to, a different tool this feature does not control.
 */
class CommandTemplateParserTest extends TestCase
{
    private const PROJECT_ID = 'project-1234';

    private function parser(): CommandTemplateParser
    {
        return new CommandTemplateParser();
    }

    // -----------------------------------------------------------------
    // No frontmatter -- body-only is valid, description null
    // -----------------------------------------------------------------

    #[Test]
    public function a_claude_command_file_with_no_frontmatter_yields_a_template_with_null_description_and_trimmed_body(): void
    {
        $raw = "\n  Do the planning work now.\n\n";

        $result = $this->parser()->parse(
            $raw,
            'speckit.plan.md',
            CommandTemplateConvention::ClaudeCommand,
            self::PROJECT_ID,
        );

        $this->assertInstanceOf(CommandTemplate::class, $result);
        $this->assertNull($result->description);
        $this->assertSame('Do the planning work now.', $result->instructions);
    }

    // -----------------------------------------------------------------
    // Name derivation (research.md D8) -- pure, path-only, case-folded
    // -----------------------------------------------------------------

    #[Test]
    public function a_nested_claude_command_relative_path_derives_a_colon_joined_lowercased_name(): void
    {
        $result = $this->parser()->parse(
            'Body text.',
            'Foo/Bar.md',
            CommandTemplateConvention::ClaudeCommand,
            self::PROJECT_ID,
        );

        $this->assertInstanceOf(CommandTemplate::class, $result);
        $this->assertSame('foo:bar', $result->name, 'nested path separators must be joined with ":" and the whole result lowercased even though the source path used mixed case');
    }

    #[Test]
    public function a_flat_claude_command_relative_path_derives_a_lowercased_name_with_no_colon(): void
    {
        $result = $this->parser()->parse(
            'Body text.',
            'Speckit.Plan.md',
            CommandTemplateConvention::ClaudeCommand,
            self::PROJECT_ID,
        );

        $this->assertInstanceOf(CommandTemplate::class, $result);
        $this->assertSame('speckit.plan', $result->name, 'only the trailing .md extension is stripped; the remaining dot inside the basename is untouched, and the whole result is lowercased');
    }

    #[Test]
    public function a_copilot_agent_relative_path_derives_a_name_with_the_agent_md_suffix_stripped_and_no_colon_joining(): void
    {
        $result = $this->parser()->parse(
            'Body text.',
            'review.agent.md',
            CommandTemplateConvention::CopilotAgent,
            self::PROJECT_ID,
        );

        $this->assertInstanceOf(CommandTemplate::class, $result);
        $this->assertSame('review', $result->name);
    }

    #[Test]
    public function a_copilot_agent_name_is_lowercased_even_when_the_source_path_has_mixed_case(): void
    {
        $result = $this->parser()->parse(
            'Body text.',
            'MyReview.agent.md',
            CommandTemplateConvention::CopilotAgent,
            self::PROJECT_ID,
        );

        $this->assertInstanceOf(CommandTemplate::class, $result);
        $this->assertSame('myreview', $result->name);
    }

    // -----------------------------------------------------------------
    // Frontmatter present, real spec-kit shape (handoffs list, scripts
    // mapping) -- unrecognized keys are silently ignored, never a problem
    // -----------------------------------------------------------------

    #[Test]
    public function frontmatter_with_description_and_unrecognized_keys_parses_successfully_and_ignores_the_unrecognized_keys(): void
    {
        $raw = <<<'MD'
---
description: Plan a feature end to end
handoffs:
  - implement
  - verify
scripts:
  sh: scripts/plan.sh
  ps: scripts/plan.ps1
---
Do the planning work now.
MD;

        $result = $this->parser()->parse(
            $raw,
            'speckit.plan.md',
            CommandTemplateConvention::ClaudeCommand,
            self::PROJECT_ID,
        );

        $this->assertInstanceOf(CommandTemplate::class, $result, 'unrecognized frontmatter keys (handoffs, scripts) must never be treated as a problem');
        $this->assertSame('Plan a feature end to end', $result->description);
        $this->assertSame('Do the planning work now.', $result->instructions);
    }

    // -----------------------------------------------------------------
    // hasArgumentPlaceholder -- literal $ARGUMENTS token in the body
    // -----------------------------------------------------------------

    #[Test]
    public function has_argument_placeholder_is_true_when_the_body_contains_the_literal_arguments_token(): void
    {
        $result = $this->parser()->parse(
            "Do the thing with \$ARGUMENTS please.",
            'speckit.plan.md',
            CommandTemplateConvention::ClaudeCommand,
            self::PROJECT_ID,
        );

        $this->assertInstanceOf(CommandTemplate::class, $result);
        $this->assertTrue($result->hasArgumentPlaceholder);
    }

    #[Test]
    public function has_argument_placeholder_is_false_when_the_body_never_mentions_the_token(): void
    {
        $result = $this->parser()->parse(
            'Do the thing, no placeholder here.',
            'speckit.plan.md',
            CommandTemplateConvention::ClaudeCommand,
            self::PROJECT_ID,
        );

        $this->assertInstanceOf(CommandTemplate::class, $result);
        $this->assertFalse($result->hasArgumentPlaceholder);
    }

    // -----------------------------------------------------------------
    // Empty/whitespace-only body -- EmptyInstructions, with or without
    // frontmatter present
    // -----------------------------------------------------------------

    #[Test]
    public function an_entirely_empty_body_with_no_frontmatter_is_an_empty_instructions_problem(): void
    {
        $result = $this->parser()->parse(
            '',
            '.claude/commands/blank.md',
            CommandTemplateConvention::ClaudeCommand,
            self::PROJECT_ID,
        );

        $this->assertInstanceOf(TemplateDiscoveryProblem::class, $result);
        $this->assertSame(TemplateDiscoveryProblemKind::EmptyInstructions, $result->kind);
        $this->assertSame('.claude/commands/blank.md', $result->relativePath);
        $this->assertSame(CommandTemplateConvention::ClaudeCommand, $result->convention);
    }

    #[Test]
    public function a_whitespace_only_body_with_no_frontmatter_is_an_empty_instructions_problem(): void
    {
        $result = $this->parser()->parse(
            "   \n\n\t  \n",
            'whitespace.agent.md',
            CommandTemplateConvention::CopilotAgent,
            self::PROJECT_ID,
        );

        $this->assertInstanceOf(TemplateDiscoveryProblem::class, $result);
        $this->assertSame(TemplateDiscoveryProblemKind::EmptyInstructions, $result->kind);
        $this->assertSame('whitespace.agent.md', $result->relativePath);
        $this->assertSame(CommandTemplateConvention::CopilotAgent, $result->convention);
    }

    #[Test]
    public function a_whitespace_only_body_after_valid_frontmatter_is_still_an_empty_instructions_problem(): void
    {
        $raw = <<<'MD'
---
description: Has a description but nothing else
---


MD;

        $result = $this->parser()->parse(
            $raw,
            'has-description-only.md',
            CommandTemplateConvention::ClaudeCommand,
            self::PROJECT_ID,
        );

        $this->assertInstanceOf(TemplateDiscoveryProblem::class, $result, 'a description alone is never enough -- the body itself must be non-empty');
        $this->assertSame(TemplateDiscoveryProblemKind::EmptyInstructions, $result->kind);
        $this->assertSame('has-description-only.md', $result->relativePath);
    }

    // -----------------------------------------------------------------
    // Malformed frontmatter -- delimiters present, block fails to parse
    // or parses to a non-mapping
    // -----------------------------------------------------------------

    #[Test]
    public function frontmatter_that_fails_yaml_parsing_is_a_malformed_frontmatter_problem(): void
    {
        $raw = <<<'MD'
---
description: [unclosed flow sequence
---
Body text that would otherwise be fine.
MD;

        $result = $this->parser()->parse(
            $raw,
            'broken-yaml.md',
            CommandTemplateConvention::ClaudeCommand,
            self::PROJECT_ID,
        );

        $this->assertInstanceOf(TemplateDiscoveryProblem::class, $result);
        $this->assertSame(TemplateDiscoveryProblemKind::MalformedFrontmatter, $result->kind);
        $this->assertSame('broken-yaml.md', $result->relativePath);
        $this->assertSame(CommandTemplateConvention::ClaudeCommand, $result->convention);
    }

    #[Test]
    public function frontmatter_that_parses_to_a_bare_scalar_is_a_malformed_frontmatter_problem(): void
    {
        $raw = <<<'MD'
---
just a plain scalar string, not a mapping
---
Body text that would otherwise be fine.
MD;

        $result = $this->parser()->parse(
            $raw,
            'scalar-frontmatter.md',
            CommandTemplateConvention::ClaudeCommand,
            self::PROJECT_ID,
        );

        $this->assertInstanceOf(TemplateDiscoveryProblem::class, $result);
        $this->assertSame(TemplateDiscoveryProblemKind::MalformedFrontmatter, $result->kind);
    }

    #[Test]
    public function frontmatter_that_parses_to_a_list_is_a_malformed_frontmatter_problem(): void
    {
        $raw = <<<'MD'
---
- one
- two
---
Body text that would otherwise be fine.
MD;

        $result = $this->parser()->parse(
            $raw,
            'list-frontmatter.md',
            CommandTemplateConvention::ClaudeCommand,
            self::PROJECT_ID,
        );

        $this->assertInstanceOf(TemplateDiscoveryProblem::class, $result);
        $this->assertSame(TemplateDiscoveryProblemKind::MalformedFrontmatter, $result->kind);
    }

    // -----------------------------------------------------------------
    // D8: name derivation purity -- content is never consulted for naming
    // -----------------------------------------------------------------

    #[Test]
    public function identical_relative_path_and_convention_produce_identical_names_regardless_of_raw_content(): void
    {
        $first = $this->parser()->parse(
            'Completely different content, version one.',
            'foo/bar.md',
            CommandTemplateConvention::ClaudeCommand,
            self::PROJECT_ID,
        );

        $second = $this->parser()->parse(
            <<<'MD'
---
description: A totally different document
---
Version two, entirely different content and frontmatter.
MD,
            'foo/bar.md',
            CommandTemplateConvention::ClaudeCommand,
            self::PROJECT_ID,
        );

        // A third variant deliberately sets a `name:` frontmatter key to a
        // value that differs from the path-derived name -- content, whether
        // in the body or in frontmatter, must never be consulted for naming
        // (D8). Without this case, a parser that started reading a `name:`
        // frontmatter key when present would go undetected: neither of the
        // two variants above ever sets that key, so a change limited to
        // "prefer a `name:` frontmatter key when present" would leave both
        // of their derived names untouched and this test green regardless.
        $third = $this->parser()->parse(
            <<<'MD'
---
name: totally-different-name
description: Yet another different document
---
Version three, with a name: key that must be ignored.
MD,
            'foo/bar.md',
            CommandTemplateConvention::ClaudeCommand,
            self::PROJECT_ID,
        );

        $this->assertInstanceOf(CommandTemplate::class, $first);
        $this->assertInstanceOf(CommandTemplate::class, $second);
        $this->assertInstanceOf(CommandTemplate::class, $third);
        $this->assertSame($first->name, $second->name, 'name derivation must be a pure function of relativePath/convention alone -- content must never be consulted');
        $this->assertSame($first->name, $third->name, 'name derivation must ignore a `name:` frontmatter key -- the relative path is the only source of truth');
        $this->assertSame('foo:bar', $first->name);
    }

    // -----------------------------------------------------------------
    // parse() never throws, for any malformed/empty input tried above
    // -----------------------------------------------------------------

    #[Test]
    public function parse_never_throws_for_any_malformed_or_empty_input(): void
    {
        $cases = [
            ['', CommandTemplateConvention::ClaudeCommand],
            ["   \n\t \n", CommandTemplateConvention::CopilotAgent],
            ["---\ndescription: [unclosed\n---\nBody\n", CommandTemplateConvention::ClaudeCommand],
            ["---\njust a scalar\n---\nBody\n", CommandTemplateConvention::ClaudeCommand],
            ["---\n- a\n- b\n---\nBody\n", CommandTemplateConvention::ClaudeCommand],
            ["---\n\n---\n\n", CommandTemplateConvention::CopilotAgent],
        ];

        foreach ($cases as [$raw, $convention]) {
            try {
                $result = $this->parser()->parse($raw, 'whatever.md', $convention, self::PROJECT_ID);
            } catch (\Throwable $e) {
                $this->fail('parse() must never throw for a content-level problem; got: '.get_class($e).': '.$e->getMessage());
            }

            $this->assertTrue(
                $result instanceof CommandTemplate || $result instanceof TemplateDiscoveryProblem,
                'parse() must always return either a CommandTemplate or a TemplateDiscoveryProblem'
            );
        }
    }
}
