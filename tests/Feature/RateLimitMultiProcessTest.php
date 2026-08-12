<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\LlmClient\LlmClientServiceProvider;
use Orchestra\Testbench\TestCase;
use Symfony\Component\Process\Process;

use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Test;

/**
 * The literal content of "the limit holds exactly under concurrent bursts
 * and across separate server processes" (FR-006/FR-007), proven by
 * genuinely separate OS processes writing to a genuine, file-backed shared
 * cache store — not by anything inside one PHPUnit process, where every
 * "process" sharing PHP's own memory would let a race hide.
 *
 * Modeled directly on OperationCacheMultiProcessTest: a throwaway SQLite
 * file backs Laravel's database cache store, and real subprocesses each
 * independently call RateLimitCounter::increment() against the same shared
 * counter key. Because Cache::increment() is atomic per the store's own
 * transaction, and RateLimitCounter treats the atomically-assigned
 * post-increment value as the count itself, every one of N concurrently
 * started processes must receive a distinct integer from 1..N — never a
 * duplicate, never a gap — and comparing each against a configured
 * max_requests is then exact, not approximate.
 */
class RateLimitMultiProcessTest extends TestCase
{
    private ?string $tempDbPath = null;

    protected function getPackageProviders($app): array
    {
        return [LlmClientServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        if (!isset($this->tempDbPath)) {
            $this->tempDbPath = sys_get_temp_dir().'/rate_limit_test_'.uniqid().'.sqlite';
        }

        $app['config']->set('database.default', 'test_sqlite');
        $app['config']->set('database.connections.test_sqlite', [
            'driver' => 'sqlite',
            'database' => $this->tempDbPath,
            'prefix' => '',
        ]);

        $app['config']->set('cache.stores.database', [
            'driver' => 'database',
            'table' => 'cache',
            'connection' => 'test_sqlite',
            'lock_table' => 'cache_locks',
        ]);

        $app['config']->set('cache.default', 'database');

        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('eloquent-multichain-bridge.disabled', true);
    }

    protected function defineEnvironment($app): void
    {
        if (!class_exists('App\Http\Controllers\Controller')) {
            eval('namespace App\Http\Controllers { class Controller { } }');
        }

        $app->singleton('multichain', function () {
            return new class {
                public function __call($method, $arguments)
                {
                    return null;
                }

                public function publish($stream, $key, $value)
                {
                    return 'stub-txid';
                }

                public function liststreams($stream)
                {
                    throw new \Exception('not found');
                }

                public function create($type, $name, $private)
                {
                    return null;
                }

                public function subscribe($stream)
                {
                    return null;
                }
            };
        });
    }

    #[Before]
    protected function buildCacheTables(): void
    {
        if (file_exists($this->tempDbPath ?? '')) {
            unlink($this->tempDbPath);
        }
        $this->tempDbPath = sys_get_temp_dir().'/rate_limit_test_'.uniqid().'.sqlite';

        $pdo = new \PDO('sqlite:'.$this->tempDbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $pdo->exec('CREATE TABLE IF NOT EXISTS cache (
            key VARCHAR PRIMARY KEY,
            value MEDIUMTEXT,
            expiration INTEGER
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS cache_locks (
            key VARCHAR PRIMARY KEY,
            owner VARCHAR,
            expiration INTEGER
        )');

        $pdo = null;
    }

    #[After]
    protected function cleanupTempDb(): void
    {
        if (isset($this->tempDbPath) && file_exists($this->tempDbPath)) {
            unlink($this->tempDbPath);
        }
    }

    #[Test]
    public function exactly_max_requests_succeed_and_the_rest_are_refused_across_genuine_concurrent_processes(): void
    {
        $userId = 'user-multiproc-'.uniqid();
        $windowSeconds = 3600; // wide enough that the whole burst falls in one window
        $maxRequests = 3;
        $processCount = 10;

        $scripts = [];
        for ($i = 0; $i < $processCount; $i++) {
            $scripts[] = $this->makeIncrementWorkerScript($userId, $windowSeconds);
        }

        $processes = [];
        foreach ($scripts as $script) {
            $process = Process::fromShellCommandline(PHP_BINARY.' '.escapeshellarg($script), dirname(__DIR__));
            $process->start();
            $processes[] = $process;
        }

        $counts = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), 'Subprocess failed: '.$process->getErrorOutput());

            $output = trim($process->getOutput());
            $data = json_decode($output, true);

            $this->assertIsArray($data, "Worker output was not valid JSON: {$output}");
            $this->assertTrue($data['available'], 'The counter must report available under a healthy shared store');

            $counts[] = $data['count'];
        }

        sort($counts);

        // Atomicity means the N processes receive N distinct consecutive
        // integers — never a duplicate (two processes both told they were
        // admission #1) and never a gap (an increment silently lost).
        $this->assertSame(
            range(1, $processCount),
            $counts,
            'Every concurrently started process must receive a distinct, gapless post-increment count'
        );

        $succeeded = array_filter($counts, fn ($count) => $count <= $maxRequests);
        $refused = array_filter($counts, fn ($count) => $count > $maxRequests);

        $this->assertCount(
            $maxRequests,
            $succeeded,
            "Exactly max_requests ({$maxRequests}) of the burst must fall within the allowance"
        );
        $this->assertCount(
            $processCount - $maxRequests,
            $refused,
            'Every request past max_requests must be identifiable as over the limit from its count alone'
        );
    }

