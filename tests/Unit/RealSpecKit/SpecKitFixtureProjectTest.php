<?php

namespace Tests\Unit\RealSpecKit;

use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionException;
use Tests\RealSpecKit\Support\SpecKitFixtureProject;
use Tests\TestCase;
use Throwable;

/**
 * SpecKitFixtureProject (data-model.md §1) has a private constructor — the
 * only public entry point is SpecKitCliFixtureBuilder::build() (T012),
 * which shells out to the real `specify` CLI and is out of scope for a
 * fast-suite unit test. This test reaches the constructor directly via
 * reflection, exactly to exercise its own guard rules (containment,
 * property fidelity) and expectedCommandNames()'s derivation, without any
 * real CLI/filesystem involvement.
 *
 * Written before SpecKitFixtureProject exists (T011, a separate task) —
 * every case here is expected to fail red right now with a "class not
 * found" fatal, not a genuine assertion failure.
 */
class SpecKitFixtureProjectTest extends TestCase
{
    /**
     * Constructs a SpecKitFixtureProject via its private constructor,
     * bypassing SpecKitCliFixtureBuilder entirely (data-model.md §1).
     */
    private function makeFixture(
        string $rootPath,
        string $aiTarget,
        ?string $integrationOptions,
        string $cliVersionString,
        array $agentCommandFiles,
    ): SpecKitFixtureProject {
        $reflection = new ReflectionClass(SpecKitFixtureProject::class);
        $constructor = $reflection->getConstructor();
        $constructor->setAccessible(true);

        /** @var SpecKitFixtureProject $instance */
        $instance = $reflection->newInstanceWithoutConstructor();
        $constructor->invoke($instance, $rootPath, $aiTarget, $integrationOptions, $cliVersionString, $agentCommandFiles);

        return $instance;
    }

    #[Test]
    public function a_root_path_genuinely_inside_the_system_temp_directory_is_accepted_and_every_property_reads_back_exactly_as_passed()
    {
        $rootPath = sys_get_temp_dir() . '/speckit-fixture-test-' . uniqid();
        $agentCommandFiles = ['.github/agents/speckit.plan.agent.md', '.github/agents/speckit.specify.agent.md'];

        $fixture = $this->makeFixture(
            $rootPath,
            'copilot',
            '--commands',
            'specify 0.16.4',
            $agentCommandFiles,
        );

        $this->assertSame($rootPath, $fixture->rootPath);
        $this->assertSame('copilot', $fixture->aiTarget);
        $this->assertSame('--commands', $fixture->integrationOptions);
        $this->assertSame('specify 0.16.4', $fixture->cliVersionString);
        $this->assertSame($agentCommandFiles, $fixture->agentCommandFiles);
    }

    #[Test]
    public function a_root_path_genuinely_inside_the_system_temp_directory_is_accepted_with_a_null_integration_options()
    {
        $rootPath = sys_get_temp_dir() . '/speckit-fixture-test-' . uniqid();

        $fixture = $this->makeFixture(
            $rootPath,
            'claude',
            null,
            'specify 0.16.4',
            ['.claude/skills/speckit-plan/SKILL.md'],
        );

        $this->assertNull($fixture->integrationOptions);
    }

    #[Test]
    public function a_root_path_equal_to_this_repositorys_own_working_tree_is_refused()
    {
        $packageRoot = dirname(__DIR__, 3);

        $this->assertContainmentGuardRejects($packageRoot);
    }

    #[Test]
    public function a_root_path_inside_this_repositorys_own_working_tree_is_refused()
    {
        $insideRepo = dirname(__DIR__, 3) . '/tests';

        $this->assertContainmentGuardRejects($insideRepo);
    }

    /**
     * Asserts the constructor rejects $rootPath with some Throwable. A
     * ReflectionException from makeFixture()'s own setup (class not found)
     * is deliberately NOT treated as a pass here — that would make this
     * case pass for the wrong reason (SpecKitFixtureProject doesn't exist
     * yet, T011) rather than because the containment guard actually fired.
     */
    private function assertContainmentGuardRejects(string $rootPath): void
    {
        try {
            $this->makeFixture($rootPath, 'copilot', null, 'specify 0.16.4', []);
        } catch (ReflectionException $e) {
            $this->fail(
                'SpecKitFixtureProject does not exist yet (T011) -- the containment guard '
                . "cannot be exercised. Underlying error: {$e->getMessage()}"
            );
        } catch (Throwable $e) {
            $this->assertNotInstanceOf(ReflectionException::class, $e);

            return;
        }

        $this->fail("Expected the constructor to refuse rootPath '{$rootPath}' (inside this repository's own working tree).");
    }

    #[Test]
    public function expected_command_names_derives_from_agent_command_files_for_the_copilot_agent_convention()
    {
        $fixture = $this->makeFixture(
            sys_get_temp_dir() . '/speckit-fixture-test-' . uniqid(),
            'copilot',
            '--commands',
            'specify 0.16.4',
            [
                '.github/agents/speckit.plan.agent.md',
                '.github/agents/speckit.specify.agent.md',
                '.github/agents/speckit.tasks.agent.md',
            ],
        );

        $this->assertSame(
            ['speckit.plan', 'speckit.specify', 'speckit.tasks'],
            $fixture->expectedCommandNames(),
        );
    }

    #[Test]
    public function expected_command_names_derives_from_agent_command_files_for_the_claude_skills_convention()
    {
        $fixture = $this->makeFixture(
            sys_get_temp_dir() . '/speckit-fixture-test-' . uniqid(),
            'claude',
            null,
            'specify 0.16.4',
            [
                '.claude/skills/speckit-plan/SKILL.md',
                '.claude/skills/speckit-specify/SKILL.md',
                '.claude/skills/speckit-tasks/SKILL.md',
            ],
        );

        $this->assertSame(
            ['speckit-plan', 'speckit-specify', 'speckit-tasks'],
            $fixture->expectedCommandNames(),
        );
    }

    /**
     * A small input/output table, never a hand-maintained list re-typed
     * elsewhere (research.md D9) — this table only fixes the derivation
     * *rule* (strip the convention's own file suffix), not a snapshot of
     * any particular CLI release's command set.
     *
     * @return list<array{0: string, 1: string, 2: string}> [aiTarget, relativePath, expectedName]
     */
    public static function derivationTable(): array
    {
        return [
            'copilot single-segment name' => ['copilot', '.github/agents/speckit.plan.agent.md', 'speckit.plan'],
            'copilot another single-segment name' => ['copilot', '.github/agents/speckit.constitution.agent.md', 'speckit.constitution'],
            'claude skill directory name' => ['claude', '.claude/skills/speckit-plan/SKILL.md', 'speckit-plan'],
            'claude another skill directory name' => ['claude', '.claude/skills/speckit-constitution/SKILL.md', 'speckit-constitution'],
        ];
    }

    #[Test]
    public function expected_command_names_matches_the_derivation_table_for_a_single_file_fixture()
    {
        foreach (self::derivationTable() as $description => [$aiTarget, $relativePath, $expectedName]) {
            $fixture = $this->makeFixture(
                sys_get_temp_dir() . '/speckit-fixture-test-' . uniqid(),
                $aiTarget,
                null,
                'specify 0.16.4',
                [$relativePath],
            );

            $this->assertSame(
                [$expectedName],
                $fixture->expectedCommandNames(),
                "Derivation mismatch for case: {$description}",
            );
        }
    }
}
