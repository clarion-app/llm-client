<?php

namespace Tests\RealSpecKit;

use ClarionApp\LlmClient\Models\CodingProject;
use ClarionApp\LlmClient\Services\CommandPackLoader;
use ClarionApp\LlmClient\Services\McpPromptRegistry;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\RealSpecKit\Support\EnvironmentUnavailableException;
use Tests\RealSpecKit\Support\SpecKitCliFixtureBuilder;
use Tests\RealSpecKit\Support\SpecKitFixtureProject;
use Tests\RealSpecKit\Support\SpecKitOutcomeLedger;
use Tests\TestCase;

/**
 * 129-speckit-verify-acceptance, Phase 3 (US1), T015 — quickstart.md
 * Scenario 1 in full: a real, unmodified, `--commands`-mode Copilot
 * Spec-Kit project's every workflow command is discoverable through
 * McpPromptRegistry::getPrompts() (the same call
 * CommandPackDiscoveryJourneyTest already exercises,
 * contracts/command-pack-mcp-reuse.md), with no file altered as a
 * precondition and no name added/renamed/reformatted to make it appear
 * (FR-002/FR-003).
 *
 * Real `specify init` CLI invocation, real filesystem, real
 * CommandPackLoader/McpPromptRegistry — only the ledger bookkeeping is
 * this feature's own new code.
 */
#[Group('real-speckit-cli')]
class CommandDiscoveryJourneyTest extends TestCase
{
    private ?SpecKitFixtureProject $fixture = null;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            SpecKitCliFixtureBuilder::assertAvailable();
        } catch (EnvironmentUnavailableException $e) {
            $this->markTestSkipped($e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if ($this->fixture !== null && is_dir($this->fixture->rootPath)) {
            (new Process(['rm', '-rf', $this->fixture->rootPath]))->run();
        }

        parent::tearDown();
    }

    #[Test]
    public function every_workflow_command_in_a_real_copilot_commands_mode_project_is_discoverable(): void
    {
        // --- Step 2: a real `specify init` run, self-scanned agent
        // command files (research.md D9 — never hardcoded).
        $this->fixture = (new SpecKitCliFixtureBuilder())->build('copilot', '--commands');

        $this->assertNotEmpty(
            $this->fixture->agentCommandFiles,
            'the real specify init run produced no .github/agents/*.agent.md files -- nothing to verify'
        );

        $checksumsBefore = $this->checksumFixtureFiles($this->fixture);

        // --- Step 3: a real, persisted CodingProject pointed at the
        // fixture's real directory. US1 never invokes anything, so no
        // confirmation_relaxed is needed.
        $userId = (string) Str::uuid();
        $project = CodingProject::create([
            'user_id' => $userId,
            'name' => 'speckit discovery journey',
            'root_path' => $this->fixture->rootPath,
            'test_command' => null,
        ]);

        // --- Step 4 (AS1/FR-003): every expected command name appears in
        // the listing, through the exact same call
        // CommandPackDiscoveryJourneyTest already exercises.
        $registry = new McpPromptRegistry();
        $listing = $registry->getPrompts(codingProjectId: $project->id, userId: $userId);

        $this->assertArrayHasKey('prompts', $listing);
        $listedNames = array_map(fn (array $p) => $p['name'], $listing['prompts']);

        $expectedNames = $this->fixture->expectedCommandNames();
        $this->assertNotEmpty($expectedNames);

        foreach ($expectedNames as $expectedName) {
            $this->assertContains(
                $expectedName,
                $listedNames,
                "expected command '{$expectedName}' (derived from a real specify init scan) to appear in getPrompts()'s listing"
            );
        }

        // --- Step 5 (AS2/FR-002): nothing was altered as a precondition
        // -- byte-identical checksums before/after discovery.
        $checksumsAfter = $this->checksumFixtureFiles($this->fixture);
        $this->assertSame(
            $checksumsBefore,
            $checksumsAfter,
            'discovery must never write to, move, or reformat any real fixture file'
        );

        // Cross-check each expected command's declared source path against
        // the real file on disk at that exact relative path.
        // McpPromptRegistry::getPrompts()'s own listing entries carry only
        // a coarse 'source' => 'project'/'builtin' marker (no per-file
        // path), so the exact relative path -- CommandTemplate::relativePath
        // -- is cross-checked against the same, unmodified
        // CommandPackLoader::discover() call the registry itself makes
        // internally (data-model.md §5: reused, unmodified entity).
        $discovery = (new CommandPackLoader())->discover($project);
        $this->assertSame([], $discovery->problems, 'a real specify init layout must parse with zero problems');

        $templatesByName = [];
        foreach ($discovery->commands as $template) {
            $templatesByName[$template->name] = $template;
        }

        foreach ($expectedNames as $expectedName) {
            $this->assertArrayHasKey(
                $expectedName,
                $templatesByName,
                "expected command '{$expectedName}' must have a corresponding CommandTemplate from CommandPackLoader::discover()"
            );

            $template = $templatesByName[$expectedName];
            $realFilePath = rtrim($this->fixture->rootPath, '/').'/'.$template->relativePath;

            $this->assertFileExists(
                $realFilePath,
                "the declared source path '{$template->relativePath}' for '{$expectedName}' must name a real file on disk"
            );
            $this->assertContains(
                $template->relativePath,
                $this->fixture->agentCommandFiles,
                "the declared source path '{$template->relativePath}' must be exactly one of the real, self-scanned agentCommandFiles -- no renaming/reformatting"
            );
        }

        // --- Step 6: build the ledger -- every command is
        // DiscoveredOnly, US1 never invokes anything.
        $ledger = new SpecKitOutcomeLedger();
        foreach ($expectedNames as $expectedName) {
            $ledger->expectDiscoveredOnly('copilot', $expectedName);
            $ledger->observeDiscoveredOnly('copilot', $expectedName);
        }

        $ledger->reconcile();

        $description = $ledger->describe();
        $this->assertNotEmpty($description);

        foreach ($expectedNames as $expectedName) {
            $this->assertStringContainsString(
                $expectedName,
                $description,
                "describe() must mention every expected command name, including '{$expectedName}'"
            );
        }
    }

    /**
     * @return array<string, string> relative path => md5
     */
    private function checksumFixtureFiles(SpecKitFixtureProject $fixture): array
    {
        $checksums = [];
        foreach ($fixture->agentCommandFiles as $relativePath) {
            $fullPath = rtrim($fixture->rootPath, '/').'/'.$relativePath;
            $checksums[$relativePath] = md5_file($fullPath);
        }

        return $checksums;
    }
}
