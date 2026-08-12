<?php

namespace Tests\RealDatabase;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;

/**
 * SC-004/FR-007, the one property only a real database can decide.
 *
 * `ReservationLedger::reserve()`'s bounded, conditional `UPDATE`
 * (research.md D5) already exists, wired into `BudgetGate::admit()`
 * (Phase 2/3). Nothing here adds production code — this class exists
 * because SQLite `:memory:` is a single connection, so a "concurrency"
 * test written there would measure the test harness's own serialization
 * rather than the database engine's atomic compare-and-set. Every scenario
 * below therefore drives genuinely separate operating-system processes,
 * each holding its own MariaDB connection, against the same provisioned
 * instance `RealDatabaseTestCase` resolves.
 *
 *  - **SC-004/FR-007 — never more than the ceiling can afford.** `M`
 *    processes race to reserve an equal, known cost against a ceiling
 *    configured to afford exactly `N` of them (`N < M`). Exactly `N` must
 *    be admitted and `M - N` declined, regardless of which processes the
 *    operating system happens to schedule first — proven by repeating the
 *    race against a fresh scope more than once in the same test.
 *  - **Mutation-checklist row 10 — one anchor row, not two.** A brand-new
 *    scope has no `budget_reservation_ledger` row until the first
 *    `reserve()` call creates it via `insertOrIgnore`. Racing several
 *    processes on that very first call — with room enough for every one of
 *    them to be admitted — isolates the anchor-row race from the
 *    bound-decline logic: if two racing `insertOrIgnore`s ever produced two
 *    rows instead of one, the total held would come out split across them
 *    rather than summing to the arithmetic total of what was genuinely
 *    admitted, and the unique-row assertion below would catch it directly.
 *
 * The processes are synchronized through marker files rather than sleeps,
 * matching `BudgetConcurrencyTest`'s own established pattern: every worker
 * announces that it is ready and then blocks until the parent releases them
 * all together, so "simultaneous" is a fact the test enforces rather than
 * one it hopes for.
 */
#[Group('real-db')]
class ReservationConcurrencyTest extends RealDatabaseTestCase
{
    private const PROVIDER_TYPE = 'llama_cpp';

    protected array $seedTables = [
        'cost_summaries',
        'spending_ceilings',
        'budget_reservation_ledger',
        'cost_reservations',
    ];

    /** Scratch directory holding this test's barrier files and worker scripts. */
    private ?string $scratch = null;

    /**
     * Whatever the workers wrote to stderr, keyed by worker index.
     *
     * @var array<int, string>
     */
    private array $workerStderr = [];

