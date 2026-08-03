<?php

namespace Tests\RealDatabase;

use ClarionApp\LlmClient\LlmClientServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Tests\RealDatabase\Support\CapabilityProbe;
use Tests\RealDatabase\Support\ConnectionSpec;
use Tests\RealDatabase\Support\DatabaseProvisioner;
use Tests\RealDatabase\Support\IsolationGuard;
use Tests\RealDatabase\Support\IsolationVerdict;
use Tests\RealDatabase\Support\ProvisionOutcome;
use Tests\RealDatabase\Support\SkipReport;

use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\After;

/**
 * Base class for real-database tests.
 *
 * Extends Orchestra\Testbench\TestCase directly (not Tests\TestCase).
 * Provisions infrastructure in #[Before], tears down in #[After].
 * Points database.default at the 'mysql' connection in getEnvironmentSetUp().
 *
 * One instance per test class; migrations run once per class.
 * Each test truncates the seeded tables before seeding.
 */
abstract class RealDatabaseTestCase extends BaseTestCase
{
    protected ?DatabaseProvisioner $provisioner = null;
    protected ?ProvisionOutcome $outcome = null;
    protected ?ConnectionSpec $spec = null;
    protected ?IsolationVerdict $verdict = null;
    protected bool $migrated = false;
    protected bool $skippedClass = false;

    /** Tables that this test class seeds, for per-test truncation. */
    protected array $seedTables = [];

