<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Events\CommandExecutionProgress;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * 123-sandboxed-shell-execution, US1/US3 (research.md D1a/D2/D3/D4/D5,
 * data-model.md §3a/§4). Runs one shell command inside a fresh, ephemeral,
 * `--rm`, uniquely-named Docker container scoped to exactly one workspace
 * root -- never a shared/persistent container across invocations (D3),
 * so concurrent commands can never interfere with each other's isolation
 * (FR-016).
 *
 * Flag set: a single bind mount at the workspace root (and never any
 * other -v/--mount, in particular never the Docker socket),
 * --read-only + --tmpfs /tmp, --security-opt no-new-privileges,
 * --rm + a fresh --name per call, plus (US3, FR-009) --memory and
 * --memory-swap set to the SAME configured value (never a larger swap
 * ceiling), --cpus, and --pids-limit, plus (US4, FR-011/FR-012,
 * research.md D7) --network none by default / --network bridge only when
 * the caller's networkEnabled argument is true.
 *
 * The `docker run` invocation is always wrapped in a Symfony Process
 * (mirroring CodingWorkspaceController::runTests()'s own
 * Process::fromShellCommandline() wrapping) behind a swappable,
 * injectable process-factory seam (a closure taking the command array and
 * returning a Process-shaped object) so a unit test can exercise every
 * branch of this class against a mocked process boundary, never a real
 * `docker` binary. Production code (the null-factory default) always
 * constructs a genuine Symfony Process.
 *
 * A cheap reachability precheck (`docker version`) runs before any
 * `docker run` is ever attempted; a precheck failure short-circuits with
 * `status: sandbox_unavailable` and a specific, named reason (FR-015).
 * This is the same three-way-plus-one status vocabulary runTests()
 * already established (completed/no_tests_configured/could_not_run),
 * extended for this feature: completed / stopped_timeout /
 * sandbox_unavailable / refused (data-model.md §3a) -- runCommand()
 * itself, not this class, is responsible for the `refused` state, since
 * that state is reached before this class is ever invoked at all.
 *
 * US3 (research.md D4): the constructed Process is given setTimeout()
 * from the configured wall-clock limit; the command is run via an
 * explicit poll loop (start() + isRunning()/checkTimeout(), rather than
 * the blocking run()) so this class controls both the incremental,
 * bounded-buffer output capture (mirroring ContentSanitizer's
 * cap-and-mark-truncated shape) and the "still running" heartbeat
 * broadcast (CommandExecutionProgress, FR-013) on the same cadence. On a
 * ProcessTimedOutException, the handler does not rely on Symfony
 * Process's own signal-forwarding alone: because the container was
 * started with a deterministic --name, an explicit `docker kill <name>`
 * is issued, followed by a `docker rm -f <name>` fallback, before
 * returning status: stopped_timeout/timed_out: true with whatever output
 * was captured up to that point (FR-008 -- never discarded).
 */
class DockerCommandExecutor
{
    private const CONTAINER_WORKSPACE_PATH = '/workspace';

    /** Real-clock interval between isRunning() polls, in microseconds. */
    private const POLL_INTERVAL_MICROSECONDS = 50_000;

