<?php

namespace Tests\RealDatabase;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;

/**
 * 101-parallel-subagent-execution, Phase 5 (US4), tasks.md T029,
 * research.md D2/D6.
 *
 * `DelegationConcurrencyGate::tryAdmit()`'s one transaction (count-then-
 * write, wrapped in RetriesConcurrencyAborts) already exists and is
 * unit-boundary-tested against SQLite `:memory:` (`DelegationConcurrencyGateTest`)
 * and choreographed at the feature level against the same single SQLite
 * connection (`DelegationConcurrencyCeilingTest`, T028). Neither can decide
 * whether the count-then-write is genuinely atomic under real concurrent
 * writers, because a single connection serializes every statement itself —
 * a "concurrency" test written there measures the test harness's own
 * serialization rather than the database engine's atomicity. This is the
 * one test in the whole suite that can tell the difference (quickstart.md's
 * mutation-checklist row 4): real operating-system processes, each holding
 * its own connection, racing `tryAdmit()` against a real MariaDB instance.
 *
 *  - **FR-006/SC-004 — never more than the per-batch ceiling admits at
 *    once.** `M` processes race to admit their own, already-`queued` row
 *    of the same batch against a ceiling configured to afford exactly `N`
 *    of them (`N < M`). Exactly `N` must be admitted and `M - N` declined,
 *    regardless of which processes the operating system happens to
 *    schedule first — proven by repeating the race against a fresh batch
 *    more than once in the same test, mirroring
 *    `ReservationConcurrencyTest`'s own repeated-iteration shape for the
 *    analogous budget property.
 *  - **FR-007 — the installation-wide ceiling is real, not the per-batch
 *    one relabeled.** Ten processes split across two different batches
 *    belonging to two different users race `tryAdmit()` at once, with the
 *    per-batch ceiling generous enough that neither batch's own axis could
 *    be what declines anyone; only the installation-wide ceiling can be
 *    the cause of any decline, and the COMBINED admitted count across both
 *    batches must equal it exactly.
 *
 * The processes are synchronized through marker files rather than sleeps,
 * matching `BudgetConcurrencyTest`/`ReservationConcurrencyTest`'s own
 * established pattern: every worker announces that it is ready and then
 * blocks until the parent releases them all together, so "simultaneous" is
 * a fact the test enforces rather than one it hopes for.
 */
#[Group('real-db')]
class DelegationConcurrencyTest extends RealDatabaseTestCase
{
    protected array $seedTables = ['agent_delegations'];

    /** Scratch directory holding this test's barrier files and worker scripts. */
    private ?string $scratch = null;

    /** @var array<int, string> */
    private array $workerStderr = [];

