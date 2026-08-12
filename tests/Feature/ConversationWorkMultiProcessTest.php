<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\LlmClient\LlmClientServiceProvider;
use Orchestra\Testbench\TestCase;
use Symfony\Component\Process\Process;

use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Test;

/**
 * The literal content of "the ceiling holds exactly under concurrent bursts
 * and across separate server processes", proven by genuinely separate OS
 * processes writing to a genuine, file-backed shared cache store — not by
 * anything inside one PHPUnit process, where every "process" sharing PHP's
 * own memory would let a race hide.
 *
 * Modeled directly on the sibling rate limiter's own multi-process proof: a
 * throwaway SQLite file backs Laravel's database cache store, and real
 * subprocesses each independently call ConversationWorkCounter::increment()
 * against the same shared counter key. Because Cache::increment() is atomic
 * per the store's own transaction, and ConversationWorkCounter treats the
 * atomically-assigned post-increment value as the count itself, every one
 * of N concurrently started processes must receive a distinct integer from
 * 1..N — never a duplicate, never a gap — and comparing each against a
 * configured max_work_units is then exact, not approximate.
 *
 * This feature's own call pattern differs from the rate limiter's in one
 * respect the rate limiter's own test does not itself exercise: a single
 * response can call increment() many times against the same key — once per
 * tool call in a burst — not once per process. The dedicated burst test
 * below proves atomicity for that call pattern specifically: one process
 * incrementing the same key repeatedly, interleaved with several other
 * processes each incrementing that same key once, must still yield a
 * strictly increasing, gapless sequence across the combined burst.
 */
class ConversationWorkMultiProcessTest extends TestCase
{
    private ?string $tempDbPath = null;

