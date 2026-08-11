<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Models\AgentRun;
use ClarionApp\LlmClient\Models\EvalCaseResult;
use ClarionApp\LlmClient\Models\EvalJudgment;
use ClarionApp\LlmClient\Models\EvalRun;
use ClarionApp\LlmClient\Models\ToolInvocationRecord;
use ClarionApp\LlmClient\Models\UsageRecord;
use ClarionApp\LlmClient\Services\EvalRunConsumptionQuery;
use ClarionApp\LlmClient\ValueObjects\ConsumptionSummary;
use ClarionApp\LlmClient\ValueObjects\EvalCaseOutcome;
use ClarionApp\LlmClient\ValueObjects\EvalRunStatus;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * research.md D11, data-model.md §6: EvalRunConsumptionQuery::summarize()
 * is a pure read-time aggregation over UsageRecord/ToolInvocationRecord/
 * agent_runs, scoped by the run's own case conversation_ids — never by
 * user_id. Every eval-run conversation carries the structurally-identical
 * user_id = '' ((string) null === ''), so scoping by user_id would sum
 * every eval run ever executed together; conversation_id is the only key
 * that actually isolates one run's consumption from another's.
 *
 * summarizeJudging() is the parallel, explicitly-separate read for what
 * rubric judging itself consumed: scoped by the run's own judge conversation
 * ids (resolved via eval_judgments.eval_case_result_id -> conversation_id,
 * never via user_id either), and never merged into the four figures
 * summarize() already returns. summarize() itself must stay byte-unchanged
 * by this feature — an agent-under-test's own figures never absorb any part
 * of a judge call's cost or tokens.
 */
class EvalRunConsumptionQueryTest extends TestCase
{
    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function makeRun(EvalRunStatus $status = EvalRunStatus::InProgress, int $caseCount = 1): EvalRun
    {
        $run = new EvalRun();
        $run->suite_id = (string) Str::uuid();
        $run->agent_label = 'home-automation-agent';
        $run->status = $status;
        $run->case_count = $caseCount;
        $run->started_at = now();
        $run->save();

        return $run;
    }

    private function makeResult(EvalRun $run, string $conversationId, EvalCaseOutcome $outcome = EvalCaseOutcome::Pass): EvalCaseResult
    {
        return EvalCaseResult::create([
            'run_id' => $run->id,
            'eval_run_case_id' => (string) Str::uuid(),
            'eval_case_id' => (string) Str::uuid(),
            'eval_case_version_id' => (string) Str::uuid(),
            'conversation_id' => $conversationId,
            'outcome' => $outcome,
            'produced_response' => 'a response',
            'attempted_actions' => [],
            'expectation_results' => [],
            'error_message' => null,
        ]);
    }

    /**
     * Every eval-run conversation's UsageRecord/ToolInvocationRecord rows
     * carry user_id = '' — MetricsRecorder::recordUsage()/recordToolInvocation()
     * are called with (string) $conversation->user_id, and the case
     * conversation's own user_id is null (research.md D11). Defaulting the
     * helper's own $userId to '' means every fixture in this file matches
     * that real shape unless a test deliberately overrides it.
     */
    private function makeUsageRecord(
        string $conversationId,
        int $totalTokens,
        string $totalCost,
        bool $costUnpriced = false,
        string $userId = '',
    ): UsageRecord {
        return UsageRecord::create([
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'attempt_group_id' => (string) Str::uuid(),
            'input_tokens' => (int) round($totalTokens / 2),
            'output_tokens' => (int) round($totalTokens / 2),
            'total_tokens' => $totalTokens,
            'model' => 'test-model',
            'provider_type' => 'openai',
            'total_cost' => $totalCost,
            'cost_unpriced' => $costUnpriced,
            'created_at' => now(),
        ]);
    }

    private function makeToolInvocation(string $conversationId, string $userId = ''): ToolInvocationRecord
    {
        return ToolInvocationRecord::create([
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'attempt_group_id' => (string) Str::uuid(),
            'tool_name' => 'contacts.create',
            'outcome' => 'success',
            'created_at' => now(),
        ]);
    }

    private function makeAgentRun(string $conversationId, int $durationMs, string $userId = ''): AgentRun
    {
        return AgentRun::create([
            'kind' => 'interactive',
            'user_id' => $userId,
            'conversation_id' => $conversationId,
            'end_state' => 'completed',
            'started_at' => now(),
            'ended_at' => now(),
            'duration_ms' => $durationMs,
        ]);
    }