    protected function tearDown(): void
    {
        $this->removeScratch();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // FR-006/SC-004 — never more than the per-batch ceiling admits at once
    // ---------------------------------------------------------------

    /**
     * Repeated more than once, each time against a fresh batch, so the
     * property is shown to hold regardless of which processes the
     * operating system happens to schedule first rather than being an
     * artifact of one lucky interleaving.
     */
    #[Test]
    public function exactly_n_of_m_concurrent_admissions_are_admitted_regardless_of_arrival_order(): void
    {
        $this->assertReady();

        $workers = 24;
        $admittable = 9;

        for ($iteration = 1; $iteration <= 3; $iteration++) {
            $batchId = (string) Str::uuid();
            $ownerUserId = (string) Str::uuid();

            $delegationIds = $this->seedQueuedBatch($batchId, $ownerUserId, $workers);

            // Effectively unconstrain the installation-wide axis for this
            // test -- it is purely about the per-batch ceiling, but earlier
            // iterations' own admitted rows are never finalized (nothing in
            // this test frees a slot), so they would otherwise keep
            // accumulating against the shared default installation ceiling
            // (20) and starve a later iteration for a reason that has
            // nothing to do with what this test is checking.
            $results = $this->runWorkers(
                $workers,
                $this->tryAdmitWorkerBody($batchId, $delegationIds, $admittable, $workers * 10)
            );

            $admitted = 0;
            $declined = 0;

            foreach ($results as $index => $result) {
                $this->assertContains(
                    $result['status'] ?? null,
                    ['admitted', 'declined'],
                    "Iteration {$iteration}, worker {$index} produced an unexpected result: ".json_encode($result)
                );

                $this->assertSame(
                    '',
                    trim($this->workerStderr[$index]),
                    "Iteration {$iteration}, worker {$index} logged an unexpected error: ".$this->workerStderr[$index]
                );

                if ($result['status'] === 'admitted') {
                    $admitted++;
                } else {
                    $declined++;
                }
            }

            $this->assertSame(
                $admittable,
                $admitted,
                "Iteration {$iteration}: expected exactly {$admittable} of {$workers} concurrent admissions "
                .'to be admitted, regardless of arrival order'
            );
            $this->assertSame($workers - $admittable, $declined, "Iteration {$iteration}: the rest must be declined");

            $this->assertSame(
                $admittable,
                DB::table('agent_delegations')->where('batch_id', $batchId)->where('status', 'in_progress')->count(),
                "Iteration {$iteration}: the observed peak concurrently-in_progress count must equal exactly what "
                .'the ceiling could afford -- nothing here ever finalizes a row, so the final in_progress count IS '
                .'the peak the race ever reached'
            );

            $this->assertSame(
                $workers - $admittable,
                DB::table('agent_delegations')->where('batch_id', $batchId)->where('status', 'queued')->count(),
                "Iteration {$iteration}: a refused admission must leave its row exactly as it was -- still queued, "
                .'never partially written'
            );
        }
    }

    // ---------------------------------------------------------------
    // FR-007 — the installation-wide ceiling, a real, separate axis
    // ---------------------------------------------------------------

    #[Test]
    public function the_installation_wide_ceiling_caps_concurrency_across_two_different_users_batches_racing_at_once(): void
    {
        $this->assertReady();

        // Generous enough that neither batch's own per-batch ceiling could
        // be what declines anyone below -- only the installation-wide
        // ceiling can be the cause.
        $perBatchCeiling = 10;
        $installationCeiling = 3;
        $workersPerBatch = 5;

        $batchX = (string) Str::uuid();
        $batchY = (string) Str::uuid();
        $userX = (string) Str::uuid();
        $userY = (string) Str::uuid();

        $idsX = $this->seedQueuedBatch($batchX, $userX, $workersPerBatch);
        $idsY = $this->seedQueuedBatch($batchY, $userY, $workersPerBatch);

        $results = $this->runWorkers(
            $workersPerBatch * 2,
            $this->tryAdmitAcrossTwoBatchesWorkerBody($batchX, $idsX, $batchY, $idsY, $perBatchCeiling, $installationCeiling)
        );

        $admitted = 0;
        $declined = 0;

        foreach ($results as $index => $result) {
            $this->assertContains(
                $result['status'] ?? null,
                ['admitted', 'declined'],
                "Worker {$index} produced an unexpected result: ".json_encode($result)
            );

            $this->assertSame(
                '',
                trim($this->workerStderr[$index]),
                "Worker {$index} logged an unexpected error: ".$this->workerStderr[$index]
            );

            if ($result['status'] === 'admitted') {
                $admitted++;
            } else {
                $declined++;
            }
        }

        $this->assertSame(
            $installationCeiling,
            $admitted,
            'exactly the installation-wide ceiling must be admitted across BOTH batches combined, regardless of '
            .'which user\'s batch each admission happened to belong to'
        );
        $this->assertSame($workersPerBatch * 2 - $installationCeiling, $declined);

        $combinedInProgress = DB::table('agent_delegations')
            ->whereIn('batch_id', [$batchX, $batchY])
            ->where('status', 'in_progress')
            ->count();

        $this->assertSame(
            $installationCeiling,
            $combinedInProgress,
            'the combined in_progress count across both batches must never have exceeded the installation-wide '
            .'ceiling -- proving the installation axis is real and separate, not the per-batch one relabeled'
        );
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    /**
     * @return array<int, string> the seeded rows' own ids, in seed order
     */
    private function seedQueuedBatch(string $batchId, string $ownerUserId, int $count): array
    {
        $ids = [];

        for ($i = 0; $i < $count; $i++) {
            $id = (string) Str::uuid();
            $ids[] = $id;

            DB::table('agent_delegations')->insert([
                'id' => $id,
                'parent_conversation_id' => (string) Str::uuid(),
                'helper_agent_id' => (string) Str::uuid(),
                'helper_conversation_id' => (string) Str::uuid(),
                'owner_user_id' => $ownerUserId,
                'task' => 'A concurrently-racing batch member.',
                'depth' => 1,
                'status' => 'queued',
                'batch_id' => $batchId,
                'started_at' => now(),
            ]);
        }

        return $ids;
    }

    // ---------------------------------------------------------------
    // Worker bodies
    // ---------------------------------------------------------------

    /**
     * One admission attempt through the real, container-resolved
     * DelegationConcurrencyGate -- the same object
     * RunDelegationBatchMemberJob::handle() uses -- so this test drives the
     * atomic count-then-write and its concurrency-abort retry wrapper
     * exactly as production does, not a hand-rolled stand-in for them. Each
     * worker acts on its own, already-seeded 'queued' row, keyed by its own
     * process index.
     *
     * @param  array<int, string>  $delegationIds
     */
    private function tryAdmitWorkerBody(string $batchId, array $delegationIds, int $perBatchCeiling, ?int $installationCeiling): string
    {
        $idsExported = var_export($delegationIds, true);
        $installationLine = $installationCeiling !== null
            ? "\$config->set('llm-client.delegation.concurrency.max_concurrent_per_installation', {$installationCeiling});"
            : '';

        return <<<PHP
        \$ids = {$idsExported};
        \$index = (int) (\$argv[1] ?? 0);
        \$delegationId = \$ids[\$index];

        \$config->set('llm-client.delegation.concurrency.max_concurrent_per_batch', {$perBatchCeiling});
        {$installationLine}

        clarion_barrier();

        \$admitted = \$app->make(\\ClarionApp\\LlmClient\\Services\\DelegationConcurrencyGate::class)->tryAdmit(
            '{$batchId}',
            \$delegationId
        );

        clarion_emit(['status' => \$admitted ? 'admitted' : 'declined']);
        PHP;
    }

    /**
     * The first $idsX-worth of workers race for batch X; the remainder race
     * for batch Y -- two different users' batches contending for one
     * shared installation-wide ceiling at once.
     *
     * @param  array<int, string>  $idsX
     * @param  array<int, string>  $idsY
     */
    private function tryAdmitAcrossTwoBatchesWorkerBody(
        string $batchX,
        array $idsX,
        string $batchY,
        array $idsY,
        int $perBatchCeiling,
        int $installationCeiling,
    ): string {
        $idsXExported = var_export($idsX, true);
        $idsYExported = var_export($idsY, true);

        return <<<PHP
        \$idsX = {$idsXExported};
        \$idsY = {$idsYExported};
        \$index = (int) (\$argv[1] ?? 0);

        if (\$index < count(\$idsX)) {
            \$batchId = '{$batchX}';
            \$delegationId = \$idsX[\$index];
        } else {
            \$batchId = '{$batchY}';
            \$delegationId = \$idsY[\$index - count(\$idsX)];
        }

        \$config->set('llm-client.delegation.concurrency.max_concurrent_per_batch', {$perBatchCeiling});
        \$config->set('llm-client.delegation.concurrency.max_concurrent_per_installation', {$installationCeiling});

        clarion_barrier();

        \$admitted = \$app->make(\\ClarionApp\\LlmClient\\Services\\DelegationConcurrencyGate::class)->tryAdmit(
            \$batchId,
            \$delegationId
        );

        clarion_emit(['status' => \$admitted ? 'admitted' : 'declined']);
        PHP;
    }

    // ---------------------------------------------------------------
    // Process orchestration
    // ---------------------------------------------------------------

    /**
     * Start $count worker processes, hold them all at the barrier, release
     * them together, and collect what each printed.
     *
     * @return array<int, array<string, mixed>>
     */
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
            $this->scratch = sys_get_temp_dir().'/delegation_concurrency_'.uniqid();
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
