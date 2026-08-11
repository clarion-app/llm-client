<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Models\EvalCase;
use ClarionApp\LlmClient\Models\EvalCaseVersion;
use ClarionApp\LlmClient\Services\EvalCaseService;
use ClarionApp\LlmClient\Services\EvalSuiteService;
use ClarionApp\LlmClient\Support\OperatorAccess;
use Illuminate\Http\Request;
use Auth;

/**
 * Operator-only case CRUD within a suite (FR-002, FR-003, FR-004, FR-005,
 * FR-006). store() is this file's User Story 1 surface — update/destroy/
 * versions are User Story 2's concern, added later.
 *
 * EvalCaseService is the sole write path; this controller only
 * translates HTTP to it, and translates its \InvalidArgumentException
 * rejections to a 422. A suiteId that is unknown or archived is 404,
 * never disclosing anything about a case under a different suite
 * (contracts §3).
 */
class EvalCaseController extends Controller
{
    public function __construct(
        private readonly EvalCaseService $service,
        private readonly EvalSuiteService $suiteService,
        private readonly EvalSuiteController $suiteController,
    ) {}

    public function store(Request $request, string $suiteId)
    {
        if (!OperatorAccess::isOperator(Auth::id())) {
            return $this->forbidden();
        }

        $suite = $this->suiteService->find($suiteId);

        if ($suite === null) {
            return $this->notFound();
        }

        try {
            $case = $this->service->addCase(
                $suite,
                (string) $request->input('given', ''),
                (string) $request->input('expected_behavior', ''),
                (array) $request->input('expectations', []),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessable($e->getMessage());
        }

        return response()->json($this->suiteController->formatCase($case), 200);
    }

    public function update(Request $request, string $suiteId, string $caseId)
    {
        if (!OperatorAccess::isOperator(Auth::id())) {
            return $this->forbidden();
        }

        $suite = $this->suiteService->find($suiteId);

        if ($suite === null) {
            return $this->notFound();
        }

        // Scoped to the named suite so a caseId belonging to a different
        // suite resolves identically to unknown — 404, never a 403 that
        // would disclose its existence elsewhere (contracts §3).
        $case = EvalCase::query()->where('suite_id', $suite->id)->find($caseId);

        if ($case === null) {
            return $this->notFound();
        }

        try {
            $case = $this->service->editCase(
                $case,
                (string) $request->input('given', ''),
                (string) $request->input('expected_behavior', ''),
                (array) $request->input('expectations', []),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessable($e->getMessage());
        }

        return response()->json($this->suiteController->formatCase($case), 200);
    }

    public function destroy(string $suiteId, string $caseId)
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

        $this->service->archive($case);

        return response()->json(null, 204);
    }

    public function versions(string $suiteId, string $caseId)
    {
        if (!OperatorAccess::isOperator(Auth::id())) {
            return $this->forbidden();
        }

        $suite = $this->suiteService->find($suiteId);

        if ($suite === null) {
            return $this->notFound();
        }

        // Deliberately includes archived cases — required so this route
        // keeps working after DELETE .../cases/{caseId} archives the case
        // (US2 scenario 4 / quickstart step 7: archiving a case never
        // erases a version). update()/destroy() above correctly stay
        // scoped to live cases only (contracts §3).
        $case = EvalCase::withTrashed()->where('suite_id', $suite->id)->find($caseId);

        if ($case === null) {
            return $this->notFound();
        }

        $versions = $this->service->versionsFor($case)
            ->map(fn (EvalCaseVersion $version) => [
                'id' => $version->id,
                'version_number' => $version->version_number,
                'given' => $version->given,
                'expected_behavior' => $version->expected_behavior,
                'expectations' => $version->expectations,
                'requires_human_judgment' => $version->requiresHumanJudgment(),
                'created_at' => optional($version->created_at)->toJSON(),
            ])
            ->values();

        return response()->json(['data' => $versions], 200);
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
            'errors' => ['case' => [$message]],
        ], 422);
    }
}
