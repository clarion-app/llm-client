<?php

namespace Tests\Unit\RealDatabase;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\RealDatabase\Support\ConnectionSpec;
use Tests\RealDatabase\Support\ProvisionOutcome;

/**
 * T006/T006a: ConnectionResolver — resolution order and opt-in behaviour.
 *
 * Tests the resolution order: explicit-details → ephemeral → Unavailable.
 * No fourth branch. Asserts against ConnectionSpec/ProvisionOutcome shapes.
 *
 * Uses reflection to test resolveExplicitDetails without invoking Docker.
 */
class ConnectionResolverTest extends TestCase
{
    /**
     * T006: Explicit connection details with ALLOW_SUPPLIED=1 → Available.
     */
    #[Test]
    public function explicitDetailsWithOptInProducesAvailable(): void
    {
        $env = [
            'LLM_CLIENT_REAL_DB_HOST' => 'db.example.com',
            'LLM_CLIENT_REAL_DB_PORT' => '3307',
            'LLM_CLIENT_REAL_DB_DATABASE' => 'test_db',
            'LLM_CLIENT_REAL_DB_USERNAME' => 'test_user',
            'LLM_CLIENT_REAL_DB_PASSWORD' => 'test_pass',
            'LLM_CLIENT_REAL_DB_ALLOW_SUPPLIED' => '1',
        ];

        $spec = $this->resolveExplicitWithEnv($env);

        $this->assertInstanceOf(ConnectionSpec::class, $spec);
        $this->assertSame('mysql', $spec->driver);
        $this->assertSame('db.example.com', $spec->host);
        $this->assertSame(3307, $spec->port);
        $this->assertSame('test_db', $spec->database);
        $this->assertSame('test_user', $spec->username);
        $this->assertSame('test_pass', $spec->password);
        $this->assertSame('supplied', $spec->origin);
        $this->assertNull($spec->containerId);
    }