    protected function tearDown(): void
    {
        $this->removeScratch();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // SC-004/FR-007 — never more than the ceiling can afford
    // ---------------------------------------------------------------

    /**
     * Repeated more than once, each time against a fresh scope, so the
     * property is shown to hold regardless of which processes the
     * operating system happens to schedule first rather than being an
     * artifact of one lucky interleaving.
     */
    #[Test]
    public function exactly_n_of_m_concurrent_reservations_are_admitted_regardless_of_arrival_order(): void
    {
        $this->assertReady();

        $workers = 24;
        $admittable = 9;
        $unitCost = '1.0000000000';

        for ($iteration = 1; $iteration <= 3; $iteration++) {
            $userId = (string) Str::uuid();

            $this->declareUserCeiling($userId, bcmul($unitCost, (string) $admittable, 10));

            $results = $this->runWorkers(
                $workers,
                $this->reserveWorkerBody($userId, $unitCost)
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
                "Iteration {$iteration}: expected exactly {$admittable} of {$workers} concurrent reservations "
                .'to be admitted, regardless of arrival order'
            );
            $this->assertSame($workers - $admittable, $declined, "Iteration {$iteration}: the rest must be declined");

            $row = DB::table('budget_reservation_ledger')
                ->where('scope_type', 'user')
                ->where('scope_id', $userId)
                ->first();

            $this->assertNotNull($row, "Iteration {$iteration}: the scope's ledger row must exist");

            // Every admitted reservation is still held (nothing in this test
            // releases or reconciles), so the ledger total is the exact
            // arithmetic sum of what was genuinely admitted. Because
            // reserved_total only ever moves via this same bounded UPDATE —
            // never decremented in this test — this final figure having
            // never exceeded the ceiling is equivalent to it never having
            // exceeded the ceiling at any earlier instant either: the
            // bounded UPDATE that produced each increment refuses to apply
            // it unless the resulting total still fits.
            $this->assertSame(
                0,
                bccomp((string) $row->reserved_total, bcmul($unitCost, (string) $admitted, 10), 10),
                "Iteration {$iteration}: reserved_total must equal the arithmetic sum of admitted reservations"
            );

            $this->assertLessThanOrEqual(
                0,
                bccomp((string) $row->reserved_total, bcmul($unitCost, (string) $admittable, 10), 10),
                "Iteration {$iteration}: reserved_total must never exceed what the ceiling could afford"
            );

            $this->assertSame(
                $admitted,
                DB::table('cost_reservations')
                    ->where('user_id', $userId)
                    ->where('status', 'held')
                    ->count(),
                "Iteration {$iteration}: exactly the admitted count of held reservation rows must exist"
            );
        }
    }

    // ---------------------------------------------------------------
    // Mutation row 10 — one anchor row, not two, for a brand-new scope
    // ---------------------------------------------------------------

    /**
     * Every one of these must be admitted (the ceiling affords all of
     * them), which isolates the anchor-row race: any split of the anchor
     * row would show up as a ledger total not summing to the arithmetic
     * total of what was admitted, or as more than one row for the scope.
     */
    #[Test]
    public function a_brand_new_scopes_first_ever_admissions_produce_exactly_one_ledger_row_under_concurrency(): void
    {
        $this->assertReady();

        $userId = (string) Str::uuid();
        $workers = 10;
        $unitCost = '1.0000000000';

        // Comfortably affords every one of the racing workers — this
        // scenario is about the anchor row, not about the bound declining
        // anything.
        $this->declareUserCeiling($userId, '100.0000000000');

        $this->assertSame(
            0,
            DB::table('budget_reservation_ledger')
                ->where('scope_type', 'user')
                ->where('scope_id', $userId)
                ->count(),
            'Precondition: this scope must have no ledger row before the race'
        );

        $results = $this->runWorkers(
            $workers,
            $this->reserveWorkerBody($userId, $unitCost)
        );

        foreach ($results as $index => $result) {
            $this->assertSame(
                'admitted',
                $result['status'] ?? null,
                "Worker {$index} must be admitted — the ceiling affords every one of them: ".json_encode($result)
            );

            $this->assertSame(
                '',
                trim($this->workerStderr[$index]),
                "Worker {$index} logged an unexpected error: ".$this->workerStderr[$index]
            );
        }

        $rows = DB::table('budget_reservation_ledger')
            ->where('scope_type', 'user')
            ->where('scope_id', $userId)
            ->get();

        $this->assertCount(
            1,
            $rows,
            'Two concurrent anchor-row creations for the same brand-new scope must produce exactly one row, '
            .'not two splitting the total'
        );

        $this->assertSame(
            0,
            bccomp((string) $rows[0]->reserved_total, bcmul($unitCost, (string) $workers, 10), 10),
            'reserved_total must be the exact arithmetic sum of every admitted reservation — a split anchor row '
            .'would instead leave part of the total stranded on a second, orphaned row'
        );

        $this->assertSame(
            $workers,
            DB::table('cost_reservations')->where('user_id', $userId)->where('status', 'held')->count()
        );
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    /**
     * A user-scoped override ceiling — resolveForUser() finds this row
     * directly, ahead of any installation-wide default, exactly matching
     * the scope key ('user:<uuid>') reserve() is driven with below.
     */
    private function declareUserCeiling(string $userId, string $amount): void
    {
        DB::table('spending_ceilings')->insert([
            'id' => (string) Str::uuid(),
            'scope_type' => 'user',
            'scope_id' => $userId,
            'amount' => $amount,
            'period_type' => 'day',
            'enforcement_mode' => 'stop',
            'approach_threshold' => '0.8000',
            'waived' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------
    // Worker bodies
    // ---------------------------------------------------------------

    /**
     * One reservation attempt through the real, container-resolved
     * ReservationLedger — the same object BudgetGate::admit() uses — so
     * this test drives the atomic UPDATE and its concurrency-abort retry
     * wrapper exactly as production does, not a hand-rolled stand-in for
     * them.
     */
    private function reserveWorkerBody(string $userId, string $unitCost): string
    {
        return <<<PHP
        clarion_barrier();

        \$reservation = \$app->make(\\ClarionApp\\LlmClient\\Services\\ReservationLedger::class)->reserve(
            ['user:{$userId}'],
            '{$unitCost}',
            \\ClarionApp\\LlmClient\\ValueObjects\\BudgetWorkKind::Interactive,
            userId: '{$userId}',
        );

        clarion_emit(['status' => \$reservation !== null ? 'admitted' : 'declined']);
        PHP;
    }

    // ---------------------------------------------------------------
    // Process orchestration
    // ---------------------------------------------------------------

    /**
     * Start $count worker processes, hold them all at the barrier, release
     * them together, and collect what each printed.
     *
     * The barrier is what makes "simultaneous" a fact rather than an
     * aspiration: every worker has booted, connected, and reached the line
     * under test before any of them is allowed past it, so the contention
     * is over the database operation itself and not over PHP startup time.
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

        // A fresh scratch directory (and worker script) is used per call —
        // the same worker.php is reused across every worker in a single
        // call, but the loop above may run again for a later iteration.
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
     * two things Eloquent actually requires — a connection resolver and an
     * event dispatcher — are wired directly instead.
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
            $this->scratch = sys_get_temp_dir().'/reservation_concurrency_'.uniqid();
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