    #[Test]
    public function a_different_user_sharing_the_same_burst_window_is_counted_independently(): void
    {
        $userA = 'user-multiproc-a-'.uniqid();
        $userB = 'user-multiproc-b-'.uniqid();
        $windowSeconds = 3600;

        $scripts = [];
        for ($i = 0; $i < 4; $i++) {
            $scripts[] = $this->makeIncrementWorkerScript($userA, $windowSeconds);
        }
        for ($i = 0; $i < 4; $i++) {
            $scripts[] = $this->makeIncrementWorkerScript($userB, $windowSeconds);
        }

        $processes = [];
        foreach ($scripts as $script) {
            $process = Process::fromShellCommandline(PHP_BINARY.' '.escapeshellarg($script), dirname(__DIR__));
            $process->start();
            $processes[] = $process;
        }

        $countsByUser = ['A' => [], 'B' => []];
        foreach ($processes as $index => $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), 'Subprocess failed: '.$process->getErrorOutput());

            $data = json_decode(trim($process->getOutput()), true);
            $countsByUser[$index < 4 ? 'A' : 'B'][] = $data['count'];
        }

        sort($countsByUser['A']);
        sort($countsByUser['B']);

        $this->assertSame([1, 2, 3, 4], $countsByUser['A'], "One user's burst must not be perturbed by another user's");
        $this->assertSame([1, 2, 3, 4], $countsByUser['B'], "One user's burst must not be perturbed by another user's");
    }

    // ------------------------------------------------------------------
    // Worker script
    // ------------------------------------------------------------------

    private function makeIncrementWorkerScript(string $userId, int $windowSeconds): string
    {
        $scriptFile = sys_get_temp_dir().'/rate_limit_worker_'.uniqid('', true).'.php';
        $counterClass = '\\ClarionApp\\LlmClient\\Services\\RateLimitCounter';

        $code = $this->getBootstrapTemplate();
        $code .= <<<PHP
\$counter = new {$counterClass}();

// SQLite's own file-level (not row-level) locking means a handful of
// genuinely simultaneous OS processes contending for the same counter row
// can transiently see "database is locked" — a storage-engine artifact of
// the disposable scratch file this test uses in place of the real
// database/Redis-backed store a deployment would run, not a defect in the
// counting protocol itself. The retry below is a test-harness concern:
// RateLimitCounter's own fail-open contract stays exactly as built, and
// each attempt here is still a genuine, independently issued increment —
// there is no coordination between workers, only persistence in the face
// of the scratch file's coarser locking.
\$reading = null;
for (\$attempt = 0; \$attempt < 25; \$attempt++) {
    \$reading = \$counter->increment("{$userId}", {$windowSeconds});
    if (\$reading->available) {
        break;
    }
    usleep(random_int(5000, 20000));
}

echo json_encode([
    'count' => \$reading->count,
    'available' => \$reading->available,
]) . "\n";
PHP;

        file_put_contents($scriptFile, $code);

        return $scriptFile;
    }

    private function getBootstrapTemplate(): string
    {
        $packageRoot = realpath(__DIR__.'/../../');
        $appId = base64_encode(random_bytes(32));
        $dbPath = $this->tempDbPath;

        return "<?php
require_once \"{$packageRoot}/vendor/autoload.php\";

\$app = new Illuminate\\Foundation\\Application(\"{$packageRoot}/vendor\");
Illuminate\\Support\\Facades\\Facade::setFacadeApplication(\$app);

\$app->singleton('config', function () {
    return new Illuminate\\Config\\Repository([]);
});

\$app->singleton(\"multichain\", function () {
    return new class {
        public function __call(\$m, \$a) { return null; }
        public function publish(\$s, \$k, \$v) { return \"stub\"; }
        public function liststreams(\$s) { throw new \\Exception(\"not found\"); }
        public function create(\$t, \$n, \$p) { return null; }
        public function subscribe(\$s) { return null; }
    };
});

\$config = \$app['config'];
\$config->set('database.default', 'test_sqlite');
\$config->set('database.connections.test_sqlite', [
    'driver' => 'sqlite',
    'database' => '{$dbPath}',
    // A generous busy timeout, not the default of none: several genuinely
    // separate OS processes hit this one SQLite file at once, and without
    // it a writer that loses the race gets an immediate \"database is
    // locked\" error instead of waiting its turn — which this counter's
    // own fail-open catch would then quietly report as an unavailable
    // store, masking the very atomicity this test exists to prove.
    'options' => [\\PDO::ATTR_TIMEOUT => 10],
]);
\$config->set('cache.default', 'database');
\$config->set('cache.stores.database', [
    'driver' => 'database',
    'table' => 'cache',
    'connection' => 'test_sqlite',
    'lock_table' => 'cache_locks',
]);
\$config->set('llm-client.rate_limit.store', null);
\$config->set('app.key', 'base64:{$appId}');
\$config->set('eloquent-multichain-bridge.disabled', true);

\$app->register(Illuminate\\Foundation\\Providers\\FoundationServiceProvider::class);
\$app->register(Illuminate\\Database\\DatabaseServiceProvider::class);
\$app->register(Illuminate\\Cache\\CacheServiceProvider::class);
\$app->register(Illuminate\\Log\\LogServiceProvider::class);

\$app->register(\\ClarionApp\\LlmClient\\LlmClientServiceProvider::class);
";
    }
}
