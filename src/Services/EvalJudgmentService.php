<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\EvalJudgment;
use ClarionApp\LlmClient\ValueObjects\RubricJudgmentResult;

/**
 * The sole write path for eval_judgments rows — an in-run judgment
 * (eval_case_result_id populated, consistency_sample_id null) and a
 * consistency-sample member judgment (consistency_sample_id populated,
 * eval_case_result_id null) alike.
 */
final class EvalJudgmentService
{
    public function record(
        string $judgmentId,
        ?string $evalCaseResultId,
        string $evalCaseVersionId,
        int $expectationIndex,
        string $criteria,
        ?string $responseText,
        RubricJudgmentResult $result,
        ?string $consistencySampleId = null,
    ): EvalJudgment {
        return EvalJudgment::create([
            'id' => $judgmentId,
            'eval_case_result_id' => $evalCaseResultId,
            'eval_case_version_id' => $evalCaseVersionId,
            'expectation_index' => $expectationIndex,
            'criteria' => $criteria,
            'response_text' => $responseText,
            'status' => $result->status,
            'score' => $result->score,
            'justification' => $result->justification,
            'unjudged_reason' => $result->unjudgedReason,
            'model' => $result->model,
            'server_id' => $result->serverId,
            'conversation_id' => $result->conversationId,
            'consistency_sample_id' => $consistencySampleId,
        ]);
    }
}
