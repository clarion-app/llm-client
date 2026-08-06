<?php

namespace Tests\Unit;

use ClarionApp\LlmClient\Services\RunTraceQuery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * contracts/trace-id-propagation.md §4 (Q1-Q5): messagesForRun()/
 * toolInvocationsForRun()/usageRecordsForRun().
 *
 * These tests drive RunTraceQuery directly against rows inserted with an
 * explicit run_id column, independent of whether the model `creating`
 * listeners (T015-T017) exist yet — the read path and the write path are
 * two separate contracts, matching the parallel-file precedent this phase
 * sets in tasks.md.
 */
class RunTraceQueryRunRecordsTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::table('messages')->delete();
        DB::table('tool_invocation_records')->delete();
        DB::table('usage_records')->delete();
        DB::table('agent_runs')->delete();

        parent::tearDown();
    }

    protected function insertRun(string $userId): string
    {
        $runId = (string) Str::uuid();
        DB::table('agent_runs')->insert([
            'id' => $runId,
            'kind' => 'interactive',
            'user_id' => $userId,
            'conversation_id' => null,
            'source' => null,
            'end_state' => 'completed',
            'end_reason' => null,
            'started_at' => now()->toDateTimeString(),
            'ended_at' => now()->toDateTimeString(),
            'duration_ms' => 10,
            'step_count' => 1,
            'created_at' => now()->toDateTimeString(),
        ]);

        return $runId;
    }

    protected function insertMessage(string $runId, ?Carbon $createdAt = null): string
    {
        $id = (string) Str::uuid();
        $createdAt ??= now();
        DB::table('messages')->insert([
            'id' => $id,
            'conversation_id' => (string) Str::uuid(),
            'role' => 'assistant',
            'content' => 'test content',
            'run_id' => $runId,
            'sequence_number' => 0,
            'created_at' => $createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $createdAt->format('Y-m-d H:i:s'),
        ]);

        return $id;
    }

    protected function insertToolInvocation(string $runId, ?Carbon $createdAt = null): string
    {
        $id = (string) Str::uuid();
        DB::table('tool_invocation_records')->insert([
            'id' => $id,
            'conversation_id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'attempt_group_id' => (string) Str::uuid(),
            'run_id' => $runId,
            'tool_name' => 'search_operations',
            'outcome' => 'success',
            'created_at' => ($createdAt ?? now())->format('Y-m-d H:i:s'),
        ]);

        return $id;
    }

    protected function insertUsageRecord(string $runId, ?Carbon $createdAt = null): string
    {
        $id = (string) Str::uuid();
        DB::table('usage_records')->insert([
            'id' => $id,
            'conversation_id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'attempt_group_id' => (string) Str::uuid(),
            'run_id' => $runId,
            'input_tokens' => 10,
            'output_tokens' => 5,
            'total_tokens' => 15,
            'created_at' => ($createdAt ?? now())->format('Y-m-d H:i:s'),
        ]);

        return $id;
    }

    // ========== messagesForRun ==========

    /** @test */
    public function messages_for_run_returns_correct_rows_for_owner(): void
    {
        $query = $this->app->make(RunTraceQuery::class);
        $userId = (string) Str::uuid();
        $runId = $this->insertRun($userId);
        $otherRunId = $this->insertRun($userId);

        $m1 = $this->insertMessage($runId);
        $m2 = $this->insertMessage($runId);
        $this->insertMessage($otherRunId); // must not leak in

        $messages = $query->messagesForRun($userId, $runId);

        $this->assertIsArray($messages);
        $ids = array_map(fn ($m) => $m->id, $messages);
        sort($ids);
        $expected = [$m1, $m2];
        sort($expected);
        $this->assertSame($expected, $ids);
    }

    /** @test */
    public function messages_for_run_returns_null_for_nonexistent_run(): void
    {
        $query = $this->app->make(RunTraceQuery::class);
        $userId = (string) Str::uuid();

        $messages = $query->messagesForRun($userId, (string) Str::uuid());

        $this->assertNull($messages);
    }

    /** @test */
    public function messages_for_run_returns_null_for_purged_run(): void
    {
        $query = $this->app->make(RunTraceQuery::class);
        $userId = (string) Str::uuid();
        $runId = $this->insertRun($userId);
        $this->insertMessage($runId);

        // Simulate spec 062's purge: the agent_runs row is gone, the message's
        // run_id column is untouched (FR-018, data-model.md §4 — no FK, no cascade).
        DB::table('agent_runs')->where('id', $runId)->delete();

        $messages = $query->messagesForRun($userId, $runId);

        $this->assertNull(
            $messages,
            'A run_id naming a since-purged run resolves identically to a nonexistent one (FR-018)'
        );
    }

    /** @test */
    public function messages_for_run_returns_null_for_another_users_run(): void
    {
        $query = $this->app->make(RunTraceQuery::class);
        $ownerId = (string) Str::uuid();
        $runId = $this->insertRun($ownerId);
        $this->insertMessage($runId);

        $otherUserId = (string) Str::uuid();
        $messages = $query->messagesForRun($otherUserId, $runId);

        $this->assertNull($messages);
    }

    /** @test */
    public function messages_for_run_returns_empty_array_for_run_with_zero_messages(): void
    {
        $query = $this->app->make(RunTraceQuery::class);
        $userId = (string) Str::uuid();
        $runId = $this->insertRun($userId);

        $messages = $query->messagesForRun($userId, $runId);

        $this->assertIsArray($messages);
        $this->assertCount(0, $messages);
    }

    /** @test */
    public function messages_for_run_orders_by_created_at_ascending(): void
    {
        $query = $this->app->make(RunTraceQuery::class);
        $userId = (string) Str::uuid();
        $runId = $this->insertRun($userId);
        $base = now();

        $third = $this->insertMessage($runId, $base->copy()->addMinutes(2));
        $first = $this->insertMessage($runId, $base->copy());
        $second = $this->insertMessage($runId, $base->copy()->addMinute());

        $messages = $query->messagesForRun($userId, $runId);

        $this->assertSame([$first, $second, $third], array_map(fn ($m) => $m->id, $messages));
    }

    // ========== toolInvocationsForRun ==========

    /** @test */
    public function tool_invocations_for_run_returns_correct_rows_for_owner(): void
    {
        $query = $this->app->make(RunTraceQuery::class);
        $userId = (string) Str::uuid();
        $runId = $this->insertRun($userId);
        $otherRunId = $this->insertRun($userId);

        $t1 = $this->insertToolInvocation($runId);
        $t2 = $this->insertToolInvocation($runId);
        $this->insertToolInvocation($otherRunId); // must not leak in

        $invocations = $query->toolInvocationsForRun($userId, $runId);

        $this->assertIsArray($invocations);
        $ids = array_map(fn ($t) => $t->id, $invocations);
        sort($ids);
        $expected = [$t1, $t2];
        sort($expected);
        $this->assertSame($expected, $ids);
    }

    /** @test */
    public function tool_invocations_for_run_returns_null_for_nonexistent_run(): void
    {
        $query = $this->app->make(RunTraceQuery::class);
        $userId = (string) Str::uuid();

        $invocations = $query->toolInvocationsForRun($userId, (string) Str::uuid());

        $this->assertNull($invocations);
    }

    /** @test */
    public function tool_invocations_for_run_returns_null_for_purged_run(): void
    {
        $query = $this->app->make(RunTraceQuery::class);
        $userId = (string) Str::uuid();
        $runId = $this->insertRun($userId);
        $this->insertToolInvocation($runId);

        DB::table('agent_runs')->where('id', $runId)->delete();

        $invocations = $query->toolInvocationsForRun($userId, $runId);

        $this->assertNull($invocations);
    }

    /** @test */
    public function tool_invocations_for_run_returns_null_for_another_users_run(): void
    {
        $query = $this->app->make(RunTraceQuery::class);
        $ownerId = (string) Str::uuid();
        $runId = $this->insertRun($ownerId);
        $this->insertToolInvocation($runId);

        $otherUserId = (string) Str::uuid();
        $invocations = $query->toolInvocationsForRun($otherUserId, $runId);

        $this->assertNull($invocations);
    }

    /** @test */
    public function tool_invocations_for_run_returns_empty_array_for_run_with_zero_invocations(): void
    {
        $query = $this->app->make(RunTraceQuery::class);
        $userId = (string) Str::uuid();
        $runId = $this->insertRun($userId);

        $invocations = $query->toolInvocationsForRun($userId, $runId);

        $this->assertIsArray($invocations);
        $this->assertCount(0, $invocations);
    }

    /** @test */
    public function tool_invocations_for_run_orders_by_created_at_ascending(): void
    {
        $query = $this->app->make(RunTraceQuery::class);
        $userId = (string) Str::uuid();
        $runId = $this->insertRun($userId);
        $base = now();

        $third = $this->insertToolInvocation($runId, $base->copy()->addMinutes(2));
        $first = $this->insertToolInvocation($runId, $base->copy());
        $second = $this->insertToolInvocation($runId, $base->copy()->addMinute());

        $invocations = $query->toolInvocationsForRun($userId, $runId);

        $this->assertSame([$first, $second, $third], array_map(fn ($t) => $t->id, $invocations));
    }

    // ========== usageRecordsForRun ==========

    /** @test */
    public function usage_records_for_run_returns_correct_rows_for_owner(): void
    {
        $query = $this->app->make(RunTraceQuery::class);
        $userId = (string) Str::uuid();
        $runId = $this->insertRun($userId);
        $otherRunId = $this->insertRun($userId);

        $u1 = $this->insertUsageRecord($runId);
        $u2 = $this->insertUsageRecord($runId);
        $this->insertUsageRecord($otherRunId); // must not leak in

        $records = $query->usageRecordsForRun($userId, $runId);

        $this->assertIsArray($records);
        $ids = array_map(fn ($u) => $u->id, $records);
        sort($ids);
        $expected = [$u1, $u2];
        sort($expected);
        $this->assertSame($expected, $ids);
    }

    /** @test */
    public function usage_records_for_run_returns_null_for_nonexistent_run(): void
    {
        $query = $this->app->make(RunTraceQuery::class);
        $userId = (string) Str::uuid();

        $records = $query->usageRecordsForRun($userId, (string) Str::uuid());

        $this->assertNull($records);
    }

    /** @test */
    public function usage_records_for_run_returns_null_for_purged_run(): void
    {
        $query = $this->app->make(RunTraceQuery::class);
        $userId = (string) Str::uuid();
        $runId = $this->insertRun($userId);
        $this->insertUsageRecord($runId);

        DB::table('agent_runs')->where('id', $runId)->delete();

        $records = $query->usageRecordsForRun($userId, $runId);

        $this->assertNull($records);
    }

    /** @test */
    public function usage_records_for_run_returns_null_for_another_users_run(): void
    {
        $query = $this->app->make(RunTraceQuery::class);
        $ownerId = (string) Str::uuid();
        $runId = $this->insertRun($ownerId);
        $this->insertUsageRecord($runId);

        $otherUserId = (string) Str::uuid();
        $records = $query->usageRecordsForRun($otherUserId, $runId);

        $this->assertNull($records);
    }

    /** @test */
    public function usage_records_for_run_returns_empty_array_for_run_with_zero_records(): void
    {
        $query = $this->app->make(RunTraceQuery::class);
        $userId = (string) Str::uuid();
        $runId = $this->insertRun($userId);

        $records = $query->usageRecordsForRun($userId, $runId);

        $this->assertIsArray($records);
        $this->assertCount(0, $records);
    }

    /** @test */
    public function usage_records_for_run_orders_by_created_at_ascending(): void
    {
        $query = $this->app->make(RunTraceQuery::class);
        $userId = (string) Str::uuid();
        $runId = $this->insertRun($userId);
        $base = now();

        $third = $this->insertUsageRecord($runId, $base->copy()->addMinutes(2));
        $first = $this->insertUsageRecord($runId, $base->copy());
        $second = $this->insertUsageRecord($runId, $base->copy()->addMinute());

        $records = $query->usageRecordsForRun($userId, $runId);

        $this->assertSame([$first, $second, $third], array_map(fn ($u) => $u->id, $records));
    }
}
