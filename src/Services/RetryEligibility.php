<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Exceptions\IncompleteStream;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
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
    /**
     * Whether a failed model call is plausibly transient: worth waiting for
     * and repeating. A provider adapter reports an HTTP status as the
     * exception code (429, 408 and every 5xx, including Anthropic's 529,
     * are transient); a transport failure (a refused or timed-out
     * connection, a transfer that never produced a response) is found
     * anywhere in the `previous` chain; a cut stream is transient. Every
     * other failure (a rejected request, an unknown model, a bug in our own
     * code) is not. {@see self::isTransient()} is unchanged: it serves the
     * tool-call retry.
     */
    public static function isTransientProviderFailure(\Throwable $e): bool
    {
        if (self::hasTransportFailure($e) || self::chainHas($e, IncompleteStream::class)) {
            return true;
        }

        $status = self::providerStatus($e);

        return $status === 429 || $status === 408 || ($status !== null && $status >= 500 && $status <= 599);
    }

    /** Whether the chain holds a failure that never produced a response. */
    public static function hasTransportFailure(\Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof ConnectException || $current instanceof ConnectionException) {
                return true;
            }
            if ($current instanceof TransferException && !($current instanceof RequestException && $current->hasResponse())) {
                return true;
            }
        }

        return false;
    }

    /** Whether any exception in the chain is an instance of $class. */
    public static function chainHas(\Throwable $e, string $class): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /** The HTTP status an adapter or Guzzle put in an exception code, outermost first. */
    public static function providerStatus(\Throwable $e): ?int
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            $code = $current->getCode();
            if (is_int($code) && $code >= 100 && $code <= 599) {
                return $code;
            }
        }

        return null;
    }
}
