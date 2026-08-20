<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Models\CodingProject;
use ClarionApp\LlmClient\Services\CommandPackLoader;
use ClarionApp\LlmClient\ValueObjects\CommandPackDiscoveryResult;
use ClarionApp\LlmClient\ValueObjects\CommandTemplateConvention;
use ClarionApp\LlmClient\ValueObjects\TemplateDiscoveryProblemKind;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for CommandPackLoader (127-command-packs, Phase 2,
 * contracts/command-template-parser.md §2, Grounding note 8) against a
 * real temp-directory fixture -- mirrors WorkspaceSearchServiceTest's own
 * fixture shape (plain mkdir(), no git needed, recursive teardown)
 * rather than a mock filesystem, since discover() is documented as a
 * live, uncached filesystem scan (research.md D6).
 *
 * CodingProject is constructed in-memory only (`new CodingProject([...])`,
 * never persisted) -- discover() never reads $project->user_id and
 * performs no ownership check itself (research.md D6); that check is
 * McpPromptRegistry's job, one layer up.
 */
class CommandPackLoaderTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectDir = sys_get_temp_dir().'/command_pack_loader_'.uniqid('', true);
        mkdir($this->projectDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);

        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            // Restore permissions before deleting -- a chmod(0000) fixture
            // file would otherwise refuse to unlink/rmdir under it.
            @chmod($path, 0777);
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @chmod($dir, 0777);
        @rmdir($dir);
    }

    private function project(?string $rootPath = null): CodingProject
    {
        return new CodingProject(['root_path' => $rootPath ?? $this->projectDir]);
    }

    private function write(string $relativePath, string $content): void
    {
        $full = $this->projectDir.'/'.$relativePath;
        @mkdir(dirname($full), 0777, true);
        file_put_contents($full, $content);
    }

    private function loader(): CommandPackLoader
    {
        return new CommandPackLoader();
    }

    private const VALID_BODY = "Do the thing now.\n";

    private const MALFORMED_FRONTMATTER = <<<'MD'
