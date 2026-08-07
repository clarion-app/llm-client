<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use Tests\TestCase;
use ClarionApp\LlmClient\Models\ModelPrice;
use ClarionApp\LlmClient\Models\UsageRecord;
use ClarionApp\LlmClient\Services\MetricsRecorder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for MetricsRecorder's cost_summaries upsert (T036), covering
 * data-model.md §3's atomic insertOrIgnore + column=column+n idiom (the
 * same one upsertSummary() already uses for usage_summaries) applied to the
 * new day-bucketed cost_summaries table.
 *
 * entity_type literals ('conversation'/'user'/'agent') are used directly
 * rather than through the (not-yet-existing) CostSummary model's constants,
 * so this file's own failure is purely about the missing upsert logic, not
 * about a second missing class.
 */
class CostSummaryUpsertTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::table('model_prices')->delete();
        parent::tearDown();
    }

    private function seedPrice(array $overrides = []): ModelPrice
    {
        return ModelPrice::create(array_merge([
            'provider_type' => 'anthropic',
            'model' => 'claude-sonnet-5',
            'reused_input_rate' => '0.30000000',
            'fresh_input_rate' => '3.00000000',
            'output_rate' => '15.00000000',
            'effective_from' => Carbon::now()->subDay(),
            'effective_until' => null,
        ], $overrides));
    }

    private function pricedProviderUsage(): array
    {
        return [
            'prompt_tokens' => 1200,
            'completion_tokens' => 450,
            'total_tokens' => 1650,
            'cache_read_input_tokens' => 900,
        ];
    }

    private function recordUsageFor(
        string $conversationId,
        string $userId,
        array $providerUsage,
        ?string $model,
        ?string $providerType,
        ?string $agentId = null,
    ): void {
        (new MetricsRecorder())->recordUsage(
            conversationId: $conversationId,
            userId: $userId,
            attemptGroupId: (string) Str::uuid(),
            providerUsage: $providerUsage,
            inputText: 'input text',
            outputText: 'output text',
            model: $model,
            providerType: $providerType,
            agentId: $agentId,
        );
    }

    private function summaryRow(string $entityType, string $entityId): ?object
    {
        return DB::table('cost_summaries')
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->first();
    }

    private function recreateCostSummariesTable(): void
    {
        Schema::create('cost_summaries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->enum('entity_type', ['conversation', 'user', 'agent']);
            $table->string('entity_id', 255);
            $table->uuid('user_id');
            $table->date('period_date');
            $table->integer('request_count')->default(0);
            $table->decimal('priced_cost_total', 20, 10)->default(0);
            $table->integer('zero_priced_request_count')->default(0);
            $table->integer('unpriced_request_count')->default(0);
            $table->bigInteger('unpriced_total_tokens')->default(0);
            $table->integer('estimated_request_count')->default(0);
            $table->timestamp('updated_at')->useCurrent();

            $table->unique(['entity_type', 'entity_id', 'user_id', 'period_date']);
            $table->index(['entity_type', 'period_date']);
        });
    }

    #[Test]
    public function one_priced_recordusage_call_upserts_three_cost_summaries_rows(): void
    {
        $this->seedPrice();

        $conversationId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        $this->recordUsageFor(
            $conversationId,
            $userId,
            $this->pricedProviderUsage(),
            'claude-sonnet-5',
            'anthropic',
            'research-agent',
        );

        $conversationRow = $this->summaryRow('conversation', $conversationId);
        $userRow = $this->summaryRow('user', $userId);
        $agentRow = $this->summaryRow('agent', 'research-agent');

        foreach (['conversationRow' => $conversationRow, 'userRow' => $userRow, 'agentRow' => $agentRow] as $label => $row) {
            $this->assertNotNull($row, "$label must exist after a single priced recordUsage() call");
            $this->assertSame(1, (int) $row->request_count);
            $this->assertEqualsWithDelta(0.00792, (float) $row->priced_cost_total, 0.0000001);
            $this->assertSame(0, (int) $row->zero_priced_request_count);
            $this->assertSame(0, (int) $row->unpriced_request_count);
            $this->assertSame(0, (int) $row->unpriced_total_tokens);
        }
    }

    #[Test]
    public function an_unpriced_request_increments_unpriced_counters_and_contributes_zero_to_priced_cost_total(): void
    {
        $conversationId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        // No ModelPrice seeded for this pair at all.
        $this->recordUsageFor(
            $conversationId,
            $userId,
            $this->pricedProviderUsage(),
            'no-such-model',
            'unknown_provider',
        );

        $row = $this->summaryRow('conversation', $conversationId);

        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->request_count);
        $this->assertEqualsWithDelta(0.0, (float) $row->priced_cost_total, 0.0000001);
        $this->assertSame(0, (int) $row->zero_priced_request_count);
        $this->assertSame(1, (int) $row->unpriced_request_count);
        $this->assertSame(1650, (int) $row->unpriced_total_tokens);
    }

    #[Test]
    public function a_zero_priced_request_increments_zero_priced_count_not_unpriced_count(): void
    {
        $this->seedPrice([
            'provider_type' => 'llama_cpp',
            'model' => 'local-llama',
            'reused_input_rate' => '0',
            'fresh_input_rate' => '0',
            'output_rate' => '0',
        ]);

        $conversationId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        $this->recordUsageFor(
            $conversationId,
            $userId,
            $this->pricedProviderUsage(),
            'local-llama',
            'llama_cpp',
        );

        $row = $this->summaryRow('conversation', $conversationId);

        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->request_count);
        $this->assertSame(1, (int) $row->zero_priced_request_count);
        $this->assertSame(0, (int) $row->unpriced_request_count);
        $this->assertEqualsWithDelta(0.0, (float) $row->priced_cost_total, 0.0000001);
    }

    #[Test]
    public function two_recordusage_calls_for_the_same_entity_and_day_atomically_accumulate(): void
    {
        $this->seedPrice();

        $conversationId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        $this->recordUsageFor($conversationId, $userId, $this->pricedProviderUsage(), 'claude-sonnet-5', 'anthropic');
        $this->recordUsageFor($conversationId, $userId, $this->pricedProviderUsage(), 'claude-sonnet-5', 'anthropic');

        $row = $this->summaryRow('conversation', $conversationId);

        $this->assertNotNull($row);
        $this->assertSame(2, (int) $row->request_count, 'No lost update across two upserts for the same bucket');
        $this->assertEqualsWithDelta(0.01584, (float) $row->priced_cost_total, 0.0000001);
    }

    #[Test]
    public function a_failure_specific_to_cost_summaries_upsert_does_not_roll_back_the_usage_record_or_usage_summaries(): void
    {
        $this->seedPrice();

        $conversationId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        // Force the cost_summaries-specific upsert step to fail by removing
        // its table entirely, while usage_records/usage_summaries remain.
        Schema::drop('cost_summaries');

        $this->recordUsageFor($conversationId, $userId, $this->pricedProviderUsage(), 'claude-sonnet-5', 'anthropic');

        $usageRecord = UsageRecord::where('conversation_id', $conversationId)->first();
        $this->assertNotNull(
            $usageRecord,
            'A cost_summaries-specific failure must not suppress the usage record itself'
        );

        $convSummary = DB::table('usage_summaries')->where('entity_type', 'conversation')->where('entity_id', $conversationId)->first();
        $userSummary = DB::table('usage_summaries')->where('entity_type', 'user')->where('entity_id', $userId)->first();
        $this->assertNotNull($convSummary, 'usage_summaries must still be incremented despite the cost_summaries failure');
        $this->assertNotNull($userSummary);
        $this->assertSame(1, (int) $convSummary->request_count);
        $this->assertSame(1, (int) $userSummary->request_count);

        // Recreate the table and prove the write path resumes normally on
        // the next call — the earlier failure was isolated to that one
        // call's cost_summaries step, not a permanent break.
        $this->recreateCostSummariesTable();

        $this->recordUsageFor($conversationId, $userId, $this->pricedProviderUsage(), 'claude-sonnet-5', 'anthropic');

        $row = $this->summaryRow('conversation', $conversationId);
        $this->assertNotNull($row, 'Once cost_summaries exists again, the next call must resume writing to it');
        $this->assertSame(1, (int) $row->request_count, 'Only the second call succeeded in writing; the first was isolated away');
    }

    /**
     * data-model.md §2/§3 (FR-015): recordUsage() captures `$now = now()`
     * exactly once at the top of its transaction closure, and every
     * cost_summaries `period_date` is bucketed from that captured instant's
     * `toDateString()` — never from a fresh `now()` call taken later in the
     * same transaction. `Carbon::setTestNow()` can't distinguish these two
     * implementations: it freezes every `now()` call in the process to the
     * same value, so a stray fresh read would coincidentally match the
     * captured one 100% of the time in a test. Instead this test replaces
     * the `Date` facade `now()` resolves through (see the global `now()`
     * helper) with a Mockery double that returns a different Carbon instant
     * on each successive call — simulating real wall-clock time crossing a
     * day boundary mid-transaction. recordUsage()'s own `$now = now()`
     * capture is the first call and gets the pre-midnight instant; every
     * other `now()` call already present in the method (upsertSummary()'s
     * and upsertCostSummary()'s own `updated_at => now()` writes) happens
     * after it and gets the post-midnight instant instead. Only a
     * `period_date` derived from something other than the captured `$now`
     * (i.e. a fresh `now()` call) could observe the post-midnight date.
     */
    #[Test]
    public function period_date_is_bucketed_from_the_captured_now_not_a_fresh_clock_read_later_in_the_transaction(): void
    {
        $this->seedPrice();

        $beforeMidnight = Carbon::parse('2026-08-07 23:59:59.900000');
        $afterMidnight = Carbon::parse('2026-08-08 00:00:00.100000');

        // First call (recordUsage()'s `$now = now()`) gets $beforeMidnight;
        // every subsequent now() call in the same recordUsage() invocation
        // gets (and keeps getting, per Mockery's andReturn() repeating its
        // last argument) $afterMidnight.
        Date::shouldReceive('now')->andReturn($beforeMidnight, $afterMidnight);

        $conversationId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        $this->recordUsageFor($conversationId, $userId, $this->pricedProviderUsage(), 'claude-sonnet-5', 'anthropic', 'research-agent');

        $conversationRow = $this->summaryRow('conversation', $conversationId);
        $userRow = $this->summaryRow('user', $userId);
        $agentRow = $this->summaryRow('agent', 'research-agent');

        foreach (['conversationRow' => $conversationRow, 'userRow' => $userRow, 'agentRow' => $agentRow] as $label => $row) {
            $this->assertNotNull($row, "$label must exist");
            $this->assertSame(
                '2026-08-07',
                Carbon::parse($row->period_date)->toDateString(),
                "$label's period_date must reflect the instant captured once at the top of recordUsage(), not a later, post-midnight now() call in the same transaction"
            );
        }
    }
}
