<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Models\SpendingCeiling;
use ClarionApp\LlmClient\Services\SpendingCeilingService;
use ClarionApp\LlmClient\Support\OperatorAccess;
use ClarionApp\LlmClient\ValueObjects\BudgetScope;
use Illuminate\Http\Request;
use Auth;

/**
 * Operator-only spending ceiling configuration: the installation-wide
 * ceiling and the default that applies to every user with no override of
 * their own.
 *
 * Two properties of this controller are deliberate rather than incidental:
 *
 *  - Every action checks operator access first and answers 403 otherwise,
 *    including for a non-operator acting on their own ceiling. Reading the
 *    list is as restricted as writing it.
 *  - None of these routes is ever subject to budget enforcement. An
 *    operator who has been stopped by a ceiling must always retain the
 *    ability to raise or waive it — that is the intended way back to
 *    capability, and it cannot be behind the very limit it undoes.
 *
 * SpendingCeilingService is the sole write path; this controller only
 * translates HTTP to it, and translates its \InvalidArgumentException
 * rejections to a 422.
 */
class BudgetCeilingController extends Controller
{
    public function __construct(
        private readonly SpendingCeilingService $service,
    ) {}

    public function index()
    {
        if (!OperatorAccess::isOperator(Auth::id())) {
            return $this->forbidden();
        }

        $data = $this->service->list()
            ->map(fn (SpendingCeiling $ceiling) => $this->formatCeiling($ceiling))
            ->values();

        return response()->json(['data' => $data], 200);
    }

    public function putInstallation(Request $request)
    {
        return $this->put($request, BudgetScope::Installation, SpendingCeiling::INSTALLATION_SCOPE_ID);
    }

    public function putUserDefault(Request $request)
    {
        return $this->put($request, BudgetScope::UserDefault, SpendingCeiling::INSTALLATION_SCOPE_ID);
    }

    public function destroyInstallation()
    {
        return $this->destroy(BudgetScope::Installation, SpendingCeiling::INSTALLATION_SCOPE_ID);
    }

    public function destroyUserDefault()
    {
        return $this->destroy(BudgetScope::UserDefault, SpendingCeiling::INSTALLATION_SCOPE_ID);
    }

    private function put(Request $request, BudgetScope $scopeType, string $scopeId)
    {
        if (!OperatorAccess::isOperator(Auth::id())) {
            return $this->forbidden();
        }

        // only() omits a key the caller did not send, which is what
        // distinguishes "no approach_threshold, use the configured default"
        // from "approach_threshold: null", and keeps an unknown field from
        // reaching the service at all.
        $attributes = $request->only([
            'amount',
            'period_type',
            'enforcement_mode',
            'approach_threshold',
            'waived',
        ]);

        try {
            $ceiling = $this->service->upsert($scopeType, $scopeId, $attributes);
        } catch (\InvalidArgumentException $e) {
            return $this->unprocessable($e->getMessage());
        }

        return response()->json($this->formatCeiling($ceiling), 200);
    }

    private function destroy(BudgetScope $scopeType, string $scopeId)
    {
        if (!OperatorAccess::isOperator(Auth::id())) {
            return $this->forbidden();
        }

        $this->service->remove($scopeType, $scopeId);

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function formatCeiling(SpendingCeiling $ceiling): array
    {
        // amount and approach_threshold are read through the model's
        // plain-decimal casts and emitted as strings on purpose: a JSON
        // number is a float on the far side of every parser, and this
        // package's bcmath-only arithmetic does not stop at the HTTP
        // boundary.
        return [
            'id' => $ceiling->id,
            'scope_type' => $ceiling->scope_type,
            'scope_id' => $ceiling->scope_id,
            'amount' => $ceiling->amount,
            'period_type' => $ceiling->period_type,
            'enforcement_mode' => $ceiling->enforcement_mode,
            'approach_threshold' => $ceiling->approach_threshold,
            'waived' => (bool) $ceiling->waived,
        ];
    }

    private function forbidden()
    {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    private function unprocessable(string $message)
    {
        return response()->json([
            'message' => $message,
            'errors' => ['ceiling' => [$message]],
        ], 422);
    }
}
