<?php

namespace Tests\RealDatabase;

use ClarionApp\LlmClient\Services\CostRollupQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;

/**
 * The step claim on the engine production runs, with real competing
 * connections.
 *
 * The fast suite proves the claim atomic on a SQLite file by injecting one
 * worker's claim between another's read and write. SQLite decides that race
 * with a whole-database lock; MySQL/MariaDB decide it with a row lock under
 * InnoDB. Only the real engine can show the conditional UPDATE is enough
 * there too. Real operating-system processes, each with its own connection
 * and each resolving the real TaskBudgetGate, are released together at a
 * barrier (the BudgetConcurrencyTest/DelegationConcurrencyTest pattern):
 *
 *  - two workers race for a task's last step, repeatedly — exactly one
 *    claim succeeds every time;
 *  - ten workers make fifty interleaved claims on a thirty-step task —
 *    exactly thirty succeed and steps_used ends at exactly thirty;
 *  - a task's spend summed over decimal(20,10) comes back as the exact
 *    decimal string.
 *
 * Every task here has both approach notices already given, so the claims
 * exercise the claim alone.
 */
#[Group('real-db')]
class TaskBudgetClaimConcurrencyTest extends RealDatabaseTestCase
{
    protected array $seedTables = ['task_budgets', 'task_budget_events', 'usage_records'];

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
    public function two_workers_racing_for_the_last_step_never_both_get_it(): void
    {
        $this->assertReady();

        for ($iteration = 1; $iteration <= 5; $iteration++) {
            $taskId = $this->seedTask(maxSteps: 10, stepsUsed: 9);

            $results = $this->runWorkers(2, $this->claimWorkerBody($taskId, 1));

            $allowed = 0;
            foreach ($results as $index => $result) {
                $this->assertSame('', trim($this->workerStderr[$index]), "Iteration {$iteration}, worker {$index} logged: ".$this->workerStderr[$index]);
                foreach ($result['decisions'] as $decision) {
                    if ($decision === 'allow') {
                        $allowed++;
                    } else {
                        $this->assertSame('stop:steps', $decision, "Iteration {$iteration}, worker {$index}");
                    }
                }
            }

            $this->assertSame(1, $allowed, "Iteration {$iteration}: exactly one worker takes the last step");
            $this->assertSame(10, (int) DB::table('task_budgets')->where('id', $taskId)->value('steps_used'), "Iteration {$iteration}: never past the ceiling");
        }
    }

    #[Test]
    public function fifty_interleaved_claims_on_a_thirty_step_task_allow_exactly_thirty(): void
    {
        $this->assertReady();

        $taskId = $this->seedTask(maxSteps: 30, stepsUsed: 0);

        $results = $this->runWorkers(10, $this->claimWorkerBody($taskId, 5));

        $allowed = 0;
        $claims = 0;
        foreach ($results as $index => $result) {
            $this->assertSame('', trim($this->workerStderr[$index]), "Worker {$index} logged: ".$this->workerStderr[$index]);
            foreach ($result['decisions'] as $decision) {
                $claims++;
                if ($decision === 'allow') {
                    $allowed++;
                } else {
                    $this->assertSame('stop:steps', $decision, "Worker {$index}");
                }
            }
        }

        $this->assertSame(50, $claims);
        $this->assertSame(30, $allowed);
        $this->assertSame(30, (int) DB::table('task_budgets')->where('id', $taskId)->value('steps_used'));
    }

    #[Test]
    public function the_task_spend_sum_is_the_exact_decimal(): void
    {
        $this->assertReady();

        $taskId = $this->seedTask(maxSteps: 30, stepsUsed: 0);
        foreach (['12345678.1234567891', '0.0000000009', '0.1000000000', '7.0000000000'] as $cost) {
            DB::table('usage_records')->insert([
                'id' => (string) Str::uuid(),
                'conversation_id' => (string) Str::uuid(),
                'user_id' => (string) Str::uuid(),
                'attempt_group_id' => (string) Str::uuid(),
                'task_budget_id' => $taskId,
                'total_cost' => $cost,
                'cost_unpriced' => false,
                'cost_estimated' => false,
                'created_at' => now(),
            ]);
        }

        $total = app(CostRollupQuery::class)->taskTotal($taskId);

        $this->assertSame('12345685.2234567900', $total['priced_cost_total']);
        $this->assertSame(4, $total['request_count']);
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    private function seedTask(int $maxSteps, int $stepsUsed): string
    {
        $id = (string) Str::uuid();

        DB::table('task_budgets')->insert([
            'id' => $id,
            'user_id' => (string) Str::uuid(),
            'root_conversation_id' => (string) Str::uuid(),
            'scope' => 'request',
            'unattended' => false,
            'state' => 'running',
            'max_steps' => $maxSteps,
            'max_steps_origin' => 'default',
            'original_max_steps' => $maxSteps,
            'max_spend' => '1.0000000000',
            'max_spend_origin' => 'default',
            'original_max_spend' => '1.0000000000',
            'steps_used' => $stepsUsed,
            'approach_threshold' => '0.8000',
            'steps_approach_noticed_at' => now(),
            'spend_approach_noticed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    // ---------------------------------------------------------------
    // Worker body
    // ---------------------------------------------------------------

    /**
     * $claims boundary checks through the real, container-resolved
     * TaskBudgetGate — the same object every boundary site uses — on a
     * conversation pointing at the task.
     */
    private function claimWorkerBody(string $taskId, int $claims): string
    {
        return <<<PHP
        \$conversation = (new \\ClarionApp\\LlmClient\\Models\\Conversation())->forceFill([
            'id' => (string) \\Illuminate\\Support\\Str::uuid(),
            'user_id' => (string) \\Illuminate\\Support\\Str::uuid(),
            'channel' => 'web',
            'active_task_budget_id' => '{$taskId}',
        ]);
        \$gate = \$app->make(\\ClarionApp\\LlmClient\\Services\\TaskBudgetGate::class);

        clarion_barrier();

        \$decisions = [];
        for (\$i = 0; \$i < {$claims}; \$i++) {
            \$decision = \$gate->beforeStep(\$conversation, null);
            \$decisions[] = \$decision->isAllow() ? 'allow' : 'stop:'.\$decision->reason;
        }

        clarion_emit(['decisions' => \$decisions]);
        PHP;
    }

    // ---------------------------------------------------------------
    // Process orchestration (DelegationConcurrencyTest's, unchanged)
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
            $this->scratch = sys_get_temp_dir().'/task_budget_concurrency_'.uniqid();
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