    protected function getPackageProviders($app): array
    {
        return [LlmClientServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // If we have a spec, configure the mysql connection.
        if ($this->spec !== null) {
            $app['config']->set('database.default', 'mysql');
            $app['config']->set('database.connections.mysql', $this->spec->toConnectionConfig());
        }

        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('eloquent-multichain-bridge.disabled', true);

        // Configure auth for tests.
        $app['config']->set('auth.defaults.guard', 'api');
        $app['config']->set('auth.guards.api', [
            'driver'   => 'token',
            'provider' => 'users',
        ]);
        $app['config']->set('auth.providers.users', [
            'driver' => 'eloquent',
            'model'  => \ClarionApp\Backend\Models\User::class,
        ]);
    }

    protected function defineEnvironment($app): void
    {
        if (!class_exists('App\Http\Controllers\Controller')) {
            eval('namespace App\Http\Controllers { class Controller { } }');
        }

        $app->singleton('multichain', function () {
            return new class {
                public function __call($method, $arguments) { return null; }
                public function publish($stream, $key, $value) { return 'stub-txid'; }
                public function liststreams($stream) { throw new \Exception('not found'); }
                public function create($type, $name, $private) { return null; }
                public function subscribe($stream) { return null; }
            };
        });
    }

    #[Before]
    protected function provisionDatabase(): void
    {
        // SkipReport registration (once per process).
        SkipReport::registerFlush();

        // If the class was already skipped, no need to re-provision.
        if ($this->skippedClass) {
            return;
        }

        $this->provisioner = new DatabaseProvisioner();
        $this->provisioner->registerShutdownHandler();

        try {
            $this->outcome = $this->provisioner->provision();
        } catch (\RuntimeException $e) {
            // Configuration failure (e.g., T006a: details without opt-in).
            $this->outcome = ProvisionOutcome::unavailable($e->getMessage());
        }

        if ($this->outcome === null || !$this->outcome->isAvailable()) {
            $this->handleUnavailable();
            return;
        }

        $this->spec = $this->outcome->spec();

        // Isolation guard (P5).
        $guard = new IsolationGuard();
        $this->verdict = $guard->check($this->spec, $this->spec->database);

        if (!$this->verdict->isolated) {
            $this->handleIsolationRefusal();
            return;
        }

        // Capability probe (P4).
        $probe = new CapabilityProbe();
        try {
            $report = $probe->probe($this->spec);
        } catch (\RuntimeException $e) {
            $this->outcome = ProvisionOutcome::unavailable($e->getMessage());
            $this->handleUnavailable();
            return;
        }

        if (!$report->isCapable()) {
            $missing = implode(', ', $report->missingCapabilities());
            $this->outcome = ProvisionOutcome::incapable(
                "missing capabilities: {$missing}",
                "server version: {$report->serverVersion}"
            );
            // Incapable always fails, never skips.
            $this->skippedClass = true;
            $this->fail($this->outcome->diagnostic());
            return;
        }

        // Migrations are deferred to setUp() after the app is booted.
        // They need config() and Artisan which require the container.
    }

    protected function getEnvironmentSetUpAtBootstrap(): void
    {
        // Re-apply environment setup after provisioning if we have a spec.
        // This is called from setUp after provisionDatabase.
        if ($this->spec !== null && !$this->skippedClass) {
            $this->app['config']->set('database.default', 'mysql');
            $this->app['config']->set('database.connections.mysql', $this->spec->toConnectionConfig());
        }
    }

    /**
     * Override setUp to re-apply the database config after provisioning.
     * Migrations run here (after app boot) rather than in #[Before].
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->getEnvironmentSetUpAtBootstrap();

        // Run migrations after the app is booted (P6).
        // config() and Artisan require the container to be available.
        if (!$this->skippedClass && !$this->migrated) {
            $this->runMigrations();
        }

        // Per-test truncation (P7).
        if (!$this->skippedClass && $this->migrated) {
            $this->truncateSeedTables();
        }
    }

    #[After]
    protected function teardownDatabase(): void
    {
        if ($this->provisioner !== null) {
            $this->provisioner->teardown();
        }
    }

    /**
     * Run the package's own migrations against the isolated instance.
     * Must be called after the app is booted (config() and Artisan need the container).
     */
    private function runMigrations(): void
    {
        // Set the embedding dimension for test fixtures.
        // Subclasses can override embeddingDimension() to change this.
        $dimension = $this->embeddingDimension();
        $this->app['config']->set('llm-client.memory.embedding.dimension', $dimension);

        $exitCode = Artisan::call('migrate', [
            '--force' => true,
        ]);

        if ($exitCode !== 0) {
            $this->skippedClass = true;
            $this->fail('Migration failed: ' . Artisan::output());
            return;
        }

        $this->migrated = true;
    }

    /**
     * Override to change the embedding dimension for migrations.
     * Default is 8 for test fixtures; override to 1536 for default-dimension tests.
     */
    protected function embeddingDimension(): int
    {
        return 8;
    }

    /**
     * Truncate the seeded tables for per-test isolation.
     */
    private function truncateSeedTables(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->seedTables as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                DB::table($table)->truncate();
            }
        }
        DB::statement('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * A1 inconclusive guard: before any scenario body, assert the resolved
     * driver is mysql and the capability probe passed.
     */
    protected function assertReady(): void
    {
        if ($this->skippedClass) {
            return; // Already skipped or failed at class level.
        }

        if ($this->spec === null) {
            $this->markTestSkipped('Database not provisioned (inconclusive)');
            return;
        }

        if ($this->spec->driver !== 'mysql') {
            $this->fail(
                "Inconclusive: resolved driver is '{$this->spec->driver}', expected 'mysql'. "
                . 'Real-database checks must run against MariaDB, not a fallback.'
            );
        }

        if (!$this->migrated) {
            $this->fail('Inconclusive: migrations did not run successfully.');
        }
    }

    /**
     * Handle an unavailable outcome (skip or fail depending on strict mode).
     */
    private function handleUnavailable(): void
    {
        $this->skippedClass = true;
        $reason = $this->outcome ? $this->outcome->diagnostic() : 'Database unavailable';
        SkipReport::recordSkipped($reason);

        $strict = getenv('LLM_CLIENT_REAL_DB_STRICT');
        if ($strict === '1' || $strict === 'true') {
            $this->fail("Strict mode: {$reason}");
        } else {
            $this->markTestSkipped($reason);
        }
    }

    /**
     * Handle an isolation guard refusal.
     */
    private function handleIsolationRefusal(): void
    {
        $this->skippedClass = true;
        $this->fail('Isolation guard refused: ' . $this->verdict->refusalMessage);
    }

    /**
     * Get the ConnectionSpec (for subclasses that need it).
     */
    protected function getSpec(): ?ConnectionSpec
    {
        return $this->spec;
    }

    /**
     * Get the ProvisionOutcome (for subclasses that need it).
     */
    protected function getOutcome(): ?ProvisionOutcome
    {
        return $this->outcome;
    }

    /**
     * T035: Assert that the returned ranking matches the expected key order exactly.
     *
     * @param object[] $results Memory entries with 'key' attribute
     * @param string[] $expectedOrder Expected key order (best first)
     */
    protected function assertRankingMatches(array $results, array $expectedOrder, string $context = ''): void
    {
        $actualOrder = array_map(fn ($e) => $e->key ?? $e['key'], $results);
        $prefix = $context !== '' ? "[{$context}] " : '';

        if (count($expectedOrder) > count($actualOrder)) {
            // Only compare the top N from actual
            $expectedSlice = array_slice($expectedOrder, 0, count($actualOrder));
        } else {
            $expectedSlice = $expectedOrder;
        }

        $this->assertSame(
            $expectedSlice,
            $actualOrder,
            $prefix . "Ranking mismatch.\nExpected order: " . json_encode($expectedSlice)
            . "\nActual order:   " . json_encode($actualOrder)
        );
    }

    /**
     * T035: Assert that engine scores agree with PHP reference ranking.
     *
     * Compares order exactly and scores within 1e-4 absolute tolerance
     * (float32 storage precision).
     *
     * @param object[] $results Memory entries with 'key' and 'similarity_score' attributes
     * @param array<string, float> $referenceScores Key → expected score mapping
     * @param float $tolerance Absolute tolerance (default 1e-4 for float32)
     */
    protected function assertAgreesWithReference(
        array $results,
        array $referenceScores,
        float $tolerance = 1e-4,
        string $context = ''
    ): void {
        $prefix = $context !== '' ? "[{$context}] " : '';

        // Check order: keys must match the reference order (sorted by score desc)
        $sortedKeys = array_keys($referenceScores);
        array_multisort(
            array_map(fn ($k) => $referenceScores[$k], $sortedKeys),
            SORT_DESC,
            $sortedKeys
        );
        $actualKeys = array_map(fn ($e) => $e->key ?? $e['key'], $results);

        // Compare order (only for entries that are in both)
        $commonKeys = array_intersect($sortedKeys, $actualKeys);
        $expectedOrder = array_values(array_filter($sortedKeys, fn ($k) => in_array($k, $commonKeys, true)));
        $actualOrder = array_values(array_filter($actualKeys, fn ($k) => in_array($k, $commonKeys, true)));

        $this->assertSame(
            $expectedOrder,
            $actualOrder,
            $prefix . "Order mismatch.\nExpected: " . json_encode($expectedOrder)
            . "\nActual:   " . json_encode($actualOrder)
        );

        // Check scores within tolerance
        foreach ($results as $entry) {
            $key = $entry->key ?? $entry['key'];
            $actualScore = $entry->similarity_score ?? $entry['similarity_score'] ?? null;
            if ($actualScore === null) {
                $this->fail($prefix . "Entry '{$key}' has no similarity_score.");
            }
            if (!isset($referenceScores[$key])) {
                continue; // Entry not in reference, skip
            }
            $expectedScore = $referenceScores[$key];
            $diff = abs($actualScore - $expectedScore);
            $this->assertLessThanOrEqual(
                $tolerance,
                $diff,
                $prefix . "Score for '{$key}' differs by {$diff} (tolerance {$tolerance}). "
                . "Expected {$expectedScore}, got {$actualScore}"
            );
        }
    }
}
