<?php

namespace Tests\RealDatabase;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;

/**
 * The execution-lease claim on the engine production runs, with real
 * competing connections.
 *
 * The fast suite proves the claim atomic on a SQLite file by injecting one
 * worker's claim between another's read and write. SQLite decides that race
 * with a whole-database lock; MySQL/MariaDB decide it with a row lock under
 * InnoDB. Only the real engine can show the conditional UPDATE is enough
 * there too. Real operating-system processes, each with its own connection
 * and each resolving the real RunLease, are released together at a barrier
 * (the TaskBudgetClaimConcurrencyTest pattern):
 *
 *  - eight workers race for a run's dispatch id, repeatedly — exactly one
 *    claim wins every time, and the winner's nonce is the stored lease
 *    holder;
 *  - a stale dispatch id raced against the current one — only the current
 *    one wins;
 *  - quickstart M5b: a claim after handOff() (lease released) lands working
 *    with no takeover, and a claim over an expired held lease lands resuming
 *    with the interruption counted exactly once. This is the only test that
 *    can see MySQL's left-to-right SET evaluation (the claim's CASE reads
 *    lease_holder before the assignment rewrites it); SQLite cannot.
 */
#[Group('real-db')]
class RunLeaseClaimConcurrencyTest extends RealDatabaseTestCase
{
    protected array $seedTables = ['agent_runs'];

    /** Scratch directory holding this test's barrier files and worker scripts. */
    private ?string $scratch = null;

    /** @var array<int, string> */
    private array $workerStderr = [];

    protected function tearDown(): void
    {
        $this->removeScratch();

        parent::tearDown();
    }

    #[Test]
    public function eight_workers_racing_one_dispatch_exactly_one_wins(): void
    {
        $this->assertReady();

        for ($round = 1; $round <= 20; $round++) {
            $dispatchId = (string) Str::uuid();
            $runId = $this->seedRun(dispatchId: $dispatchId);

            $results = $this->runWorkers(8, $this->claimWorkerBody($runId, $dispatchId));

            $winners = 0;
            $winnerNonce = null;
            foreach ($results as $index => $result) {
                $this->assertSame('', trim($this->workerStderr[$index]), "Round {$round}, worker {$index} logged: ".$this->workerStderr[$index]);
                if ($result['won']) {
                    $winners++;
                    $winnerNonce = $result['nonce'];
                } else {
                    $this->assertSame('taken', $result['reason'], "Round {$round}, worker {$index} lost to a live lease");
                }
            }

            $this->assertSame(1, $winners, "Round {$round}: exactly one worker wins the dispatch");
            $this->assertSame($winnerNonce, DB::table('agent_runs')->where('id', $runId)->value('lease_holder'), "Round {$round}: the winner's nonce is the stored lease holder");
        }
    }

    #[Test]
    public function a_stale_dispatch_id_raced_against_the_current_one_only_the_current_wins(): void
    {
        $this->assertReady();

        $dispatchId = (string) Str::uuid();
        $stale = (string) Str::uuid();
        $runId = $this->seedRun(dispatchId: $dispatchId);

        $results = $this->runWorkers(2, $this->claimWorkerBody($runId, $dispatchId, $stale));

        foreach ($results as $index => $result) {
            $this->assertSame('', trim($this->workerStderr[$index]), "Worker {$index} logged: ".$this->workerStderr[$index]);
        }

        $this->assertTrue($results[0]['won'], 'the current dispatch id wins');
        $this->assertFalse($results[1]['won'], 'the stale dispatch id loses');
        $this->assertSame('stale_dispatch', $results[1]['reason'], 'the stale dispatch id is named');
    }

