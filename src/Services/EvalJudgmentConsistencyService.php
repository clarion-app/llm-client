<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\EvalCase;
use ClarionApp\LlmClient\Models\EvalCaseVersion;
use ClarionApp\LlmClient\Models\EvalJudgmentConsistencySample;
use Illuminate\Support\Str;

/**
 * Judges a single fixed response repeatedly against one rubric expectation's
 * pinned criteria, sequentially, and summarizes the resulting spread of
 * scores — not only a mean — plus an automated stability flag.
 *
 * Every repeat goes through RubricJudge exactly like an in-run judgment
 * would, sharing one dedicated judge Conversation for the whole sample. The
 * sample's own id is pre-minted before any repeat runs, so each repeat's
 * eval_judgments row can carry it from the start.
 */
final class EvalJudgmentConsistencyService
{
    public function run(
        EvalCase $case,
        EvalCaseVersion $version,
        int $expectationIndex,
        string $responseText,
        ?string $sourceEvalCaseResultId,
        ?int $sampleSize,
        string $requestedBy,
    ): EvalJudgmentConsistencySample {
        $sampleSize = $this->resolveSampleSize($sampleSize);

        // A time-ordered UUID rather than a plain random one: the samples
        // list needs a "newest first" ordering finer-grained than this
        // table's second-precision created_at column can guarantee on its
        // own — two requests landing in the same wall-clock second must
        // still sort correctly, and this id's own byte layout already
        // carries that ordering, portable across every supported database.
        $sampleId = (string) Str::orderedUuid();

        $judgeConversation = Conversation::firstOrCreate(
            ['title' => 'eval-judgment-consistency:'.$sampleId],
            ['user_id' => null, 'character' => 'eval-judge'],
        );

        $criteria = (string) ($version->expectations[$expectationIndex]['criteria'] ?? '');

        $scores = [];

        for ($i = 0; $i < $sampleSize; $i++) {
            $result = app(RubricJudge::class)->judge(
                $criteria,
                $version->given,
                $responseText,
                [],
                $judgeConversation,
                'eval_judgment_consistency_check',
            );

            app(EvalJudgmentService::class)->record(
                (string) Str::uuid(),
                null,
                $version->id,
                $expectationIndex,
                $criteria,
                $responseText,
                $result,
                $sampleId,
            );

            if ($result->status === 'judged') {
                $scores[] = $result->score;
            }
        }

        $judgedCount = count($scores);
        $unjudgedCount = $sampleSize - $judgedCount;

        $scoreMin = $judgedCount > 0 ? min($scores) : null;
        $scoreMax = $judgedCount > 0 ? max($scores) : null;
        $scoreMean = $judgedCount > 0 ? round(array_sum($scores) / $judgedCount, 2) : null;

        // Read fresh at request time — never cached/snapshotted across
        // calls, so a config change between two consistency-check requests
        // is reflected on the very next one without a process restart.
        $flagThresholdUsed = (int) config('llm-client.eval_judging.consistency_flag_threshold', 3);

        $flaggedUnstable = $judgedCount > 0
            ? ($scoreMax - $scoreMin) > $flagThresholdUsed
            : null;

        return EvalJudgmentConsistencySample::create([
            'id' => $sampleId,
            'eval_case_id' => $case->id,
            'eval_case_version_id' => $version->id,
            'expectation_index' => $expectationIndex,
            'source_eval_case_result_id' => $sourceEvalCaseResultId,
            'response_text' => $responseText,
            'sample_size' => $sampleSize,
            'judged_count' => $judgedCount,
            'unjudged_count' => $unjudgedCount,
            'scores' => $scores,
            'score_min' => $scoreMin,
            'score_max' => $scoreMax,
            'score_mean' => $scoreMean,
            'flag_threshold_used' => $flagThresholdUsed,
            'flagged_unstable' => $flaggedUnstable,
            'requested_by' => $requestedBy,
        ]);
    }

    /**
     * Applies the configured default when unspecified, then clamps into
     * [1, max_consistency_sample_size] — read fresh on every call, never
     * cached, matching flag_threshold_used's own freshness rule.
     */
    private function resolveSampleSize(?int $sampleSize): int
    {
        $sampleSize ??= (int) config('llm-client.eval_judging.consistency_sample_size', 5);
        $max = (int) config('llm-client.eval_judging.max_consistency_sample_size', 10);

        return max(1, min($sampleSize, $max));
    }
}
