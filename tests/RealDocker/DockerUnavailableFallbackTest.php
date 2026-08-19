<?php

namespace Tests\RealDocker;

use ClarionApp\LlmClient\Services\DockerCommandExecutor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 123-sandboxed-shell-execution, US1, T011 (research.md D5, FR-015,
 * quickstart.md Scenario 5.1). research.md D5's own note: this is the one
 * part of this feature's real-Docker suite that must be simulated, since
 * the real development host offers no way to make Docker itself
 * unavailable without disrupting the rest of the test run. The
 * simulation is still genuine at the boundary that matters: a real
 * `docker` binary is actually invoked, pointed at a deliberately invalid
 * daemon address via DockerCommandExecutor's own `$env` constructor seam
 * (merged on top of the inherited process environment for every Process
 * it constructs) -- never a mocked process boundary.
 */
#[Group('real-docker')]
class DockerUnavailableFallbackTest extends TestCase
{
    #[Test]
    public function an_unreachable_tcp_docker_host_yields_sandbox_unavailable_never_a_500_or_unhandled_exception(): void
    {
        // Port 1 is a reserved, essentially never-listening TCP port --
        // the real `docker` CLI genuinely attempts and fails this
        // connection, it is not intercepted or faked in any way.
        $executor = new DockerCommandExecutor(env: ['DOCKER_HOST' => 'tcp://127.0.0.1:1']);

        $result = $executor->run(sys_get_temp_dir(), 'echo hello');

        $this->assertSame('sandbox_unavailable', $result['status']);
        $this->assertArrayHasKey('reason', $result);
        $this->assertNotEmpty($result['reason'], 'a sandbox_unavailable result must always name a specific reason, never an opaque failure');
        $this->assertStringContainsString('not reachable', $result['reason']);
        $this->assertNull($result['exit_code']);
        $this->assertNull($result['stdout']);
        $this->assertNull($result['stderr']);
        $this->assertNull($result['duration_ms']);
    }

    #[Test]
    public function a_nonexistent_unix_socket_path_yields_sandbox_unavailable_never_a_500_or_unhandled_exception(): void
    {
        $bogusSocket = '/tmp/coding-agent-does-not-exist-'.uniqid('', true).'.sock';
        $this->assertFileDoesNotExist($bogusSocket, 'fixture sanity: the socket path must genuinely not exist');

        $executor = new DockerCommandExecutor(env: ['DOCKER_HOST' => 'unix://'.$bogusSocket]);

        $result = $executor->run(sys_get_temp_dir(), 'echo hello');

        $this->assertSame('sandbox_unavailable', $result['status']);
        $this->assertNotEmpty($result['reason']);
        $this->assertNull($result['exit_code']);
    }
}