    #[Test]
    public function clean_handoff_is_working_on_real_engine(): void
    {
        $this->assertReady();

        // A working run, no lease held.
        $runId = $this->seedRun(dispatchId: '10000000-0000-0000-0000-000000000001', resumeState: 'working');
        $lease = app(\ClarionApp\LlmClient\Services\RunLease::class);

        // Claim it, then hand it off: the lease is released, a new dispatch id minted.
        $this->assertTrue($lease->claim($runId, '10000000-0000-0000-0000-000000000001')->won, 'precondition: the lease is held');
        $next = $lease->handOff($runId);

        // A claim after the hand-off (lease released): it lands working, no
        // takeover, the interruption count unchanged.
        $clean = $lease->claim($runId, $next);
        $row = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertTrue($clean->won, 'the claim after the hand-off wins');
        $this->assertFalse($clean->takeover, 'a released lease is not a takeover');
        $this->assertSame('working', $row->resume_state, 'it lands working');
        $this->assertSame(0, (int) $row->consecutive_interruptions, 'the count is unchanged');

        // An expired, still-held lease: the claim takes the run over and
        // counts the interruption exactly once.
        DB::table('agent_runs')->where('id', $runId)->update([
            'lease_holder' => '99999999-9999-9999-9999-999999999999',
            'lease_expires_at' => now()->subSeconds(1)->format('Y-m-d H:i:s.u'),
            'dispatch_id' => '30000000-0000-0000-0000-000000000001',
        ]);
        $takeover = $lease->claim($runId, '30000000-0000-0000-0000-000000000001');
        $row = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertTrue($takeover->won, 'the claim over the expired held lease wins');
        $this->assertTrue($takeover->takeover, 'an expired held lease is a takeover');
        $this->assertSame('resuming', $row->resume_state, 'it lands resuming');
        $this->assertSame(1, (int) $row->consecutive_interruptions, 'the interruption is counted exactly once');
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    /** A minimal in-progress run row. */
    private function seedRun(string $dispatchId, ?string $resumeState = null, ?string $leaseHolder = null, ?string $leaseExpiresAt = null): string
    {
        $id = (string) Str::uuid();
        DB::table('agent_runs')->insert([
            'id' => $id,
            'kind' => 'interactive',
            'user_id' => (string) Str::uuid(),
            'started_at' => now()->format('Y-m-d H:i:s.u'),
            'end_state' => 'in_progress',
            'dispatch_id' => $dispatchId,
            'resume_state' => $resumeState,
            'lease_holder' => $leaseHolder,
            'lease_expires_at' => $leaseExpiresAt,
            'consecutive_interruptions' => 0,
        ]);

        return $id;
    }

    // ---------------------------------------------------------------
    // Worker body
    // ---------------------------------------------------------------

    /**
     * $claims of $dispatchId (or $stale for worker 1) through the real,
     * container-resolved RunLease.
     */
    private function claimWorkerBody(string $runId, string $dispatchId, ?string $stale = null): string
    {
        return <<<PHP
        \$lease = \$app->make(\\ClarionApp\\LlmClient\\Services\\RunLease::class);

        clarion_barrier();

        \$dispatch = (\$argv[1] == '1' && {$this->quote($stale)}) ? {$this->quote($stale)} : {$this->quote($dispatchId)};
        \$result = \$lease->claim('{$runId}', \$dispatch);
        clarion_emit([
            'won' => \$result->won,
            'nonce' => \$result->nonce,
            'takeover' => \$result->takeover,
            'detectedHere' => \$result->detectedHere,
            'reason' => \$result->reason,
        ]);
        PHP;
    }

    private function quote(?string $value): string
    {
        return $value === null ? 'null' : "'{$value}'";
    }

    // ---------------------------------------------------------------
    // Process orchestration (TaskBudgetClaimConcurrencyTest's, unchanged)
    // ---------------------------------------------------------------

    private function runWorkers(int $count, string $body): array
    {
        $scratch = $this->scratchDir();
        $script = $scratch.'/worker.php';

        file_put_contents($script, $this->workerScript($body, $scratch));

        /** @var array<int, Process> $processes */
        $processes = [];

        for ($i = 0; $i < $count; $i++) {
            $process = new Process([PHP_BINARY, $script, (string) $i], dirname(__DIR__, 2));
            $process->setTimeout(120);
            $process->start();
            $processes[$i] = $process;
        }

        $this->awaitBarrier($scratch, $count, $processes);

        // One file releases all of them at once.
        file_put_contents($scratch.'/go', '1');

        $results = [];
        $this->workerStderr = [];

        foreach ($processes as $index => $process) {
            $process->wait();

            $this->workerStderr[$index] = $process->getErrorOutput();

            $this->assertTrue(
                $process->isSuccessful(),
                "Worker {$index} failed (exit {$process->getExitCode()}): "
                .$process->getErrorOutput().$process->getOutput()
            );

            $decoded = json_decode(trim($process->getOutput()), true);

            $this->assertIsArray(
                $decoded,
                "Worker {$index} produced no result payload. Output: ".$process->getOutput()
                .' Errors: '.$process->getErrorOutput()
            );

            $results[$index] = $decoded;
        }

        // A fresh scratch directory (and worker script) is used per call.
        $this->removeScratch();
        $this->scratch = null;

        return $results;
    }

    /**
     * Block until every worker has announced that it is ready, failing with
     * the workers' own diagnostics rather than a bare timeout if they have
     * not.
     *
     * @param  array<int, Process>  $processes
     */
    private function awaitBarrier(string $scratch, int $count, array $processes): void
    {
        $deadline = microtime(true) + 60;

        while (microtime(true) < $deadline) {
            if (count(glob($scratch.'/ready-*')) >= $count) {
                return;
            }

            foreach ($processes as $index => $process) {
                if (!$process->isRunning() && !$process->isSuccessful()) {
                    $this->fail(
                        "Worker {$index} died before reaching the barrier: "
                        .$process->getErrorOutput().$process->getOutput()
                    );
                }
            }

            usleep(20_000);
        }

        $reached = count(glob($scratch.'/ready-*'));

        // Release whatever is waiting so nothing is left hanging, then say
        // what was actually observed.
        file_put_contents($scratch.'/go', '1');

        $this->fail("Only {$reached} of {$count} workers reached the barrier within the timeout");
    }

    /**
     * The worker program: a minimal application pointed at the same
     * provisioned database this test class is using, plus the body under
     * test.
     *
     * Deliberately not booted. Booting would run the package provider's
     * scheduling and route registration, none of which a worker needs; the
     * two things Eloquent actually requires -- a connection resolver and an
     * event dispatcher -- are wired directly instead.
     */
    private function workerScript(string $body, string $scratch): string
    {
        $spec = $this->getSpec();
        $root = realpath(__DIR__.'/../../');
        $connection = var_export($spec->toConnectionConfig(), true);
        $appKey = base64_encode(random_bytes(32));

        return <<<PHP
        <?php

        require_once '{$root}/vendor/autoload.php';

        /** Announce readiness, then wait to be released with everybody else. */
        function clarion_barrier(): void
        {
            file_put_contents('{$scratch}/ready-'.getmypid(), '1');

            \$deadline = microtime(true) + 60;

            while (!file_exists('{$scratch}/go')) {
                if (microtime(true) > \$deadline) {
                    fwrite(STDERR, "barrier timeout\\n");
                    exit(1);
                }

                usleep(5000);
            }
        }

        function clarion_emit(array \$payload): void
        {
            echo json_encode(\$payload);
        }

        \$app = new Illuminate\\Foundation\\Application('{$root}/vendor');

        // Facades are wired by a bootstrapper this worker never runs, and
        // the code under test reaches for DB through it.
        Illuminate\\Support\\Facades\\Facade::setFacadeApplication(\$app);

        \$app->singleton('config', fn () => new Illuminate\\Config\\Repository([]));

        \$app->singleton('multichain', function () {
            return new class {
                public function __call(\$m, \$a) { return null; }
                public function publish(\$s, \$k, \$v) { return 'stub'; }
                public function liststreams(\$s) { throw new \\Exception('not found'); }
                public function create(\$t, \$n, \$p) { return null; }
                public function subscribe(\$s) { return null; }
            };
        });

        \$config = \$app['config'];
        \$config->set('app.key', 'base64:{$appKey}');
        \$config->set('app.timezone', 'UTC');
        \$config->set('database.default', 'mysql');
        \$config->set('database.connections.mysql', {$connection});
        \$config->set('cache.default', 'array');
        \$config->set('cache.stores.array', ['driver' => 'array']);
        // Logged to stderr rather than discarded: a worker that quietly did
        // nothing would otherwise be indistinguishable from one that found
        // nothing to do.
        \$config->set('logging.default', 'stderr');
        \$config->set('logging.channels.stderr', [
            'driver' => 'monolog',
            'handler' => Monolog\\Handler\\StreamHandler::class,
            'with' => ['stream' => 'php://stderr'],
        ]);
        \$config->set('eloquent-multichain-bridge.disabled', true);
        // A claim announces the run's changed state to its owner's screen.
        // The worker has no broadcaster to announce to, and what it tests is
        // the claim itself, so the run record's announcements are off.
        \$config->set('llm-client.run_trace.enabled', false);

        \$app->register(Illuminate\\Database\\DatabaseServiceProvider::class);
        \$app->register(Illuminate\\Cache\\CacheServiceProvider::class);
        \$app->register(\\ClarionApp\\LlmClient\\LlmClientServiceProvider::class);

        Illuminate\\Database\\Eloquent\\Model::setConnectionResolver(\$app['db']);
        Illuminate\\Database\\Eloquent\\Model::setEventDispatcher(\$app['events']);

        try {
        {$body}
        } catch (\\Throwable \$e) {
            fwrite(STDERR, get_class(\$e).': '.\$e->getMessage()."\\n".\$e->getTraceAsString()."\\n");
            exit(1);
        }
        PHP;
    }

    private function scratchDir(): string
    {
        if ($this->scratch === null) {
            $this->scratch = sys_get_temp_dir().'/run_lease_concurrency_'.uniqid();
            mkdir($this->scratch, 0700, true);
        }

        return $this->scratch;
    }

    private function removeScratch(): void
    {
        if ($this->scratch === null || !is_dir($this->scratch)) {
            $this->scratch = null;

            return;
        }

        foreach (glob($this->scratch.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->scratch);

        $this->scratch = null;
    }
}