    protected function getPackageProviders($app): array
    {
        return [LlmClientServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        if (!isset($this->tempDbPath)) {
            $this->tempDbPath = sys_get_temp_dir().'/conversation_work_test_'.uniqid().'.sqlite';
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
        $this->tempDbPath = sys_get_temp_dir().'/conversation_work_test_'.uniqid().'.sqlite';

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

        // The ceiling configuration itself, so a subprocess can resolve a
        // real ceiling and reach a real decision rather than having the
        // comparison performed for it by the parent test.
        $pdo->exec('CREATE TABLE IF NOT EXISTS conversation_work_ceilings (
            id VARCHAR PRIMARY KEY,
            scope_type VARCHAR,
            scope_id VARCHAR,
            max_work_units INTEGER,
            window_seconds INTEGER,
            waived TINYINT DEFAULT 0,
            created_at DATETIME,
            updated_at DATETIME,
            deleted_at DATETIME
        )');

        $pdo = null;
    }

    /**
     * Write a conversation-scoped ceiling straight into the scratch file the
     * subprocesses share, so each one resolves it independently.
     */
    private function declareConversationCeiling(string $conversationId, int $maxWorkUnits, int $windowSeconds): void
    {
        $pdo = new \PDO('sqlite:'.$this->tempDbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $statement = $pdo->prepare(
            'INSERT INTO conversation_work_ceilings
                (id, scope_type, scope_id, max_work_units, window_seconds, waived, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 0, ?, ?)'
        );

        $now = date('Y-m-d H:i:s');
        $statement->execute([
            (string) \Illuminate\Support\Str::uuid(),
            'conversation',
            $conversationId,
            $maxWorkUnits,
            $windowSeconds,
            $now,
            $now,
        ]);

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
    public function exactly_max_work_units_succeed_and_the_rest_are_refused_across_genuine_concurrent_processes(): void
    {
        $conversationId = 'conversation-multiproc-'.uniqid();
        $windowSeconds = 3600; // wide enough that the whole burst falls in one window
        $maxWorkUnits = 3;
        $processCount = 10;

        $scripts = [];
        for ($i = 0; $i < $processCount; $i++) {
            $scripts[] = $this->makeIncrementWorkerScript($conversationId, $windowSeconds);
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
        // work unit #1) and never a gap (an increment silently lost).
        $this->assertSame(
            range(1, $processCount),
            $counts,
            'Every concurrently started process must receive a distinct, gapless post-increment count'
        );

        $succeeded = array_filter($counts, fn ($count) => $count <= $maxWorkUnits);
        $refused = array_filter($counts, fn ($count) => $count > $maxWorkUnits);

        $this->assertCount(
            $maxWorkUnits,
            $succeeded,
            "Exactly max_work_units ({$maxWorkUnits}) of the burst must fall within the allowance"
        );
        $this->assertCount(
            $processCount - $maxWorkUnits,
            $refused,
            'Every work unit past max_work_units must be identifiable as over the ceiling from its count alone'
        );
    }

    #[Test]
    public function a_different_conversation_sharing_the_same_burst_window_is_counted_independently(): void
    {
        $conversationA = 'conversation-multiproc-a-'.uniqid();
        $conversationB = 'conversation-multiproc-b-'.uniqid();
        $windowSeconds = 3600;

        $scripts = [];
        for ($i = 0; $i < 4; $i++) {
            $scripts[] = $this->makeIncrementWorkerScript($conversationA, $windowSeconds);
        }
        for ($i = 0; $i < 4; $i++) {
            $scripts[] = $this->makeIncrementWorkerScript($conversationB, $windowSeconds);
        }

        $processes = [];
        foreach ($scripts as $script) {
            $process = Process::fromShellCommandline(PHP_BINARY.' '.escapeshellarg($script), dirname(__DIR__));
            $process->start();
            $processes[] = $process;
        }

        $countsByConversation = ['A' => [], 'B' => []];
        foreach ($processes as $index => $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), 'Subprocess failed: '.$process->getErrorOutput());

            $data = json_decode(trim($process->getOutput()), true);
            $countsByConversation[$index < 4 ? 'A' : 'B'][] = $data['count'];
        }

        sort($countsByConversation['A']);
        sort($countsByConversation['B']);

        $this->assertSame([1, 2, 3, 4], $countsByConversation['A'], "One conversation's burst must not be perturbed by another conversation's");
        $this->assertSame([1, 2, 3, 4], $countsByConversation['B'], "One conversation's burst must not be perturbed by another conversation's");
    }

    /**
     * This feature's own respect in which a single response calls
     * increment() many times against the same key, unlike the sibling rate
     * limiter's at-most-twice-per-request call pattern: one process bursts
     * five increments in a tight loop against the same key, interleaved
     * with five other processes each incrementing that same key once. The
     * combined ten results must still be a strictly increasing, gapless
     * 1..10 sequence — atomicity holding across both a burst-within-one-
     * process and a burst-across-many-processes at once.
     */
    #[Test]
    public function a_single_process_burst_interleaved_with_other_processes_still_yields_a_gapless_sequence(): void
    {
        $conversationId = 'conversation-multiproc-burst-'.uniqid();
        $windowSeconds = 3600;
        $burstSize = 5;
        $singleIncrementWorkerCount = 5;

        $scripts = [$this->makeBurstWorkerScript($conversationId, $windowSeconds, $burstSize)];
        for ($i = 0; $i < $singleIncrementWorkerCount; $i++) {
            $scripts[] = $this->makeIncrementWorkerScript($conversationId, $windowSeconds);
        }

        $processes = [];
        foreach ($scripts as $script) {
            $process = Process::fromShellCommandline(PHP_BINARY.' '.escapeshellarg($script), dirname(__DIR__));
            $process->start();
            $processes[] = $process;
        }

        $allCounts = [];
        foreach ($processes as $index => $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), 'Subprocess failed: '.$process->getErrorOutput());

            $data = json_decode(trim($process->getOutput()), true);
            $this->assertIsArray($data, 'Worker output was not valid JSON: '.$process->getOutput());

            if ($index === 0) {
                // The burst worker: an array of counts, one per increment
                // in its own tight loop.
                $this->assertTrue($data['available']);
                $counts = $data['counts'];
                $this->assertCount($burstSize, $counts, 'The burst worker must have performed every increment in its own loop');

                // Within the burst worker's own sequential loop, its own
                // counts must themselves be strictly increasing — no
                // increment silently skipped or repeated within one process.
                $sortedOwn = $counts;
                sort($sortedOwn);
                $this->assertSame($counts, $sortedOwn, "The burst worker's own sequence must already be increasing before merging with the other processes'");

                array_push($allCounts, ...$counts);
            } else {
                $this->assertTrue($data['available']);
                $allCounts[] = $data['count'];
            }
        }

        sort($allCounts);

        $totalIncrements = $burstSize + $singleIncrementWorkerCount;
        $this->assertSame(
            range(1, $totalIncrements),
            $allCounts,
            'The combined sequence across a single-process burst and several other concurrent processes must be strictly increasing with no duplicate and no gap'
        );
    }

    /**
     * The two cases above prove the counting *primitive* is atomic across
     * processes; the gate's own boundary comparison is proven, separately,
     * by ConversationWorkGateTest in a single process. FR-009 is the
     * conjunction of the two — "the ceiling is enforced accurately no matter
     * which process does the work" — and nothing so far exercises the
     * conjunction itself: a mutation to the comparison would leave both of
     * those tests' own properties intact from the other's point of view.
     *
     * This case closes that: every subprocess resolves a real ceiling from
     * the shared configuration table and reaches a real
     * ConversationWorkDecision, with no comparison performed by the parent
     * test at all. Exactly max_work_units of the concurrent burst may be
     * allowed, and every remaining process must be stopped — whatever order
     * they happen to arrive in.
     */
    #[Test]
    public function the_gate_itself_admits_exactly_max_work_units_across_genuine_concurrent_processes(): void
    {
        $conversationId = (string) \Illuminate\Support\Str::uuid();
        $windowSeconds = 3600;
        $maxWorkUnits = 3;
        $processCount = 10;

        $this->declareConversationCeiling($conversationId, $maxWorkUnits, $windowSeconds);

        $processes = [];
        for ($i = 0; $i < $processCount; $i++) {
            $script = $this->makeGateWorkerScript($conversationId);
            $process = Process::fromShellCommandline(PHP_BINARY.' '.escapeshellarg($script), dirname(__DIR__));
            $process->start();
            $processes[] = $process;
        }

        $outcomes = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), 'Subprocess failed: '.$process->getErrorOutput());