    /**
     * One eval_judgments row, the only path summarizeJudging() is meant to
     * discover a judge conversation through: eval_case_result_id ->
     * conversation_id. The case result's own agent conversation is never
     * involved here — a judgment's conversation_id is the judge's own,
     * dedicated conversation, distinct from the case's agent conversation.
     */
    private function makeJudgment(
        string $evalCaseResultId,
        string $conversationId,
        string $status = 'judged',
    ): EvalJudgment {
        return EvalJudgment::create([
            'id' => (string) Str::uuid(),
            'eval_case_result_id' => $evalCaseResultId,
            'eval_case_version_id' => (string) Str::uuid(),
            'expectation_index' => 0,
            'criteria' => 'The response must be polite and helpful.',
            'response_text' => 'a response',
            'status' => $status,
            'score' => $status === 'judged' ? 8 : null,
            'justification' => $status === 'judged' ? 'Polite and helpful throughout.' : null,
            'unjudged_reason' => $status === 'judged' ? null : 'judge unavailable',
            'model' => 'judge-test-model',
            'server_id' => (string) Str::uuid(),
            'conversation_id' => $conversationId,
            'consistency_sample_id' => null,
        ]);
    }

    // ---------------------------------------------------------------
    // Conversation-id scoping, not user_id scoping (research.md D11,
    // mutation-checklist row 12) — the central property this class exists
    // to guarantee.
    // ---------------------------------------------------------------

    #[Test]
    public function summarize_sums_usage_by_the_runs_own_conversation_ids_and_ignores_a_decoy_sharing_the_same_empty_user_id(): void
    {
        $run = $this->makeRun(EvalRunStatus::Completed, 2);
        $conversationA = (string) Str::uuid();
        $conversationB = (string) Str::uuid();
        $this->makeResult($run, $conversationA);
        $this->makeResult($run, $conversationB);

        $this->makeUsageRecord($conversationA, 100, '0.0100000000');
        $this->makeUsageRecord($conversationB, 200, '0.0200000000');

        // A decoy: same structurally-shared user_id = '', but its
        // conversation_id belongs to no eval_case_results row of this run
        // at all — exactly the "every eval run ever executed" leak D11
        // names. If summarize() ever filtered by user_id instead of
        // conversation_id, this row would be wrongly included.
        $decoyConversation = (string) Str::uuid();
        $this->makeUsageRecord($decoyConversation, 9999, '99.0000000000');

        $summary = app(EvalRunConsumptionQuery::class)->summarize($run);

        $this->assertInstanceOf(ConsumptionSummary::class, $summary);
        $this->assertSame(300, $summary->totalTokens);
        $this->assertEqualsWithDelta(0.03, (float) $summary->totalCost, 0.0000001);
    }

    #[Test]
    public function summarize_excludes_a_second_independent_runs_usage_even_though_both_runs_share_the_empty_user_id(): void
    {
        $runOne = $this->makeRun(EvalRunStatus::Completed, 1);
        $conversationOne = (string) Str::uuid();
        $this->makeResult($runOne, $conversationOne);
        $this->makeUsageRecord($conversationOne, 50, '0.0050000000');

        $runTwo = $this->makeRun(EvalRunStatus::Completed, 1);
        $conversationTwo = (string) Str::uuid();
        $this->makeResult($runTwo, $conversationTwo);
        // Deliberately larger, so an accidental sum-across-runs would be
        // obvious rather than coincidentally matching.
        $this->makeUsageRecord($conversationTwo, 5000, '5.0000000000');

        $summaryOne = app(EvalRunConsumptionQuery::class)->summarize($runOne);
        $summaryTwo = app(EvalRunConsumptionQuery::class)->summarize($runTwo);

        $this->assertSame(50, $summaryOne->totalTokens);
        $this->assertEqualsWithDelta(0.005, (float) $summaryOne->totalCost, 0.0000001);

        $this->assertSame(5000, $summaryTwo->totalTokens);
        $this->assertEqualsWithDelta(5.0, (float) $summaryTwo->totalCost, 0.0000001);
    }

    // ---------------------------------------------------------------
    // Tool invocation count, scoped identically
    // ---------------------------------------------------------------

    #[Test]
    public function summarize_counts_only_tool_invocations_belonging_to_the_runs_own_conversations(): void
    {
        $run = $this->makeRun(EvalRunStatus::Completed, 1);
        $conversation = (string) Str::uuid();
        $this->makeResult($run, $conversation);

        $this->makeToolInvocation($conversation);
        $this->makeToolInvocation($conversation);

        // A decoy tool invocation on a conversation outside this run,
        // sharing the same empty user_id.
        $this->makeToolInvocation((string) Str::uuid());

        $summary = app(EvalRunConsumptionQuery::class)->summarize($run);

        $this->assertSame(2, $summary->toolInvocationCount);
    }

