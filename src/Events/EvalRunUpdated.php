<?php

namespace ClarionApp\LlmClient\Events;

use ClarionApp\LlmClient\Models\EvalRun;
use ClarionApp\LlmClient\Services\EvalRunConsumptionQuery;
use ClarionApp\LlmClient\Services\EvalRunService;
use ClarionApp\LlmClient\Support\OperatorAccess;
use ClarionApp\LlmClient\ValueObjects\ConsumptionSummary;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * Announces one eval run's own status transition to every configured
 * operator, so a run still in progress is never invisible until it
 * finishes and an interrupted run is shown as such rather than silently
 * presented as normally finished. There is no single run owner to target
 * (an eval run is operator-initiated, installation-shared telemetry, not
 * a user-owned conversation) so this fans out to every id configured as
 * an operator, exactly as SpendingCeilingReached already does for its own
 * installation-wide notices.
 *
 * Both broadcastOn() and broadcastWith() re-resolve the run fresh from
 * the database at broadcast time rather than trusting anything captured
 * at construction time, so a pushed payload can never disagree with what
 * a direct GET of the same run would return at the same instant.
 */
class EvalRunUpdated implements ShouldBroadcastNow
{
    use SerializesModels;

    public function __construct(
        public readonly string $runId,
    ) {
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        if (EvalRun::find($this->runId) === null) {
            return [];
        }

        return $this->operatorChannels();
    }

    /**
     * The same shape the run-detail read endpoint returns: everything
     * formatRunSummary() carries (id, suite_id, agent_label, status,
     * case_count, completed_count, remaining_count, started_at,
     * completed_at) plus failure_reason, overall, outcome_counts, and
     * consumption. Built directly from EvalRunService::summarize() and
     * EvalRunConsumptionQuery rather than through the controller (which
     * exposes no public formatter) -- the same three already-tested
     * pieces the controller itself composes, in the same order, so this
     * payload and a GET of the same run can never drift apart.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $run = EvalRun::find($this->runId);

        if ($run === null) {
            return [];
        }

        $runService = app(EvalRunService::class);
        $consumptionQuery = app(EvalRunConsumptionQuery::class);

        $summary = $runService->summarize($run);
        $agentConsumption = $consumptionQuery->summarize($run);
        $judging = $consumptionQuery->summarizeJudging($run);

        $consumption = new ConsumptionSummary(
            totalCost: $agentConsumption->totalCost,
            totalTokens: $agentConsumption->totalTokens,
            toolInvocationCount: $agentConsumption->toolInvocationCount,
            totalDurationMs: $agentConsumption->totalDurationMs,
            costUnpriced: $agentConsumption->costUnpriced,
            judgingCost: $judging['cost'],
            judgingTokens: $judging['tokens'],
            judgingInvocationCount: $judging['invocationCount'],
            judgingCostUnpriced: $judging['costUnpriced'],
        );

        return [
            'id' => $run->id,
            'suite_id' => $run->suite_id,
            'agent_label' => $run->agent_label,
            'status' => $run->status->value,
            'case_count' => $run->case_count,
            'completed_count' => $summary['completed_count'],
            'remaining_count' => $summary['remaining_count'],
            'started_at' => optional($run->started_at)->toJSON(),
            'completed_at' => optional($run->completed_at)->toJSON(),
            'failure_reason' => $run->failure_reason,
            'overall' => $summary['overall'],
            'outcome_counts' => [
                'pass' => $summary['pass'],
                'fail' => $summary['fail'],
                'needs_human_review' => $summary['needs_human_review'],
                'errored' => $summary['errored'],
                'unjudged' => $summary['unjudged'],
            ],
            'consumption' => [
                'total_cost' => $consumption->totalCost,
                'cost_currency' => config('llm-client.cost.currency'),
                'cost_unpriced' => $consumption->costUnpriced,
                'total_tokens' => $consumption->totalTokens,
                'tool_invocation_count' => $consumption->toolInvocationCount,
                'total_duration_ms' => $consumption->totalDurationMs,
                'judging' => [
                    'total_cost' => $consumption->judgingCost,
                    'total_tokens' => $consumption->judgingTokens,
                    'invocation_count' => $consumption->judgingInvocationCount,
                    'cost_unpriced' => $consumption->judgingCostUnpriced,
                ],
            ],
        ];
    }

    /**
     * One private channel per configured operator id -- the
     * SpendingCeilingReached fan-out precedent, reused verbatim. A
     * non-string entry landing in the config array (a copy/paste or
     * JSON-decoding slip) is skipped rather than turned into a channel.
     *
     * @return array<int, PrivateChannel>
     */
    private function operatorChannels(): array
    {
        $channels = [];

        foreach ((array) config('llm-client.cost.operator_user_ids', []) as $operatorId) {
            if (!is_string($operatorId) || !OperatorAccess::isOperator($operatorId)) {
                continue;
            }

            $channels[] = new PrivateChannel('User.'.$operatorId);
        }

        return $channels;
    }
}