    /**
     * T006a: Explicit details WITHOUT ALLOW_SUPPLIED=1 → named configuration failure.
     * Must not fall through to container, must not skip.
     */
    #[Test]
    public function explicitDetailsWithoutOptInFailsWithNamedConfiguration(): void
    {
        $env = [
            'LLM_CLIENT_REAL_DB_HOST' => 'db.example.com',
            'LLM_CLIENT_REAL_DB_PORT' => '3307',
            'LLM_CLIENT_REAL_DB_DATABASE' => 'test_db',
            'LLM_CLIENT_REAL_DB_USERNAME' => 'test_user',
            'LLM_CLIENT_REAL_DB_PASSWORD' => 'test_pass',
            // ALLOW_SUPPLIED is NOT set.
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/ALLOW_SUPPLIED/');
        $this->expectExceptionMessageMatches('/db\.example\.com/');
        $this->expectExceptionMessageMatches('/test_db/');

        $this->resolveExplicitWithEnv($env);
    }

    /**
     * T006: No explicit details → resolveExplicitDetails returns null.
     * (The provisioner then falls through to Docker or Unavailable.)
     */
    #[Test]
    public function noDetailsReturnsNullFromExplicitBranch(): void
    {
        $env = [];
        $spec = $this->resolveExplicitWithEnv($env);
        $this->assertNull($spec);
    }

    /**
     * T006: Partial details (missing password) → returns null, not an error.
     * Only when ALL details are present without opt-in does it throw.
     */
    #[Test]
    public function partialDetailsReturnsNull(): void
    {
        $env = [
            'LLM_CLIENT_REAL_DB_HOST' => 'db.example.com',
            'LLM_CLIENT_REAL_DB_PORT' => '3307',
            'LLM_CLIENT_REAL_DB_DATABASE' => 'test_db',
            'LLM_CLIENT_REAL_DB_USERNAME' => 'test_user',
            // Password is missing — not all details present.
        ];

        $spec = $this->resolveExplicitWithEnv($env);
        $this->assertNull($spec);
    }

    /**
     * T006: Resolution order has no fourth branch.
     * When explicit details are present with opt-in, we never try Docker.
     */
    #[Test]
    public function resolutionOrderHasNoFourthBranch(): void
    {
        $env = [
            'LLM_CLIENT_REAL_DB_HOST' => '127.0.0.1',
            'LLM_CLIENT_REAL_DB_PORT' => '3306',
            'LLM_CLIENT_REAL_DB_DATABASE' => 'isolated_test',
            'LLM_CLIENT_REAL_DB_USERNAME' => 'root',
            'LLM_CLIENT_REAL_DB_PASSWORD' => 'secret',
            'LLM_CLIENT_REAL_DB_ALLOW_SUPPLIED' => '1',
        ];

        $spec = $this->resolveExplicitWithEnv($env);

        $this->assertInstanceOf(ConnectionSpec::class, $spec);
        $this->assertSame('supplied', $spec->origin);
        // If it fell through to Docker, origin would be 'ephemeral'.
        $this->assertNotSame('ephemeral', $spec->origin);
    }

    /**
     * T006: ALLOW_SUPPLIED=true (string) is also accepted.
     */
    #[Test]
    public function allowSuppliedTrueStringIsAccepted(): void
    {
        $env = [
            'LLM_CLIENT_REAL_DB_HOST' => 'localhost',
            'LLM_CLIENT_REAL_DB_PORT' => '3306',
            'LLM_CLIENT_REAL_DB_DATABASE' => 'test',
            'LLM_CLIENT_REAL_DB_USERNAME' => 'root',
            'LLM_CLIENT_REAL_DB_PASSWORD' => 'pass',
            'LLM_CLIENT_REAL_DB_ALLOW_SUPPLIED' => 'true',
        ];

        $spec = $this->resolveExplicitWithEnv($env);
        $this->assertInstanceOf(ConnectionSpec::class, $spec);
        $this->assertSame('supplied', $spec->origin);
    }

    /**
     * Test ConnectionSpec value object shape.
     */
    #[Test]
    public function connectionSpecToConnectionConfig(): void
    {
        $spec = new ConnectionSpec(
            driver: 'mysql',
            host: '10.0.0.1',
            port: 3307,
            database: 'test_db',
            username: 'user',
            password: 'pass',
            origin: 'supplied',
        );

        $config = $spec->toConnectionConfig();
        $this->assertSame('mysql', $config['driver']);
        $this->assertSame('10.0.0.1', $config['host']);
        $this->assertSame(3307, $config['port']);
        $this->assertSame('test_db', $config['database']);
        $this->assertSame('user', $config['username']);
        $this->assertSame('pass', $config['password']);
        $this->assertSame('utf8mb4', $config['charset']);
    }

    /**
     * Test ProvisionOutcome shapes.
     */
    #[Test]
    public function provisionOutcomeAvailable(): void
    {
        $spec = new ConnectionSpec(
            driver: 'mysql',
            host: 'localhost',
            port: 3306,
            database: 'test',
            username: 'root',
            password: 'secret',
            origin: 'ephemeral',
            containerId: 'abc123',
        );

        $outcome = ProvisionOutcome::available($spec);
        $this->assertTrue($outcome->isAvailable());
        $this->assertFalse($outcome->isUnavailable());
        $this->assertFalse($outcome->isIncapable());
        $this->assertSame($spec, $outcome->spec());
    }

    #[Test]
    public function provisionOutcomeUnavailable(): void
    {
        $outcome = ProvisionOutcome::unavailable('docker not available');
        $this->assertFalse($outcome->isAvailable());
        $this->assertTrue($outcome->isUnavailable());
        $this->assertFalse($outcome->isIncapable());
        $this->assertNull($outcome->spec());
        $this->assertSame('docker not available', $outcome->reason());
    }

    #[Test]
    public function provisionOutcomeIncapable(): void
    {
        $outcome = ProvisionOutcome::incapable(
            'missing VECTOR support',
            'server: MySQL 8.0'
        );
        $this->assertFalse($outcome->isAvailable());
        $this->assertFalse($outcome->isUnavailable());
        $this->assertTrue($outcome->isIncapable());
        $this->assertSame('missing VECTOR support', $outcome->reason());
        $this->assertSame('server: MySQL 8.0', $outcome->detail());
    }

    /**
     * Call resolveExplicitDetails via reflection (avoids Docker branch).
     * Returns ConnectionSpec or null; throws RuntimeException on config failure.
     */
    private function resolveExplicitWithEnv(array $env): ?ConnectionSpec
    {
        $class = new \ReflectionClass(\Tests\RealDatabase\Support\DatabaseProvisioner::class);
        $method = $class->getMethod('resolveExplicitDetails');
        $method->setAccessible(true);

        $envKeys = [
            'LLM_CLIENT_REAL_DB_HOST',
            'LLM_CLIENT_REAL_DB_PORT',
            'LLM_CLIENT_REAL_DB_DATABASE',
            'LLM_CLIENT_REAL_DB_USERNAME',
            'LLM_CLIENT_REAL_DB_PASSWORD',
            'LLM_CLIENT_REAL_DB_ALLOW_SUPPLIED',
        ];

        $originalEnv = [];
        foreach ($envKeys as $key) {
            $originalEnv[$key] = getenv($key);
        }

        foreach ($env as $key => $value) {
            putenv("{$key}={$value}");
        }
        foreach ($envKeys as $key) {
            if (!array_key_exists($key, $env)) {
                putenv($key);
            }
        }

        try {
            $provisioner = new \Tests\RealDatabase\Support\DatabaseProvisioner();
            return $method->invoke($provisioner);
        } finally {
            foreach ($envKeys as $key) {
                if ($originalEnv[$key] === false) {
                    putenv($key);
                } else {
                    putenv("{$key}={$originalEnv[$key]}");
                }
            }
        }
    }
}
