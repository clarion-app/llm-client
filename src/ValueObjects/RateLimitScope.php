<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * The scope vocabulary for rate_limits rows and resolution.
 *
 * Two cases, not three: there is no installation-wide axis. A rate limit
 * protects fairness among users on a shared installation, not a total
 * installation-wide throughput ceiling, so there is nothing for a third,
 * installation-scoped case to mean.
 */
enum RateLimitScope: string
{
    case UserDefault = 'user_default';
    case User = 'user';
}
