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
 * uniquely-named Docker container scoped to exactly one workspace root --
 * never a shared/persistent container across invocations (D3), so
 * concurrent commands can never interfere with each other's isolation
 * (FR-016).
 *
 * Flag set: a single bind mount at the workspace root (and never any
 * other -v/--mount, in particular never the Docker socket),
 * --read-only + --tmpfs /tmp, --security-opt no-new-privileges, a fresh
 * --name per call, plus (US3, FR-009) --memory and --memory-swap set to
 * the SAME configured value (never a larger swap ceiling), --cpus, and
 * --pids-limit, plus (US4, FR-011/FR-012, research.md D7) --network none
 * by default / --network bridge only when the caller's networkEnabled
 * argument is true.
 *
 * 124-command-limit-controls, US3 (research.md R3a): `--rm` is
 * deliberately NOT used -- a direct test confirmed a `--rm` container is
 * already gone ("no such object") by the time `docker inspect` could ever
 * run afterward, which is required to detect an OOM kill. Every exit path
 * (ordinary completion, timeout, disk-limit, pids-limit, OOM alike)
 * therefore issues its own explicit, unconditional `docker rm -f` instead.
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
     * @param  ?\Closure(string): ?int  $pidsCurrentReader  124-command-
     *   limit-controls, US3 (research.md R3b): injected seam mirroring
     *   $processFactory's own shape, receiving the container's full
     *   64-char id and returning the parsed `pids.current` value (or null
     *   when unreadable). This read is deliberately a plain file read,
     *   never a subprocess, so it does NOT flow through
     *   makeProcess()/$processFactory -- it needs its own seam for tests
     *   to simulate a pids-limit breach without a real cgroup filesystem.
     *   Null in production, where a real is_readable()/file_get_contents()
     *   read is always performed, probing the systemd-driver cgroup path
     *   first and falling back to the plain-cgroupfs path.
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
        private readonly ?\Closure $pidsCurrentReader = null,
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
        ?string $stdin = null,
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
        $resolvedDiskLimitMb = $diskLimitMb ?? (int) config('llm-client.coding_agent.command_disk_limit_mb', 512);
        $broadcastAfterSeconds = max(0, (int) config('llm-client.coding_agent.command_progress_broadcast_after_seconds', 5));

        $dockerRunCommand = [
            'docker', 'run',
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
            ...($stdin !== null ? ['-i', '--interactive'] : []),
            '--workdir', self::CONTAINER_WORKSPACE_PATH,
            $image,
            'sh', '-c', $command,
        ];

        // 124-command-limit-controls, US2 (research.md R2): measured
        // BEFORE `docker run` starts, over the bind-mounted workspace root
        // only -- this is the baseline every later delta is measured
        // against, which is exactly what excludes space already used by
        // files that existed in the workspace beforehand (spec.md Edge
        // Case). A null baseline (du unreachable/unparseable) disables the
        // disk-limit check for this whole invocation -- a best-effort
        // mechanism, never allowed to turn an unrelated measurement
        // failure into a false stop.
        $diskUsageBaselineBytes = $this->measureDiskUsageBytes($rootPath);

        $process = $this->makeProcess($dockerRunCommand);
        $process->setTimeout($timeoutSeconds > 0 ? $timeoutSeconds : null);
        if ($stdin !== null) {
            $process->setInput($stdin);
        }

        $startedAt = microtime(true);
        $stdout = '';
        $stderr = '';
        $truncated = false;
        $nextBroadcastAtSeconds = $broadcastAfterSeconds;

        // 124-command-limit-controls, US3 (research.md R3b): the
        // container's full 64-char id is needed for the live pids.current
        // cgroup poll below -- resolved once, immediately after start(),
        // never re-resolved on every tick. A null id (resolution failed)
        // disables the pids-limit check for this whole invocation, the
        // same best-effort discipline the disk check's null baseline
        // already follows.
        $containerId = null;

        try {
            $process->start();

            $containerId = $this->resolveContainerId($containerName);

            while ($process->isRunning()) {
                $process->checkTimeout();

                $this->appendCapped($stdout, $truncated, (string) $process->getIncrementalOutput(), $resolvedOutputCapBytes);
                $this->appendCapped($stderr, $truncated, (string) $process->getIncrementalErrorOutput(), $resolvedOutputCapBytes);

                $elapsedSeconds = (int) floor(microtime(true) - $startedAt);
                if ($elapsedSeconds >= $nextBroadcastAtSeconds) {
                    $this->broadcastProgress($codingProjectId, $userId, $elapsedSeconds);
                    $nextBroadcastAtSeconds = $elapsedSeconds + max(1, $broadcastAfterSeconds);

                    // US2 (research.md R2/R3c): re-measured on this SAME
                    // coarser-cadence accumulator the progress-heartbeat
                    // broadcast already uses -- never on every 50ms poll
                    // tick, since a `du` call over a large tree is too
                    // costly to run that often. Strictly AFTER
                    // checkTimeout() above: a ProcessTimedOutException
                    // thrown by checkTimeout() unwinds out of this loop
                    // body entirely, so this block is never reached on a
                    // tick where the timeout has already fired.
                    if ($diskUsageBaselineBytes !== null) {
                        $currentDiskUsageBytes = $this->measureDiskUsageBytes($rootPath);

                        if ($currentDiskUsageBytes !== null) {
                            $deltaBytes = $currentDiskUsageBytes - $diskUsageBaselineBytes;
                            $diskLimitBytes = $resolvedDiskLimitMb * 1024 * 1024;

                            if ($deltaBytes > $diskLimitBytes) {
                                // FR-009/Edge Case: one final capture of
                                // whatever output arrived since the last
                                // loop check, mirroring the ordinary-exit
                                // path's own trailing capture step --
                                // output already produced is never
                                // discarded.
                                $this->appendCapped($stdout, $truncated, (string) $process->getIncrementalOutput(), $resolvedOutputCapBytes);
                                $this->appendCapped($stderr, $truncated, (string) $process->getIncrementalErrorOutput(), $resolvedOutputCapBytes);

                                $this->killContainer($containerName);

                                $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

                                return [
                                    'status' => 'stopped_disk_limit',
                                    'exit_code' => null,
                                    'timed_out' => false,
                                    'stdout' => $stdout,
                                    'stderr' => $stderr,
                                    'output_truncated' => $truncated,
                                    'duration_ms' => $durationMs,
                                ];
                            }
                        }
                    }

                    // US3 (research.md R3b/R3c): checked STRICTLY AFTER
                    // the disk check above -- on a tick where both a disk
                    // breach and a pids breach are present, disk has
                    // already returned by this point, so this block is
                    // never reached at all (the full timeout-then-disk-
                    // then-pids order). A null container id (resolution
                    // failed) disables this check for the whole
                    // invocation, same best-effort discipline as the disk
                    // check's null baseline.
                    if ($containerId !== null) {
                        $pidsCurrent = $this->readPidsCurrent($containerId);

                        if ($pidsCurrent !== null && $pidsCurrent >= $resolvedPidsLimit) {
                            // FR-009/Edge Case: one final capture, same
                            // pattern as the disk-limit kill above.
                            $this->appendCapped($stdout, $truncated, (string) $process->getIncrementalOutput(), $resolvedOutputCapBytes);
                            $this->appendCapped($stderr, $truncated, (string) $process->getIncrementalErrorOutput(), $resolvedOutputCapBytes);

                            $this->killContainer($containerName);

                            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

                            return [
                                'status' => 'stopped_pids_limit',
                                'exit_code' => null,
                                'timed_out' => false,
                                'stdout' => $stdout,
                                'stderr' => $stderr,
                                'output_truncated' => $truncated,
                                'duration_ms' => $durationMs,
                            ];
                        }
                    }
                }

                usleep(self::POLL_INTERVAL_MICROSECONDS);
            }

            // The process ended on its own -- capture whatever incremental
            // output arrived between the last loop check and process exit.
            $this->appendCapped($stdout, $truncated, (string) $process->getIncrementalOutput(), $resolvedOutputCapBytes);
            $this->appendCapped($stderr, $truncated, (string) $process->getIncrementalErrorOutput(), $resolvedOutputCapBytes);

            // 124-command-limit-controls, US3 (research.md R3a): this is
            // the "process ended on its own -- none of this executor's own
            // proactive kills fired" path. --rm is no longer used at all,
            // so `docker inspect` is consulted FIRST, before the container
            // is ever removed, to distinguish a genuine kernel OOM kill
            // from an ordinary exit; the explicit `docker rm -f` then runs
            // unconditionally regardless of which of the two this turns
            // out to be.
            $oomInspection = $this->inspectOomAndExitCode($containerName);
            $this->removeContainer($containerName);

            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            if ($oomInspection !== null && $oomInspection['oom_killed']) {
                return [
                    'status' => 'stopped_oom',
                    // The one limit-stop status that keeps the container's
                    // real, kernel-reported exit code -- Docker's OOM
                    // killer produces a genuine, diagnostically useful
                    // signal-derived exit code, unlike the proactive kills
                    // above, which have no exit code of their own to
                    // report.
                    'exit_code' => $oomInspection['exit_code'],
                    'timed_out' => false,
                    'stdout' => $stdout,
                    'stderr' => $stderr,
                    'output_truncated' => $truncated,
                    'duration_ms' => $durationMs,
                ];
            }

            return [
                'status' => 'completed',
                'exit_code' => $process->getExitCode(),
                'timed_out' => false,
                'stdout' => $stdout,
                'stderr' => $stderr,
                'output_truncated' => $truncated,
                'duration_ms' => $durationMs,
            ];
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
            //
            // Now that --rm is no longer used at all, this path is the
            // one place left with no proactive-kill or ordinary-exit
            // cleanup already covering it -- an unexpected throwable from
            // anywhere in the poll loop (after the container has already
            // been started) would otherwise leave it running with nothing
            // left to ever remove it. killContainer() is safe to call
            // unconditionally here even when the container never actually
            // started (the underlying docker kill/rm -f simply fails and
            // logs a warning, never surfaced to the caller).
            $this->killContainer($containerName);
            return [
                'status' => 'sandbox_unavailable',
                'reason' => 'Docker is not reachable on this host: '.$e->getMessage(),
                'exit_code' => null,
                'stdout' => null,
                'stderr' => null,
                'duration_ms' => null,
            ];
        }
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
     * research.md D4/124-command-limit-controls R3a: killing the client
     * Process alone is not guaranteed to stop the container it started --
     * an explicit `docker kill` is issued first, followed unconditionally
     * by a `docker rm -f` (since `--rm` is no longer used at all, this is
     * the container's ONLY cleanup on every one of this executor's own
     * proactive-kill paths: timeout, disk-limit, pids-limit). Both are
     * best-effort: a failure here is logged, never allowed to mask the
     * result already being returned to the caller.
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

        $this->removeContainer($containerName);
    }

    /**
     * 124-command-limit-controls, US3 (research.md R3a): the explicit
     * `docker rm -f` every exit path now issues in place of `--rm`'s
     * implicit (and, per research.md's own direct test, race-losing)
     * cleanup. Best-effort: a failure here is logged, never allowed to
     * mask the result already being returned to the caller.
     */
    private function removeContainer(string $containerName): void
    {
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
     * 124-command-limit-controls, US2 (research.md R2): a plain `du -sb
     * <path>` shell-out over the bind-mounted workspace root only -- never
     * the container's own filesystem, which is `--read-only` plus a
     * `--tmpfs /tmp` scratch area bounded by its own, separate mechanism.
     * Routed through the same makeProcess()/$processFactory seam every
     * other shell-out in this class already uses. Best-effort: any
     * failure to run or to parse a leading byte count out of `du`'s own
     * stdout returns null rather than throwing, so a transient
     * measurement failure can never turn into a false stop -- the caller
     * treats a null result as "disk-limit check unavailable for this
     * invocation," never as "0 bytes used."
     */
    private function measureDiskUsageBytes(string $path): ?int
    {
        try {
            $process = $this->makeProcess(['du', '-sb', $path]);
            $process->run();
        } catch (\Throwable $e) {
            return null;
        }

        if ($process->getExitCode() !== 0) {
            return null;
        }

        $output = trim((string) $process->getOutput());
        if (!preg_match('/^(\d+)/', $output, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * 124-command-limit-controls, US3 (research.md R3b): resolved once,
     * immediately after `docker run` starts -- needed by the live
     * pids.current cgroup poll, which addresses the container by its full
     * 64-char id, not its `--name`. Routed through the same
     * makeProcess()/$processFactory seam every other shell-out in this
     * class already uses.
     *
     * Confirmed directly on this host: `Process::start()` returns as soon
     * as the `docker run` client process has been launched, which is
     * BEFORE the daemon has necessarily finished registering the
     * container -- a `docker inspect` fired immediately afterward can
     * genuinely fail with "no such object" for a brief window (this is a
     * real, observed race, not a theoretical one). A short, bounded retry
     * absorbs that startup race without meaningfully delaying the poll
     * loop that follows: a non-zero exit (or a thrown exception) is
     * retried up to a small fixed number of times with a brief pause
     * between attempts; a genuinely unresolvable name still gives up
     * rather than retrying forever. Best-effort throughout: exhausting
     * the retry budget, or an empty result on an eventual success, both
     * return null, which disables the pids-limit check for this whole
     * invocation rather than producing a false stop.
     */
    private function resolveContainerId(string $containerName): ?string
    {
        $maxAttempts = 10;
        $retryDelayMicroseconds = 100_000;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            try {
                $process = $this->makeProcess(['docker', 'inspect', $containerName, '--format', '{{.Id}}']);
                $process->run();
            } catch (\Throwable $e) {
                usleep($retryDelayMicroseconds);

                continue;
            }

            if ($process->getExitCode() !== 0) {
                usleep($retryDelayMicroseconds);

                continue;
            }

            $id = trim((string) $process->getOutput());

            return $id !== '' ? $id : null;
        }

        return null;
    }

    /**
     * 124-command-limit-controls, US3 (research.md R3a): consulted ONLY on
     * the "process ended on its own" path -- none of this executor's own
     * proactive kills (timeout/disk/pids) ever reach this call, since each
     * of those already returns its own definitive status directly. Must
     * run BEFORE the container is removed -- inspecting a container that
     * has already been torn down is a guaranteed "no such object" failure
     * (research.md R3a's own direct test), which is exactly why this is
     * called ahead of removeContainer(), not after. Best-effort: any
     * failure to run, a non-zero exit, or an unparseable result returns
     * null, which the caller treats as "not an OOM kill" -- falling back
     * to the ordinary completed status exactly as before this feature,
     * never a false stopped_oom.
     *
     * @return ?array{oom_killed: bool, exit_code: int}
     */
    private function inspectOomAndExitCode(string $containerName): ?array
    {
        try {
            $process = $this->makeProcess(['docker', 'inspect', $containerName, '--format', '{{.State.OOMKilled}} {{.State.ExitCode}}']);
            $process->run();
        } catch (\Throwable $e) {
            return null;
        }

        if ($process->getExitCode() !== 0) {
            return null;
        }

        $output = trim((string) $process->getOutput());
        if (!preg_match('/^(true|false)\s+(-?\d+)/', $output, $matches)) {
            return null;
        }

        return [
            'oom_killed' => $matches[1] === 'true',
            'exit_code' => (int) $matches[2],
        ];
    }

    /**
     * 124-command-limit-controls, US3 (research.md R3b): reads the
     * container's live `pids.current` cgroup value -- deliberately a plain
     * host-side file read, never a subprocess, so it does NOT flow through
     * makeProcess()/$processFactory (that seam mocks Process-shaped
     * objects; a file read needs its own seam, $pidsCurrentReader). Probes
     * the systemd cgroup-driver path shape first, falling back to the
     * plain-cgroupfs shape (research.md R3b's own portability caveat).
     * Best-effort: an unreadable/unparseable path at either candidate
     * returns null, which the caller treats as "pids-limit check
     * unavailable for this invocation," never as "0 processes running."
     */
    private function readPidsCurrent(string $containerId): ?int
    {
        if ($this->pidsCurrentReader !== null) {
            return ($this->pidsCurrentReader)($containerId);
        }

        $candidatePaths = [
            '/sys/fs/cgroup/system.slice/docker-'.$containerId.'.scope/pids.current',
            '/sys/fs/cgroup/docker/'.$containerId.'/pids.current',
        ];

        foreach ($candidatePaths as $path) {
            if (!is_readable($path)) {
                continue;
            }

            $contents = @file_get_contents($path);
            if ($contents === false) {
                continue;
            }

            $trimmed = trim($contents);
            if (preg_match('/^(\d+)/', $trimmed, $matches)) {
                return (int) $matches[1];
            }
        }

        return null;
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
