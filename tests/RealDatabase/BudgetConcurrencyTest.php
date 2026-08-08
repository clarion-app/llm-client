<?php

namespace Tests\RealDatabase;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;

/**
 * The three budget properties that only a real database can decide.
 *
 * Everything else in this feature is provable in the fast suite. These three
 * are not, and the reason is the same each time: SQLite `:memory:` is a
 * single connection, so a "concurrency" test written there measures the
 * harness's own serialization rather than the engine's atomicity, and a
 * process-local cache is indistinguishable from a durable one when there is
 * only ever one process. Each scenario below therefore runs real operating
 * system processes against a real MariaDB instance.
 *
 *  - **SC-005 — no lost increments.** Fifty simultaneous completing units of
 *    work against one scope must produce exactly the arithmetic sum of their
 *    costs. This is the property research.md D2 *inherits* rather than
 *    re-implements: MetricsRecorder::upsertCostSummary() already increments
 *    with `priced_cost_total + n` in SQL. A plan that leans on an existing
 *    guarantee owes a test that would notice if the guarantee were removed,
 *    which is why mutation row 29 deliberately mutates spec 073's code.
 *  - **SC-006 — nothing slips through after the crossing.** Fifty processes
 *    each make a request while the scope is under its ceiling, then all make
 *    a second request after it has been carried over. Every one of the second
 *    requests must be refused. The two-phase shape is deliberate: a purely
 *    simultaneous burst would not notice a consumption figure cached beyond
 *    the life of one request, because nothing would have changed between the
 *    read and the decision. A memo promoted from `scoped()` to a static or
 *    singleton cache — mutation rows 30 and 31 — is precisely a figure that
 *    outlives the request that read it, and phase two is where it shows.
 *  - **FR-028 — one warning, whoever gets there first.** Fifty processes
 *    cross the same approach threshold at the same instant. The unique index
 *    on budget_threshold_notifications is an atomic test-and-set, and
 *    insertOrIgnore returning 1 is how a process learns it won. A
 *    SELECT-then-INSERT would pass every single-process test in the suite and
 *    duplicate here (mutation row 11).
 *
 * The processes are synchronized through marker files rather than sleeps, so
 * "at the same instant" is enforced rather than hoped for: every worker
 * announces that it is ready and then blocks until the parent releases them
 * all together.
 */
#[Group('real-db')]
class BudgetConcurrencyTest extends RealDatabaseTestCase
{
    /** How many processes contend in each scenario. */
    private const WORKERS = 50;

    /** Cost of one recorded unit of work, at the rate declared below. */
    private const UNIT_COST = '0.1234567890';

    /** 50 x UNIT_COST, stated rather than computed, so the test asserts a number. */
    private const EXPECTED_TOTAL = '6.1728394500';

    private const PRICED_MODEL = 'concurrency-priced-model';
    private const PROVIDER_TYPE = 'llama_cpp';

    protected array $seedTables = [
        'cost_summaries',
        'usage_records',
        'usage_summaries',
        'model_prices',
        'spending_ceilings',
        'budget_threshold_notifications',
        'agent_runs',
        'agent_run_steps',
        'agent_run_actions',
    ];

    /** Scratch directory holding this test's barrier files and worker scripts. */
    private ?string $scratch = null;

    /**
     * Whatever the workers wrote to stderr, keyed by worker index.
     *
     * Kept because several of the paths under test log and swallow their own
     * failures: a worker that did nothing at all exits 0 and prints a
     * perfectly well-formed result, so the log is the only place the
     * difference shows.
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
    // SC-005 — fifty completions, no lost and no double increments
    // ---------------------------------------------------------------

    /**
     * The increment belongs to the database.
     *
     * Run with snapshot isolation off, which is the regime MySQL 8 always
     * presents and MariaDB presented until 11.6 — see recordUsageWorkerBody()
     * for why that is the harder case rather than a contrived one.
     */
    #[Test]
    public function fifty_simultaneous_completions_sum_exactly_with_no_lost_increments(): void
    {
        $this->assertFiftyCompletionsSumExactly(weakenIsolation: true);
    }

