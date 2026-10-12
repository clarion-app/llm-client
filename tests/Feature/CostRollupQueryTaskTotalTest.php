<?php

namespace Tests\Feature;

use ClarionApp\LlmClient\Services\CostRollupQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A task's spend is the sum of its own usage records — nothing else.
 *
 * There is no second cost counter for a task: every model call already
 * leaves exactly one usage record with its cost, and the record is stamped
 * with the task it ran under. taskTotal() is one aggregate over those rows,
 * in the same shape every other rollup returns, read fresh on every call.
 * A figure remembered between calls would let a worker decide on spend that
 * another worker has since added to.
 */
class CostRollupQueryTaskTotalTest extends TestCase
{
    private string $task;

    protected function setUp(): void
    {
        parent::setUp();
        $this->task = (string) Str::uuid();
    }

    protected function tearDown(): void
    {
        DB::table('usage_records')->delete();

        parent::tearDown();
    }

    private function usage(?string $taskBudgetId, array $attributes = []): void
    {
        DB::table('usage_records')->insert($attributes + [
            'id' => (string) Str::uuid(),
            'conversation_id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'attempt_group_id' => (string) Str::uuid(),
            'task_budget_id' => $taskBudgetId,
            'input_tokens' => 10,
            'output_tokens' => 5,
            'total_tokens' => 15,
            'total_cost' => '0.0100000000',
            'cost_unpriced' => false,
            'cost_estimated' => false,
            'created_at' => now(),
        ]);
    }

    #[Test]
    public function the_shape_is_the_common_rollup_shape(): void
    {
        $query = app(CostRollupQuery::class);

        $task = $query->taskTotal($this->task);
        $user = $query->userTotal((string) Str::uuid(), '2000-01-01', '2100-01-01', null, true);

        $this->assertSame(array_keys($user), array_keys($task));
        foreach ($user as $key => $value) {
            $this->assertSame(get_debug_type($value), get_debug_type($task[$key]), "{$key} has the same type");
        }
    }

    #[Test]
    public function an_empty_task_has_zero_spend_and_no_requests(): void
    {
        $this->usage(null);
        $this->usage((string) Str::uuid());

        $this->assertSame([
            'priced_cost_total' => '0.0000000000',
            'request_count' => 0,
            'zero_priced_request_count' => 0,
            'unpriced_request_count' => 0,
            'unpriced_total_tokens' => 0,
            'has_estimated_cost' => false,
        ], app(CostRollupQuery::class)->taskTotal($this->task));
    }

    #[Test]
    public function only_the_tasks_own_priced_rows_are_summed(): void
    {
        $this->usage($this->task, ['total_cost' => '0.1234567890']);
        $this->usage($this->task, ['total_cost' => '0.0000000001']);
        $this->usage($this->task, ['total_cost' => '2.5000000000']);
        $this->usage((string) Str::uuid(), ['total_cost' => '9.0000000000']);
        $this->usage(null, ['total_cost' => '7.0000000000']);

        $total = app(CostRollupQuery::class)->taskTotal($this->task);

        $this->assertSame('2.6234567891', $total['priced_cost_total']);
        $this->assertSame(3, $total['request_count']);
    }

    #[Test]
    public function unpriced_and_zero_priced_requests_are_counted_not_summed(): void
    {
        $this->usage($this->task, ['total_cost' => '0.5000000000']);
        $this->usage($this->task, ['total_cost' => '0.0000000000']);
        $this->usage($this->task, ['total_cost' => null, 'cost_unpriced' => true, 'total_tokens' => 120]);
        $this->usage($this->task, ['total_cost' => null, 'cost_unpriced' => true, 'total_tokens' => 30]);
        $this->usage((string) Str::uuid(), ['total_cost' => null, 'cost_unpriced' => true, 'total_tokens' => 999]);

        $total = app(CostRollupQuery::class)->taskTotal($this->task);

        $this->assertSame('0.5000000000', $total['priced_cost_total']);
        $this->assertSame(4, $total['request_count']);
        $this->assertSame(1, $total['zero_priced_request_count']);
        $this->assertSame(2, $total['unpriced_request_count']);
        $this->assertSame(150, $total['unpriced_total_tokens']);
        $this->assertFalse($total['has_estimated_cost']);
    }

    #[Test]
    public function any_estimated_row_marks_the_total_estimated(): void
    {
        $this->usage($this->task);
        $this->assertFalse(app(CostRollupQuery::class)->taskTotal($this->task)['has_estimated_cost']);

        $this->usage((string) Str::uuid(), ['cost_estimated' => true]);
        $this->assertFalse(app(CostRollupQuery::class)->taskTotal($this->task)['has_estimated_cost'], 'another task\'s estimate does not count');

        $this->usage($this->task, ['cost_estimated' => true]);
        $this->assertTrue(app(CostRollupQuery::class)->taskTotal($this->task)['has_estimated_cost']);
    }

    #[Test]
    public function a_thousand_tiny_costs_sum_without_drift(): void
    {
        $rows = [];
        for ($i = 0; $i < 1000; $i++) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'conversation_id' => (string) Str::uuid(),
                'user_id' => (string) Str::uuid(),
                'attempt_group_id' => (string) Str::uuid(),
                'task_budget_id' => $this->task,
                'total_cost' => '0.0000000001',
                'cost_unpriced' => false,
                'cost_estimated' => false,
                'created_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('usage_records')->insert($chunk);
        }

        $total = app(CostRollupQuery::class)->taskTotal($this->task);

        $this->assertSame('0.0000001000', $total['priced_cost_total']);
        $this->assertSame(1000, $total['request_count']);
    }

    #[Test]
    public function the_total_is_read_fresh_on_every_call(): void
    {
        $query = app(CostRollupQuery::class);

        $this->usage($this->task, ['total_cost' => '0.4000000000']);
        $this->assertSame('0.4000000000', $query->taskTotal($this->task)['priced_cost_total']);

        $this->usage($this->task, ['total_cost' => '0.2500000000']);
        $second = $query->taskTotal($this->task);

        $this->assertSame('0.6500000000', $second['priced_cost_total'], 'the same instance sees the row written since its last call');
        $this->assertSame(2, $second['request_count']);
    }
}
