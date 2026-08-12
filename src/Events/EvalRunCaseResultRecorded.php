<?php

namespace ClarionApp\LlmClient\Events;

use ClarionApp\LlmClient\Models\EvalCaseResult;
use ClarionApp\LlmClient\Models\EvalRun;
use ClarionApp\LlmClient\Support\OperatorAccess;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * A small "this case just finished" tick to every configured operator --
 * never to any notion of who owns the case's own conversation, since an
 * eval run (and the case results it produces) has no owner at all.
 * Deliberately excludes the case's full content (produced_response,
 * attempted_actions, expectation_results); a live viewer refetches that
 * case's own detail on demand instead of receiving it inline on every
 * single completion, the same "small tick, full detail on demand"
 * precedent the run-diagram feature's own action-update event already
 * established.
 *
 * Both broadcastOn() and broadcastWith() re-resolve fresh from the
 * database at broadcast time rather than trusting anything captured at
 * construction time.
 */
class EvalRunCaseResultRecorded implements ShouldBroadcastNow
{
    use SerializesModels;

    public function __construct(
        public readonly string $caseResultId,
    ) {
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $caseResult = EvalCaseResult::find($this->caseResultId);

        if ($caseResult === null) {
            return [];
        }

        if (EvalRun::find($caseResult->run_id) === null) {
            return [];
        }

        $channels = [];

        foreach ((array) config('llm-client.cost.operator_user_ids', []) as $operatorId) {
            if (!is_string($operatorId) || !OperatorAccess::isOperator($operatorId)) {
                continue;
            }

            $channels[] = new PrivateChannel('User.'.$operatorId);
        }

        return $channels;
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $caseResult = EvalCaseResult::find($this->caseResultId);

        if ($caseResult === null) {
            return [];
        }

        return [
            'id' => $caseResult->id,
            'run_id' => $caseResult->run_id,
            'eval_case_id' => $caseResult->eval_case_id,
            'outcome' => $caseResult->outcome->value,
            'outcome_override' => $caseResult->outcome_override,
            'created_at' => optional($caseResult->created_at)->toJSON(),
        ];
    }
}
