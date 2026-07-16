<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\LlmClient\LlmClientServiceProvider;
use ClarionApp\LlmClient\Services\OperationCache;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Symfony\Component\Process\Process;

use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Test;

class OperationCacheMultiProcessTest extends TestCase
{
    private ?string $tempDbPath = null;

    protected function getPackageProviders($app): array
    {
        return [LlmClientServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Ensure tempDbPath is set (may already be set by #[Before])
        if (!isset($this->tempDbPath)) {
            $this->tempDbPath = sys_get_temp_dir() . '/op_cache_test_' . uniqid() . '.sqlite';
        }

        $app['config']->set('database.default', 'test_sqlite');
        $app['config']->set('database.connections.test_sqlite', [
            'driver'   => 'sqlite',
            'database' => $this->tempDbPath,
            'prefix'   => '',
        ]);

        $app['config']->set('cache.stores.database', [
            'driver'     => 'database',
            'table'      => 'cache',
            'connection' => 'test_sqlite',
            'lock_table' => 'cache_locks',
        ]);

        $app['config']->set('cache.default', 'database');

        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('eloquent-multichain-bridge.disabled', true);
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
    protected function buildCacheTables(): void
    {
        // Initialize temp DB path first (#[Before] runs before getEnvironmentSetUp in testbench)
        if (file_exists($this->tempDbPath ?? '')) {
            unlink($this->tempDbPath);
        }
        $this->tempDbPath = sys_get_temp_dir() . '/op_cache_test_' . uniqid() . '.sqlite';

        // Create tables using raw SQLite connection
        $pdo = new \PDO('sqlite:' . $this->tempDbPath);
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
    public function put_in_one_process_visible_in_another()
    {
        $conversationId = 'conv-multi-' . uniqid();

        // Subprocess A: put an operation into the shared cache
        $scriptA = $this->makeWorkerScript('put', $conversationId, 'create-contact', 'POST', '/contacts', 'Create a new contact');
        $processA = Process::fromShellCommandline(PHP_BINARY . ' ' . escapeshellarg($scriptA), dirname(__DIR__));
        $processA->run();
        $this->assertTrue($processA->isSuccessful(), "Subprocess A failed: " . $processA->getErrorOutput());

        // Subprocess B: read the operation back
        $scriptB = $this->makeWorkerScript('summaries', $conversationId);
        $processB = Process::fromShellCommandline(PHP_BINARY . ' ' . escapeshellarg($scriptB), dirname(__DIR__));
        $processB->run();
        $this->assertTrue($processB->isSuccessful(), "Subprocess B failed: " . $processB->getErrorOutput());

        $output = trim($processB->getOutput());
        $data = json_decode($output, true);

        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertStringContainsString('create-contact (POST /contacts)', $data[0]);
    }

    #[Test]
    public function concurrent_writers_preserve_all_entries()
    {
        $conversationId = 'conv-concurrent-' . uniqid();
        $writerCount = 5;

        $scripts = [];
        for ($i = 1; $i <= $writerCount; $i++) {
            $scripts[] = $this->makeWorkerScript('put', $conversationId, 'op-' . $i, 'GET', '/op/' . $i, 'Operation ' . $i);
        }

        // Run all writers in parallel
        $writers = [];
        foreach ($scripts as $script) {
            $writers[] = Process::fromShellCommandline(PHP_BINARY . ' ' . escapeshellarg($script), dirname(__DIR__));
            end($writers)->start();
        }

        foreach ($writers as $writer) {
            $writer->wait();
            $this->assertTrue($writer->isSuccessful(), "Writer failed: " . $writer->getErrorOutput());
        }

        // Read back and verify no entries lost
        $readScript = $this->makeWorkerScript('entries', $conversationId);
        $reader = Process::fromShellCommandline(PHP_BINARY . ' ' . escapeshellarg($readScript), dirname(__DIR__));
        $reader->run();
        $this->assertTrue($reader->isSuccessful(), "Reader failed: " . $reader->getErrorOutput());

        $output = trim($reader->getOutput());
        $data = json_decode($output, true);

        $this->assertGreaterThanOrEqual($writerCount, count($data));

        $operationIds = array_column($data, 'operationId');
        for ($i = 1; $i <= $writerCount; $i++) {
            $this->assertContains('op-' . $i, $operationIds, "op-{$i} should not be lost");
        }
    }

    #[Test]
    public function cross_process_eviction_respects_lru()
    {
        $conversationId = 'conv-evict-' . uniqid();
        $maxEntries = 3;

        // Subprocess A: fill cache to max
        $fillScript = $this->makeFillScript($conversationId, $maxEntries);
        $processA = Process::fromShellCommandline(PHP_BINARY . ' ' . escapeshellarg($fillScript), dirname(__DIR__));
        $processA->run();
        $this->assertTrue($processA->isSuccessful(), "Process A failed: " . $processA->getErrorOutput());

        // Subprocess B: add one more (should evict op-1, the LRU)
        $evictScript = $this->makeWorkerScript('put', $conversationId, 'op-new', 'GET', '/op/new', 'New operation', $maxEntries);
        $processB = Process::fromShellCommandline(PHP_BINARY . ' ' . escapeshellarg($evictScript), dirname(__DIR__));
        $processB->run();
        $this->assertTrue($processB->isSuccessful(), "Process B failed: " . $processB->getErrorOutput());

        // Subprocess C: verify op-1 is gone and cap holds
        $checkScript = $this->makeCheckScript($conversationId, $maxEntries);
        $processC = Process::fromShellCommandline(PHP_BINARY . ' ' . escapeshellarg($checkScript), dirname(__DIR__));
        $processC->run();
        $this->assertTrue($processC->isSuccessful(), "Process C failed: " . $processC->getErrorOutput());

        $output = trim($processC->getOutput());
        $data = json_decode($output, true);

        $this->assertFalse($data['op1_exists'], 'op-1 should be evicted as LRU');
        $this->assertEquals($maxEntries, $data['count'], 'Cache should still be at max capacity');
    }

    #[Test]
    public function cross_process_readd_after_eviction()
    {
        $conversationId = 'conv-readd-' . uniqid();
        $maxEntries = 2;

        // Process A: fill cache with op-a, op-b
        $fillScript = $this->makeFillScript($conversationId, $maxEntries, 'op-a', 'op-b');
        $processA = Process::fromShellCommandline(PHP_BINARY . ' ' . escapeshellarg($fillScript), dirname(__DIR__));
        $processA->run();
        $this->assertTrue($processA->isSuccessful(), "Process A failed: " . $processA->getErrorOutput());

        // Process B: add op-c (evicts op-a)
        $evictScript = $this->makeWorkerScript('put', $conversationId, 'op-c', 'GET', '/c', 'C', $maxEntries);
        $processB = Process::fromShellCommandline(PHP_BINARY . ' ' . escapeshellarg($evictScript), dirname(__DIR__));
        $processB->run();
        $this->assertTrue($processB->isSuccessful(), "Process B failed: " . $processB->getErrorOutput());

        // Process C: re-add op-a with new details
        $readdScript = $this->makeWorkerScript('put', $conversationId, 'op-a', 'POST', '/a/v2', 'A (re-added)', $maxEntries);
        $processC = Process::fromShellCommandline(PHP_BINARY . ' ' . escapeshellarg($readdScript), dirname(__DIR__));
        $processC->run();
        $this->assertTrue($processC->isSuccessful(), "Process C failed: " . $processC->getErrorOutput());

        // Process D: verify op-a is at MRU with new details
        $checkScript = $this->makeReaddCheckScript($conversationId);
        $processD = Process::fromShellCommandline(PHP_BINARY . ' ' . escapeshellarg($checkScript), dirname(__DIR__));
        $processD->run();
        $this->assertTrue($processD->isSuccessful(), "Process D failed: " . $processD->getErrorOutput());

        $output = trim($processD->getOutput());
        $data = json_decode($output, true);

        $this->assertEquals('A (re-added)', $data['op_a_summary']);
        $this->assertEquals('POST', $data['op_a_method']);
        $this->assertEquals('op-a', $data['first_entry'], 'op-a should be at MRU (first in getEntries)');
    }

    // ------------------------------------------------------------------
    // Helper methods for creating subprocess scripts
    // ------------------------------------------------------------------

    /**
     * Create a simple worker script that performs a single operation.
     */
    private function makeWorkerScript(string $action, string $conversationId, string $opId = '', string $method = '', string $path = '', string $summary = '', ?int $maxEntries = null): string
    {
        $scriptFile = sys_get_temp_dir() . '/op_cache_worker_' . uniqid() . '.php';
        $cacheClass = '\\ClarionApp\\LlmClient\\Services\\OperationCache';
        $maxEntriesLine = $maxEntries !== null ? "new {$cacheClass}({$maxEntries}, \$store)" : "new {$cacheClass}(null, \$store)";

        $code = $this->getBootstrapTemplate();

        if ($action === 'put') {
            $code .= <<<PHP
\$cache = {$maxEntriesLine};
\$cache->put("{$conversationId}", "{$opId}", [
    'operationId' => "{$opId}",
    'summary'     => "{$summary}",
    'method'      => "{$method}",
    'path'        => "{$path}",
    'paramSchema' => null,
]);
echo "OK\n";
PHP;
        } elseif ($action === 'summaries') {
            $code .= <<<PHP
\$cache = {$maxEntriesLine};
\$summaries = \$cache->getSummaries("{$conversationId}");
echo json_encode(\$summaries) . "\n";
PHP;
        } elseif ($action === 'entries') {
            $code .= <<<PHP
\$cache = {$maxEntriesLine};
\$entries = \$cache->getEntries("{$conversationId}");
echo json_encode(\$entries) . "\n";
PHP;
        }

        file_put_contents($scriptFile, $code);
        return $scriptFile;
    }

    /**
     * Create a script that fills the cache with N entries.
     */
    private function makeFillScript(string $conversationId, int $maxEntries, string $firstOp = 'op-1', string $secondOp = 'op-2'): string
    {
        $scriptFile = sys_get_temp_dir() . '/op_cache_fill_' . uniqid() . '.php';
        $cacheClass = '\\ClarionApp\\LlmClient\\Services\\OperationCache';

        $puts = '';
        for ($i = 1; $i <= $maxEntries; $i++) {
            $opName = 'op-' . $i;
            if ($i === 1) $opName = $firstOp;
            if ($i === 2) $opName = $secondOp;
            $puts .= <<<PHP
\$cache->put("{$conversationId}", "{$opName}", [
    'operationId' => "{$opName}",
    'summary'     => 'Operation {$i}',
    'method'      => 'GET',
    'path'        => '/op/{$i}',
    'paramSchema' => null,
]);
PHP;
        }

        $code = $this->getBootstrapTemplate();
        $code .= <<<PHP
\$cache = new {$cacheClass}({$maxEntries}, \$store);
{$puts}
echo "OK\n";
PHP;

        file_put_contents($scriptFile, $code);
        return $scriptFile;
    }

    /**
     * Create a script that checks for LRU eviction results.
     */
    private function makeCheckScript(string $conversationId, int $maxEntries): string
    {
        $scriptFile = sys_get_temp_dir() . '/op_cache_check_' . uniqid() . '.php';
        $cacheClass = '\\ClarionApp\\LlmClient\\Services\\OperationCache';
        $code = $this->getBootstrapTemplate();
        $code .= <<<PHP
\$cache = new {$cacheClass}({$maxEntries}, \$store);
\$op1 = \$cache->get("{$conversationId}", 'op-1');
\$count = \$cache->count("{$conversationId}");
echo json_encode(['op1_exists' => \$op1 !== null, 'count' => \$count]) . "\n";
PHP;

        file_put_contents($scriptFile, $code);
        return $scriptFile;
    }

    /**
     * Create a script that checks re-add results.
     */
    private function makeReaddCheckScript(string $conversationId): string
    {
        $scriptFile = sys_get_temp_dir() . '/op_cache_readd_check_' . uniqid() . '.php';
        $cacheClass = '\\ClarionApp\\LlmClient\\Services\\OperationCache';
        $code = $this->getBootstrapTemplate();
        $code .= <<<PHP
\$cache = new {$cacheClass}(null, \$store);
\$entry = \$cache->get("{$conversationId}", 'op-a');
\$entries = \$cache->getEntries("{$conversationId}");
echo json_encode([
    'op_a_summary' => \$entry['summary'] ?? null,
    'op_a_method'  => \$entry['method'] ?? null,
    'first_entry'  => \$entries[0]['operationId'] ?? null,
]) . "\n";
PHP;

        file_put_contents($scriptFile, $code);
        return $scriptFile;
    }

    /**
     * Bootstrap template that all subprocess scripts use.
     */
    private function getBootstrapTemplate(): string
    {
        $packageRoot = realpath(__DIR__ . '/../../');
        $appId = base64_encode(random_bytes(32));
        $dbPath = $this->tempDbPath;

        return "<?php
require_once \"{$packageRoot}/vendor/autoload.php\";

\$app = new Illuminate\\Foundation\\Application(\"{$packageRoot}/vendor\");

// Register config repository first
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

// Set config values before registering providers
\$config = \$app['config'];
\$config->set('database.default', 'test_sqlite');
\$config->set('database.connections.test_sqlite', [
    'driver' => 'sqlite',
    'database' => '{$dbPath}',
]);
\$config->set('cache.default', 'database');
\$config->set('cache.stores.database', [
    'driver' => 'database',
    'table' => 'cache',
    'connection' => 'test_sqlite',
    'lock_table' => 'cache_locks',
]);
\$config->set('app.key', 'base64:{$appId}');
\$config->set('eloquent-multichain-bridge.disabled', true);

// Register core service providers
\$app->register(Illuminate\\Foundation\\Providers\\FoundationServiceProvider::class);
\$app->register(Illuminate\\Database\\DatabaseServiceProvider::class);
\$app->register(Illuminate\\Cache\\CacheServiceProvider::class);

\$app->register(\\ClarionApp\\LlmClient\\LlmClientServiceProvider::class);

\$store = \$app->make(\\Illuminate\\Contracts\\Cache\\Repository::class);
";
    }
}
