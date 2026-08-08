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
 * FR-011 access-scoping proof for the full authorization table in
 * contracts/tool-reliability-api.md §4: an operator's read is always the
 * full cross-user total for the requested scope; a non-operator's read
 * always narrows to their own invocations -- never a 403, since (unlike a
 * conversation) no tool or agent is "owned" by anyone here for a
 * non-operator to be forbidden from. Mirrors RollupRoleScopingJourneyTest's
 * (073) and LatencyAccessScopingJourneyTest's (074) structure for this
 * feature's own three GET endpoints (show, list, agentBreakdown).
 */
class ToolReliabilityAccessScopingJourneyTest extends TestCase
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

    private function recordAt(Carbon $when, string $toolName, string $userId, bool $success, ?string $agentId = null, ?ToolFailureCategory $category = null): void
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
                agentId: $agentId,
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * contracts/tool-reliability-api.md §4 row 1: a non-operator's
     * GET /tool-reliability/tools/{toolName} reflects only their own
     * invocations, compared against a second non-operator's own call and
     * an operator's unrestricted view -- and never a 403.
     */
    #[Test]
    public function non_operator_single_tool_read_reflects_only_their_own_invocations_never_403(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $day = Carbon::parse('2026-03-02 10:00:00', 'UTC');

        for ($i = 0; $i < 4; $i++) {
            $this->recordAt($day, 'shared_tool', $userA->id, true);
        }
        $this->recordAt($day, 'shared_tool', $userA->id, false, null, ToolFailureCategory::Timeout);

        for ($i = 0; $i < 9; $i++) {
            $this->recordAt($day, 'shared_tool', $userB->id, false, null, ToolFailureCategory::ServerError);
        }

        $url = $this->endpoint('tools/shared_tool?period=day&date=2026-03-02');

        $userAResponse = $this->actingAs($userA)->getJson($url);
        $userAResponse->assertStatus(200, "a non-operator's read must never be 403'd");
        $this->assertSame(5, $userAResponse->json('invocation_count'), "userA's own invocations only, never userB's");
        $this->assertSame(1, $userAResponse->json('failure_count'));

        $userBResponse = $this->actingAs($userB)->getJson($url);
        $userBResponse->assertStatus(200);
        $this->assertSame(9, $userBResponse->json('invocation_count'), "userB's own invocations only, never userA's");
        $this->assertSame(9, $userBResponse->json('failure_count'));
    }

    /**
     * contracts/tool-reliability-api.md §4 row 1: an operator's read is
     * unrestricted -- its count equals or exceeds the sum of both
     * non-operators' own counts for the same tool/period.
     */
    #[Test]
    public function operator_single_tool_read_is_the_unrestricted_cross_user_total(): void
    {
        $operator = User::factory()->create();
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        config(['llm-client.cost.operator_user_ids' => [$operator->id]]);

        $day = Carbon::parse('2026-03-02 10:00:00', 'UTC');
        for ($i = 0; $i < 4; $i++) {
            $this->recordAt($day, 'shared_tool', $userA->id, true);
        }
        for ($i = 0; $i < 9; $i++) {
            $this->recordAt($day, 'shared_tool', $userB->id, false, null, ToolFailureCategory::ServerError);
        }

        $url = $this->endpoint('tools/shared_tool?period=day&date=2026-03-02');

        $operatorResponse = $this->actingAs($operator)->getJson($url);
        $operatorResponse->assertStatus(200);
        // The operator has no invocations of their own at all, yet sees
        // both other users' contributions summed -- proving this is a
        // genuinely unrestricted cross-user read, not merely "not 403".
        $this->assertSame(13, $operatorResponse->json('invocation_count'), "an operator's read must sum every user's contribution");
        $this->assertSame(9, $operatorResponse->json('failure_count'));
    }

    /**
     * contracts/tool-reliability-api.md §4 row 2: a non-operator's
     * GET /tool-reliability/tools (list) omits any tool the caller never
     * personally invoked, even if another user invoked it heavily.
     */
    #[Test]
    public function non_operator_tool_list_omits_tools_the_caller_never_personally_invoked(): void
    {
        $caller = User::factory()->create();
        $stranger = User::factory()->create();
        $day = Carbon::parse('2026-03-03 10:00:00', 'UTC');

        for ($i = 0; $i < 3; $i++) {
            $this->recordAt($day, 'callers_own_tool', $caller->id, true);
        }
        for ($i = 0; $i < 500; $i++) {
            $this->recordAt($day, 'strangers_heavily_used_tool', $stranger->id, false, null, ToolFailureCategory::Timeout);
        }

        $response = $this->actingAs($caller)->getJson(
            $this->endpoint('tools?period=day&date=2026-03-03')
        );
        $response->assertStatus(200, "a non-operator's list read must never be 403'd");

        $names = collect($response->json('data'))->pluck('tool_name')->all();
        $this->assertContains('callers_own_tool', $names);
        $this->assertNotContains(
            'strangers_heavily_used_tool',
            $names,
            "a tool the caller never personally invoked must not appear, no matter how heavily another user invoked it"
        );
    }

    /**
     * contracts/tool-reliability-api.md §4 row 2: an operator's list read
     * includes every tool with activity, full cross-user totals.
     */
    #[Test]
    public function operator_tool_list_includes_every_tool_with_full_cross_user_totals(): void
    {
        $operator = User::factory()->create();
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        config(['llm-client.cost.operator_user_ids' => [$operator->id]]);

        $day = Carbon::parse('2026-03-03 10:00:00', 'UTC');
        for ($i = 0; $i < 3; $i++) {
            $this->recordAt($day, 'user_a_tool', $userA->id, true);
        }
        for ($i = 0; $i < 6; $i++) {
            $this->recordAt($day, 'user_b_tool', $userB->id, false, null, ToolFailureCategory::Timeout);
        }

        $response = $this->actingAs($operator)->getJson(
            $this->endpoint('tools?period=day&date=2026-03-03')
        );
        $response->assertStatus(200);

        $names = collect($response->json('data'))->pluck('tool_name')->all();
        $this->assertContains('user_a_tool', $names);
        $this->assertContains('user_b_tool', $names, "an operator's list must include every tool with activity, regardless of who invoked it");
    }

    /**
     * contracts/tool-reliability-api.md §4 row 3: a non-operator's
     * GET /tool-reliability/tools/{toolName}/agents shows only their own
     * contribution to each agent's total, and omits an agent the caller
     * never personally used this tool under.
     */
    #[Test]
    public function non_operator_agent_breakdown_shows_only_their_own_contribution_and_omits_untouched_agents(): void
    {
        $caller = User::factory()->create();
        $stranger = User::factory()->create();
        $day = Carbon::parse('2026-03-04 10:00:00', 'UTC');

        // A shared agent both users contributed to -- the caller must see
        // only their own contribution to it.
        for ($i = 0; $i < 2; $i++) {
            $this->recordAt($day, 'shared_tool', $caller->id, true, 'shared-agent');
        }
        for ($i = 0; $i < 40; $i++) {
            $this->recordAt($day, 'shared_tool', $stranger->id, false, 'shared-agent', ToolFailureCategory::ServerError);
        }

        // An agent the caller never personally used this tool under -- must
        // be omitted from the caller's own breakdown entirely.
        for ($i = 0; $i < 15; $i++) {
            $this->recordAt($day, 'shared_tool', $stranger->id, false, 'strangers-only-agent', ToolFailureCategory::Timeout);
        }

        $response = $this->actingAs($caller)->getJson(
            $this->endpoint('tools/shared_tool/agents?period=day&date=2026-03-04')
        );
        $response->assertStatus(200, "a non-operator's agent-breakdown read must never be 403'd");

        $rows = collect($response->json('data'));
        $agentIds = $rows->pluck('agent_id')->all();

        $this->assertContains('shared-agent', $agentIds);
        $this->assertNotContains(
            'strangers-only-agent',
            $agentIds,
            'an agent the caller never personally used this tool under must not appear as a row'
        );

        $sharedAgentRow = $rows->firstWhere('agent_id', 'shared-agent');
        $this->assertSame(2, $sharedAgentRow['invocation_count'], "only the caller's own contribution to shared-agent's total, never the stranger's 40");
        $this->assertSame(0, $sharedAgentRow['failure_count'], "the stranger's 40 failures on shared-agent must not be folded into the caller's own view");
    }

    /**
     * contracts/tool-reliability-api.md §4 row 3: an operator's agent
     * breakdown shows every agent's full cross-user total.
     */
    #[Test]
    public function operator_agent_breakdown_shows_every_agents_full_cross_user_total(): void
    {
        $operator = User::factory()->create();
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        config(['llm-client.cost.operator_user_ids' => [$operator->id]]);

        $day = Carbon::parse('2026-03-04 10:00:00', 'UTC');
        for ($i = 0; $i < 2; $i++) {
            $this->recordAt($day, 'shared_tool', $userA->id, true, 'shared-agent');
        }
        for ($i = 0; $i < 40; $i++) {
            $this->recordAt($day, 'shared_tool', $userB->id, false, 'shared-agent', ToolFailureCategory::ServerError);
        }

        $response = $this->actingAs($operator)->getJson(
            $this->endpoint('tools/shared_tool/agents?period=day&date=2026-03-04')
        );
        $response->assertStatus(200);

        $sharedAgentRow = collect($response->json('data'))->firstWhere('agent_id', 'shared-agent');
        $this->assertSame(42, $sharedAgentRow['invocation_count'], "an operator sees both users' contributions to shared-agent summed");
        $this->assertSame(40, $sharedAgentRow['failure_count']);
    }
}
