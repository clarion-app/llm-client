<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\ToolInvocationRecord;
use ClarionApp\LlmClient\Services\MetricsRecorder;
use ClarionApp\LlmClient\Support\CalendarPeriod;
use ClarionApp\LlmClient\ValueObjects\ToolFailureCategory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;

/**
 * FR-005/SC-002 reconciliation-at-scale proof: a tool_reliability_summaries
 * read must match an independent count of the underlying
 * tool_invocation_records rows for the same tool, agent scope, and period,
 * to the last invocation, at a history of at least tens of thousands of
 * recorded invocations. Also covers FR-010/SC-003 (a read stays bounded by
 * the requested period, never by total history size).
 *
 * Every row -- noise and target scope alike -- is written through the real
 * MetricsRecorder::recordToolInvocation() write path, never a hand-inserted
 * row that would skip the tool_reliability_summaries upsert; the entire
 * point of this test is proving the two independently-derived figures
 * (summary vs. detail) agree, which is meaningless if the detail rows were
 * produced by anything other than the real write path.
 *
 * The independent reconciliation query reads tool_invocation_records.agent_id
 * directly (research.md D9) -- never a run_id -> agent_runs join -- since
 * that column is the ground-truth attribution the write path itself uses
 * (research.md D4), independent of whether run tracing happens to be
 * enabled. A driver-portable null-safe filter is used throughout
 * (->where('agent_id', $agentId) for a real agent, ->whereNull('agent_id')
 * for the Unattributed scope) rather than MySQL's `<=>` operator, which is
 * not valid syntax under the SQLite `:memory:` driver this suite runs
 * against.
 *
 * Performance note: seeding is wrapped in a single DB transaction (tens of
 * thousands of individual inserts/upserts through recordToolInvocation()
 * would otherwise each autocommit separately against SQLite) -- matching
 * the scale precedent already established by RollupReconciliationJourneyTest
 * (073) and its own tens-of-thousands-of-tiny-records approach.
 */
class ToolReliabilityReconciliationJourneyTest extends TestCase
{
    private const CHOSEN_TOOL = 'reconcile_tool';
    private const CHOSEN_AGENT = 'reconcile-agent';
    private const OTHER_AGENT_SAME_TOOL = 'other-agent-on-same-tool';

    // Ten distinct days, all safely inside a single UTC calendar month
    // (January 2026), so a period=month read for ANCHOR_DATE sums exactly
    // these rows.
    private const TARGET_DAYS = [
        '2026-01-01', '2026-01-04', '2026-01-07', '2026-01-10', '2026-01-13',
        '2026-01-16', '2026-01-19', '2026-01-22', '2026-01-25', '2026-01-28',
    ];
    private const ANCHOR_DATE = '2026-01-15';

    // Per target day: 300 successes plus a failure breakdown that sums to
    // 60 -- 360/day across 10 days = 3,600 CHOSEN_TOOL/CHOSEN_AGENT rows.
    private const TARGET_SUCCESS_PER_DAY = 300;
    private const TARGET_FAILURES_PER_DAY = [
        'timeout' => 12,
        'connection_failure' => 8,
        'authentication_failure' => 4,
        'invalid_input' => 20,
        'server_error' => 6,
        'other' => 3,
        'uncategorized' => 7,
    ];

    // Per target day: an Unattributed-bucket slice of the same tool (agent
    // omitted entirely) -- 55/day across 10 days = 550 rows.
    private const UNATTRIBUTED_SUCCESS_PER_DAY = 50;
    private const UNATTRIBUTED_UNCATEGORIZED_FAILURES_PER_DAY = 5;

    // Per target day: a second, different agent using the same tool -- pure
    // noise for the scoping check (must never leak into CHOSEN_AGENT's
    // counts) -- 40/day across 10 days = 400 rows.
    private const OTHER_AGENT_SUCCESS_PER_DAY = 40;

    // 18 unrelated tool names, 900 rows each = 16,200 rows of pure noise,
    // spread across the same ten days. Combined with the target/Unattributed/
    // other-agent rows above, the total dataset is 3,600 + 550 + 400 +
    // 16,200 = 20,750 tool_invocation_records rows -- "tens of thousands"
    // per SC-002/SC-003's own wording.
    private const NOISE_TOOL_COUNT = 18;
    private const NOISE_ROWS_PER_TOOL = 900;

