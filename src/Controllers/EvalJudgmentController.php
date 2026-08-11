<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use Auth;
use Carbon\Carbon;
use ClarionApp\LlmClient\Models\EvalCase;
use ClarionApp\LlmClient\Models\EvalCaseResult;
use ClarionApp\LlmClient\Models\EvalJudgment;
use ClarionApp\LlmClient\Models\EvalJudgmentConsistencySample;
use ClarionApp\LlmClient\Models\EvalJudgmentOverride;
use ClarionApp\LlmClient\Services\EvalJudgmentConsistencyService;
use ClarionApp\LlmClient\Services\EvalJudgmentOverrideService;
use ClarionApp\LlmClient\Services\EvalSuiteService;
use ClarionApp\LlmClient\Support\OperatorAccess;
use ClarionApp\LlmClient\ValueObjects\ExpectationKind;
use Illuminate\Http\Request;

/**
 * Operator-gated reads and writes for produced judgments and
 * operator-requested consistency checks. consistencyChecks()/
 * listConsistencyChecks() cover consistency-check requests; show()/
 * override() cover reading a single judgment's full detail and
 * recording an operator's correction to it.
 */
class EvalJudgmentController extends Controller
{
    public function __construct(
        private readonly EvalSuiteService $suiteService,
        private readonly EvalJudgmentConsistencyService $consistencyService,
        private readonly EvalJudgmentOverrideService $overrideService,
    ) {}

    public function show(string $judgmentId)
    {
        if (!OperatorAccess::isOperator(Auth::id())) {
            return $this->forbidden();
        }

        $judgment = EvalJudgment::with('overrides')->find($judgmentId);

        if ($judgment === null) {
            return $this->notFound();
        }

        return response()->json($this->formatJudgment($judgment), 200);
    }

    public function override(Request $request, string $judgmentId)
    {
        if (!OperatorAccess::isOperator(Auth::id())) {
            return $this->forbidden();
        }

        $judgment = EvalJudgment::with('overrides')->find($judgmentId);

        if ($judgment === null) {
            return $this->notFound();
        }

        if ($judgment->status === 'unjudged') {
            return $this->unprocessable('An unjudged judgment cannot be overridden.', 'override');
        }

        $hasScore = $request->has('score') && $request->input('score') !== null;
        $hasJustification = $request->has('justification') && $request->input('justification') !== null;

        if (!$hasScore && !$hasJustification) {
            return $this->unprocessable('score or justification is required.', 'override');
        }

        $scoreScaleMax = (int) config('llm-client.eval_judging.score_scale_max', 10);
        $score = $hasScore ? (int) $request->input('score') : null;

        if ($score !== null && ($score < 1 || $score > $scoreScaleMax)) {
            return $this->unprocessable("score must be between 1 and {$scoreScaleMax}.", 'score');
        }

        $justification = $hasJustification ? (string) $request->input('justification') : null;

        $this->overrideService->override($judgment, $score, $justification, (string) Auth::id());

        // The judgment's overrides relation was mutated by the service
        // call above (a new row now exists) — reload it before rendering
        // so `effective`/`overrides` reflect the just-recorded correction.
        $judgment->load('overrides');

        return response()->json($this->formatJudgment($judgment), 200);
    }

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

    private function formatJudgment(EvalJudgment $judgment): array
    {
        return [
            'id' => $judgment->id,
            'eval_case_result_id' => $judgment->eval_case_result_id,
            'eval_case_version_id' => $judgment->eval_case_version_id,
            'expectation_index' => $judgment->expectation_index,
            'criteria' => $judgment->criteria,
            'response_text' => $judgment->response_text,
            'status' => $judgment->status,
            'score' => $judgment->score,
            'justification' => $judgment->justification,
            'unjudged_reason' => $judgment->unjudged_reason,
            'model' => $judgment->model,
            'server_id' => $judgment->server_id,
            'conversation_id' => $judgment->conversation_id,
            'consistency_sample_id' => $judgment->consistency_sample_id,
            'created_at' => Carbon::parse($judgment->created_at)->toJSON(),
            'effective' => $judgment->effective(),
            'overrides' => $judgment->overrides
                ->map(fn (EvalJudgmentOverride $override) => [
                    'id' => $override->id,
                    'user_id' => $override->user_id,
                    'score' => $override->score,
                    'justification' => $override->justification,
                    'created_at' => Carbon::parse($override->created_at)->toJSON(),
                ])
                ->values()
                ->all(),
        ];
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
            // Ordered by id as well as created_at: a sample's repeats all
            // land within the same second-precision created_at, and their
            // ids are minted time-ordered precisely so "the order they
            // were produced" survives that.
            'judgment_ids' => EvalJudgment::where('consistency_sample_id', $sample->id)
                ->orderBy('created_at')
                ->orderBy('id')
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

    /**
     * The 422 shape 077/078's controllers already return. $field names
     * the thing the caller got wrong so a client rendering field-level
     * errors puts the message somewhere sensible — an override's
     * rejection is not a consistency-check error.
     */
    private function unprocessable(string $message, string $field = 'consistency_check')
    {
        return response()->json([
            'message' => $message,
            'errors' => [$field => [$message]],
        ], 422);
    }
}
