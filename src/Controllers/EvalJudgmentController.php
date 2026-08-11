<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use Auth;
use Carbon\Carbon;
use ClarionApp\LlmClient\Models\EvalCase;
use ClarionApp\LlmClient\Models\EvalCaseResult;
use ClarionApp\LlmClient\Models\EvalJudgment;
use ClarionApp\LlmClient\Models\EvalJudgmentConsistencySample;
use ClarionApp\LlmClient\Services\EvalJudgmentConsistencyService;
use ClarionApp\LlmClient\Services\EvalSuiteService;
use ClarionApp\LlmClient\Support\OperatorAccess;
use ClarionApp\LlmClient\ValueObjects\ExpectationKind;
use Illuminate\Http\Request;

/**
 * Operator-gated reads and writes for produced judgments and
 * operator-requested consistency checks. This file's own two actions —
 * consistencyChecks()/listConsistencyChecks() — cover consistency-check
 * requests; a judgment's own detail/override actions are added later,
 * extending this same file rather than a second controller.
 */
class EvalJudgmentController extends Controller
{
    public function __construct(
        private readonly EvalSuiteService $suiteService,
        private readonly EvalJudgmentConsistencyService $consistencyService,
    ) {}

    public function consistencyChecks(Request $request, string $suiteId, string $caseId)
    {
        if (!OperatorAccess::isOperator(Auth::id())) {
            return $this->forbidden();
        }

        $suite = $this->suiteService->find($suiteId);

        if ($suite === null) {
            return $this->notFound();
        }

        $case = EvalCase::query()->where('suite_id', $suite->id)->find($caseId);

        if ($case === null) {
            return $this->notFound();
        }

        $version = $case->currentVersion;
        $expectations = $version?->expectations ?? [];
        $expectationIndex = (int) $request->input('expectation_index');

        if (
            !array_key_exists($expectationIndex, $expectations)
            || ($expectations[$expectationIndex]['kind'] ?? null) !== ExpectationKind::RubricJudgment->value
        ) {
            return $this->unprocessable("Expectation at index {$expectationIndex} is not a rubric_judgment expectation.");
        }

        $sourceEvalCaseResultId = $request->input('source_eval_case_result_id');
        $responseText = $request->input('response_text');

        if (($sourceEvalCaseResultId === null) === ($responseText === null)) {
            return $this->unprocessable('Provide either source_eval_case_result_id or response_text.');
        }

        if ($sourceEvalCaseResultId !== null) {
            $sourceResult = EvalCaseResult::find($sourceEvalCaseResultId);

            if ($sourceResult === null || $sourceResult->eval_case_id !== $case->id) {
                return $this->unprocessable('source_eval_case_result_id does not belong to this case.');
            }

            $responseText = (string) $sourceResult->produced_response;
        }

        $sampleSizeInput = $request->input('sample_size');
        $sampleSize = $sampleSizeInput === null ? null : (int) $sampleSizeInput;

        $sample = $this->consistencyService->run(
            $case,
            $version,
            $expectationIndex,
            (string) $responseText,
            $sourceEvalCaseResultId,
            $sampleSize,
            (string) Auth::id(),
        );

        return response()->json($this->formatSample($sample), 201);
    }

    public function listConsistencyChecks(string $suiteId, string $caseId)
    {
        if (!OperatorAccess::isOperator(Auth::id())) {
            return $this->forbidden();
        }

        $suite = $this->suiteService->find($suiteId);

        if ($suite === null) {
            return $this->notFound();
        }

        $case = EvalCase::query()->where('suite_id', $suite->id)->find($caseId);

        if ($case === null) {
            return $this->notFound();
        }

        // Ordered by id, not created_at: the sample id is minted as a
        // time-ordered UUID precisely so "newest first" stays correct even
        // when two requests land within the same wall-clock second (this
        // table's created_at column is only second-precision).
        $samples = EvalJudgmentConsistencySample::where('eval_case_id', $case->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (EvalJudgmentConsistencySample $sample) => $this->formatSample($sample))
            ->values();

        return response()->json(['data' => $samples], 200);
    }

    private function formatSample(EvalJudgmentConsistencySample $sample): array
    {
        return [
            'id' => $sample->id,
            'eval_case_id' => $sample->eval_case_id,
            'expectation_index' => $sample->expectation_index,
            'response_text' => $sample->response_text,
            'sample_size' => $sample->sample_size,
            'judged_count' => $sample->judged_count,
            'unjudged_count' => $sample->unjudged_count,
            'scores' => $sample->scores,
            'score_min' => $sample->score_min,
            'score_max' => $sample->score_max,
            'score_mean' => $sample->score_mean,
            'flagged_unstable' => $sample->flagged_unstable,
            'flag_threshold_used' => $sample->flag_threshold_used,
            'requested_by' => $sample->requested_by,
            'created_at' => Carbon::parse($sample->created_at)->toJSON(),
            'judgment_ids' => EvalJudgment::where('consistency_sample_id', $sample->id)
                ->orderBy('created_at')
                ->pluck('id')
                ->values()
                ->all(),
        ];
    }

    private function forbidden()
    {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    private function notFound()
    {
        return response()->json(['message' => 'Not found.'], 404);
    }

    private function unprocessable(string $message)
    {
        return response()->json([
            'message' => $message,
            'errors' => ['consistency_check' => [$message]],
        ], 422);
    }
}
