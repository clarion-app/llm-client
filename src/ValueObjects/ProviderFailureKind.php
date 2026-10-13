<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * The classified kind of a provider failure (FR-022, research D13).
 * Everything but `rejected` is transient and eligible for the provider
 * retry; `rejected` (a 4xx the provider will not retry) fails as today.
 */
enum ProviderFailureKind: string
{
    case RateLimited = 'rate_limited';
    case Unavailable = 'unavailable';
    case Connection = 'connection';
    case Timeout = 'timeout';
    case IncompleteStream = 'incomplete_stream';
    case Rejected = 'rejected';

    public function isTransient(): bool
    {
        return $this !== self::Rejected;
    }
}
