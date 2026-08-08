<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Services\BudgetGate;
use ClarionApp\LlmClient\Support\OperatorAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Auth;

/**
 * "Where do I stand?" — the read-only face of the same enforcement that
 * would otherwise announce itself by stopping somebody.
 *
 * Every action here is a 200 with figures in it. A scope under, at, or past
 * its ceiling all report as standings: a 402 from this controller would be
 * the feature failing at its stated purpose, which is that a user should not
 * have to be refused in order to discover how much of their allowance is
 * left. Nothing here writes a row, fires an event, or records a run — the
 * whole surface is BudgetGate::standingFor(), which is built on evaluate()
 * rather than admit() precisely so that asking cannot be the thing that
 * warns you.
 *
 * Three addresses, one envelope. Every action returns the same
 * user_ceiling / installation_ceiling / degraded shape, so an interface
 * renders all three with one code path; the routes differ in *whose* user
 * block is served and in who may ask, never in the shape of the answer.
 *
 * A foreign user's standing is a 403, not a filtered 200: a shaped-but-empty
 * body would let a caller infer another user's existence, and eventually
 * their spend, from the difference between two answers (FR-036/SC-015).
 *
 * The installation block, by contrast, is served to whoever asks. It is an
 * installation-wide aggregate — never any individual user's figures — and it
 * is the ceiling that will stop a non-operator just as surely as their own,
 * so withholding it would leave them to be surprised by a limit they had no
 * way to see. It is also exactly what the refusal body already hands a
 * non-operator when the installation ceiling stops them, and a report that
 * contradicted the refusal would be the one thing this feature must not do.
 */
class BudgetStandingController extends Controller
{
    public function __construct(
        private readonly BudgetGate $gate,
    ) {}

    /**
     * The caller's own standing. Any authenticated caller, always about
     * themselves — there is no parameter here to point somewhere else.
     */
    public function self(Request $request): JsonResponse
    {
        return response()->json($this->gate->standingFor((string) Auth::id()), 200);
    }

    /**
     * One named user's standing: an operator, or that user themselves.
     *
     * $userId is the raw UUID from the path and is not resolved to a User
     * model. A user with no ceiling and no usage has a standing to report
     * (FR-037's "no ceiling configured" block), so an absent row is never a
     * 404 — and answering 404 for an unknown id while answering 403 for a
     * known one would leak the very existence this route must not disclose.
     */
    public function user(Request $request, string $userId): JsonResponse
    {
        if (!OperatorAccess::isOperator(Auth::id()) && $userId !== Auth::id()) {
            return $this->forbidden();
        }

        return response()->json($this->gate->standingFor($userId), 200);
    }

    /**
     * The installation-wide standing, addressed directly. Operator only.
     *
     * The user block is the caller's own, which is what keeps the envelope
     * identical across all three routes; the installation block is the point
     * of the address.
     */
    public function installation(Request $request): JsonResponse
    {
        if (!OperatorAccess::isOperator(Auth::id())) {
            return $this->forbidden();
        }

        return response()->json($this->gate->standingFor((string) Auth::id()), 200);
    }

    private function forbidden(): JsonResponse
    {
        return response()->json(['message' => 'Forbidden'], 403);
    }
}
