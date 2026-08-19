<?php

namespace ClarionApp\LlmClient\Services;

use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * 123-sandboxed-shell-execution, US1 (research.md D1a/D2/D3/D5,
 * data-model.md §3a). Runs one shell command inside a fresh, ephemeral,
 * `--rm`, uniquely-named Docker container scoped to exactly one workspace
 * root -- never a shared/persistent container across invocations (D3),
 * so concurrent commands can never interfere with each other's isolation
 * (FR-016).
 *
 * This story's flag set only: a single bind mount at the workspace root
 * (and never any other -v/--mount, in particular never the Docker
 * socket), --read-only + --tmpfs /tmp, --security-opt no-new-privileges,
 * --rm + a fresh --name per call. Resource limits (--memory/--cpus/
 * --pids-limit), the wall-clock timeout/kill sequence, output-cap/
 * truncation, and the --network flag are deliberately NOT built here --
 * Phases 5/6 append to this flag set without altering what this class
 * already constructs.
 *
 * The `docker` invocation itself is always wrapped in a Symfony Process,
 * mirroring CodingWorkspaceController::runTests()'s own
 * Process::fromShellCommandline() wrapping -- but behind a swappable,
 * injectable process-factory seam (a closure taking the command array and
 * returning a Process-shaped object) so a unit test can exercise every
 * branch of this class against a mocked process boundary, never a real
 * `docker` binary. Production code (the null-factory default) always
 * constructs a genuine Symfony Process.
 *
 * A cheap reachability precheck (`docker version`) runs before any
 * `docker run` is ever attempted; a precheck failure short-circuits with
 * `status: sandbox_unavailable` and a specific, named reason (FR-015),
 * never an opaque process-exec error. This is the same three-way-plus-one
 * status vocabulary runTests() already established
 * (completed/no_tests_configured/could_not_run), extended for this
 * feature: completed / stopped_timeout / sandbox_unavailable / refused
 * (data-model.md §3a) -- runCommand() itself, not this class, is
 * responsible for the `refused` state, since that state is reached
 * before this class is ever invoked at all.
 */
class DockerCommandExecutor
{
    private const CONTAINER_WORKSPACE_PATH = '/workspace';

    /**
     * @param  ?\Closure(array<int, string>): Process  $processFactory  Injected
     *   seam for tests -- receives the full `docker ...` command array and
     *   must return a Process-shaped object (run()/getExitCode()/
     *   getOutput()/getErrorOutput()). Null in production, where a genuine
     *   Symfony Process is always constructed.
     * @param  ?array<string, string>  $env  Extra environment variables
     *   merged on top of the inherited process environment for every
     *   Process this class constructs (e.g. an overridden DOCKER_HOST) --
     *   the seam tests/RealDocker/DockerUnavailableFallbackTest.php uses
     *   to point at a genuinely invalid Docker socket without mutating the
     *   whole test run's global environment.
     */
    public function __construct(
        private readonly ?\Closure $processFactory = null,
        private readonly ?array $env = null,
    ) {
    }

    /**
     * @return array{
     *     status: string,
     *     exit_code: ?int,
     *     stdout: ?string,
     *     stderr: ?string,
     *     duration_ms: ?int,
     *     reason?: string,
     * }
     */
    public function run(string $rootPath, string $command): array
    {
        $reachability = $this->checkReachable();
        if (!$reachability['reachable']) {
            return [
                'status' => 'sandbox_unavailable',
                'reason' => $reachability['reason'],
                'exit_code' => null,
                'stdout' => null,
                'stderr' => null,
                'duration_ms' => null,
            ];
        }

        $containerName = 'coding-cmd-'.(string) Str::uuid();
        $image = (string) config('llm-client.coding_agent.command_image', 'alpine:latest');

        $dockerRunCommand = [
            'docker', 'run',
            '--rm',
            '--name', $containerName,
            '-v', $rootPath.':'.self::CONTAINER_WORKSPACE_PATH.':rw',
            '--read-only',
            '--tmpfs', '/tmp',
            '--security-opt', 'no-new-privileges',
            '--workdir', self::CONTAINER_WORKSPACE_PATH,
            $image,
            'sh', '-c', $command,
        ];

        $process = $this->makeProcess($dockerRunCommand);

        $startedAt = microtime(true);

        try {
            $process->run();
        } catch (\Throwable $e) {
            // The precheck above already confirmed reachability -- a
            // Throwable here means the docker run invocation itself could
            // not be started (e.g. a transient failure between the
            // precheck and this call). Never a fifth, ambiguous state
            // (data-model.md §3a): folded into sandbox_unavailable, the
            // same status a precheck failure produces.
            return [
                'status' => 'sandbox_unavailable',
                'reason' => 'Docker is not reachable on this host: '.$e->getMessage(),
                'exit_code' => null,
                'stdout' => null,
                'stderr' => null,
                'duration_ms' => null,
            ];
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        return [
            'status' => 'completed',
            'exit_code' => $process->getExitCode(),
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
            'duration_ms' => $durationMs,
        ];
    }

    /**
     * @return array{reachable: bool, reason: ?string}
     */
    private function checkReachable(): array
    {
        $process = $this->makeProcess(['docker', 'version', '--format', '{{.Server.Version}}']);

        try {
            $process->run();
        } catch (\Throwable $e) {
            return ['reachable' => false, 'reason' => 'Docker is not reachable on this host: '.$e->getMessage()];
        }

        if ($process->getExitCode() !== 0) {
            $reason = 'Docker is not reachable on this host';
            $errorOutput = trim((string) $process->getErrorOutput());
            if ($errorOutput !== '') {
                $reason .= ': '.$errorOutput;
            }

            return ['reachable' => false, 'reason' => $reason];
        }

        return ['reachable' => true, 'reason' => null];
    }

    /**
     * @param  array<int, string>  $command
     */
    private function makeProcess(array $command): Process
    {
        if ($this->processFactory !== null) {
            return ($this->processFactory)($command);
        }

        return new Process($command, null, $this->env);
    }
}