    /**
     * The same fifty completions under whatever isolation the engine is
     * actually configured with.
     *
     * On MariaDB 11.6 and later that is snapshot isolation, which does not
     * lose updates — it *aborts* the transactions that would have. Nothing
     * about the arithmetic changes, but everything about the failure mode
     * does: without a retry, each abort is a whole usage record that
     * recordUsage() logs and swallows, and consumption silently under-reports
     * by however many completions happened at once. Measured on MariaDB 11.8
     * before MetricsRecorder retried, fifty completions recorded five.
     */
    #[Test]
    public function fifty_simultaneous_completions_sum_exactly_under_the_engines_own_isolation(): void
    {
        $this->assertFiftyCompletionsSumExactly(weakenIsolation: false);
    }

    private function assertFiftyCompletionsSumExactly(bool $weakenIsolation): void
    {
        $this->assertReady();

        $userId = (string) Str::uuid();

        $this->declarePrice();

        // Each worker uses a conversation of its own, which is the shape the
        // scenario actually describes: one *budget* scope — the user —
        // carrying fifty units of work that are concurrent with one another.
        // Fifty completions inside a single conversation is a different
        // situation, and one this feature does not claim.
        $results = $this->runWorkers(
            self::WORKERS,
            $this->recordUsageWorkerBody($userId, $weakenIsolation)
        );

        foreach ($results as $index => $result) {
            $this->assertSame('recorded', $result['status'] ?? null, "Worker {$index} did not complete: ".json_encode($result));

            // recordUsage() logs and swallows every failure it meets, so a
            // worker that recorded nothing still reports success. The log is
            // where a lost increment would actually announce itself.
            $this->assertSame(
                '',
                trim($this->workerStderr[$index]),
                "Worker {$index} logged a failure while recording: ".$this->workerStderr[$index]
            );
        }

        $row = DB::table('cost_summaries')
            ->where('entity_type', 'user')
            ->where('entity_id', $userId)
            ->first();

        $this->assertNotNull($row, 'Fifty completions must have produced the one user-scoped bucket for the period');

        $this->assertSame(
            self::WORKERS,
            (int) $row->request_count,
            'Every completion is counted exactly once — none lost, none double-counted'
        );

        $this->assertSame(
            0,
            bccomp((string) $row->priced_cost_total, self::EXPECTED_TOTAL, 10),
            'The recorded consumption must be the exact arithmetic sum of the fifty units, '
            .'which holds only because the increment is done by the database rather than read-modify-written in PHP'
        );

        // One bucket, not fifty: the insertOrIgnore that creates it is as much
        // a part of the guarantee as the increment that follows.
        $this->assertSame(
            1,
            DB::table('cost_summaries')->where('entity_type', 'user')->where('entity_id', $userId)->count()
        );

        $this->assertSame(
            self::WORKERS,
            DB::table('usage_records')->where('user_id', $userId)->count(),
            'Each unit of work also left exactly one usage record'
        );
    }

    // ---------------------------------------------------------------
    // SC-006 — 100% refused after the crossing, 0% slipping through
    // ---------------------------------------------------------------

    #[Test]
    public function once_the_ceiling_is_crossed_every_concurrent_request_is_refused(): void
    {
        $this->assertReady();

        $userId = (string) Str::uuid();

        // Under the ceiling to begin with, so phase one is genuinely allowed
        // and the workers have a figure to have read before the crossing.
        $this->declareCeiling('100.00', 'stop');
        $this->seedConsumption($userId, '10.0000000000');

        $results = $this->runWorkers(
            self::WORKERS,
            $this->admitTwicePastABarrierWorkerBody($userId),
            // Between the two phases, carry the scope past its ceiling. Every
            // worker has already made one decision by this point and is
            // blocked waiting; the second decision each of them makes has to
            // reflect this write, not the figure it read before it.
            function () use ($userId) {
                DB::table('cost_summaries')
                    ->where('entity_type', 'user')
                    ->where('entity_id', $userId)
                    ->update(['priced_cost_total' => '250.0000000000']);
            }
        );

        $allowedBefore = 0;
        $refusedAfter = 0;

        foreach ($results as $index => $result) {
            $this->assertArrayHasKey('phase_one', $result, "Worker {$index}: ".json_encode($result));

            $this->assertSame(
                'allowed',
                $result['phase_one'],
                "Worker {$index} must be admitted while the scope is under its ceiling"
            );
            $allowedBefore++;

            if ($result['phase_two'] === 'refused') {
                $refusedAfter++;
            }
        }

        $this->assertSame(self::WORKERS, $allowedBefore);

        $this->assertSame(
            self::WORKERS,
            $refusedAfter,
            'Every concurrent request after the crossing must be refused — 0% may slip through on a figure '
            .'read before the crossing, which is exactly what a consumption memo outliving its request would allow'
        );
    }

