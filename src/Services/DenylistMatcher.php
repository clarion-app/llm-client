<?php

namespace ClarionApp\LlmClient\Services;

/**
 * The single shared fnmatch()-over-denylist matching primitive both
 * ApiCallValidator and McpClientCallValidator call, so their two
 * validate() methods read the same config array through the same
 * matching code rather than each independently reimplementing the
 * identical loop.
 *
 * Stateless and pure: reads no config, env, or database state of its
 * own -- every input is a plain argument, so callers stay fully
 * unit-testable without mocking global config inside this class.
 */
final class DenylistMatcher
{
    /**
     * Returns the first denylist pattern (from $denylist, in order) that
     * fnmatch()-matches any one of $candidates, or null if none match.
     *
     * @param list<string> $denylist
     * @param string ...$candidates One or more strings to check against every pattern -- a match on ANY candidate is a match.
     */
    public static function matchesAny(array $denylist, string ...$candidates): ?string
    {
        foreach ($denylist as $pattern) {
            foreach ($candidates as $candidate) {
                if (fnmatch($pattern, $candidate)) {
                    return $pattern;
                }
            }
        }

        return null;
    }
}