    // ---------------------------------------------------------------
    // Duration, summed from agent_runs for the same conversation set
    // (research.md D11 — the 062 agent_runs.duration_ms figure)
    // ---------------------------------------------------------------

    #[Test]
    public function summarize_sums_each_cases_agent_run_duration_for_the_runs_own_conversations(): void
    {
        $run = $this->makeRun(EvalRunStatus::Completed, 2);
        $conversationA = (string) Str::uuid();
        $conversationB = (string) Str::uuid();
        $this->makeResult($run, $conversationA);
        $this->makeResult($run, $conversationB);

        $this->makeAgentRun($conversationA, 1200);
        $this->makeAgentRun($conversationB, 3400);

        // A decoy agent_runs row for a conversation outside this run.
        $this->makeAgentRun((string) Str::uuid(), 999999);

        $summary = app(EvalRunConsumptionQuery::class)->summarize($run);

        $this->assertSame(1200 + 3400, $summary->totalDurationMs);
    }

    // ---------------------------------------------------------------
    // cost_unpriced propagation (076 precedent — never silently drop the
    // caveat)
    // ---------------------------------------------------------------

    #[Test]
    public function summarize_reports_cost_unpriced_true_when_any_contributing_usage_record_is_unpriced(): void
    {
        $run = $this->makeRun(EvalRunStatus::Completed, 2);
        $conversationA = (string) Str::uuid();
        $conversationB = (string) Str::uuid();
        $this->makeResult($run, $conversationA);
        $this->makeResult($run, $conversationB);

        $this->makeUsageRecord($conversationA, 100, '0.0100000000', costUnpriced: false);
        $this->makeUsageRecord($conversationB, 100, '0.0000000000', costUnpriced: true);

        $summary = app(EvalRunConsumptionQuery::class)->summarize($run);

        $this->assertTrue(
            $summary->costUnpriced,
            'a single unpriced contributing UsageRecord must make the whole summary cost_unpriced'
        );
    }

    #[Test]
    public function summarize_reports_cost_unpriced_false_when_every_contributing_usage_record_is_priced(): void
    {
        $run = $this->makeRun(EvalRunStatus::Completed, 1);
        $conversation = (string) Str::uuid();
        $this->makeResult($run, $conversation);

        $this->makeUsageRecord($conversation, 100, '0.0100000000', costUnpriced: false);

        $summary = app(EvalRunConsumptionQuery::class)->summarize($run);

        $this->assertFalse($summary->costUnpriced);
    }

    // ---------------------------------------------------------------
    // Read-time only — identical computation whether the run is still
    // in_progress or already completed (FR-011's both-states requirement)
    // ---------------------------------------------------------------

    #[Test]
    public function summarize_computes_the_identical_figures_whether_the_run_is_in_progress_or_completed(): void
    {
        $inProgressRun = $this->makeRun(EvalRunStatus::InProgress, 2);
        $conversationA = (string) Str::uuid();
        $this->makeResult($inProgressRun, $conversationA);
        $this->makeUsageRecord($conversationA, 100, '0.0100000000');
        $this->makeToolInvocation($conversationA);
        $this->makeAgentRun($conversationA, 1500);

        $inProgressSummary = app(EvalRunConsumptionQuery::class)->summarize($inProgressRun);

        // Advancing the run to completed changes nothing about the read —
        // no new write path, no cached/incrementally-maintained figure
        // (research.md D11).
        $inProgressRun->update(['status' => EvalRunStatus::Completed, 'completed_at' => now()]);

        $completedSummary = app(EvalRunConsumptionQuery::class)->summarize($inProgressRun->fresh());

        $this->assertSame($inProgressSummary->totalTokens, $completedSummary->totalTokens);
        $this->assertSame($inProgressSummary->totalCost, $completedSummary->totalCost);
        $this->assertSame($inProgressSummary->toolInvocationCount, $completedSummary->toolInvocationCount);
        $this->assertSame($inProgressSummary->totalDurationMs, $completedSummary->totalDurationMs);
        $this->assertSame($inProgressSummary->costUnpriced, $completedSummary->costUnpriced);
    }

    // ---------------------------------------------------------------
    // summarizeJudging(): scoped by the run's own judge conversation ids
    // (via eval_judgments.eval_case_result_id), not by user_id — the same
    // property summarize() already guarantees for the agent side, applied
    // to the judging side.
    // ---------------------------------------------------------------

