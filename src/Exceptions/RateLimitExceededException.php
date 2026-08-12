<?php

namespace ClarionApp\LlmClient\Exceptions;

use ClarionApp\LlmClient\ValueObjects\BudgetWorkKind;
use ClarionApp\LlmClient\ValueObjects\RateLimitDecision;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A per-user rate limit refused a unit of work.
 *
 * Extends \Exception and deliberately NOT \RuntimeException — the identical
 * reasoning BudgetExceededException's own docblock gives: this package
 * already catches \RuntimeException around code a refusal now travels
 * through (ConversationController::confirmApiCall() wraps resume() in one
 * and inspects the message text for "expired"), so a \RuntimeException
 * subclass would be silently absorbed, or reshaped into an unrelated 422,
 * by a catch written about an entirely different failure.
 *
 * The status is 429, not 402 and not 403:
 *
 *  - not 402, which this package already reserves for a spending ceiling —
 *    no money is at issue here;
 *  - not 403, which says "you may not do this" when the truth is "you have
 *    started too many requests too quickly"; the caller's permissions are
 *    unchanged and the identical request succeeds once the window resets;
 *  - 429 carries a standard Retry-After header a well-behaved HTTP client
 *    already knows to treat as "back off and retry" — exactly the case a
 *    rate limit is idiomatic for.
 *
 * render() is picked up by Laravel's handler on any exception that defines
 * it, so the package needs no host-application change to produce the body.
 */
class RateLimitExceededException extends \Exception
{
    public function __construct(
        public readonly RateLimitDecision $decision,
        public readonly BudgetWorkKind $workKind,
    ) {
        parent::__construct($decision->reason ?? 'Rate limit exceeded.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()
            ->json($this->decision->toArray($this->workKind), 429)
            ->header('Retry-After', (string) ($this->decision->retryAfterSeconds() ?? 0));
    }
}
