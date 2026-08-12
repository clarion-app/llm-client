<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Services\DegradationGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Auth;

/**
 * GET /degradation/status (contracts §2, US4/FR-007/SC-004) — the live,
 * non-persisted "would a fresh request be reduced right now" read.
 *
 * No operator gate: every authenticated user may check their own status —
 * there is no route parameter for another user's id, mirroring
 * BudgetStandingController::self()'s own "always about yourself, nothing
 * here points somewhere else" shape.
 *
 * The optional ?conversation_id= query parameter is passed through
 * unvalidated: ownership is checked entirely inside
 * DegradationGate::statusFor() (research.md D9, Constitution §IV), so this
 * controller never itself distinguishes a foreign conversation id from an
 * absent one — the one property that must hold for the ownership leak this
 * endpoint could otherwise create to be structurally impossible.
 */
class DegradationStatusController extends Controller
{
    public function __construct(
        private readonly DegradationGate $gate,
    ) {}

    public function self(Request $request): JsonResponse
    {
        $status = $this->gate->statusFor(
            (string) Auth::id(),
            $request->query('conversation_id'),
        );

        return response()->json($status, 200);
    }
}
