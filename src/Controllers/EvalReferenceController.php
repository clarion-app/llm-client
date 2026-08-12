<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use Auth;
use ClarionApp\LlmClient\Models\EvalReferenceDesignation;
use ClarionApp\LlmClient\Services\EvalReferenceService;
use ClarionApp\LlmClient\Services\EvalSuiteService;
use ClarionApp\LlmClient\Support\OperatorAccess;
use Illuminate\Http\Request;

/**
 * Reference designation and its audit history (contracts §2). Operator
 * gated throughout, reads included, mirroring EvalRunController's own
 * gated shape (077-079).
 */
class EvalReferenceController extends Controller
{
    public function __construct(
        private readonly EvalReferenceService $service,
        private readonly EvalSuiteService $suiteService,
    ) {
    }

    public function designate(Request $request, string $runId)
    {
        if (!OperatorAccess::isOperator(Auth::id())) {
            return $this->forbidden();
        }

        try {
            $designation = $this->service->designate($runId, Auth::id());
        } catch (\InvalidArgumentException $e) {
            if ($e->getMessage() === 'Run not found.') {
                return $this->notFound();
            }

            return $this->unprocessable($e->getMessage());
        }

        return response()->json($this->formatReference($designation), 201);
    }

    public function current(string $suiteId)
    {
        if (!OperatorAccess::isOperator(Auth::id())) {
            return $this->forbidden();
        }

        $suite = $this->suiteService->find($suiteId);

        if ($suite === null) {
            return $this->notFound();
        }

        $designation = $this->service->current($suite->agent_identifier);

        if ($designation === null) {
            // response()->json(null) cannot express a literal JSON `null`
            // body — Symfony's JsonResponse substitutes an empty
            // ArrayObject for a null $data argument, which would encode
            // as `{}` instead. "No reference set" (AC5) is a real, valid
            // state, not an error, so the body must be the literal JSON
            // null a caller can distinguish from an empty object.
            return response('null', 200, ['Content-Type' => 'application/json']);
        }

        return response()->json($this->formatReference($designation), 200);
    }

    public function history(string $suiteId)
    {
        if (!OperatorAccess::isOperator(Auth::id())) {
            return $this->forbidden();
        }

        $suite = $this->suiteService->find($suiteId);

        if ($suite === null) {
            return $this->notFound();
        }

        $designations = $this->service->history($suite->agent_identifier)
            ->map(fn (EvalReferenceDesignation $designation) => $this->formatReference($designation))
            ->values();

        return response()->json(['data' => $designations], 200);
    }

    /**
     * The §1.1 `reference` shape.
     *
     * @return array<string, mixed>
     */
    private function formatReference(EvalReferenceDesignation $designation): array
    {
        return [
            'agent_label' => $designation->agent_label,
            'run_id' => $designation->run_id,
            'designated_by' => $designation->designated_by,
            'designated_at' => optional($designation->created_at)->toJSON(),
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
            'errors' => ['run' => [$message]],
        ], 422);
    }
}
