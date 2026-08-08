<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Services\MetricsRecorder;
use ClarionApp\LlmClient\ValueObjects\ToolFailureCategory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;

/**
 * HTTP-level journey test for User Story 1 (spec.md,
 * 075-tool-reliability-rates): an operator picks a tool and a chosen
 * calendar period and sees how many invocations succeeded, how many
 * failed, and a breakdown of the failures by kind -- via
 * GET /tool-reliability/tools/{toolName} and the list form
 * GET /tool-reliability/tools (contracts/tool-reliability-api.md §1-2).
 * Covers spec.md User Story 1 Acceptance Scenarios 1-4.
 *
 * Invocations are generated through the real
 * MetricsRecorder::recordToolInvocation() write path (never hand-inserted
 * tool_reliability_summaries rows), matching CostRollupJourneyTest's
 * approach for its own write-then-read journey. User Story 1 does not yet
 * wire agent attribution at the call sites -- every invocation here omits
 * agentId and lands in the Unattributed bucket, which the all-agents
 * aggregate this story reads sums across along with any named-agent rows.
 */
class ToolReliabilityRateJourneyTest extends TestCase
{
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

    private function today(): string
    {
        return Carbon::now()->toDateString();
    }

    /**
     * Records one tool invocation at a specific instant via the real
     * MetricsRecorder write path, so the resulting
     * tool_reliability_summaries row lands in a specific, controllable UTC
     * day bucket.
     */
    private function recordAt(Carbon $when, string $toolName, string $userId, bool $success, ?ToolFailureCategory $category = null): void
    {
        Carbon::setTestNow($when);
        try {
            (new MetricsRecorder())->recordToolInvocation(
                conversationId: (string) Str::uuid(),
                userId: $userId,
                attemptGroupId: (string) Str::uuid(),
                toolName: $toolName,
                success: $success,
                failureCategory: $category,
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * Acceptance Scenario 1: success/failure counts and a per-kind failure
     * breakdown that accounts for every failure.
     */
    #[Test]
    public function tool_summary_reports_success_failure_counts_and_the_failure_breakdown(): void
    {
        $user = User::factory()->create();
        $now = Carbon::now();

        for ($i = 0; $i < 8; $i++) {
            $this->recordAt($now, 'search_documents', $user->id, true);
        }
        $this->recordAt($now, 'search_documents', $user->id, false, ToolFailureCategory::Timeout);
        $this->recordAt($now, 'search_documents', $user->id, false, ToolFailureCategory::InvalidInput);

        $response = $this->actingAs($user)->getJson(
            $this->endpoint("tools/search_documents?period=day&date={$this->today()}")
        );

        $response->assertStatus(200);
        $body = $response->json();

        $this->assertSame('search_documents', $body['tool_name']);
        $this->assertSame(10, $body['invocation_count']);
        $this->assertSame(8, $body['success_count']);
        $this->assertSame(2, $body['failure_count']);
        $this->assertSame(1, $body['failure_breakdown']['timeout']);
        $this->assertSame(1, $body['failure_breakdown']['invalid_input']);
        $this->assertSame(0, $body['failure_breakdown']['connection_failure']);
        $this->assertSame(0, $body['failure_breakdown']['authentication_failure']);
        $this->assertSame(0, $body['failure_breakdown']['server_error']);
        $this->assertSame(0, $body['failure_breakdown']['other']);
        $this->assertSame(0, $body['failure_breakdown']['uncategorized']);
        $this->assertFalse($body['no_activity']);
    }

    /**
     * Acceptance Scenario 2: only successful invocations in the period
     * shows a real, computed all-zero breakdown, never fabricated/omitted.
     */
    #[Test]
    public function all_successful_invocations_report_a_real_all_zero_failure_breakdown(): void
    {
        $user = User::factory()->create();
        $now = Carbon::now();

        for ($i = 0; $i < 4; $i++) {
            $this->recordAt($now, 'search_documents', $user->id, true);
        }

        $response = $this->actingAs($user)->getJson(
            $this->endpoint("tools/search_documents?period=day&date={$this->today()}")
        );

        $response->assertStatus(200);
        $body = $response->json();

        $this->assertSame(4, $body['invocation_count']);
        $this->assertSame(0, $body['failure_count']);
        foreach ($body['failure_breakdown'] as $kind => $count) {
            $this->assertSame(0, $count, "failure_breakdown.{$kind} must be a real computed 0");
        }
    }

    /**
     * Acceptance Scenario 3: invocations recorded on two different UTC days
     * are never blended -- each day's own period=day&date=... read reflects
     * only that day's invocations.
     */
    #[Test]
    public function invocations_on_different_days_are_never_blended(): void
    {
        $user = User::factory()->create();
        $today = Carbon::now();
        $yesterday = Carbon::now()->subDay();

        for ($i = 0; $i < 3; $i++) {
            $this->recordAt($today, 'search_documents', $user->id, true);
        }
        for ($i = 0; $i < 5; $i++) {
            $this->recordAt($yesterday, 'search_documents', $user->id, false, ToolFailureCategory::ServerError);
        }

        $todayResponse = $this->actingAs($user)->getJson(
            $this->endpoint("tools/search_documents?period=day&date={$today->toDateString()}")
        );
        $todayResponse->assertStatus(200);
        $this->assertSame(3, $todayResponse->json('invocation_count'));
        $this->assertSame(0, $todayResponse->json('failure_count'));

        $yesterdayResponse = $this->actingAs($user)->getJson(
            $this->endpoint("tools/search_documents?period=day&date={$yesterday->toDateString()}")
        );
        $yesterdayResponse->assertStatus(200);
        $this->assertSame(5, $yesterdayResponse->json('invocation_count'));
        $this->assertSame(5, $yesterdayResponse->json('failure_count'));
    }

    /**
     * Acceptance Scenario 4: switching which tool is requested reflects
     * that tool specifically, never a blend of others.
     */
    #[Test]
    public function switching_the_requested_tool_reflects_that_tool_specifically(): void
    {
        $user = User::factory()->create();
        $now = Carbon::now();

        for ($i = 0; $i < 2; $i++) {
            $this->recordAt($now, 'search_documents', $user->id, true);
        }
        for ($i = 0; $i < 6; $i++) {
            $this->recordAt($now, 'fetch_url', $user->id, false, ToolFailureCategory::ConnectionFailure);
        }

        $searchResponse = $this->actingAs($user)->getJson(
            $this->endpoint("tools/search_documents?period=day&date={$this->today()}")
        );
        $fetchResponse = $this->actingAs($user)->getJson(
            $this->endpoint("tools/fetch_url?period=day&date={$this->today()}")
        );

        $searchResponse->assertStatus(200);
        $fetchResponse->assertStatus(200);

        $this->assertSame(2, $searchResponse->json('invocation_count'));
        $this->assertSame(0, $searchResponse->json('failure_count'));

        $this->assertSame(6, $fetchResponse->json('invocation_count'));
        $this->assertSame(6, $fetchResponse->json('failure_count'));
        $this->assertSame(6, $fetchResponse->json('failure_breakdown.connection_failure'));
    }

    /**
     * The list form (SC-001) returns one entry per tool with activity in
     * the period, ordered failure_count DESC, so the worst-performing tool
     * is visible at a glance.
     */
    #[Test]
    public function tool_list_returns_one_entry_per_tool_ordered_by_failure_count_descending(): void
    {
        $user = User::factory()->create();
        $now = Carbon::now();

        for ($i = 0; $i < 9; $i++) {
            $this->recordAt($now, 'reliable_tool', $user->id, true);
        }
        for ($i = 0; $i < 7; $i++) {
            $this->recordAt($now, 'unreliable_tool', $user->id, false, ToolFailureCategory::Timeout);
        }

        $response = $this->actingAs($user)->getJson(
            $this->endpoint("tools?period=day&date={$this->today()}")
        );

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('tool_name')->all();

        $this->assertContains('reliable_tool', $names);
        $this->assertContains('unreliable_tool', $names);
        $this->assertSame('unreliable_tool', $names[0], 'the tool with more failures must be listed first');
    }

    /**
     * 422 when period is missing or not one of day/week/month.
     */
    #[Test]
    public function missing_or_invalid_period_returns_422(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson(
            $this->endpoint("tools/search_documents?date={$this->today()}")
        );
        $response->assertStatus(422);

        $response = $this->actingAs($user)->getJson(
            $this->endpoint("tools/search_documents?period=fortnight&date={$this->today()}")
        );
        $response->assertStatus(422);
    }

    /**
     * 422 when date is missing or not a valid date.
     */
    #[Test]
    public function missing_or_invalid_date_returns_422(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson(
            $this->endpoint('tools/search_documents?period=day')
        );
        $response->assertStatus(422);

        $response = $this->actingAs($user)->getJson(
            $this->endpoint('tools/search_documents?period=day&date=not-a-date')
        );
        $response->assertStatus(422);
    }
}
