<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\CodingProject;
use ClarionApp\LlmClient\ValueObjects\CommandPackDiscoveryResult;
use ClarionApp\LlmClient\ValueObjects\CommandTemplate;
use ClarionApp\LlmClient\ValueObjects\CommandTemplateConvention;
use ClarionApp\LlmClient\ValueObjects\TemplateDiscoveryProblem;
use ClarionApp\LlmClient\ValueObjects\TemplateDiscoveryProblemKind;
use FilesystemIterator;
use Generator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Scans both recognized command-template conventions (research.md D1)
 * under $project->root_path and parses every matching file found
 * (127-command-packs, contracts/command-template-parser.md §2). A missing
 * root_path, or a workspace with no recognized files under either
 * convention, returns an empty result — never an error.
 *
 * Performs the is_readable() precondition itself (reporting UnreadableFile
 * directly, never delegating that check to CommandTemplateParser, whose
 * own contract guarantees it is only ever called with content already
 * successfully read). Every file is independently parsed — one file's
 * TemplateDiscoveryProblem never prevents any other file, in the same
 * workspace or any other, from being discovered (FR-011).
 *
 * Re-scans the filesystem on every call — no internal caching (research.md
 * D6). Performs no writes of any kind, and never consults
 * $project->user_id or performs any ownership check itself — $project is
 * assumed already resolved and owned by the caller (McpPromptRegistry).
 *
 * Performs no bare-name deduplication of its own: when both conventions
 * derive the same name for the same workspace, both CommandTemplate
 * entries are returned in $commands untouched — collision resolution is
 * entirely McpPromptRegistry's job, one layer up (tasks.md Grounding note
 * 13).
 */
final class CommandPackLoader
{
    private const CLAUDE_COMMAND_SUBDIR = '.claude/commands';

    private const COPILOT_AGENT_SUBDIR = '.github/agents';

    public function __construct(private readonly CommandTemplateParser $parser = new CommandTemplateParser())
    {
    }

    public function discover(CodingProject $project): CommandPackDiscoveryResult
    {
        $commands = [];
        $problems = [];

        $this->scanClaudeCommands($project, $commands, $problems);
        $this->scanCopilotAgents($project, $commands, $problems);

        return new CommandPackDiscoveryResult(commands: $commands, problems: $problems);
    }

    /**
     * @param list<CommandTemplate> $commands
     * @param list<TemplateDiscoveryProblem> $problems
     */
    private function scanClaudeCommands(CodingProject $project, array &$commands, array &$problems): void
    {
        $root = $this->conventionRoot($project, self::CLAUDE_COMMAND_SUBDIR);

        if (!is_dir($root)) {
            return;
        }

        foreach ($this->recursiveMarkdownFiles($root) as $fullPath => $relativeToRoot) {
            $relativePath = self::CLAUDE_COMMAND_SUBDIR.'/'.$relativeToRoot;
            $this->parseOneFile($project, $fullPath, $relativePath, CommandTemplateConvention::ClaudeCommand, $commands, $problems);
        }
    }

    /**
     * @param list<CommandTemplate> $commands
     * @param list<TemplateDiscoveryProblem> $problems
     */
    private function scanCopilotAgents(CodingProject $project, array &$commands, array &$problems): void
    {
        $root = $this->conventionRoot($project, self::COPILOT_AGENT_SUBDIR);

        if (!is_dir($root)) {
            return;
        }

        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $fullPath = $root.'/'.$entry;

            if (!is_file($fullPath) || !str_ends_with(strtolower($entry), '.agent.md')) {
                continue;
            }

            $relativePath = self::COPILOT_AGENT_SUBDIR.'/'.$entry;
            $this->parseOneFile($project, $fullPath, $relativePath, CommandTemplateConvention::CopilotAgent, $commands, $problems);
        }
    }

    private function conventionRoot(CodingProject $project, string $subdir): string
    {
        return rtrim((string) $project->root_path, '/').'/'.$subdir;
    }

    /**
     * Recursively finds every *.md file under $root (ClaudeCommand allows
     * nested directories, research.md D1).
     *
     * @return Generator<string, string> fullPath => path relative to $root, forward-slash separated
     */
    private function recursiveMarkdownFiles(string $root): Generator
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if (!$file->isFile() || !str_ends_with(strtolower($file->getFilename()), '.md')) {
                continue;
            }

            $fullPath = $file->getPathname();
            $relativeToRoot = ltrim(str_replace('\\', '/', substr($fullPath, strlen($root))), '/');

            yield $fullPath => $relativeToRoot;
        }
    }

    /**
     * @param list<CommandTemplate> $commands
     * @param list<TemplateDiscoveryProblem> $problems
     */
    private function parseOneFile(
        CodingProject $project,
        string $fullPath,
        string $relativePath,
        CommandTemplateConvention $convention,
        array &$commands,
        array &$problems,
    ): void {
        $codingProjectId = (string) $project->id;

        if (!is_readable($fullPath)) {
            $problems[] = new TemplateDiscoveryProblem(
                codingProjectId: $codingProjectId,
                relativePath: $relativePath,
                kind: TemplateDiscoveryProblemKind::UnreadableFile,
                message: "\"{$relativePath}\" could not be read.",
                convention: $convention,
            );

            return;
        }

        $rawContent = @file_get_contents($fullPath);

        if ($rawContent === false) {
            $problems[] = new TemplateDiscoveryProblem(
                codingProjectId: $codingProjectId,
                relativePath: $relativePath,
                kind: TemplateDiscoveryProblemKind::UnreadableFile,
                message: "\"{$relativePath}\" could not be read.",
                convention: $convention,
            );

            return;
        }

        $result = $this->parser->parse($rawContent, $relativePath, $convention, $codingProjectId);

        if ($result instanceof CommandTemplate) {
            $commands[] = $result;
        } else {
            $problems[] = $result;
        }
    }
}
