<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Models\ReductionStep;
use ClarionApp\LlmClient\Services\ReductionLadderService;
use ClarionApp\LlmClient\Support\OperatorAccess;
use Illuminate\Http\Request;
use Auth;

/**
 * Operator-only reduction ladder configuration (contracts §1, US3): the
 * ordered set of rungs DegradationGate::evaluate() reads fresh on every
 * admitted request — no restart, no cache to bust (FR-011/SC-008).
 *
 * Mirrors ConversationWorkCeilingController's exact shape:
 *
 *  - Every action checks operator access first and answers 403 otherwise,
 *    including index() — reading the ladder is as restricted as writing
 *    it.
 *  - ReductionLadderService is the sole write path; this controller only
 *    translates HTTP to it, and translates its InvalidArgumentException
 *    rejections to a 422.
 */
class ReductionStepController extends Controller
{
    public function __construct(
        private readonly ReductionLadderService $service,
    ) {}

    public function index()
    {
        if (!OperatorAccess::isOperator(Auth::id())) {
            return $this->forbidden();
        }

        $data = $this->service->list()
            ->map(fn (ReductionStep $step) => $this->formatStep($step))
            ->values();

        return response()->json(['reduction_steps' => $data], 200);
    }

    public function store(Request $request)
    {
        return $this->put($request, null);
    }

    public function update(Request $request, string $id)
    {
        return $this->put($request, $id);
    }

    public function destroy(string $id)
    {
        if (!OperatorAccess::isOperator(Auth::id())) {
            return $this->forbidden();
        }

        $this->service->destroy($id);

        return response()->noContent();
    }

    private function put(Request $request, ?string $id)
    {
        if (!OperatorAccess::isOperator(Auth::id())) {
            return $this->forbidden();
        }

        // only() omits a key the caller did not send, which is what keeps
        // an unknown field from reaching the service at all.
        $attributes = $request->only([
            'axis',
            'threshold_ratio',
            'substitute_model',
            'substitute_server_id',
            'withheld_tools',
            'history_budget_ratio',
            'enabled',
        ]);

        try {
            $step = $this->service->put($attributes, $id);
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessable($e->getMessage());
        }

        return response()->json($this->formatStep($step), 200);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatStep(ReductionStep $step): array
    {
        return [
            'id' => $step->id,
            'axis' => $step->axis,
            'threshold_ratio' => $step->threshold_ratio,
            'substitute_model' => $step->substitute_model,
            'substitute_server_id' => $step->substitute_server_id,
            'withheld_tools' => $step->withheld_tools ?? [],
            'history_budget_ratio' => $step->history_budget_ratio,
            'enabled' => (bool) $step->enabled,
        ];
    }

    private function forbidden()
    {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    private function unprocessable(string $rawMessage)
    {
        [$code, $message] = $this->splitCode($rawMessage);

        return response()->json([
            'message' => $message,
            'code' => $code,
            'errors' => ['reduction_step' => [$message]],
        ], 422);
    }

    /**
     * ReductionLadderService prefixes every rejection message with its
     * contracts §1 machine code, "{code}: {message}" — split the two back
     * apart here so the HTTP response carries both, rather than
     * duplicating a second code-lookup table in this controller.
     *
     * @return array{0: ?string, 1: string}
     */
    private function splitCode(string $rawMessage): array
    {
        if (str_contains($rawMessage, ': ')) {
            [$code, $message] = explode(': ', $rawMessage, 2);

            return [$code, $message];
        }

        return [null, $rawMessage];
    }
}