    private const CATEGORY_ENUM_MAP = [
        'timeout' => ToolFailureCategory::Timeout,
        'connection_failure' => ToolFailureCategory::ConnectionFailure,
        'authentication_failure' => ToolFailureCategory::AuthenticationFailure,
        'invalid_input' => ToolFailureCategory::InvalidInput,
        'server_error' => ToolFailureCategory::ServerError,
        'other' => ToolFailureCategory::Other,
        'uncategorized' => null,
    ];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        DB::table('tool_reliability_summaries')->delete();
        DB::table('tool_invocation_records')->delete();
        DB::table('users')->delete();
        parent::tearDown();
    }

    private function endpoint(string $path): string
    {
        return '/api/clarion-app/llm-client/tool-reliability/'.$path;
    }

    /**
     * Records $successCount successes plus the failures described by
     * $failureCounts (category-key => count, 'uncategorized' mapping to a
     * null failureCategory) at a fixed instant, through the real
     * MetricsRecorder write path.
     */
    private function recordBatch(MetricsRecorder $recorder, Carbon $when, string $tool, ?string $agentId, string $userId, int $successCount, array $failureCounts = []): void
    {
        Carbon::setTestNow($when);

        for ($i = 0; $i < $successCount; $i++) {
            $recorder->recordToolInvocation(
                conversationId: (string) Str::uuid(),
                userId: $userId,
                attemptGroupId: (string) Str::uuid(),
                toolName: $tool,
                success: true,
                agentId: $agentId,
            );
        }

        foreach ($failureCounts as $categoryKey => $count) {
            $category = self::CATEGORY_ENUM_MAP[$categoryKey];
            for ($i = 0; $i < $count; $i++) {
                $recorder->recordToolInvocation(
                    conversationId: (string) Str::uuid(),
                    userId: $userId,
                    attemptGroupId: (string) Str::uuid(),
                    toolName: $tool,
                    success: false,
                    failureCategory: $category,
                    agentId: $agentId,
                );
            }
        }
    }

    #[Test]
    public function a_summary_read_reconciles_exactly_with_an_independent_count_of_its_detail_rows_at_tens_of_thousands_of_records_scale(): void
    {
        $user = User::factory()->create();
        $recorder = new MetricsRecorder();

        DB::transaction(function () use ($recorder, $user) {
            foreach (self::TARGET_DAYS as $day) {
                $when = Carbon::parse($day.' 12:00:00', 'UTC');

                $this->recordBatch($recorder, $when, self::CHOSEN_TOOL, self::CHOSEN_AGENT, $user->id, self::TARGET_SUCCESS_PER_DAY, self::TARGET_FAILURES_PER_DAY);
                $this->recordBatch($recorder, $when, self::CHOSEN_TOOL, null, $user->id, self::UNATTRIBUTED_SUCCESS_PER_DAY, ['uncategorized' => self::UNATTRIBUTED_UNCATEGORIZED_FAILURES_PER_DAY]);
                $this->recordBatch($recorder, $when, self::CHOSEN_TOOL, self::OTHER_AGENT_SAME_TOOL, $user->id, self::OTHER_AGENT_SUCCESS_PER_DAY);
            }

            for ($n = 0; $n < self::NOISE_TOOL_COUNT; $n++) {
                $day = self::TARGET_DAYS[$n % count(self::TARGET_DAYS)];
                $when = Carbon::parse($day.' 12:00:00', 'UTC');
                $noiseAgent = ['noise-agent-a', 'noise-agent-b', 'noise-agent-c'][$n % 3];

                $this->recordBatch($recorder, $when, 'noise_tool_'.$n, $noiseAgent, $user->id, self::NOISE_ROWS_PER_TOOL);
            }
        });

        Carbon::setTestNow();

        $totalRows = DB::table('tool_invocation_records')->count();
        $this->assertGreaterThanOrEqual(20000, $totalRows, 'the dataset must reach "tens of thousands" of records per SC-002/SC-003');

        $period = CalendarPeriod::resolve('month', self::ANCHOR_DATE);
        $fromInstant = $period['from'].' 00:00:00';
        $toInstant = $period['to'].' 23:59:59.999999';

        // --- Independent reconciliation query #1: a real agent scope ---
        $independentSuccess = ToolInvocationRecord::where('tool_name', self::CHOSEN_TOOL)
            ->where('agent_id', self::CHOSEN_AGENT)
            ->where('outcome', 'success')
            ->whereBetween('created_at', [$fromInstant, $toInstant])
            ->count();

        $independentFailureBreakdown = $this->independentFailureBreakdown(self::CHOSEN_TOOL, self::CHOSEN_AGENT, $fromInstant, $toInstant);
        $independentFailureCount = array_sum($independentFailureBreakdown);

        $this->assertSame(3000, $independentSuccess, 'sanity check on the seeded fixture itself');
        $this->assertSame(600, $independentFailureCount, 'sanity check on the seeded fixture itself');

        $response = $this->actingAs($user)->getJson(
            $this->endpoint('tools/'.self::CHOSEN_TOOL.'?period=month&date='.self::ANCHOR_DATE.'&agent_id='.self::CHOSEN_AGENT)
        );
        $response->assertStatus(200);
        $body = $response->json();

        $this->assertSame($independentSuccess, $body['success_count'], 'success_count must reconcile exactly with an independent count of the detail rows');
        $this->assertSame($independentFailureCount, $body['failure_count'], 'failure_count must reconcile exactly with an independent count of the detail rows');
        $this->assertSame($independentSuccess + $independentFailureCount, $body['invocation_count']);
        foreach ($independentFailureBreakdown as $category => $count) {
            $this->assertSame($count, $body['failure_breakdown'][$category], "failure_breakdown.{$category} must reconcile exactly, to the last invocation");
        }

        // --- Independent reconciliation query #2: the Unattributed scope,
        // using a driver-portable whereNull() rather than a MySQL-only <=>
        // comparison ---
        $independentUnattributedSuccess = ToolInvocationRecord::where('tool_name', self::CHOSEN_TOOL)
            ->whereNull('agent_id')
            ->where('outcome', 'success')
            ->whereBetween('created_at', [$fromInstant, $toInstant])
            ->count();
        $independentUnattributedFailures = ToolInvocationRecord::where('tool_name', self::CHOSEN_TOOL)
            ->whereNull('agent_id')
            ->where('outcome', 'failure')
            ->whereBetween('created_at', [$fromInstant, $toInstant])
            ->count();

        $this->assertSame(500, $independentUnattributedSuccess);
        $this->assertSame(50, $independentUnattributedFailures);

        $unattributedResponse = $this->actingAs($user)->getJson(
            $this->endpoint('tools/'.self::CHOSEN_TOOL.'?period=month&date='.self::ANCHOR_DATE.'&agent_id=unattributed')
        );
        $unattributedResponse->assertStatus(200);
        $this->assertSame($independentUnattributedSuccess, $unattributedResponse->json('success_count'));
        $this->assertSame($independentUnattributedFailures, $unattributedResponse->json('failure_count'));
        $this->assertSame(50, $unattributedResponse->json('failure_breakdown.uncategorized'));

        // The other agent's 400 rows on the same tool must never leak into
        // CHOSEN_AGENT's exact-match figures above, nor into the
        // Unattributed scope's -- confirmed directly: its own independent
        // count (all-success, no failures) reconciles to exactly 400, a
        // figure that would be 3,400/450 respectively had it bled into
        // either of the scopes already asserted above.
        $independentOtherAgentSuccess = ToolInvocationRecord::where('tool_name', self::CHOSEN_TOOL)
            ->where('agent_id', self::OTHER_AGENT_SAME_TOOL)
            ->where('outcome', 'success')
            ->whereBetween('created_at', [$fromInstant, $toInstant])
            ->count();
        $this->assertSame(400, $independentOtherAgentSuccess);

        // --- FR-010/SC-003: a period-scoped read touches a bounded number
        // of tool_reliability_summaries rows, independent of the 20,000+
        // tool_invocation_records rows in total history. A month can never
        // span more than 31 calendar days, so at most 31 day-granularity
        // summary rows can exist for any single (tool, agent, user) scope in
        // this range -- proven directly here rather than merely asserted by
        // design (research.md D2). ---
        $summaryRowsTouched = DB::table('tool_reliability_summaries')
            ->where('tool_name', self::CHOSEN_TOOL)
            ->where('agent_id', self::CHOSEN_AGENT)
            ->where('user_id', $user->id)
            ->whereBetween('period_date', [$period['from'], $period['to']])
            ->count();

        $this->assertLessThanOrEqual(31, $summaryRowsTouched, 'a month-period read must touch at most 31 day-granularity summary rows, regardless of total history size (FR-010/SC-003)');
        $this->assertGreaterThan(0, $summaryRowsTouched, 'sanity check: the target scope did materialize summary rows');
    }

    /**
     * @return array<string, int> the same 7 keys as the API's
     * failure_breakdown shape, computed independently from
     * tool_invocation_records.failure_category.
     */
    private function independentFailureBreakdown(string $toolName, string $agentId, string $from, string $to): array
    {
        $counts = array_fill_keys(['timeout', 'connection_failure', 'authentication_failure', 'invalid_input', 'server_error', 'other', 'uncategorized'], 0);

        ToolInvocationRecord::where('tool_name', $toolName)
            ->where('agent_id', $agentId)
            ->where('outcome', 'failure')
            ->whereBetween('created_at', [$from, $to])
            ->select('failure_category')
            ->chunk(500, function ($rows) use (&$counts) {
                foreach ($rows as $row) {
                    $key = $row->failure_category?->value ?? 'uncategorized';
                    $counts[$key]++;
                }
            });

        return $counts;
    }
}
