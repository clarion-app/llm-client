<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Models\EvalCase;
use ClarionApp\LlmClient\Models\EvalSuite;
use ClarionApp\LlmClient\Services\EvalSuiteService;
use ClarionApp\LlmClient\Support\OperatorAccess;
use Illuminate\Http\Request;
use Auth;

/**
 * Operator-only suite CRUD (FR-001, FR-002, FR-008). Every action checks
 * operator access first and answers 403 otherwise, including for a plain
 * read — reading the list is as restricted as writing it (research.md D1,
 * the BudgetCeilingController precedent).
 *
 * EvalSuiteService is the sole write path; this controller only
 * translates HTTP to it, and translates its \InvalidArgumentException
 * rejections to a 422.
 */
class EvalSuiteController extends Controller
{
    public function __construct(
        private readonly EvalSuiteService $service,
    ) {}

    public function index()
    {
        if (!OperatorAccess::isOperator(Auth::id())) {
            return $this->forbidden();
        }

        $data = $this->service->list()
            ->map(fn (EvalSuite $suite) => $this->formatSuiteSummary($suite))
            ->values();

        return response()->json(['data' => $data], 200);
    }

    public function store(Request $request)
    {
        if (!OperatorAccess::isOperator(Auth::id())) {
            return $this->forbidden();
        }

        try {
            $suite = $this->service->create(
                (string) $request->input('name', ''),
                (string) $request->input('agent_identifier', ''),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessable($e->getMessage());
        }

        return response()->json($this->formatSuiteSummary($suite), 200);
    }

    public function show(string $id)
    {
        if (!OperatorAccess::isOperator(Auth::id())) {
            return $this->forbidden();
        }

        $suite = $this->service->find($id);

        if ($suite === null) {
            return $this->notFound();
        }

        return response()->json($this->formatSuiteDetail($suite), 200);
    }

    public function update(Request $request, string $id)
    {
        if (!OperatorAccess::isOperator(Auth::id())) {
            return $this->forbidden();
        }

        $suite = $this->service->find($id);

        if ($suite === null) {
            return $this->notFound();
        }

        // only() distinguishes a field that is genuinely absent from the
        // request from one explicitly sent as null/empty — an omitted
        // field must be left unchanged (contracts §2).
        $fields = $request->only(['name', 'agent_identifier']);

        try {
            $suite = $this->service->rename(
                $suite,
                array_key_exists('name', $fields) ? (string) $fields['name'] : null,
                array_key_exists('agent_identifier', $fields) ? (string) $fields['agent_identifier'] : null,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessable($e->getMessage());
        }

        return response()->json($this->formatSuiteSummary($suite), 200);
    }

    public function destroy(string $id)
    {
        if (!OperatorAccess::isOperator(Auth::id())) {
            return $this->forbidden();
        }

        $suite = $this->service->find($id);

        if ($suite === null) {
            return $this->notFound();
        }

        $this->service->archive($suite);

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatSuiteSummary(EvalSuite $suite): array
    {
        return [
            'id' => $suite->id,
            'name' => $suite->name,
            'agent_identifier' => $suite->agent_identifier,
            'case_count' => $suite->cases()->count(),
            'created_at' => optional($suite->created_at)->toJSON(),
            'updated_at' => optional($suite->updated_at)->toJSON(),
        ];
    }

    /**
     * The §1.4 `suite` detail shape (contracts/eval-suites-api.md), reused
     * by EvalSuiteExportController so a freshly imported suite renders
     * identically to any other suite's detail view.
     *
     * @return array<string, mixed>
     */
    public function formatSuiteDetail(EvalSuite $suite): array
    {
        $cases = $suite->cases()->with('currentVersion')->get();

        return array_merge($this->formatSuiteSummary($suite), [
            'cases' => $cases->map(fn (EvalCase $case) => $this->formatCase($case))->values()->all(),
        ]);
    }

    /**
     * The §1.2 `case` shape (contracts/eval-suites-api.md), reused by
     * EvalCaseController so both controllers render a case identically.
     * requires_human_judgment is computed from the current version, never
     * a stored field (research.md D5). created_at/updated_at are the case
     * identity row's own timestamps (when the case was first added / last
     * edited), not the current version row's — the two coincide for the
     * current version by construction, but the case is what is being
     * described here (contracts §1.2).
     *
     * @return array<string, mixed>
     */
    public function formatCase(EvalCase $case): array
    {
        $version = $case->currentVersion;

        return [
            'id' => $case->id,
            'suite_id' => $case->suite_id,
            'version_id' => $version?->id,
            'version_number' => $version?->version_number,
            'given' => $version?->given,
            'expected_behavior' => $version?->expected_behavior,
            'expectations' => $version?->expectations ?? [],
            'requires_human_judgment' => $version?->requiresHumanJudgment() ?? false,
            'created_at' => optional($case->created_at)->toJSON(),
            'updated_at' => optional($case->updated_at)->toJSON(),
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
            'errors' => ['suite' => [$message]],
        ], 422);
    }
}
