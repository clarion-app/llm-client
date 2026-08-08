<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Services\MetricsRecorder;
use ClarionApp\LlmClient\Models\ToolReliabilitySummary;
use ClarionApp\LlmClient\ValueObjects\ToolFailureCategory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use PHPUnit\Framework\Attributes\Test;

/**
 * HTTP-level journey test for User Story 2 (spec.md, 075-tool-reliability-
 * rates): an operator narrows a tool's reliability summary to a single
 * agent -- or the explicit Unattributed bucket -- so a given agent's use of
 * a tool can be judged without the signal being diluted by every other
 * agent calling the same tool. Covers spec.md User Story 2 Acceptance
 * Scenarios 1-2 and FR-013, via GET /tool-reliability/tools/{toolName}
 * (with the agent_id query param) and
 * GET /tool-reliability/tools/{toolName}/agents (contracts/tool-reliability-
 * api.md §1 and §3).
 *
 * Invocations are generated through the real
 * MetricsRecorder::recordToolInvocation() write path (never hand-inserted
 * tool_reliability_summaries rows), passing agentId directly rather than
 * routing through AgentLoopService/AgentLoopStreamHandler -- those two call
 * sites' own agent-attribution wiring is covered separately by
 * AgentLoopServiceTest and AgentLoopStreamHandlerTest.
 */