    /**
     * @param  ?\Closure(array<int, string>): Process  $processFactory  Injected
     *   seam for tests -- receives the full `docker ...` command array and
     *   must return a Process-shaped object (start()/isRunning()/
     *   checkTimeout()/getIncrementalOutput()/getIncrementalErrorOutput()/
     *   getExitCode()/run()/getErrorOutput()). Null in production, where a
     *   genuine Symfony Process is always constructed.
     * @param  ?array<string, string>  $env  Extra environment variables
     *   merged on top of the inherited process environment for every
     *   Process this class constructs (e.g. an overridden DOCKER_HOST) --
     *   the seam tests/RealDocker/DockerUnavailableFallbackTest.php uses
     *   to point at a genuinely invalid Docker socket without mutating the
     *   whole test run's global environment.
     *
     * US4 (research.md D7): the run() method itself takes the workspace's
     * network_enabled boolean as a plain argument -- this class has no
     * knowledge of CodingProject at all, only the boolean the caller
     * (CodingWorkspaceController::runCommand()) hands it, read exactly
     * once from the column immediately before this flag set is built.
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
     *     timed_out?: bool,
     *     stdout: ?string,
     *     stderr: ?string,
     *     output_truncated?: bool,
     *     duration_ms: ?int,
     *     reason?: string,
     * }
     */
    /**
     * 124-command-limit-controls, US1 (contracts/resource-limits.md §2):
     * the six trailing parameters below are the resolved, effective
     * per-workspace limits (ResourceLimitResolver::resolve()) -- passed
     * explicitly by the caller rather than read from config() inside this
     * method. Each defaults to null, falling back to the same config()
     * default this method always used, so existing callers that do not
     * yet resolve a workspace's overrides (in particular this class's own
     * unit tests) are unaffected; a caller that DOES pass an explicit
     * value always wins over the config default. $diskLimitMb is unused
     * by this method's own logic today -- disk-limit enforcement is wired
     * in a later phase; the signature only changes once for this whole
     * feature.
     */
    public function run(
        string $rootPath,
        string $command,
        ?string $codingProjectId = null,
        ?string $userId = null,
        bool $networkEnabled = false,
        ?int $timeLimitSeconds = null,
        ?int $memoryLimitMb = null,
        ?string $cpuLimit = null,
        ?int $pidsLimit = null,
        ?int $outputCapBytes = null,
        ?int $diskLimitMb = null,
    ): array {
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
        $resolvedMemoryLimitMb = $memoryLimitMb ?? (int) config('llm-client.coding_agent.command_memory_limit_mb', 256);
        $memoryLimit = $resolvedMemoryLimitMb.'m';
        $resolvedCpuLimit = $cpuLimit ?? (string) config('llm-client.coding_agent.command_cpu_limit', '1.0');
        $resolvedPidsLimit = $pidsLimit ?? (int) config('llm-client.coding_agent.command_pids_limit', 128);
        $timeoutSeconds = $timeLimitSeconds ?? (int) config('llm-client.coding_agent.command_timeout_seconds', 60);
        $resolvedOutputCapBytes = $outputCapBytes ?? (int) config('llm-client.coding_agent.command_output_cap_bytes', 262144);
        $broadcastAfterSeconds = max(0, (int) config('llm-client.coding_agent.command_progress_broadcast_after_seconds', 5));

        $dockerRunCommand = [
            'docker', 'run',
            '--rm',
            '--name', $containerName,
            '-v', $rootPath.':'.self::CONTAINER_WORKSPACE_PATH.':rw',
            '--read-only',
            '--tmpfs', '/tmp',
            '--security-opt', 'no-new-privileges',
            // US4, FR-011/FR-012/research.md D7 -- 'none' by default, and
            // only 'bridge' (Docker's own standard default network) when
            // the workspace's own network_enabled column is true. This
            // column is never consulted anywhere in the confirmation/
            // allowlist stack (ApiCallValidator,
            // AgentLoopService::isConfirmationRequired(),
            // CommandAllowlistMatcher) -- it is a resource-isolation
            // boundary, completely independent of the confirmation
            // decision.
            '--network', $networkEnabled ? 'bridge' : 'none',
            // US3, FR-009/research.md D1a -- --memory-swap is deliberately
            // set to the SAME value as --memory: without this, Docker
            // silently allows the container to consume up to 2x the
            // stated memory cap via swap.
            '--memory', $memoryLimit,
            '--memory-swap', $memoryLimit,
            '--cpus', $resolvedCpuLimit,
            '--pids-limit', (string) $resolvedPidsLimit,
            '--workdir', self::CONTAINER_WORKSPACE_PATH,
            $image,
            'sh', '-c', $command,
        ];

        $process = $this->makeProcess($dockerRunCommand);
        $process->setTimeout($timeoutSeconds > 0 ? $timeoutSeconds : null);

        $startedAt = microtime(true);
        $stdout = '';
        $stderr = '';
        $truncated = false;
        $nextBroadcastAtSeconds = $broadcastAfterSeconds;

        try {
            $process->start();

            while ($process->isRunning()) {
                $process->checkTimeout();

                $this->appendCapped($stdout, $truncated, (string) $process->getIncrementalOutput(), $resolvedOutputCapBytes);
                $this->appendCapped($stderr, $truncated, (string) $process->getIncrementalErrorOutput(), $resolvedOutputCapBytes);

                $elapsedSeconds = (int) floor(microtime(true) - $startedAt);
                if ($elapsedSeconds >= $nextBroadcastAtSeconds) {
                    $this->broadcastProgress($codingProjectId, $userId, $elapsedSeconds);
                    $nextBroadcastAtSeconds = $elapsedSeconds + max(1, $broadcastAfterSeconds);
                }

                usleep(self::POLL_INTERVAL_MICROSECONDS);
            }

            // The process ended on its own -- capture whatever incremental
            // output arrived between the last loop check and process exit.
            $this->appendCapped($stdout, $truncated, (string) $process->getIncrementalOutput(), $resolvedOutputCapBytes);
            $this->appendCapped($stderr, $truncated, (string) $process->getIncrementalErrorOutput(), $resolvedOutputCapBytes);
        } catch (ProcessTimedOutException $e) {
            $this->killContainer($containerName);

            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            return [
                'status' => 'stopped_timeout',
                'timed_out' => true,
                'exit_code' => null,
                'stdout' => $stdout,
                'stderr' => $stderr,
                'output_truncated' => $truncated,
                'duration_ms' => $durationMs,
            ];
        } catch (\Throwable $e) {
            // The reachability precheck above already confirmed Docker was
            // reachable -- a Throwable here means the docker run
            // invocation itself could not be carried through (e.g. a
            // transient failure between the precheck and this call).
            // Never a fifth, ambiguous state (data-model.md §3a): folded
            // into sandbox_unavailable, the same status a precheck
            // failure produces.
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
            'timed_out' => false,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'output_truncated' => $truncated,
            'duration_ms' => $durationMs,
        ];
    }

