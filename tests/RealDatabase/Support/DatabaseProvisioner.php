<?php

namespace Tests\RealDatabase\Support;

use Symfony\Component\Process\Process;

/**
 * Resolves a database for the real-database test harness.
 *
 * Resolution order (contract P1):
 * 1. Explicit connection details from env vars + ALLOW_SUPPLIED opt-in → use them.
 * 2. Start ephemeral mariadb:11.8 container via Docker.
 * 3. Unavailable.
 *
 * No fourth branch — no implicit fallback to a local instance.
 */
class DatabaseProvisioner
{
    /** Track the container ID so teardown can clean up. */
    private ?string $containerId = null;

    /** Track the generated password for readiness checks. */
    private string $rootPassword = '';

    /** Track the generated database name. */
    private string $databaseName = '';

    /** Track whether the image was pulled cold (first run) or is cached. */
    private static bool $imageLocal = false;

    /**
     * Resolve a database connection.
     *
     * Returns ProvisionOutcome::available(), ::unavailable(), or ::incapable().
     */
    public function provision(): ProvisionOutcome
    {
        // Branch 1: Explicit connection details from environment.
        $spec = $this->resolveExplicitDetails();
        if ($spec !== null) {
            return ProvisionOutcome::available($spec);
        }

        // Branch 2: Ephemeral Docker container.
        $outcome = $this->startEphemeralContainer();
        if ($outcome !== null) {
            return $outcome;
        }

        // Branch 3: Unavailable.
        return ProvisionOutcome::unavailable(
            'no explicit connection details and no usable Docker daemon'
        );
    }

    /**
     * The database name this provisioner is entitled to use, independent of
     * what the resolved ConnectionSpec claims.
     *
     * P5's second check compares the two: for an ephemeral instance this is the
     * name this run generated, and for a supplied one it is the name the
     * environment names. Reading it back off the spec would make the check
     * compare a value with itself and pass unconditionally.
     */
    public function expectedDatabaseName(): string
    {
        if ($this->databaseName !== '') {
            return $this->databaseName;
        }

        $supplied = getenv('LLM_CLIENT_REAL_DB_DATABASE');

        return $supplied === false ? '' : (string) $supplied;
    }

    /**
     * Tear down any ephemeral container started by this provisioner.
     */
    public function teardown(): void
    {
        if ($this->containerId !== null) {
            $process = Process::fromShellCommandline(
                'docker stop ' . escapeshellarg($this->containerId) . ' 2>/dev/null'
            );
            $process->setTimeout(10);
            $process->run();
            $this->containerId = null;
        }
    }

    /**
     * Register a shutdown handler so interrupted runs still clean up.
     */
    public function registerShutdownHandler(): void
    {
        register_shutdown_function(function () {
            $this->teardown();
        });
    }

    /**
     * Resolve explicit connection details from environment variables.
     *
     * Requires LLM_CLIENT_REAL_DB_ALLOW_SUPPLIED=1 plus the connection details.
     * Returns null if details are absent or opt-in flag is not set.
     * Throws an exception if details are present but opt-in flag is absent (T006a).
     */
    private function resolveExplicitDetails(): ?ConnectionSpec
    {
        $host = getenv('LLM_CLIENT_REAL_DB_HOST');
        $port = getenv('LLM_CLIENT_REAL_DB_PORT');
        $database = getenv('LLM_CLIENT_REAL_DB_DATABASE');
        $username = getenv('LLM_CLIENT_REAL_DB_USERNAME');
        $password = getenv('LLM_CLIENT_REAL_DB_PASSWORD');
        $allowSupplied = getenv('LLM_CLIENT_REAL_DB_ALLOW_SUPPLIED');

        $hasDetails = $host !== false && $port !== false && $database !== false
            && $username !== false && $password !== false;

        if (!$hasDetails) {
            return null;
        }

        // T006a: Details present but opt-in flag absent → named configuration failure.
        if ($allowSupplied !== '1' && $allowSupplied !== 'true') {
            throw new \RuntimeException(
                'Explicit database details provided but LLM_CLIENT_REAL_DB_ALLOW_SUPPLIED is not set. '
                . 'Set LLM_CLIENT_REAL_DB_ALLOW_SUPPLIED=1 to opt into using a supplied instance '
                . '(host=' . $host . ', port=' . $port . ', database=' . $database . ').'
            );
        }

        return new ConnectionSpec(
            driver: 'mysql',
            host: (string) $host,
            port: (int) $port,
            database: (string) $database,
            username: (string) $username,
            password: (string) $password,
            origin: 'supplied',
            containerId: null,
        );
    }

