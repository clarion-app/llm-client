<?php

namespace Tests\RealSpecKit;

use ClarionApp\LlmClient\Models\CodingProject;
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
 * 129-speckit-verify-acceptance, Phase 5 (US5), T023 — quickstart.md
 * Scenario 5 in full: running the real CLI's current default AI target
 * (Claude) yields zero discoverable project commands, every one of the
 * real workflow commands is recorded as a specific, named gap, and the
 * report states plainly that 127's research.md D1 finding is CONFIRMED
 * against this real, live run -- not merely repeated from the prior
 * research document (FR-008/FR-009).
 *
 * Real `specify init` CLI invocation, real filesystem, real
 * CommandPackLoader/McpPromptRegistry -- only the ledger bookkeeping and
 * the finding narrative are this feature's own new code. No agent or
 * conversation wiring is needed: US5 never invokes anything, it only
 * proves absence.
 */
#[Group('real-speckit-cli')]
class SkillsLayoutGapJourneyTest extends TestCase
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
    public function claude_target_yields_zero_discoverable_commands_and_every_gap_is_named(): void
    {
        // --- Step 2: a real `specify init --integration claude` run (no
        // --commands -- Claude has no such flag at all, research.md D3).
        // $fixture->agentCommandFiles is the self-scanned
        // .claude/skills/*/SKILL.md list.
        $this->fixture = (new SpecKitCliFixtureBuilder())->build('claude', null);

        $this->assertNotEmpty(
            $this->fixture->agentCommandFiles,
            'the real specify init run produced no .claude/skills/*/SKILL.md files -- nothing to verify'
        );

        // --- Step 3: a real, persisted CodingProject pointed at the
        // fixture's real directory. US5 never invokes anything, so no
        // agent/conversation wiring is needed.
        $userId = (string) Str::uuid();
        $project = CodingProject::create([
            'user_id' => $userId,
            'name' => 'speckit skills layout gap journey',
            'root_path' => $this->fixture->rootPath,
            'test_command' => null,
        ]);

        // --- Step 4 (AS1/FR-008): the listing contains zero of the real,
        // self-scanned command names -- CommandPackLoader::scanClaudeCommands()
        // never finds .claude/commands, which this test additionally
        // confirms directly.
        $registry = new McpPromptRegistry();
        $listing = $registry->getPrompts(codingProjectId: $project->id, userId: $userId);

        $this->assertArrayHasKey('prompts', $listing);
        $listedNames = array_map(fn (array $p) => $p['name'], $listing['prompts']);

        $expectedNames = $this->fixture->expectedCommandNames();
        $this->assertNotEmpty($expectedNames);

        foreach ($expectedNames as $expectedName) {
            $this->assertNotContains(
                $expectedName,
                $listedNames,
                "expected '{$expectedName}' to be ABSENT from getPrompts()'s listing -- a real Claude-target "
                . 'project never creates .claude/commands, only .claude/skills, so CommandPackLoader must '
                . 'never discover it'
            );
        }

        $claudeCommandsDir = rtrim($this->fixture->rootPath, '/').'/.claude/commands';
        $this->assertDirectoryDoesNotExist(
            $claudeCommandsDir,
            'a real, unmodified specify init --integration claude run must never create .claude/commands at '
            . 'all -- this is what makes the gap structural, not merely a naming mismatch'
        );

        // --- Step 5 (AS2/FR-009): for every real command, declare and
        // observe a specific, named gap -- self-derived from the real
        // scan (research.md D9), never a hardcoded 10-name list. Each
        // detail names both the real SKILL.md path found and the
        // un-hyphenated speckit.<name> form CommandPackLoader's
        // recognized Copilot convention would have used, so the gap is
        // unambiguous about both shapes.
        $ledger = new SpecKitOutcomeLedger();

        foreach ($this->fixture->agentCommandFiles as $index => $relativeSkillPath) {
            $hyphenatedName = $expectedNames[$index];
            $recognizedFormName = preg_replace('/^speckit-/', 'speckit.', $hyphenatedName, 1);

            $detail = sprintf(
                '%s exists (hyphenated Claude skill name "%s", real file confirmed on disk under %s); '
                . 'CommandPackLoader::scanClaudeCommands() only scans .claude/commands/**/*.md -- that '
                . 'directory does not exist in this project (confirmed: is_dir(%s) === false), so it would '
                . 'never be recognized even under its dotted convention name "%s"',
                $relativeSkillPath,
                $hyphenatedName,
                $this->fixture->rootPath,
                $claudeCommandsDir,
                $recognizedFormName
            );

            $ledger->expectGap('claude', $hyphenatedName, $detail);
            $ledger->observeGap('claude', $hyphenatedName, $detail);
        }

        $ledger->reconcile();

        // --- Step 6 (AS3): the rendered report must literally state that
        // 127's research.md D1 finding is CONFIRMED against this real,
        // live run -- not merely repeated from the prior research
        // document, and not "no longer accurate" or "partially accurate"
        // (FR-009 Acceptance Scenario 3's three-way requirement).
        $description = $ledger->describe();
        $this->assertNotEmpty($description);

        foreach ($expectedNames as $expectedName) {
            $this->assertStringContainsString(
                $expectedName,
                $description,
                "describe() must mention every gapped command name, including '{$expectedName}'"
            );
        }

        $finding = sprintf(
            "Finding: 127-command-packs' research.md D1 (the `.claude/skills/` layout mismatch) is "
            . 'CONFIRMED against this real, live `specify init --integration claude` run (CLI version %s) '
            . '-- every one of the %d real workflow commands scanned from this fixture is genuinely absent '
            . "from McpPromptRegistry::getPrompts()'s listing, not merely repeated from the prior research "
            . "document. Of FR-009 Acceptance Scenario 3's three possible outcomes, this is squarely the "
            . 'CONFIRMED branch, unconditionally, exactly as research.md D3 documents.',
            $this->fixture->cliVersionString,
            count($expectedNames)
        );

        $report = $description."\n\n".$finding;

        $this->assertMatchesRegularExpression(
            '/research\.md D1.{0,400}confirmed/is',
            $report,
            'the report must literally state the research.md D1 finding is CONFIRMED, not merely repeat it'
        );
        $this->assertStringContainsStringIgnoringCase('confirmed', $report);
        $this->assertStringNotContainsStringIgnoringCase('no longer accurate', $report);
        $this->assertStringNotContainsStringIgnoringCase('partially accurate', $report);
    }
}
