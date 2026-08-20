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
 * 129-speckit-verify-acceptance, Phase 8 (US6), T035 -- quickstart.md
 * Scenario 6 in full: running the discovery flow (CommandDiscoveryJourneyTest's
 * own Phase 3 flow, T015 steps 2-6) twice, back-to-back, each against its
 * own freshly-built, never-reused fixture, produces byte-identical
 * SpecKitOutcomeLedger::describe() output both times (AS1/FR-010/SC-004),
 * and neither run depends on the other's fixture still existing on disk
 * (AS2).
 *
 * Discovery-only, like US1 -- no scripted AgentLoopService invocation
 * needed.
 */
#[Group('real-speckit-cli')]
class RepeatableCleanRunJourneyTest extends TestCase
{
    private ?SpecKitFixtureProject $fixtureRun1 = null;

    private ?SpecKitFixtureProject $fixtureRun2 = null;

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
        // Defensive: earlier steps in the test method may already have
        // removed one or both directories as part of proving AS2.
        $this->removeFixtureDirectory($this->fixtureRun1);
        $this->removeFixtureDirectory($this->fixtureRun2);

        parent::tearDown();
    }

    #[Test]
    public function running_discovery_twice_against_independent_fixtures_yields_byte_identical_reports(): void
    {
        // --- Run 1: its own fresh CLI invocation, its own temp
        // subdirectory, its own CodingProject row, its own ledger.
        [$this->fixtureRun1, $ledgerRun1] = $this->runDiscoveryFlow();

        $rootPathRun1 = $this->fixtureRun1->rootPath;
        $descriptionRun1 = $ledgerRun1->describe();

        // Run 1's own assertions are complete and captured above (rootPath,
        // description) before run 2 starts -- run 2 never touches run 1's
        // fixture or database row.

        // --- Run 2: a second, entirely independent fresh CLI invocation.
        // Never reuses or caches run 1's directory or CodingProject row.
        [$this->fixtureRun2, $ledgerRun2] = $this->runDiscoveryFlow();

        $rootPathRun2 = $this->fixtureRun2->rootPath;
        $descriptionRun2 = $ledgerRun2->describe();

        // --- AS2: each run got its own, distinct sys_get_temp_dir()
        // subdirectory -- proves "own subdirectory," not an accidental
        // cache hit.
        $this->assertNotSame(
            $rootPathRun1,
            $rootPathRun2,
            'each discovery run must build its own fresh fixture directory, never reuse the prior run\'s'
        );

        // --- AS1/FR-010/SC-004: byte-identical report text across the two
        // independent runs.
        $this->assertSame(
            $descriptionRun1,
            $descriptionRun2,
            'SpecKitOutcomeLedger::describe() must be byte-identical across two independent, real discovery runs'
        );

        // --- AS2 continued: remove run 1's directory now, then re-assert
        // run 2's already-captured values are unaffected by run 1's
        // fixture no longer existing on disk.
        $this->removeFixtureDirectory($this->fixtureRun1);
        $this->assertDirectoryDoesNotExist(
            $rootPathRun1,
            'run 1\'s own cleanup must actually remove its directory'
        );

        // Re-derive run 2's description again -- it must still hold,
        // proving run 2's already-completed assertions never depended on
        // run 1's fixture still being present.
        $this->assertSame(
            $descriptionRun2,
            $ledgerRun2->describe(),
            'run 2\'s report must remain identical after run 1\'s directory has been deleted'
        );
        $this->assertSame(
            $descriptionRun1,
            $ledgerRun2->describe(),
            'run 2\'s report, re-read after run 1\'s cleanup, must still match run 1\'s originally captured report'
        );
        $this->assertDirectoryExists(
            $rootPathRun2,
            'run 2\'s own fixture directory must still exist -- deleting run 1\'s directory must not affect it'
        );
    }

    /**
     * Factored from CommandDiscoveryJourneyTest's Phase 3 flow (T015 steps
     * 2-6): a real `specify init` copilot/--commands run, a real persisted
     * CodingProject, real discovery through McpPromptRegistry/CommandPackLoader,
     * and a fully declared+observed, reconciled SpecKitOutcomeLedger. Called
     * twice by the test above, each call producing an entirely fresh,
     * never-shared fixture and ledger.
     *
     * @return array{0: SpecKitFixtureProject, 1: SpecKitOutcomeLedger}
     */
    private function runDiscoveryFlow(): array
    {
        // --- Step 2: a real `specify init` run, self-scanned agent
        // command files (research.md D9 -- never hardcoded).
        $fixture = (new SpecKitCliFixtureBuilder())->build('copilot', '--commands');

        $this->assertNotEmpty(
            $fixture->agentCommandFiles,
            'the real specify init run produced no .github/agents/*.agent.md files -- nothing to verify'
        );

        // --- Step 3: a real, persisted CodingProject pointed at the
        // fixture's real directory. Discovery-only, no confirmation
        // needed.
        $userId = (string) Str::uuid();
        $project = CodingProject::create([
            'user_id' => $userId,
            'name' => 'speckit repeatable clean run journey',
            'root_path' => $fixture->rootPath,
            'test_command' => null,
        ]);

        // --- Step 4 (AS1/FR-003): every expected command name appears in
        // the listing.
        $registry = new McpPromptRegistry();
        $listing = $registry->getPrompts(codingProjectId: $project->id, userId: $userId);

        $this->assertArrayHasKey('prompts', $listing);
        $listedNames = array_map(fn (array $p) => $p['name'], $listing['prompts']);

        $expectedNames = $fixture->expectedCommandNames();
        $this->assertNotEmpty($expectedNames);

        foreach ($expectedNames as $expectedName) {
            $this->assertContains(
                $expectedName,
                $listedNames,
                "expected command '{$expectedName}' (derived from a real specify init scan) to appear in getPrompts()'s listing"
            );
        }

        // --- Step 5: cross-check each expected command's declared source
        // path against the real file on disk, via the same
        // CommandPackLoader::discover() call the registry itself makes
        // internally.
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
            $realFilePath = rtrim($fixture->rootPath, '/').'/'.$template->relativePath;

            $this->assertFileExists(
                $realFilePath,
                "the declared source path '{$template->relativePath}' for '{$expectedName}' must name a real file on disk"
            );
            $this->assertContains(
                $template->relativePath,
                $fixture->agentCommandFiles,
                "the declared source path '{$template->relativePath}' must be exactly one of the real, self-scanned agentCommandFiles -- no renaming/reformatting"
            );
        }

        // --- Step 6: build the ledger -- every command is DiscoveredOnly,
        // this flow never invokes anything.
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

        return [$fixture, $ledger];
    }

    private function removeFixtureDirectory(?SpecKitFixtureProject $fixture): void
    {
        if ($fixture !== null && is_dir($fixture->rootPath)) {
            (new Process(['rm', '-rf', $fixture->rootPath]))->run();
        }
    }
}
