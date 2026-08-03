<?php

namespace Tests\RealDatabase;

use ClarionApp\LlmClient\LlmClientServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase as BaseTestCase;
use PDO;
use PDOException;
use Tests\RealDatabase\Support\CapabilityProbe;
use Tests\RealDatabase\Support\ConnectionSpec;
use Tests\RealDatabase\Support\DatabaseProvisioner;
use Tests\RealDatabase\Support\IsolationGuard;
use Tests\RealDatabase\Support\IsolationVerdict;
use Tests\RealDatabase\Support\ProvisionOutcome;
use Tests\RealDatabase\Support\SkipReport;

use PHPUnit\Framework\Attributes\Before;

/**
 * Base class for real-database tests.
 *
 * Extends Orchestra\Testbench\TestCase directly (not Tests\TestCase).
 * Points database.default at the 'mysql' connection in getEnvironmentSetUp().
 *
 * Contract P7: one instance per test class, migrations once per class, and
 * truncation per test. PHPUnit builds a fresh TestCase object per test method,
 * so the provisioned database is held in per-class static state and released in
 * tearDownAfterClass() — not in a per-test #[After], which would start (and pay
 * for) a container per test and, on a supplied instance, make the isolation
 * guard refuse every test after the first because the previous test's
 * migrations left tables behind.
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

    /** The class the static state below belongs to; null when unprovisioned. */
    private static ?string $stateOwner = null;
    private static ?DatabaseProvisioner $sharedProvisioner = null;
    private static ?ProvisionOutcome $sharedOutcome = null;
    private static ?ConnectionSpec $sharedSpec = null;
    private static ?IsolationVerdict $sharedVerdict = null;
    private static bool $sharedMigrated = false;

    /**
     * A class-level failure that every test in the class must report: a
     * configuration error (P2), an incapable database (G4/FR-019), or an
     * isolation refusal (P5). Distinct from Unavailable, which skips.
     */
    private static ?string $sharedFailure = null;

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
        SkipReport::registerFlush();

        // P7: acquire once per class. Every later test in the class reuses the
        // same instance, the same migrations, and the same verdict.
        if (self::$stateOwner !== static::class) {
            self::resetClassState();
            self::$stateOwner = static::class;
            self::acquireDatabase();
        }

        $this->provisioner = self::$sharedProvisioner;
        $this->outcome    = self::$sharedOutcome;
        $this->spec       = self::$sharedSpec;
        $this->verdict    = self::$sharedVerdict;
        $this->migrated   = self::$sharedMigrated;

        // A class-level failure is reported by every test in the class: an
        // incapable database, an isolation refusal, or a configuration error
        // are never skips (G4, P2, P5).
        if (self::$sharedFailure !== null) {
            $this->skippedClass = true;
            $this->fail(self::$sharedFailure);
            return;
        }

        if ($this->outcome === null || !$this->outcome->isAvailable()) {
            $this->handleUnavailable();
        }
    }

    /**
     * Obtain, verify, and record a database for the current test class.
     *
     * Writes only to the static state; the skip-versus-fail decision belongs to
     * the per-test hook, because PHPUnit can only mark the test it is running.
     */
    private static function acquireDatabase(): void
    {
        self::$sharedProvisioner = new DatabaseProvisioner();
        self::$sharedProvisioner->registerShutdownHandler();

        try {
            self::$sharedOutcome = self::$sharedProvisioner->provision();
        } catch (\RuntimeException $e) {
            // P2: explicit details without the opt-in flag is a named
            // configuration failure. Not a skip, and not a fall-through to
            // starting a container behind the developer's back.
            self::$sharedFailure = 'Real-database configuration error: ' . $e->getMessage();
            return;
        }

        if (!self::$sharedOutcome->isAvailable()) {
            return; // Unavailable → per-test skip, or fail under strict mode.
        }

        self::$sharedSpec = self::$sharedOutcome->spec();

        // P5/FR-022a: the isolation guard runs before anything writes to the
        // resolved instance — including the capability probe, which creates and
        // drops tables of its own.
        $guard = new IsolationGuard();
        self::$sharedVerdict = $guard->check(
            self::$sharedSpec,
            self::$sharedProvisioner->expectedDatabaseName()
        );

        if (!self::$sharedVerdict->isolated) {
            self::$sharedFailure = 'Isolation guard refused: ' . self::$sharedVerdict->refusalMessage;
            return;
        }

        if (self::$sharedSpec->origin === 'supplied') {
            // P8: an interrupted run must not leave a supplied schema populated
            // for the next class — or the next run — to trip over. Bound by
            // value, because the static state belongs to the next class by the
            // time the process exits.
            $spec = self::$sharedSpec;
            register_shutdown_function(static fn () => self::dropAllTables($spec));
        }

        // P4: capability is probed functionally. A version string is not
        // evidence — MySQL 8 reports a plausible one and has none of this.
        $probe = new CapabilityProbe();
        try {
            $report = $probe->probe(self::$sharedSpec);
        } catch (\RuntimeException $e) {
            self::$sharedOutcome = ProvisionOutcome::unavailable($e->getMessage());
            self::$sharedSpec = null;
            return;
        }

        if (!$report->isCapable()) {
            $missing = implode(', ', $report->missingCapabilities());
            self::$sharedOutcome = ProvisionOutcome::incapable(
                "missing capabilities: {$missing}",
                "server version: {$report->serverVersion}"
            );
            // G4/FR-019: incapable always fails, never skips.
            self::$sharedFailure = self::$sharedOutcome->diagnostic();
        }

        // Migrations are deferred to setUp(), which runs after the app is
        // booted: config() and Artisan need the container.
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

        // Run migrations after the app is booted (P6), once per class.
        // config() and Artisan require the container to be available.
        if (!$this->skippedClass && !self::$sharedMigrated) {
            $this->runMigrations();
        }
        $this->migrated = self::$sharedMigrated;

        // Per-test truncation (P7).
        if (!$this->skippedClass && $this->migrated) {
            $this->truncateSeedTables();
        }
    }

    /**
     * P7/P8/FR-024: release the class's database when the class is done —
     * stop an ephemeral container, or return a supplied schema to the empty
     * state the isolation guard requires and this run found it in.
     */
    public static function tearDownAfterClass(): void
    {
        self::releaseDatabase();

        parent::tearDownAfterClass();
    }

    private static function releaseDatabase(): void
    {
        $spec = self::$sharedSpec;

        if ($spec !== null && $spec->origin === 'supplied' && self::$sharedVerdict?->isolated) {
            self::dropAllTables($spec);
        }

        self::$sharedProvisioner?->teardown();
        self::resetClassState();
    }

    private static function resetClassState(): void
    {
        self::$stateOwner        = null;
        self::$sharedProvisioner = null;
        self::$sharedOutcome     = null;
        self::$sharedSpec        = null;
        self::$sharedVerdict     = null;
        self::$sharedMigrated    = false;
        self::$sharedFailure     = null;
    }

    /**
     * Drop every table in a supplied schema.
     *
     * Safe because the isolation guard confirmed the schema was empty before
     * the first migration, so everything present was created by this run.
     */
    private static function dropAllTables(ConnectionSpec $spec): void
    {
        try {
            $pdo = new PDO(
                "mysql:host={$spec->host};port={$spec->port};dbname={$spec->database}",
                $spec->username,
                $spec->password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            $tables = $pdo->query(
                'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES '
                . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
            )->fetchAll(PDO::FETCH_COLUMN);

            if ($tables === []) {
                return;
            }

            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($tables as $table) {
                $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '', (string) $table) . '`');
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        } catch (PDOException) {
            // Best effort: the next class's isolation guard reports a schema
            // that was not cleared, which is the diagnostic that matters.
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
            self::$sharedFailure = 'Migration failed: ' . Artisan::output();
            $this->fail(self::$sharedFailure);
            return;
        }

        self::$sharedMigrated = true;
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

        // FR-017/G6: a run states how many checks actually reached the engine,
        // so "ran and passed" is distinguishable from "skipped and green".
        SkipReport::recordExecuted();
    }

    /**
     * Handle an unavailable outcome (skip or fail depending on strict mode).
     */
    private function handleUnavailable(): void
    {
        $this->skippedClass = true;
        $reason = $this->outcome ? $this->outcome->diagnostic() : 'Database unavailable';

        $strict = getenv('LLM_CLIENT_REAL_DB_STRICT');
        if ($strict === '1' || $strict === 'true') {
            // G5: strict mode removes the skip, so the run must not report one.
            $this->fail("Strict mode: {$reason}");
        }

        SkipReport::recordSkipped($reason);
        $this->markTestSkipped($reason);
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