---
description: [unclosed flow sequence
---
Body text that would otherwise be fine.
MD;

    // -----------------------------------------------------------------
    // Both conventions scanned; nested + flat claude_command, flat-only
    // copilot_agent
    // -----------------------------------------------------------------

    #[Test]
    public function both_conventions_are_scanned_and_every_matching_file_yields_one_command_with_no_problems(): void
    {
        $this->write('.claude/commands/speckit.plan.md', self::VALID_BODY);
        $this->write('.claude/commands/nested/deep.md', self::VALID_BODY);
        $this->write('.github/agents/review.agent.md', self::VALID_BODY);

        $result = $this->loader()->discover($this->project());

        $this->assertInstanceOf(CommandPackDiscoveryResult::class, $result);
        $this->assertSame([], $result->problems);
        $this->assertCount(3, $result->commands);

        $relativePaths = array_map(static fn ($c) => $c->relativePath, $result->commands);
        sort($relativePaths);
        $expected = ['.claude/commands/nested/deep.md', '.claude/commands/speckit.plan.md', '.github/agents/review.agent.md'];
        sort($expected);
        $this->assertSame($expected, $relativePaths);
    }

    // -----------------------------------------------------------------
    // Per-file isolation (FR-011/US5): one bad file must never sink
    // another, in the same directory
    // -----------------------------------------------------------------

    #[Test]
    public function one_malformed_file_and_one_empty_file_never_prevent_the_valid_files_in_the_same_directory_from_being_discovered(): void
    {
        $this->write('.claude/commands/valid-one.md', self::VALID_BODY);
        $this->write('.claude/commands/valid-two.md', self::VALID_BODY);
        $this->write('.claude/commands/empty-file.md', '');
        $this->write('.claude/commands/broken-frontmatter.md', self::MALFORMED_FRONTMATTER);

        $result = $this->loader()->discover($this->project());

        $commandPaths = array_map(static fn ($c) => $c->relativePath, $result->commands);
        sort($commandPaths);
        $this->assertSame(
            ['.claude/commands/valid-one.md', '.claude/commands/valid-two.md'],
            $commandPaths,
            'only the two genuinely valid files may appear in commands'
        );

        $this->assertCount(2, $result->problems);

        $problemsByPath = [];
        foreach ($result->problems as $problem) {
            $problemsByPath[$problem->relativePath] = $problem;
        }

        $this->assertArrayHasKey('.claude/commands/empty-file.md', $problemsByPath);
        $this->assertArrayHasKey('.claude/commands/broken-frontmatter.md', $problemsByPath);
        $this->assertSame(TemplateDiscoveryProblemKind::EmptyInstructions, $problemsByPath['.claude/commands/empty-file.md']->kind);
        $this->assertSame(TemplateDiscoveryProblemKind::MalformedFrontmatter, $problemsByPath['.claude/commands/broken-frontmatter.md']->kind);
    }

    // -----------------------------------------------------------------
    // Missing root_path -- empty result, never an exception
    // -----------------------------------------------------------------

    #[Test]
    public function a_missing_root_path_yields_an_empty_result_with_no_exception(): void
    {
        $missingPath = sys_get_temp_dir().'/command_pack_loader_missing_'.uniqid('', true);
        $this->assertDirectoryDoesNotExist($missingPath);

        $result = $this->loader()->discover($this->project($missingPath));

        $this->assertSame([], $result->commands);
        $this->assertSame([], $result->problems);
    }

    // -----------------------------------------------------------------
    // Zero recognized files -- unrecognized content silently absent
    // -----------------------------------------------------------------

    #[Test]
    public function a_workspace_with_only_an_unrelated_file_yields_an_empty_result(): void
    {
        $this->write('README.txt', 'nothing to see here');

        $result = $this->loader()->discover($this->project());

        $this->assertSame([], $result->commands);
        $this->assertSame([], $result->problems);
    }

    #[Test]
    public function a_workspace_with_only_one_of_the_two_convention_directories_still_discovers_that_conventions_files(): void
    {
        $this->write('.claude/commands/speckit.plan.md', self::VALID_BODY);
        // Deliberately no .github/agents directory at all.

        $result = $this->loader()->discover($this->project());

        $this->assertCount(1, $result->commands);
        $this->assertSame('.claude/commands/speckit.plan.md', $result->commands[0]->relativePath);
        $this->assertSame(CommandTemplateConvention::ClaudeCommand, $result->commands[0]->convention);
        $this->assertSame([], $result->problems, 'a missing convention directory must never itself be reported as a problem');
    }

    // -----------------------------------------------------------------
    // Unreadable file -- UnreadableFile problem, no warning/exception
    // -----------------------------------------------------------------

    #[Test]
    public function an_unreadable_file_yields_an_unreadable_file_problem_with_no_warning_or_exception(): void
    {
        $this->write('.claude/commands/locked.md', self::VALID_BODY);
        $fullPath = $this->projectDir.'/.claude/commands/locked.md';
        chmod($fullPath, 0000);

        if (is_readable($fullPath)) {
            $this->markTestSkipped('chmod(0000) has no effect for the current user (likely running as root) -- cannot exercise the unreadable-file path');
        }

        $result = $this->loader()->discover($this->project());

        $this->assertSame([], $result->commands);
        $this->assertCount(1, $result->problems);
        $this->assertSame(TemplateDiscoveryProblemKind::UnreadableFile, $result->problems[0]->kind);
        $this->assertSame('.claude/commands/locked.md', $result->problems[0]->relativePath);
    }

    // -----------------------------------------------------------------
    // Intra-workspace tie-break (research.md D4): both conventions
    // deriving the same bare name must both be returned, never deduped
    // by the loader itself
    // -----------------------------------------------------------------

    #[Test]
    public function both_conventions_producing_the_same_derived_name_are_both_returned_with_no_dedup_or_overwrite(): void
    {
        $this->write('.claude/commands/review.md', self::VALID_BODY);
        $this->write('.github/agents/review.agent.md', self::VALID_BODY);

        $result = $this->loader()->discover($this->project());

        $this->assertCount(2, $result->commands, 'the loader itself must perform no bare-name deduplication');
        $this->assertSame([], $result->problems);

        $byConvention = [];
        foreach ($result->commands as $command) {
            $byConvention[$command->convention->value] = $command;
        }

        $this->assertArrayHasKey(CommandTemplateConvention::ClaudeCommand->value, $byConvention);
        $this->assertArrayHasKey(CommandTemplateConvention::CopilotAgent->value, $byConvention);
        $this->assertSame('review', $byConvention[CommandTemplateConvention::ClaudeCommand->value]->name);
        $this->assertSame('review', $byConvention[CommandTemplateConvention::CopilotAgent->value]->name);
    }

    // -----------------------------------------------------------------
    // Determinism -- two calls in a row, no filesystem change, identical
    // results
    // -----------------------------------------------------------------

    #[Test]
    public function two_discover_calls_in_a_row_with_no_filesystem_change_return_field_for_field_identical_results(): void
    {
        $this->write('.claude/commands/speckit.plan.md', self::VALID_BODY);
        $this->write('.claude/commands/broken.md', self::MALFORMED_FRONTMATTER);
        $this->write('.github/agents/review.agent.md', self::VALID_BODY);

        $project = $this->project();
        $loader = $this->loader();

        $first = $loader->discover($project);
        $second = $loader->discover($project);

        $this->assertEquals($first, $second, 'discover() must be a pure, uncached scan -- repeating it with no filesystem change must yield an identical result');
    }

    // -----------------------------------------------------------------
    // No writes; no ownership check performed here
    // -----------------------------------------------------------------

    #[Test]
    public function discover_performs_no_filesystem_writes_and_never_consults_user_id(): void
    {
        $this->write('.claude/commands/speckit.plan.md', self::VALID_BODY);

        $before = $this->snapshotTree($this->projectDir);

        // No user_id set at all -- discover() must not fail attempting to
        // read or compare it, since ownership checking is not its job.
        $project = new CodingProject(['root_path' => $this->projectDir]);
        $result = $this->loader()->discover($project);

        $this->assertCount(1, $result->commands);

        $after = $this->snapshotTree($this->projectDir);
        $this->assertSame($before, $after, 'discover() must perform no writes of any kind to the scanned workspace');
    }

    // -----------------------------------------------------------------
    // Volume edge case (spec.md Edge Cases: "A workspace defines a very
    // large number of command templates -- all remain individually
    // discoverable; none are silently dropped for volume."). Added during
    // Phase 8 (T038) reconciliation -- no prior test exercised this case.
    // -----------------------------------------------------------------

    #[Test]
    public function a_large_number_of_valid_templates_are_all_individually_discovered_with_none_dropped(): void
    {
        $count = 250;
        for ($i = 0; $i < $count; $i++) {
            $this->write(".claude/commands/cmd-{$i}.md", self::VALID_BODY);
        }

        $result = $this->loader()->discover($this->project());

        $this->assertSame([], $result->problems);
        $this->assertCount($count, $result->commands, 'none of the many valid templates may be silently dropped for volume');

        $names = array_map(static fn ($c) => $c->name, $result->commands);
        $this->assertCount($count, array_unique($names), 'every one of the many templates must remain individually distinguishable');
        for ($i = 0; $i < $count; $i++) {
            $this->assertContains("cmd-{$i}", $names);
        }
    }

    /**
     * @return array<string, string>
     */
    private function snapshotTree(string $dir): array
    {
        $snapshot = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $relative = substr($file->getPathname(), strlen($dir) + 1);
            $snapshot[$relative] = $file->isFile() ? md5_file($file->getPathname()) : 'dir';
        }

        ksort($snapshot);

        return $snapshot;
    }
}