    #[Test]
    public function summarize_judging_sums_usage_by_the_runs_own_judge_conversation_ids_and_not_by_user_id(): void
    {
        $run = $this->makeRun(EvalRunStatus::Completed, 1);
        $agentConversation = (string) Str::uuid();
        $result = $this->makeResult($run, $agentConversation);

        $judgeConversation = (string) Str::uuid();
        $this->makeJudgment($result->id, $judgeConversation);
        $this->makeUsageRecord($judgeConversation, 400, '0.0400000000');

        // A decoy usage record sharing the structurally-identical
        // user_id = '', but on a conversation no eval_judgments row of
        // this run references at all. If summarizeJudging() ever filtered
        // by user_id instead of the judge-conversation join, this row
        // would be wrongly included.
        $decoyConversation = (string) Str::uuid();
        $this->makeUsageRecord($decoyConversation, 9999, '99.0000000000');

        $judging = app(EvalRunConsumptionQuery::class)->summarizeJudging($run);

        $this->assertSame(400, $judging['tokens']);
        $this->assertEqualsWithDelta(0.04, (float) $judging['cost'], 0.0000001);
        $this->assertSame(1, $judging['invocationCount']);
    }

    #[Test]
    public function summarize_judging_excludes_a_second_independent_runs_judge_usage_even_though_both_share_the_empty_user_id(): void
    {
        $runOne = $this->makeRun(EvalRunStatus::Completed, 1);
        $conversationOne = (string) Str::uuid();
        $resultOne = $this->makeResult($runOne, $conversationOne);
        $judgeConversationOne = (string) Str::uuid();
        $this->makeJudgment($resultOne->id, $judgeConversationOne);
        $this->makeUsageRecord($judgeConversationOne, 100, '0.0100000000');

        $runTwo = $this->makeRun(EvalRunStatus::Completed, 1);
        $conversationTwo = (string) Str::uuid();
        $resultTwo = $this->makeResult($runTwo, $conversationTwo);
        $judgeConversationTwo = (string) Str::uuid();
        $this->makeJudgment($resultTwo->id, $judgeConversationTwo);
        // Deliberately much larger, so an accidental cross-run sum would be
        // obvious rather than coincidentally matching.
        $this->makeUsageRecord($judgeConversationTwo, 9000, '9.0000000000');

        $judgingOne = app(EvalRunConsumptionQuery::class)->summarizeJudging($runOne);
        $judgingTwo = app(EvalRunConsumptionQuery::class)->summarizeJudging($runTwo);

        $this->assertSame(100, $judgingOne['tokens']);
        $this->assertEqualsWithDelta(0.01, (float) $judgingOne['cost'], 0.0000001);

        $this->assertSame(9000, $judgingTwo['tokens']);
        $this->assertEqualsWithDelta(9.0, (float) $judgingTwo['cost'], 0.0000001);
    }

    // ---------------------------------------------------------------
    // A judge call never invokes a tool, and its own latency is not "agent
    // work duration" — summarizeJudging()'s own return value carries no
    // duration/tool-count figure at all, and a tool invocation or
    // agent_runs row that happens to land on the judge's own conversation
    // must never be pulled into either figure.
    // ---------------------------------------------------------------

    #[Test]
    public function summarize_judging_never_folds_tool_invocations_or_agent_run_duration_into_its_own_figures(): void
    {
        $run = $this->makeRun(EvalRunStatus::Completed, 1);
        $agentConversation = (string) Str::uuid();
        $result = $this->makeResult($run, $agentConversation);

        $judgeConversation = (string) Str::uuid();
        $this->makeJudgment($result->id, $judgeConversation);
        $this->makeUsageRecord($judgeConversation, 100, '0.0100000000');

        // These rows deliberately live on the judge's own conversation, to
        // prove summarizeJudging() does not fold them in — not merely that
        // they happen to live on a different conversation than the agent's.
        $this->makeToolInvocation($judgeConversation);
        $this->makeAgentRun($judgeConversation, 999999);

        $judging = app(EvalRunConsumptionQuery::class)->summarizeJudging($run);

        $this->assertArrayNotHasKey('toolInvocationCount', $judging);
        $this->assertArrayNotHasKey('totalDurationMs', $judging);
        // invocationCount reflects the single UsageRecord (one judge LLM
        // call), not the tool invocation that also happened to target the
        // same conversation.
        $this->assertSame(1, $judging['invocationCount']);

        // The pre-existing agent-side figure for this run's own agent
        // conversation stays untouched by the judge conversation's tool
        // invocation and agent_run rows — summarize()'s totalDurationMs
        // stays agent-only, per its own docblock.
        $summary = app(EvalRunConsumptionQuery::class)->summarize($run);
        $this->assertSame(0, $summary->toolInvocationCount);
        $this->assertSame(0, $summary->totalDurationMs);
    }

