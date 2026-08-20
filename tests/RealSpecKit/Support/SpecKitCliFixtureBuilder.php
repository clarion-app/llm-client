<?php

namespace Tests\RealSpecKit\Support;

use ReflectionClass;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Produces a real, disposable SpecKitFixtureProject by actually shelling
 * out to the real `specify` CLI (data-model.md §4, research.md D1,
 * contracts/speckit-cli-invocation.md). Uses Symfony\Component\Process
 * (already a package dependency -- DockerCommandExecutor's own use of the
 * same package) exactly like every other real-subprocess call in this
 * package.
 *
 * Not a value object -- this is the one small piece of *behavior* this
 * feature adds (shelling out, scanning the result), kept entirely inside
 * tests/RealSpecKit/Support/ and never imported by anything under src/.
 */
final class SpecKitCliFixtureBuilder
{
    private const CLI_SOURCE = 'git+https://github.com/github/spec-kit.git';

    /**
     * Attempts the real D1 invocation against a throwaway temp path with a
     * short timeout, converting any Process-level failure (binary not
     * found, non-zero exit, timeout) into EnvironmentUnavailableException,
     * naming the specific unavailability -- mirroring
     * RealDatabaseTestCase::handleUnavailable()'s markTestSkipped($reason)
     * precedent (research.md D7, Grounding note 15). Every real-speckit-cli
     * test calls this first, before anything else.
     */
    public static function assertAvailable(): void
    {
        $probeDir = sys_get_temp_dir().'/speckit-cli-probe-'.uniqid('', true);

        $process = new Process([
            'uvx', '--from', self::CLI_SOURCE, 'specify', 'init', $probeDir,
            '--integration', 'copilot',
            '--integration-options=--commands',
            '--ignore-agent-tools',
        ]);
        $process->setTimeout(60);

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            self::cleanup($probeDir);

            throw new EnvironmentUnavailableException(
                'The real Spec-Kit CLI (`uvx --from '.self::CLI_SOURCE.' specify init`) timed out -- '
                .'this environment may lack network egress to fetch/build the CLI, or the invocation '
                ."is otherwise hung. Underlying error: {$e->getMessage()}"
            );
        } catch (Throwable $e) {
            self::cleanup($probeDir);

            throw new EnvironmentUnavailableException(
                'The real Spec-Kit CLI could not be run at all in this environment -- `uv`/`uvx` may '
                ."be missing from PATH. Underlying error: {$e->getMessage()}"
            );
        }

        if (!$process->isSuccessful()) {
            self::cleanup($probeDir);

            throw new EnvironmentUnavailableException(
                'The real Spec-Kit CLI ran but exited non-zero (exit code '.$process->getExitCode().') -- '
                .'this may indicate missing network egress to GitHub, a broken CLI build, or another '
                .'environment problem. stderr: '.trim($process->getErrorOutput())
            );
        }

        self::cleanup($probeDir);
    }

    /**
     * Runs `specify init` for real, non-interactively, always with
     * --ignore-agent-tools (research.md D1's load-bearing flag), against a
     * fresh sys_get_temp_dir() subdirectory this method creates itself.
     * Captures `specify --version`'s output verbatim (research.md D9 --
     * never hardcoded) and self-scans the produced directory to build
     * SpecKitFixtureProject::$agentCommandFiles -- never a literal array.
     */
    public function build(string $aiTarget, ?string $integrationOptions = null): SpecKitFixtureProject
    {
        $rootPath = sys_get_temp_dir().'/speckit-fixture-'.uniqid('', true);

        $command = [
            'uvx', '--from', self::CLI_SOURCE, 'specify', 'init', $rootPath,
            '--integration', $aiTarget,
        ];

        if ($integrationOptions !== null) {
            $command[] = "--integration-options={$integrationOptions}";
        }

        $command[] = '--ignore-agent-tools';

        $process = new Process($command);
        $process->setTimeout(300);
        $process->mustRun();

        $versionProcess = new Process(['uvx', '--from', self::CLI_SOURCE, 'specify', '--version']);
        $versionProcess->setTimeout(120);
        $versionProcess->mustRun();
        $cliVersionString = trim($versionProcess->getOutput());

        $agentCommandFiles = $this->scanAgentCommandFiles($rootPath, $aiTarget);

        return $this->instantiate($rootPath, $aiTarget, $integrationOptions, $cliVersionString, $agentCommandFiles);
    }

    /**
     * @return list<string>
     */
    private function scanAgentCommandFiles(string $rootPath, string $aiTarget): array
    {
        $matches = $aiTarget === 'claude'
            ? glob($rootPath.'/.claude/skills/*/SKILL.md')
            : glob($rootPath.'/.github/agents/*.agent.md');

        $matches = $matches ?: [];
        sort($matches);

        return array_values(array_map(
            fn (string $absolutePath): string => ltrim(substr($absolutePath, strlen($rootPath)), '/'),
            $matches
        ));
    }

    /**
     * SpecKitFixtureProject's constructor is private (data-model.md §1) --
     * the builder reaches it via reflection, the only mechanism PHP offers
     * a collaborating class (not a subclass) to invoke a private
     * constructor, mirroring SpecKitFixtureProjectTest's own reflection
     * idiom.
     */
    private function instantiate(
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

    private static function cleanup(string $dir): void
    {
        if (is_dir($dir)) {
            (new Process(['rm', '-rf', $dir]))->run();
        }
    }
}
