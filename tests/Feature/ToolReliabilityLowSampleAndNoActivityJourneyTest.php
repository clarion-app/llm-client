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
 * HTTP-level journey test for User Story 3 (spec.md, 075-tool-reliability-
 * rates): an operator viewing a tool's summary can immediately tell whether
 * a rate is backed by enough invocations to mean anything, and can tell a
 * tool that simply was not called during a period apart from one that was
 * called and succeeded every time. Covers spec.md User Story 3 Acceptance
 * Scenarios 1-3, via GET /tool-reliability/tools/{toolName}
 * (contracts/tool-reliability-api.md §1).
 *
 * low_sample/no_activity are computed as part of
 * ToolReliabilityQuery::toolSummary()'s own aggregation step, already built
 * for User Story 1 -- this file adds no new production code and is expected
 * to pass against the existing implementation. Invocations are generated
 * through the real MetricsRecorder::recordToolInvocation() write path
 * (never hand-inserted tool_reliability_summaries rows), mirroring
 * ToolReliabilityRateJourneyTest's and ToolReliabilityAgentScopingJourneyTest's
 * own conventions.
 */
class ToolReliabilityLowSampleAndNoActivityJourneyTest extends TestCase
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
     * Acceptance Scenario 1: a tool with only a handful of invocations in
     * the period is marked as too little data to be a meaningful rate, but
     * its actual (thin) counts are still returned in full, never withheld.
     */
    #[Test]
    public function a_handful_of_invocations_is_marked_low_sample_with_the_real_counts_still_present(): void
    {
        $user = User::factory()->create();
        $now = Carbon::now();

        $this->recordAt($now, 'rare_diagnostic_tool', $user->id, true);
        $this->recordAt($now, 'rare_diagnostic_tool', $user->id, true);
        $this->recordAt($now, 'rare_diagnostic_tool', $user->id, false, ToolFailureCategory::Timeout);

        $response = $this->actingAs($user)->getJson(
            $this->endpoint("tools/rare_diagnostic_tool?period=day&date={$this->today()}")
        );

        $response->assertStatus(200);
        $body = $response->json();

        $this->assertTrue($body['low_sample'], 'fewer than 10 invocations must be flagged low_sample');
        $this->assertFalse($body['no_activity']);
        $this->assertSame(3, $body['invocation_count'], 'the real, thin count must still be reported, never withheld');
        $this->assertSame(2, $body['success_count']);
        $this->assertSame(1, $body['failure_count']);
        $this->assertSame(1, $body['failure_breakdown']['timeout']);
    }

    /**
     * Acceptance Scenario 2: a tool invoked in a prior period but not at
     * all during the requested period shows no_activity for the requested
     * period, explicitly distinct from a 100%-success shape -- not
     * success_count equal to some prior total.
     */
    #[Test]
    public function a_period_with_no_invocations_shows_no_activity_distinct_from_a_prior_periods_success_total(): void
    {
        $user = User::factory()->create();
        $priorPeriod = Carbon::now()->subWeek();
        $requestedPeriod = Carbon::now();

        for ($i = 0; $i < 12; $i++) {
            $this->recordAt($priorPeriod, 'quiet_tool', $user->id, true);
        }

        $response = $this->actingAs($user)->getJson(
            $this->endpoint("tools/quiet_tool?period=day&date={$requestedPeriod->toDateString()}")
        );

        $response->assertStatus(200);
        $body = $response->json();

        $this->assertSame(0, $body['invocation_count']);
        $this->assertTrue($body['no_activity'], 'the requested period had no invocations at all');
        $this->assertSame(0, $body['success_count'], 'must not carry over the prior period\'s 12 successes');
        $this->assertSame(0, $body['failure_count']);
        foreach ($body['failure_breakdown'] as $kind => $count) {
            $this->assertSame(0, $count, "failure_breakdown.{$kind} must be a real computed 0, not omitted");
        }

        // The prior period, read on its own terms, still shows its own
        // activity -- proving the requested period's no_activity reading
        // above is not a global default but genuinely period-specific.
        $priorResponse = $this->actingAs($user)->getJson(
            $this->endpoint("tools/quiet_tool?period=day&date={$priorPeriod->toDateString()}")
        );
        $priorResponse->assertStatus(200);
        $this->assertFalse($priorResponse->json('no_activity'));
        $this->assertSame(12, $priorResponse->json('invocation_count'));
        $this->assertSame(12, $priorResponse->json('success_count'));
    }

    /**
     * Acceptance Scenario 3 / FR-009: a tool name that has never been
     * invoked at all still returns the no_activity shape at 200, never a
     * 404 or other error.
     */
    #[Test]
    public function a_never_invoked_tool_name_returns_the_no_activity_shape_never_a_404(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson(
            $this->endpoint("tools/never_seen_tool?period=day&date={$this->today()}")
        );

        $response->assertStatus(200);
        $body = $response->json();

        $this->assertSame('never_seen_tool', $body['tool_name']);
        $this->assertSame(0, $body['invocation_count']);
        $this->assertSame(0, $body['success_count']);
        $this->assertSame(0, $body['failure_count']);
        $this->assertTrue($body['no_activity']);
    }

    /**
     * research.md D6's exact boundary, reasserted at the HTTP/acceptance
     * level: 9 invocations in the period is low_sample; 10 is not.
     */
    #[Test]
    public function the_low_sample_boundary_is_nine_true_ten_false_over_the_real_endpoint(): void
    {
        $user = User::factory()->create();
        $now = Carbon::now();

        for ($i = 0; $i < 9; $i++) {
            $this->recordAt($now, 'boundary_tool_nine', $user->id, true);
        }
        for ($i = 0; $i < 10; $i++) {
            $this->recordAt($now, 'boundary_tool_ten', $user->id, true);
        }

        $nineResponse = $this->actingAs($user)->getJson(
            $this->endpoint("tools/boundary_tool_nine?period=day&date={$this->today()}")
        );
        $tenResponse = $this->actingAs($user)->getJson(
            $this->endpoint("tools/boundary_tool_ten?period=day&date={$this->today()}")
        );

        $nineResponse->assertStatus(200);
        $tenResponse->assertStatus(200);

        $this->assertSame(9, $nineResponse->json('invocation_count'));
        $this->assertTrue($nineResponse->json('low_sample'), '9 invocations must be low_sample');

        $this->assertSame(10, $tenResponse->json('invocation_count'));
        $this->assertFalse($tenResponse->json('low_sample'), '10 invocations must not be low_sample');
    }
}