    // ---------------------------------------------------------------
    // FR-028 — fifty simultaneous crossings, one warning
    // ---------------------------------------------------------------

    #[Test]
    public function fifty_simultaneous_threshold_crossings_produce_exactly_one_notification(): void
    {
        $this->assertReady();

        $userId = (string) Str::uuid();

        // 25.00 at the default 0.80 threshold warns from 20.00. 22.00 is
        // across the threshold and below the ceiling, so every worker
        // evaluates a genuine approach crossing and none of them a reached
        // one.
        $this->declareCeiling('25.00', 'stop', '0.80');
        $this->seedConsumption($userId, '22.0000000000');

        $results = $this->runWorkers(
            self::WORKERS,
            $this->notifyPastABarrierWorkerBody($userId)
        );

        $announced = 0;

        foreach ($results as $index => $result) {
            $this->assertSame('done', $result['status'] ?? null, "Worker {$index}: ".json_encode($result));
            $announced += (int) ($result['events'] ?? 0);
        }

        $rows = DB::table('budget_threshold_notifications')
            ->where('scope_id', $userId)
            ->get();

        $this->assertCount(
            1,
            $rows,
            'The unique index is the latch: fifty simultaneous crossings win it once between them'
        );
        $this->assertSame('approach', $rows[0]->kind);

        $this->assertSame(
            1,
            $announced,
            'Exactly one process announced the crossing — the one that won the row. A SELECT-then-INSERT '
            .'would let several read "not yet fired" before any of them wrote'
        );

        $this->assertSame(
            0,
            DB::table('budget_threshold_notifications')->where('kind', 'reached')->count(),
            'A crossing below the ceiling is an approach and nothing else'
        );
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    /**
     * One input token costs exactly UNIT_COST, so the expected total is a
     * number this file states rather than one the reader has to derive.
     */
    private function declarePrice(): void
    {
        DB::table('model_prices')->insert([
            'id' => (string) Str::uuid(),
            'provider_type' => self::PROVIDER_TYPE,
            'model' => self::PRICED_MODEL,
            'reused_input_rate' => '0.00000000',
            'fresh_input_rate' => '123456.78900000',
            'output_rate' => '0.00000000',
            'effective_from' => now()->subDay(),
            'effective_until' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function declareCeiling(string $amount, string $mode, string $threshold = '0.8000'): void
    {
        DB::table('spending_ceilings')->insert([
            'id' => (string) Str::uuid(),
            'scope_type' => 'user_default',
            'scope_id' => '00000000-0000-0000-0000-000000000000',
            'amount' => $amount,
            'period_type' => 'day',
            'enforcement_mode' => $mode,
            'approach_threshold' => $threshold,
            'waived' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedConsumption(string $userId, string $amount): void
    {
        DB::table('cost_summaries')->insert([
            'id' => (string) Str::uuid(),
            'entity_type' => 'user',
            'entity_id' => $userId,
            'user_id' => $userId,
            'period_date' => now()->toDateString(),
            'request_count' => 1,
            'priced_cost_total' => $amount,
            'zero_priced_request_count' => 0,
            'unpriced_request_count' => 0,
            'unpriced_total_tokens' => 0,
            'estimated_request_count' => 0,
            'updated_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------
    // Worker bodies
    // ---------------------------------------------------------------

    /**
     * Record one completed, priced unit of work through the real metrics
     * path — the same path a finished turn goes through, and the one that
     * owns the atomic increment under test.
     */
    private function recordUsageWorkerBody(string $userId, bool $weakenIsolation): string
    {
        $model = self::PRICED_MODEL;
        $providerType = self::PROVIDER_TYPE;

        // Run one of the two SC-005 scenarios under the *weaker* of the two
        // isolation regimes the deployment target can present.
        //
        // MariaDB 11.6 turned innodb_snapshot_isolation on by default, and
        // under it a transaction that would lose an update is aborted
        // instead — which quietly makes a read-modify-write counter correct
        // too, and would leave the scenario unable to tell the atomic
        // increment apart from a hand-rolled one. MySQL 8 has no such
        // feature and neither does MariaDB with the variable off, so the
        // property SC-005 actually needs — that the increment is done by the
        // database rather than computed in PHP — is only observable there.
        // Turning it off is testing the harder case, not a contrived one.
        $weaken = $weakenIsolation ? <<<'PHP'
        try {
            \Illuminate\Support\Facades\DB::statement('SET SESSION innodb_snapshot_isolation = OFF');
        } catch (\Throwable $e) {
            // An engine without the variable is already in the weaker regime.
        }
        PHP : '';

        return <<<PHP
        \$conversationId = (string) \\Illuminate\\Support\\Str::uuid();

        {$weaken}

        clarion_barrier();

        (new \\ClarionApp\\LlmClient\\Services\\MetricsRecorder())->recordUsage(
            conversationId: \$conversationId,
            userId: '{$userId}',
            attemptGroupId: (string) \\Illuminate\\Support\\Str::uuid(),
            providerUsage: ['prompt_tokens' => 1, 'completion_tokens' => 0, 'total_tokens' => 1],
            inputText: 'x',
            outputText: '',
            model: '{$model}',
            providerType: '{$providerType}',
        );

        clarion_emit(['status' => 'recorded']);
        PHP;
    }

    /**
     * Two gate decisions with a barrier between them, each made by a freshly
     * built container — the in-process equivalent of two requests handled by
     * one queue worker, which is where a memo that outlived its request would
     * hand the second decision the first one's figure.
     */
    private function admitTwicePastABarrierWorkerBody(string $userId): string
    {
        return <<<PHP
        \$decide = function () use (\$app) {
            \$app->forgetScopedInstances();

            try {
                \$app->make(\\ClarionApp\\LlmClient\\Services\\BudgetGate::class)->admit(
                    '{$userId}',
                    \\ClarionApp\\LlmClient\\ValueObjects\\BudgetWorkKind::Interactive,
                );

                return 'allowed';
            } catch (\\ClarionApp\\LlmClient\\Exceptions\\BudgetExceededException \$e) {
                return 'refused';
            }
        };

        \$phaseOne = \$decide();

        clarion_barrier();

        \$phaseTwo = \$decide();

        clarion_emit(['phase_one' => \$phaseOne, 'phase_two' => \$phaseTwo]);
        PHP;
    }

    /**
     * Evaluate the thresholds for one scope, counting how many notification
     * events this process actually announced.
     */
    private function notifyPastABarrierWorkerBody(string $userId): string
    {
        return <<<PHP
        \$announced = 0;

        \$app['events']->listen(
            \\ClarionApp\\LlmClient\\Events\\SpendingThresholdWarned::class,
            function () use (&\$announced) { \$announced++; }
        );
        \$app['events']->listen(
            \\ClarionApp\\LlmClient\\Events\\SpendingCeilingReached::class,
            function () use (&\$announced) { \$announced++; }
        );

        clarion_barrier();

        \$app->make(\\ClarionApp\\LlmClient\\Services\\BudgetThresholdNotifier::class)->notify('{$userId}');

        clarion_emit(['status' => 'done', 'events' => \$announced]);
        PHP;
    }

    // ---------------------------------------------------------------
    // Process orchestration
    // ---------------------------------------------------------------

    /**
     * Start $count worker processes, hold them all at the barrier, run
     * $betweenPhases if given, release them, and collect what each printed.
     *
     * The barrier is what makes "simultaneous" a fact rather than an
     * aspiration: every worker has booted, connected, and reached the line
     * under test before any of them is allowed past it, so the contention is
     * over the database operation itself and not over PHP startup time.
     *
     * @param  callable|null  $betweenPhases  run once, after every worker has
     *   reached the barrier and before any is released
     * @return array<int, array<string, mixed>>
     */
    private function runWorkers(int $count, string $body, ?callable $betweenPhases = null): array
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

        if ($betweenPhases !== null) {
            $betweenPhases();
        }

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
        // the code under test reaches for Log and DB through them.
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
        // Logged to stderr rather than discarded: several of the code paths
        // under test swallow their own failures and log them, and a worker
        // that quietly did nothing would otherwise read as a worker that
        // found nothing to do.
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

        // ShouldBroadcastNow events resolve a broadcast factory before any
        // listener runs. There is nothing to broadcast to from a worker, and
        // a binding failure here would be swallowed by the notifier's own
        // catch and read as "no crossing happened".
        \$app->singleton(Illuminate\\Contracts\\Broadcasting\\Factory::class, function () {
            return new class implements Illuminate\\Contracts\\Broadcasting\\Factory {
                public function connection(\$name = null) { return \$this; }
                public function queue(\$event) { }
                public function event(\$event = null) { }
            };
        });

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
            $this->scratch = sys_get_temp_dir().'/budget_concurrency_'.uniqid();
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