            $data = json_decode(trim($process->getOutput()), true);
            $this->assertIsArray($data, 'Worker output was not valid JSON: '.$process->getOutput());
            $this->assertTrue(
                $data['ceiling_resolved'],
                'Precondition: every worker must have resolved the shared ceiling, or it decided nothing at all'
            );

            $outcomes[] = $data['outcome'];
        }

        $allowed = array_filter($outcomes, fn ($outcome) => $outcome === 'allow');
        $stopped = array_filter($outcomes, fn ($outcome) => $outcome === 'stop');

        $this->assertCount(
            $maxWorkUnits,
            $allowed,
            'Exactly max_work_units work units may be admitted across every process combined'
        );
        $this->assertCount(
            $processCount - $maxWorkUnits,
            $stopped,
            'Every work unit past the ceiling must be stopped, whichever process happens to carry it'
        );
    }

    // ------------------------------------------------------------------
    // Worker scripts
    // ------------------------------------------------------------------

    private function makeIncrementWorkerScript(string $conversationId, int $windowSeconds): string
    {
        $scriptFile = sys_get_temp_dir().'/conversation_work_worker_'.uniqid('', true).'.php';
        $counterClass = '\\ClarionApp\\LlmClient\\Services\\ConversationWorkCounter';

        $code = $this->getBootstrapTemplate();
        $code .= <<<PHP
\$counter = new {$counterClass}();

// SQLite's own file-level (not row-level) locking means a handful of
// genuinely simultaneous OS processes contending for the same counter row
// can transiently see "database is locked" — a storage-engine artifact of
// the disposable scratch file this test uses in place of the real
// database/Redis-backed store a deployment would run, not a defect in the
// counting protocol itself. The retry below is a test-harness concern:
// ConversationWorkCounter's own fail-open contract stays exactly as built,
// and each attempt here is still a genuine, independently issued increment
// — there is no coordination between workers, only persistence in the face
// of the scratch file's coarser locking.
\$reading = null;
for (\$attempt = 0; \$attempt < 25; \$attempt++) {
    \$reading = \$counter->increment("{$conversationId}", {$windowSeconds});
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

    private function makeBurstWorkerScript(string $conversationId, int $windowSeconds, int $burstSize): string
    {
        $scriptFile = sys_get_temp_dir().'/conversation_work_burst_worker_'.uniqid('', true).'.php';
        $counterClass = '\\ClarionApp\\LlmClient\\Services\\ConversationWorkCounter';

        $code = $this->getBootstrapTemplate();
        $code .= <<<PHP
\$counter = new {$counterClass}();
\$counts = [];

for (\$i = 0; \$i < {$burstSize}; \$i++) {
    \$reading = null;
    for (\$attempt = 0; \$attempt < 25; \$attempt++) {
        \$reading = \$counter->increment("{$conversationId}", {$windowSeconds});
        if (\$reading->available) {
            break;
        }
        usleep(random_int(5000, 20000));
    }

    if (!\$reading->available) {
        echo json_encode(['available' => false]) . "\n";
        exit(0);
    }

    \$counts[] = \$reading->count;
}

echo json_encode([
    'counts' => \$counts,
    'available' => true,
]) . "\n";
PHP;

        file_put_contents($scriptFile, $code);

        return $scriptFile;
    }

    /**
     * A worker that reaches a genuine ConversationWorkDecision: it resolves
     * the ceiling from the shared configuration table itself and compares
     * against its own atomically assigned count, exactly as an in-loop call
     * site does. The retry loop is the same scratch-SQLite locking
     * accommodation the increment workers carry, and only ever retries a
     * decision the gate reported as unmeasurable — never one it decided.
     */
    private function makeGateWorkerScript(string $conversationId): string
    {
        $scriptFile = sys_get_temp_dir().'/conversation_work_gate_worker_'.uniqid('', true).'.php';
        $gateClass = '\\ClarionApp\\LlmClient\\Services\\ConversationWorkGate';
        $serviceClass = '\\ClarionApp\\LlmClient\\Services\\ConversationWorkCeilingService';
        $counterClass = '\\ClarionApp\\LlmClient\\Services\\ConversationWorkCounter';

        $code = $this->getBootstrapTemplate();
        $code .= <<<PHP
\Illuminate\Database\Eloquent\Model::setConnectionResolver(\$app['db']);
\Illuminate\Database\Eloquent\Model::setEventDispatcher(\$app['events']);

\$ceilings = new {$serviceClass}();
\$gate = new {$gateClass}(\$ceilings, new {$counterClass}());

\$decision = null;
for (\$attempt = 0; \$attempt < 25; \$attempt++) {
    \$decision = \$gate->evaluate("{$conversationId}");

    // An unmeasurable counter fails open by design, so a decision reached
    // that way says nothing about the ceiling — retry rather than record it.
    if (\$decision->reading !== null && \$decision->reading->available) {
        break;
    }
    usleep(random_int(5000, 20000));
}

echo json_encode([
    'outcome' => \$decision->outcome,
    'ceiling_resolved' => \$decision->ceiling !== null,
    'count' => \$decision->reading?->count,
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
\$config->set('llm-client.conversation_work.store', null);
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
