<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Exceptions\IncompleteStream;
use ClarionApp\LlmClient\ValueObjects\ProviderFailureKind;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Carbon;

/**
 * Names what a failed model call is: a rate limit, an overload, a refused or
 * timed-out connection, a cut stream (all worth waiting for) or a request the
 * provider rejects (which no amount of waiting changes). Built on
 * {@see RetryEligibility}, the package's one transience classifier.
 */
final class ProviderFailure
{
    public static function classify(\Throwable $e): ProviderFailureKind
    {
        if (!RetryEligibility::isTransientProviderFailure($e)) {
            return ProviderFailureKind::Rejected;
        }

        if (RetryEligibility::hasTransportFailure($e)) {
            return self::isTimeout($e) ? ProviderFailureKind::Timeout : ProviderFailureKind::Connection;
        }

        if (RetryEligibility::chainHas($e, IncompleteStream::class)) {
            return ProviderFailureKind::IncompleteStream;
        }

        return RetryEligibility::providerStatus($e) === 429
            ? ProviderFailureKind::RateLimited
            : ProviderFailureKind::Unavailable;
    }

    /** The HTTP status the failure carries, when it has one. */
    public static function status(\Throwable $e): ?int
    {
        return RetryEligibility::providerStatus($e);
    }

    /**
     * Seconds the provider asked us to wait (`Retry-After`, as seconds or an
     * HTTP date), read from the response of a wrapped request exception.
     * Null when absent or not understood; never negative.
     */
    public static function retryAfterSeconds(\Throwable $e): ?int
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if (!($current instanceof RequestException) || !$current->hasResponse()) {
                continue;
            }

            $header = trim($current->getResponse()->getHeaderLine('Retry-After'));
            if ($header === '') {
                continue;
            }
            if (ctype_digit($header)) {
                return (int) $header;
            }

            $at = strtotime($header);
            if ($at !== false) {
                return max(0, $at - Carbon::now()->getTimestamp());
            }
        }

        return null;
    }

    /** Whether a connect failure in the chain is cURL's operation-timed-out error. */
    private static function isTimeout(\Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof ConnectException) {
                if (($current->getHandlerContext()['errno'] ?? null) === 28 || str_contains($current->getMessage(), 'cURL error 28')) {
                    return true;
                }
            } elseif (str_contains($current->getMessage(), 'cURL error 28')) {
                return true;
            }
        }

        return false;
    }
}
