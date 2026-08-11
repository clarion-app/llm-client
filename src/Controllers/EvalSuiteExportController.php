<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Exceptions\NameConflictException;
use ClarionApp\LlmClient\Services\EvalSuiteExporter;
use ClarionApp\LlmClient\Services\EvalSuiteImporter;
use ClarionApp\LlmClient\Services\EvalSuiteService;
use ClarionApp\LlmClient\Support\OperatorAccess;
use Illuminate\Http\Request;
use Auth;

/**
 * Operator-only suite export/import (FR-014, FR-015, FR-016, FR-017,
 * contracts/eval-suites-api.md §4). Mirrors EvalSuiteController's
 * operator-gate-first shape.
 *
 * export() returns the bare data-model.md §6 document, no envelope.
 * import() reads that same document shape from the request body plus two
 * optional overrides, and maps EvalSuiteImporter's two distinct failure
 * modes to the two distinct HTTP statuses the contract requires:
 * NameConflictException -> 409 (a state conflict with what already
 * exists), \InvalidArgumentException -> 422 (the document itself is
 * malformed or out of bounds).
 */
class EvalSuiteExportController extends Controller
{
    public function __construct(
        private readonly EvalSuiteService $suiteService,
        private readonly EvalSuiteExporter $exporter,
        private readonly EvalSuiteImporter $importer,
        private readonly EvalSuiteController $suiteController,
    ) {}

    public function export(string $suiteId)
    {
        if (!OperatorAccess::isOperator(Auth::id())) {
            return $this->forbidden();
        }

        $suite = $this->suiteService->find($suiteId);

        if ($suite === null) {
            return $this->notFound();
        }

        return response()->json($this->exporter->export($suite), 200);
    }

    public function import(Request $request)
    {
        if (!OperatorAccess::isOperator(Auth::id())) {
            return $this->forbidden();
        }

        // Everything except the two overrides is the document itself,
        // passed through exactly as received — EvalSuiteImporter is what
        // validates its shape, not this controller.
        $document = $request->except(['name_override', 'agent_identifier_override']);

        $nameOverride = $request->input('name_override');
        $agentIdentifierOverride = $request->input('agent_identifier_override');

        try {
            $suite = $this->importer->import(
                $document,
                $nameOverride !== null ? (string) $nameOverride : null,
                $agentIdentifierOverride !== null ? (string) $agentIdentifierOverride : null,
            );
        } catch (NameConflictException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'name_conflict',
            ], 409);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['import' => [$e->getMessage()]],
            ], 422);
        }

        return response()->json($this->suiteController->formatSuiteDetail($suite), 201);
    }

    private function forbidden()
    {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    private function notFound()
    {
        return response()->json(['message' => 'Not found.'], 404);
    }
}