    /**
     * Appends $chunk onto $buffer, capping the buffer at $capBytes total
     * and marking $truncated once the cap is reached -- mirroring
     * ContentSanitizer's cap-and-mark-truncated shape. Once truncated, no
     * further bytes are ever appended, but whatever was captured before
     * the cap (or before a subsequent timeout-kill) is preserved, never
     * discarded (FR-008).
     */
    private function appendCapped(string &$buffer, bool &$truncated, string $chunk, int $capBytes): void
    {
        if ($truncated || $chunk === '') {
            return;
        }

        $remaining = $capBytes - strlen($buffer);
        if ($remaining <= 0) {
            $truncated = true;

            return;
        }

        if (strlen($chunk) > $remaining) {
            $buffer .= substr($chunk, 0, $remaining);
            $truncated = true;

            return;
        }

        $buffer .= $chunk;
    }

    /**
     * FR-013 "still running" heartbeat -- wrapped in a try/catch-and-
     * log-only isolation pattern (mirroring RunTraceRecorder::broadcast())
     * so a broadcast failure can never affect the command's own in-flight
     * execution or eventual result. A null codingProjectId/userId (the
     * caller declined to identify the acting user/workspace) silently
     * skips broadcasting rather than firing an unaddressable event.
     */
    private function broadcastProgress(?string $codingProjectId, ?string $userId, int $elapsedSeconds): void
    {
        if ($codingProjectId === null || $userId === null) {
            return;
        }

        try {
            event(new CommandExecutionProgress($codingProjectId, $userId, $elapsedSeconds));
        } catch (\Throwable $e) {
            Log::warning('DockerCommandExecutor: progress broadcast failed', [
                'coding_project_id' => $codingProjectId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * research.md D4: killing the client Process alone is not guaranteed
     * to stop the container it started -- an explicit `docker kill` is
     * issued first, followed unconditionally by a `docker rm -f` as a
     * belt-and-suspenders fallback (a killed `docker run` client process
     * can otherwise leave its --rm cleanup never performed, since that
     * cleanup happens client-side on ordinary exit). Both are best-effort:
     * a failure here is logged, never allowed to mask the timeout result
     * already being returned to the caller.
     */
    private function killContainer(string $containerName): void
    {
        try {
            $this->makeProcess(['docker', 'kill', $containerName])->run();
        } catch (\Throwable $e) {
            Log::warning('DockerCommandExecutor: docker kill failed', [
                'container' => $containerName,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $this->makeProcess(['docker', 'rm', '-f', $containerName])->run();
        } catch (\Throwable $e) {
            Log::warning('DockerCommandExecutor: docker rm -f failed', [
                'container' => $containerName,
                'error' => $e->getMessage(),
            ]);
        }
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