class ToolReliabilityAgentScopingJourneyTest extends TestCase
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
     * MetricsRecorder write path, optionally attributed to a real agent id
     * (omitted/null lands in the Unattributed bucket).
     */
    private function recordAt(Carbon $when, string $toolName, string $userId, bool $success, ?ToolFailureCategory $category = null, ?string $agentId = null): void
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
     * Acceptance Scenario 1: a tool invoked by two different agents -- the
     * agent-scoped read reflects only the requested agent's invocations,
     * never a blend of both.
     */
    #[Test]
    public function agent_scoped_reads_reflect_only_the_requested_agents_invocations(): void
    {
        $user = User::factory()->create();
        $now = Carbon::now();

        for ($i = 0; $i < 6; $i++) {
            $this->recordAt($now, 'shared_tool', $user->id, true, null, 'agent-alpha');
        }
        $this->recordAt($now, 'shared_tool', $user->id, false, ToolFailureCategory::Timeout, 'agent-alpha');

        for ($i = 0; $i < 3; $i++) {
            $this->recordAt($now, 'shared_tool', $user->id, false, ToolFailureCategory::ServerError, 'agent-beta');
        }

        $alphaResponse = $this->actingAs($user)->getJson(
            $this->endpoint("tools/shared_tool?period=day&date={$this->today()}&agent_id=agent-alpha")
        );
        $betaResponse = $this->actingAs($user)->getJson(
            $this->endpoint("tools/shared_tool?period=day&date={$this->today()}&agent_id=agent-beta")
        );

        $alphaResponse->assertStatus(200);
        $betaResponse->assertStatus(200);

        $this->assertSame('agent-alpha', $alphaResponse->json('agent_id'));
        $this->assertSame(7, $alphaResponse->json('invocation_count'), "agent-alpha's own invocations only, never agent-beta's");
        $this->assertSame(1, $alphaResponse->json('failure_count'));
        $this->assertSame(1, $alphaResponse->json('failure_breakdown.timeout'));
        $this->assertSame(0, $alphaResponse->json('failure_breakdown.server_error'), "agent-beta's failures must not leak into agent-alpha's scope");

        $this->assertSame('agent-beta', $betaResponse->json('agent_id'));
        $this->assertSame(3, $betaResponse->json('invocation_count'), "agent-beta's own invocations only, never agent-alpha's");
        $this->assertSame(3, $betaResponse->json('failure_count'));
        $this->assertSame(3, $betaResponse->json('failure_breakdown.server_error'));
    }

    /**
     * FR-013: invocations with no agent attribution appear under the
     * literal `agent_id=unattributed` query value -- never the raw sentinel
     * UUID anywhere in the response -- and as an explicit
     * "agent_id": "unattributed" row in the per-agent breakdown, alongside
     * the named-agent rows, never merged into one or omitted.
     */
    #[Test]
    public function unattributed_invocations_are_addressable_by_the_literal_query_value_and_appear_as_an_explicit_breakdown_row(): void
    {
        $user = User::factory()->create();
        $now = Carbon::now();

        for ($i = 0; $i < 5; $i++) {
            $this->recordAt($now, 'shared_tool', $user->id, true, null, 'agent-alpha');
        }
        for ($i = 0; $i < 2; $i++) {
            $this->recordAt($now, 'shared_tool', $user->id, false, ToolFailureCategory::InvalidInput, null);
        }

        $unattributedResponse = $this->actingAs($user)->getJson(
            $this->endpoint("tools/shared_tool?period=day&date={$this->today()}&agent_id=unattributed")
        );

        $unattributedResponse->assertStatus(200);
        $this->assertSame('unattributed', $unattributedResponse->json('agent_id'), 'the literal string, never the internal sentinel UUID');
        $this->assertSame(2, $unattributedResponse->json('invocation_count'));
        $this->assertSame(2, $unattributedResponse->json('failure_count'));
        $this->assertStringNotContainsString(
            ToolReliabilitySummary::UNATTRIBUTED_AGENT_BUCKET,
            $unattributedResponse->getContent(),
            'the internal sentinel UUID must never leak into the API response'
        );

        $breakdownResponse = $this->actingAs($user)->getJson(
            $this->endpoint("tools/shared_tool/agents?period=day&date={$this->today()}")
        );

        $breakdownResponse->assertStatus(200);
        $rows = collect($breakdownResponse->json('data'));

        $agentIds = $rows->pluck('agent_id')->all();
        $this->assertContains('agent-alpha', $agentIds);
        $this->assertContains('unattributed', $agentIds, 'FR-013: an explicit Unattributed row, never dropped');
        $this->assertCount(2, $agentIds, 'agent-alpha and unattributed must be separate rows, never merged into one');

        $unattributedRow = $rows->firstWhere('agent_id', 'unattributed');
        $this->assertSame(2, $unattributedRow['invocation_count']);
        $this->assertStringNotContainsString(
            ToolReliabilitySummary::UNATTRIBUTED_AGENT_BUCKET,
            $breakdownResponse->getContent()
        );
    }

    /**
     * Acceptance Scenario 2: a change made to one agent's configuration is
     * visible in that agent's own before/after comparison without needing
     * to account for other agents' activity -- a run of failures injected
     * under agent A only shows up in agent A's comparison, while agent B's
     * (untouched) comparison shows none.
     */
    #[Test]
    public function a_reliability_shift_in_one_agent_is_isolated_from_an_untouched_agent(): void
    {
        $user = User::factory()->create();
        $before = Carbon::now()->subWeek();
        $after = Carbon::now();

        // "Before" period: both agents are healthy.
        for ($i = 0; $i < 8; $i++) {
            $this->recordAt($before, 'shared_tool', $user->id, true, null, 'agent-alpha');
        }
        for ($i = 0; $i < 8; $i++) {
            $this->recordAt($before, 'shared_tool', $user->id, true, null, 'agent-beta');
        }

        // "After" period: agent-alpha regresses; agent-beta is untouched.
        for ($i = 0; $i < 5; $i++) {
            $this->recordAt($after, 'shared_tool', $user->id, false, ToolFailureCategory::ServerError, 'agent-alpha');
        }
        for ($i = 0; $i < 8; $i++) {
            $this->recordAt($after, 'shared_tool', $user->id, true, null, 'agent-beta');
        }

        $alphaBefore = $this->actingAs($user)->getJson(
            $this->endpoint("tools/shared_tool?period=day&date={$before->toDateString()}&agent_id=agent-alpha")
        )->json();
        $alphaAfter = $this->actingAs($user)->getJson(
            $this->endpoint("tools/shared_tool?period=day&date={$after->toDateString()}&agent_id=agent-alpha")
        )->json();

        $betaBefore = $this->actingAs($user)->getJson(
            $this->endpoint("tools/shared_tool?period=day&date={$before->toDateString()}&agent_id=agent-beta")
        )->json();
        $betaAfter = $this->actingAs($user)->getJson(
            $this->endpoint("tools/shared_tool?period=day&date={$after->toDateString()}&agent_id=agent-beta")
        )->json();

        $this->assertSame(0, $alphaBefore['failure_count']);
        $this->assertSame(5, $alphaAfter['failure_count'], "agent-alpha's regression must be visible in its own before/after comparison");

        $this->assertSame(0, $betaBefore['failure_count']);
        $this->assertSame(0, $betaAfter['failure_count'], "agent-beta's comparison must show no shift -- untouched by agent-alpha's regression");
    }
}