    // ---------------------------------------------------------------
    // cost_unpriced propagation, identical discipline to summarize()'s own
    // (076 precedent — never silently drop the caveat)
    // ---------------------------------------------------------------

    #[Test]
    public function summarize_judging_reports_cost_unpriced_true_when_any_contributing_judge_usage_record_is_unpriced(): void
    {
        $run = $this->makeRun(EvalRunStatus::Completed, 2);
        $conversationA = (string) Str::uuid();
        $conversationB = (string) Str::uuid();
        $resultA = $this->makeResult($run, $conversationA);
        $resultB = $this->makeResult($run, $conversationB);

        $judgeConversationA = (string) Str::uuid();
        $judgeConversationB = (string) Str::uuid();
        $this->makeJudgment($resultA->id, $judgeConversationA);
        $this->makeJudgment($resultB->id, $judgeConversationB);

        $this->makeUsageRecord($judgeConversationA, 100, '0.0100000000', costUnpriced: false);
        $this->makeUsageRecord($judgeConversationB, 100, '0.0000000000', costUnpriced: true);

        $judging = app(EvalRunConsumptionQuery::class)->summarizeJudging($run);

        $this->assertTrue(
            $judging['costUnpriced'],
            'a single unpriced contributing judge UsageRecord must make the whole judging figure cost_unpriced'
        );
    }

    #[Test]
    public function summarize_judging_reports_cost_unpriced_false_when_every_contributing_judge_usage_record_is_priced(): void
    {
        $run = $this->makeRun(EvalRunStatus::Completed, 1);
        $conversation = (string) Str::uuid();
        $result = $this->makeResult($run, $conversation);

        $judgeConversation = (string) Str::uuid();
        $this->makeJudgment($result->id, $judgeConversation);
        $this->makeUsageRecord($judgeConversation, 100, '0.0100000000', costUnpriced: false);

        $judging = app(EvalRunConsumptionQuery::class)->summarizeJudging($run);

        $this->assertFalse($judging['costUnpriced']);
    }

    // ---------------------------------------------------------------
    // Regression: the pre-existing summarize() method must stay
    // byte-unchanged by this feature — the agent-under-test's own figures
    // are identical whether or not the same run also happens to include a
    // rubric-judged case with its own, entirely separate judge conversation
    // and UsageRecord.
    // ---------------------------------------------------------------

    #[Test]
    public function summarize_remains_byte_unchanged_for_a_run_that_also_contains_a_rubric_judged_case(): void
    {
        $plainRun = $this->makeRun(EvalRunStatus::Completed, 1);
        $plainConversation = (string) Str::uuid();
        $this->makeResult($plainRun, $plainConversation);
        $this->makeUsageRecord($plainConversation, 300, '0.0300000000');
        $this->makeToolInvocation($plainConversation);
        $this->makeAgentRun($plainConversation, 1500);

        $rubricRun = $this->makeRun(EvalRunStatus::Completed, 1);
        $rubricConversation = (string) Str::uuid();
        $rubricResult = $this->makeResult($rubricRun, $rubricConversation);
        $this->makeUsageRecord($rubricConversation, 300, '0.0300000000');
        $this->makeToolInvocation($rubricConversation);
        $this->makeAgentRun($rubricConversation, 1500);

        // The rubric judgment itself, and its own, deliberately much
        // larger UsageRecord — present only on rubricRun. If summarize()
        // ever absorbed any part of this into the agent-under-test's own
        // figures, the two runs' summarize() results would diverge here.
        $judgeConversation = (string) Str::uuid();
        $this->makeJudgment($rubricResult->id, $judgeConversation);
        $this->makeUsageRecord($judgeConversation, 50000, '50.0000000000');

        $plainSummary = app(EvalRunConsumptionQuery::class)->summarize($plainRun);
        $rubricSummary = app(EvalRunConsumptionQuery::class)->summarize($rubricRun);

        $this->assertSame($plainSummary->totalTokens, $rubricSummary->totalTokens);
        $this->assertSame($plainSummary->totalCost, $rubricSummary->totalCost);
        $this->assertSame($plainSummary->toolInvocationCount, $rubricSummary->toolInvocationCount);
        $this->assertSame($plainSummary->totalDurationMs, $rubricSummary->totalDurationMs);
        $this->assertSame($plainSummary->costUnpriced, $rubricSummary->costUnpriced);
    }
}