    /**
     * Start an ephemeral MariaDB container.
     *
     * Returns ProvisionOutcome or null if Docker is not available.
     */
    private function startEphemeralContainer(): ?ProvisionOutcome
    {
        // Check Docker availability.
        $dockerCheck = Process::fromShellCommandline('docker info 2>&1');
        $dockerCheck->run();
        if (!$dockerCheck->isSuccessful()) {
            return null;
        }

        $this->databaseName = 'clarion_realdb_' . uniqid('', true);
        $this->rootPassword = 'test_' . bin2hex(random_bytes(16));
        $containerName = 'clarion_test_' . $this->databaseName;

        // Remove any pre-existing container with the same name.
        Process::fromShellCommandline(
            'docker rm -f ' . escapeshellarg($containerName) . ' 2>/dev/null'
        )->run();

        // Start container with --rm so it cleans up on stop, -p for port publishing.
        $cmd = sprintf(
            'docker run -d --rm -p 3306 -e MARIADB_ROOT_PASSWORD=%s -e MARIADB_DATABASE=%s '
            . '--name %s mariadb:11.8',
            escapeshellarg($this->rootPassword),
            escapeshellarg($this->databaseName),
            escapeshellarg($containerName)
        );

        $process = Process::fromShellCommandline($cmd);
        $process->setTimeout(300);
        $process->run();

        if (!$process->isSuccessful()) {
            $error = trim($process->getErrorOutput());
            // If docker run fails (e.g., image not found, permission denied),
            // treat as unavailable rather than incapable.
            return null;
        }

        $this->containerId = trim($process->getOutput());

        // Readiness poll.
        $readinessTimeout = self::$imageLocal ? 15 : 120;
        self::$imageLocal = true;

        $spec = $this->waitForReady($readinessTimeout);
        if ($spec === null) {
            $this->teardown();
            return ProvisionOutcome::unavailable(
                "database did not become ready within {$readinessTimeout}s"
            );
        }

        return ProvisionOutcome::available($spec);
    }

    /**
     * Poll until the database is ready, then return a ConnectionSpec.
     * Returns null on timeout.
     */
    private function waitForReady(int $timeoutSeconds): ?ConnectionSpec
    {
        $port = 3306;
        $deadline = time() + $timeoutSeconds;

        while (time() < $deadline) {
            // Get the mapped port.
            $portCmd = 'docker port ' . escapeshellarg($this->containerId) . ' 3306 2>/dev/null';
            $portProcess = Process::fromShellCommandline($portCmd);
            $portProcess->run();

            if ($portProcess->isSuccessful()) {
                $output = trim($portProcess->getOutput());
                if (str_contains($output, ':')) {
                    $parts = explode(':', $output);
                    $port = (int) end($parts);
                }
            }

            // Check if MariaDB is ready using mariadb-admin ping.
            // The socket path varies by image/version; mariadb-admin ping is more reliable.
            $pingProcess = Process::fromShellCommandline(
                'docker exec ' . escapeshellarg($this->containerId)
                . ' mariadb-admin -u root -p' . escapeshellarg($this->rootPassword) . ' ping 2>&1'
            );
            $pingProcess->setTimeout(5);
            $pingProcess->run();

            $output = trim($pingProcess->getOutput());
            if ($pingProcess->isSuccessful() && str_contains($output, 'alive')) {
                return new ConnectionSpec(
                    driver: 'mysql',
                    host: '127.0.0.1',
                    port: $port,
                    database: $this->databaseName,
                    username: 'root',
                    password: $this->rootPassword,
                    origin: 'ephemeral',
                    containerId: $this->containerId,
                );
            }

            sleep(2);
        }

        return null;
    }
}
