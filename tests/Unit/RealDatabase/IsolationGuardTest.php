<?php

namespace Tests\Unit\RealDatabase;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\RealDatabase\Support\ConnectionSpec;
use Tests\RealDatabase\Support\IsolationGuard;
use Tests\RealDatabase\Support\IsolationVerdict;

/**
 * T007: IsolationGuard — each refusal case fires correctly.
 *
 * Tests driver check, database name check, and schema check.
 * Refusal message names host, port, and database. Asserts no bypass flag exists.
 */
class IsolationGuardTest extends TestCase
{
    private IsolationGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new IsolationGuard();
    }

    /**
     * T007: Driver not 'mysql' → refusal.
     */
    #[Test]
    public function refusesWhenDriverIsNotMysql(): void
    {
        $spec = new ConnectionSpec(
            driver: 'sqlite',
            host: '127.0.0.1',
            port: 3306,
            database: 'clarion_realdb_test123',
            username: 'root',
            password: 'secret',
            origin: 'ephemeral',
        );

        $verdict = $this->guard->check($spec, 'clarion_realdb_test123');

        $this->assertFalse($verdict->isolated);
        $this->assertStringContainsString('sqlite', $verdict->refusalMessage);
        $this->assertStringContainsString('mysql', $verdict->refusalMessage);
        $this->assertStringContainsString('127.0.0.1:3306/clarion_realdb_test123', $verdict->refusalMessage);
    }

    /**
     * T007: Database name mismatch → refusal.
     */
    #[Test]
    public function refusesWhenDatabaseNameMismatch(): void
    {
        $spec = new ConnectionSpec(
            driver: 'mysql',
            host: '10.0.0.5',
            port: 3307,
            database: 'production_db',
            username: 'root',
            password: 'secret',
            origin: 'supplied',
        );

        $verdict = $this->guard->check($spec, 'clarion_realdb_expected');

        $this->assertFalse($verdict->isolated);
        $this->assertStringContainsString('production_db', $verdict->refusalMessage);
        $this->assertStringContainsString('clarion_realdb_expected', $verdict->refusalMessage);
        $this->assertStringContainsString('10.0.0.5:3307/production_db', $verdict->refusalMessage);
    }

    /**
     * T007: Refusal message names host, port, and database.
     */
    #[Test]
    public function refusalMessageNamesHostPortDatabase(): void
    {
        $spec = new ConnectionSpec(
            driver: 'mysql',
            host: 'db-server.internal',
            port: 13306,
            database: 'test_schema',
            username: 'test_user',
            password: 'test_pass',
            origin: 'supplied',
        );

        $verdict = $this->guard->check($spec, 'clarion_realdb_other');

        $this->assertFalse($verdict->isolated);
        $this->assertStringContainsString('db-server.internal', $verdict->refusalMessage);
        $this->assertStringContainsString('13306', $verdict->refusalMessage);
        $this->assertStringContainsString('test_schema', $verdict->refusalMessage);
    }

    /**
     * T007: Assert there is no bypass flag on IsolationVerdict.
     */
    #[Test]
    public function noBypassFlagExists(): void
    {
        $reflected = new \ReflectionClass(IsolationVerdict::class);
        $properties = $reflected->getProperties();

        $propertyNames = array_map(fn ($p) => $p->getName(), $properties);

        // The class should only have 'isolated', 'checks', and 'refusalMessage'.
        // No 'bypass', 'force', 'skipGuard', etc.
        $this->assertNotContains('bypass', $propertyNames);
        $this->assertNotContains('force', $propertyNames);
        $this->assertNotContains('skipGuard', $propertyNames);
        $this->assertNotContains('disableGuard', $propertyNames);
    }

    /**
     * T007: Assert there is no bypass flag on IsolationGuard.
     */
    #[Test]
    public function guardHasNoBypassMethod(): void
    {
        $reflected = new \ReflectionClass(IsolationGuard::class);
        $methods = $reflected->getMethods();

        $methodNames = array_map(fn ($m) => $m->getName(), $methods);

        $this->assertNotContains('bypass', $methodNames);
        $this->assertNotContains('disable', $methodNames);
        $this->assertNotContains('skip', $methodNames);
    }

    /**
     * Isolated verdict is created correctly.
     */
    #[Test]
    public function isolatedVerdictIsCorrect(): void
    {
        $verdict = IsolationVerdict::isolated();
        $this->assertTrue($verdict->isolated);
        $this->assertSame('', $verdict->refusalMessage);
    }

    /**
     * Refused verdict carries checks.
     */
    #[Test]
    public function refusedVerdictCarriesChecks(): void
    {
        $verdict = IsolationVerdict::refused('test refusal', [
            'driver_is_mysql' => false,
            'database_name_matches' => true,
        ]);

        $this->assertFalse($verdict->isolated);
        $this->assertSame('test refusal', $verdict->refusalMessage);
        $this->assertArrayHasKey('driver_is_mysql', $verdict->checks);
        $this->assertFalse($verdict->checks['driver_is_mysql']);
    }
}
