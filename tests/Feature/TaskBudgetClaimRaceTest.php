<?php

namespace Tests\Feature;

use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Services\TaskBudgetGate;
use ClarionApp\LlmClient\ValueObjects\TaskBudgetDecision;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\TemporarySqliteFile;
use Tests\TestCase;

/**
 * Two workers can never both take a task's last step.
 *
 * Helpers of one task run on different queue workers, each with its own
 * database connection. The step claim must therefore be atomic in the
 * database, not in PHP: a gate that reads steps_used, decides there is
 * room, and then writes, leaves a window in which another worker's claim
 * lands — and both take the last step.
 *
 * This test opens that window on purpose. Worker A's gate runs on one
 * connection to a temporary SQLite file, worker B's on a second connection
 * to the same file, and B's complete claim is injected after each query A
 * makes in turn — after its read of the task, after its read of the spend,
 * after its claim. Whatever the position, exactly one of the two claims
 * succeeds and the task ends exactly at its ceiling. A read-then-write
 * claim over-claims here.
 */
class TaskBudgetClaimRaceTest extends TestCase
{
    private const A = 'task_budget_race_a';
    private const B = 'task_budget_race_b';

    private TemporarySqliteFile $file;

    private TaskBudgetGate $gateA;

    private TaskBudgetGate $gateB;

    /** @var (callable(QueryExecuted): void)|null */
    private $onQuery = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = new TemporarySqliteFile();
        $this->file->connect(self::A, self::B);
        $this->file->declareSchema(self::A);

        DB::listen(function (QueryExecuted $query) {
            if ($this->onQuery !== null) {
                ($this->onQuery)($query);
            }
        });

        // Two workers: two gates from two container scopes.
        $this->gateA = app(TaskBudgetGate::class);
        $this->app->forgetScopedInstances();
        $this->gateB = app(TaskBudgetGate::class);
        $this->assertNotSame($this->gateA, $this->gateB);
    }

    protected function tearDown(): void
    {
        $this->onQuery = null;
        DB::setDefaultConnection('sqlite');
        $this->file->delete();

        parent::tearDown();
    }

    /** A running task on the file, with both approach notices already given. */
    private function task(int $maxSteps, int $stepsUsed): string
    {
        $id = (string) Str::uuid();

        DB::connection(self::A)->table('task_budgets')->insert([
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

    private function conversationFor(string $taskId): Conversation
    {
        return (new Conversation())->forceFill([
            'id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'channel' => 'web',
            'active_task_budget_id' => $taskId,
        ]);
    }

    private function stepsUsed(string $taskId): int
    {
        return (int) DB::connection(self::A)->table('task_budgets')->where('id', $taskId)->value('steps_used');
    }

    /** Run $fn with $connection as the default connection, as a worker bound to it would. */
    private function on(string $connection, callable $fn): mixed
    {
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection($connection);

        try {
            return $fn();
        } finally {
            DB::setDefaultConnection($previous);
        }
    }

    #[Test]
    public function the_last_step_goes_to_exactly_one_worker_wherever_the_other_claim_lands(): void
    {
        $positionsExercised = 0;

        for ($after = 1; $after <= 12; $after++) {
            $taskId = $this->task(maxSteps: 5, stepsUsed: 4);
            $conversationA = $this->conversationFor($taskId);
            $conversationB = $this->conversationFor($taskId);

            $seen = 0;
            $decisionB = null;
            $this->onQuery = function (QueryExecuted $query) use (&$seen, &$decisionB, $after, $conversationB) {
                if ($query->connectionName !== self::A || $decisionB !== null) {
                    return;
                }
                if (++$seen === $after) {
                    // Worker B's whole boundary check, on its own connection,
                    // lands right after A's query number $after.
                    $decisionB = $this->on(self::B, fn () => $this->gateB->beforeStep($conversationB, null));
                }
            };

            $decisionA = $this->on(self::A, fn () => $this->gateA->beforeStep($conversationA, null));
            $this->onQuery = null;

            if ($decisionB === null) {
                // A made fewer than $after queries: every position has been tried.
                $this->assertSame(5, $this->stepsUsed($taskId), 'A alone took the last step');
                $this->assertTrue($decisionA->isAllow());
                break;
            }

            $positionsExercised++;
            $decisions = [$decisionA, $decisionB];
            $allowed = array_filter($decisions, fn (TaskBudgetDecision $d) => $d->isAllow());
            $refused = array_values(array_filter($decisions, fn (TaskBudgetDecision $d) => $d->isStop()));

            $this->assertCount(1, $allowed, "B injected after A's query {$after}: exactly one claim succeeds");
            $this->assertSame('steps', $refused[0]->reason, "B injected after A's query {$after}: the other is refused on steps");
            $this->assertSame(5, $this->stepsUsed($taskId), "B injected after A's query {$after}: never past the ceiling");
        }

        $this->assertGreaterThanOrEqual(2, $positionsExercised, 'B was injected both before and after A\'s claim');
    }

    #[Test]
    public function alternating_claims_across_two_connections_stop_exactly_at_the_ceiling(): void
    {
        $taskId = $this->task(maxSteps: 30, stepsUsed: 0);
        $conversation = $this->conversationFor($taskId);

        $allowed = 0;
        for ($i = 0; $i < 50; $i++) {
            [$connection, $gate] = $i % 2 === 0 ? [self::A, $this->gateA] : [self::B, $this->gateB];
            $decision = $this->on($connection, fn () => $gate->beforeStep($conversation, null));

            if ($decision->isAllow()) {
                $allowed++;
            } else {
                $this->assertSame('steps', $decision->reason, "claim {$i}");
            }
        }

        $this->assertSame(30, $allowed);
        $this->assertSame(30, $this->stepsUsed($taskId));
    }

    #[Test]
    public function the_temporary_file_is_removed(): void
    {
        $path = $this->file->path;
        $this->assertFileExists($path);
        $this->assertStringStartsWith(sys_get_temp_dir(), $path);

        $this->file->delete();

        $this->assertFileDoesNotExist($path);
    }
}
