<?php

namespace ClarionApp\LlmClient\Services;

use Illuminate\Http\Client\ConnectionException;

/**
 * A small, generic classifier deciding whether a failed tool-call attempt
 * is plausibly transient -- worth retrying -- or not. Scoped strictly to
 * HTTP-transport-level transience, never to an application-level judgment
 * call: an operation that reached the server and produced an ordinary
 * response (any well-formed 4xx, an application-error body) is never
 * transient, because retrying it can never change the outcome. A
 * permission/authorization refusal is handled separately, unconditionally,
 * before any call this classifier ever sees is dispatched -- it never
 * reaches isTransient() in the first place, but is classified false here
 * too, defensively, since 401/403 are never in the transient set below.
 *
 * $outcome is either the \Throwable a dispatch attempt itself threw (never
 * reached a response at all), or an array carrying at least a 'status' key
 * once a response was received -- the minimal shape a caller needs to
 * build without depending on any particular HTTP client's response class.
 */
final class RetryEligibility
{
    /**
     * @param \Throwable|array{status?: int} $outcome
     */
    public static function isTransient(\Throwable|array $outcome): bool
    {
        if ($outcome instanceof \Throwable) {
            return $outcome instanceof ConnectionException;
        }

        $status = $outcome['status'] ?? null;

        if (!is_int($status)) {
            return false;
        }

        return $status === 429 || ($status >= 500 && $status <= 599);
    }
}
