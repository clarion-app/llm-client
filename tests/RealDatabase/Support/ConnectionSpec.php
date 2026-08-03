<?php

namespace Tests\RealDatabase\Support;

/**
 * Resolved database connection details.
 *
 * Immutable value object produced by DatabaseProvisioner.
 * Consumed by IsolationGuard, CapabilityProbe, and RealDatabaseTestCase.
 */
class ConnectionSpec
{
    public function __construct(
        public readonly string $driver,
        public readonly string $host,
        public readonly int $port,
        public readonly string $database,
        public readonly string $username,
        public readonly string $password,
        public readonly string $origin,        // 'supplied' | 'ephemeral'
        public readonly ?string $containerId = null,
    ) {
    }

    /**
     * Turn this spec into the Laravel connection config array.
     */
    public function toConnectionConfig(): array
    {
        return [
            'driver'   => $this->driver,
            'host'     => $this->host,
            'port'     => $this->port,
            'database' => $this->database,
            'username' => $this->username,
            'password' => $this->password,
            'charset'  => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix'   => '',
            'strict'   => true,
            'engine'   => null,
        ];
    }
}
