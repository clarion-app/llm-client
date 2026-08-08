<?php

namespace ClarionApp\LlmClient\Exceptions;

use ClarionApp\LlmClient\ValueObjects\BudgetWorkKind;
use ClarionApp\LlmClient\ValueObjects\EnforcementDecision;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A ceiling in stopping mode refused a unit of work.
 *
 * Extends \Exception and deliberately NOT \RuntimeException. That is not a
 * stylistic choice: this package already catches \RuntimeException around
 * code a refusal now travels through — ConversationController::confirmApiCall()
 * wraps resume() in one and inspects the message text for "expired", and
 * the role-assignment consumers catch it for role failures — so a
 * \RuntimeException subclass would be silently absorbed, or reshaped into an
 * unrelated 422, by
 * catches written about entirely different failures. No single call site
 * would look wrong, which is what makes that defect class expensive to find.
 *
 * The status is 402:
 *
 *  - not 403, which says "you may not do this" when the truth is "there is
 *    no money left for this"; the caller's permissions are unchanged and
 *    the same request will succeed next period;
 *  - not 500, which says the request failed for a reason nobody planned —
 *    the exact unexplained failure this feature exists to replace;
 *  - not 429, which would imply the rate limiting this feature explicitly
 *    excludes, and would invite a client to retry after a short backoff.
 *
 * render() is picked up by Laravel's handler on any exception that defines
 * it, so the package needs no host-application change to produce the body.
 */
class BudgetExceededException extends \Exception
{
    public function __construct(
        public readonly EnforcementDecision $decision,
        public readonly BudgetWorkKind $workKind,
    ) {
        parent::__construct($decision->reason ?? 'Spending ceiling reached.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json($this->decision->toArray($this->workKind), 402);
    }
}
