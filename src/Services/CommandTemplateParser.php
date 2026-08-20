<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\ValueObjects\CommandTemplate;
use ClarionApp\LlmClient\ValueObjects\CommandTemplateConvention;
use ClarionApp\LlmClient\ValueObjects\TemplateDiscoveryProblem;
use ClarionApp\LlmClient\ValueObjects\TemplateDiscoveryProblemKind;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Parses a single command template file's already-read raw content into
 * either a CommandTemplate or a TemplateDiscoveryProblem — never both,
 * never neither, never a thrown exception for a content-level problem
 * (127-command-packs, contracts/command-template-parser.md §1, research.md
 * D1/D2/D8).
 *
 * Mirrors AgentDefinitionParser's collecting-not-throwing posture
 * (llm-client/src/Services/AgentDefinitionParser.php) but with a
 * deliberately shorter, more permissive rule set (research.md D2's one
 * divergence): frontmatter is optional, and when present, only
 * `description` is read — every other key (a real Spec-Kit-initialized
 * repo's `handoffs`/`scripts`, or anything else a third-party tool wrote)
 * is read past silently, never a problem. There is no unknown-key scan at
 * all here, unlike AgentDefinitionParser::scanForUnknownKeys().
 *
 * Pure function of its four arguments — no filesystem access, no database
 * access, no config lookup beyond the fixed convention/extension table
 * below. CommandPackLoader alone is responsible for turning a directory
 * into (rawContent, relativePath, convention) triples and for the
 * is_readable() precondition (UnreadableFile is reported by that caller,
 * never here).
 *
 * $relativePath is echoed back verbatim into whichever value object is
 * returned (it is the per-file identifier FR-011 requires every problem to
 * carry). For name derivation only, a leading convention-root prefix
 * (".claude/commands/" or ".github/agents/") is stripped first when
 * present, so the same derivation logic produces identical names whether
 * the caller passes a path already relative to the convention's own root
 * (e.g. "speckit.plan.md") or the fuller workspace-root-relative form
 * CommandPackLoader actually passes (e.g. ".claude/commands/speckit.plan.md").
 */
final class CommandTemplateParser
{
    private const CONVENTION_ROOTS = [
        'claude_command' => '.claude/commands/',
        'copilot_agent' => '.github/agents/',
    ];

    public function parse(
        string $rawContent,
        string $relativePath,
        CommandTemplateConvention $convention,
        string $codingProjectId,
    ): CommandTemplate|TemplateDiscoveryProblem {
        [$frontmatterBlock, $body] = $this->splitFrontmatter($rawContent);

        $description = null;

        if ($frontmatterBlock !== null) {
            try {
                $parsed = Yaml::parse($frontmatterBlock);
            } catch (ParseException $e) {
                return $this->problem(
                    $codingProjectId,
                    $relativePath,
                    TemplateDiscoveryProblemKind::MalformedFrontmatter,
                    $convention,
                    "Frontmatter in \"{$relativePath}\" could not be parsed as YAML: {$e->getMessage()}",
                );
            }

            if (!$this->isValidMapping($parsed)) {
                return $this->problem(
                    $codingProjectId,
                    $relativePath,
                    TemplateDiscoveryProblemKind::MalformedFrontmatter,
                    $convention,
                    "Frontmatter in \"{$relativePath}\" must be a YAML mapping.",
                );
            }

            $mapping = is_array($parsed) ? $parsed : [];

            if (isset($mapping['description']) && is_scalar($mapping['description'])) {
                $description = (string) $mapping['description'];
            }
        }

        $trimmedBody = trim($body);

        if ($trimmedBody === '') {
            return $this->problem(
                $codingProjectId,
                $relativePath,
                TemplateDiscoveryProblemKind::EmptyInstructions,
                $convention,
                "\"{$relativePath}\" has no instructions (the body is empty or whitespace-only).",
            );
        }

        return new CommandTemplate(
            codingProjectId: $codingProjectId,
            name: $this->deriveName($relativePath, $convention),
            description: $description,
            instructions: $trimmedBody,
            hasArgumentPlaceholder: str_contains($body, '$ARGUMENTS'),
            relativePath: $relativePath,
            convention: $convention,
        );
    }

    /**
     * Splits raw content into [frontmatterBlock, body]. Content not
     * starting with "---" followed by a newline is treated as body-only —
     * frontmatterBlock is null, and $rawContent in full is the body
     * (research.md D2). When a well-formed opening/closing "---" delimiter
     * pair is found, frontmatterBlock is the raw text between them (still
     * to be YAML-parsed by the caller) and body is everything after the
     * closing delimiter. A "---" opening delimiter with no matching
     * closing delimiter is treated the same as no frontmatter at all (the
     * entire content becomes the body) — there is no well-formed block to
     * report as malformed.
     *
     * @return array{0: ?string, 1: string}
     */
    private function splitFrontmatter(string $rawContent): array
    {
        if (preg_match('/^---\r?\n(.*?\r?\n)---\r?\n?(.*)$/s', $rawContent, $matches) === 1) {
            return [$matches[1], $matches[2]];
        }

        return [null, $rawContent];
    }

    /**
     * A parsed frontmatter block is valid when it is null (Yaml::parse()'s
     * own representation of an empty document — treated as an empty
     * mapping, mirroring AgentDefinitionParser::parseYaml()'s identical
     * choice), an empty array, or a non-list (associative) array. A bare
     * scalar or a list is not a mapping.
     */
    private function isValidMapping(mixed $parsed): bool
    {
        if ($parsed === null) {
            return true;
        }

        if (!is_array($parsed)) {
            return false;
        }

        if ($parsed === []) {
            return true;
        }

        return !array_is_list($parsed);
    }

    /**
     * Name derivation (research.md D8) — deterministic, case-folded, no
     * I/O, never consults $rawContent. A leading convention-root prefix is
     * stripped first if present; the remainder has its convention-specific
     * suffix stripped, and, for ClaudeCommand only, any remaining path
     * separators are joined with ":" before the whole result is
     * lowercased via mb_strtolower().
     */
    private function deriveName(string $relativePath, CommandTemplateConvention $convention): string
    {
        $path = $this->stripConventionRoot($relativePath, $convention);

        if ($convention === CommandTemplateConvention::CopilotAgent) {
            $path = preg_replace('/\.agent\.md$/i', '', $path) ?? $path;

            return mb_strtolower($path);
        }

        $path = preg_replace('/\.md$/i', '', $path) ?? $path;
        $path = str_replace('/', ':', $path);

        return mb_strtolower($path);
    }

    private function stripConventionRoot(string $relativePath, CommandTemplateConvention $convention): string
    {
        $root = self::CONVENTION_ROOTS[$convention->value];

        return str_starts_with($relativePath, $root) ? substr($relativePath, strlen($root)) : $relativePath;
    }

    private function problem(
        string $codingProjectId,
        string $relativePath,
        TemplateDiscoveryProblemKind $kind,
        CommandTemplateConvention $convention,
        string $message,
    ): TemplateDiscoveryProblem {
        return new TemplateDiscoveryProblem(
            codingProjectId: $codingProjectId,
            relativePath: $relativePath,
            kind: $kind,
            message: $message,
            convention: $convention,
        );
    }
}
