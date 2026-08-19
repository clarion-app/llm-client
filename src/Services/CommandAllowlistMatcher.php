<?php

namespace ClarionApp\LlmClient\Services;

/**
 * 123-sandboxed-shell-execution, US2 (data-model.md §2, FR-006, spec.md
 * Edge Case). A pure matching rule, not a database entity — defines what
 * a `CodingProject.command_allowlist` entry actually means.
 *
 * Two, and only two, comparison forms:
 *   - Exact, whitespace-normalized equality (leading/trailing whitespace
 *     trimmed, internal whitespace runs collapsed to a single space).
 *   - A single, explicit trailing-" *" wildcard form: a pattern ending in
 *     exactly a space followed by an asterisk matches the literal prefix
 *     up to and including that space, followed by anything.
 *
 * No other wildcard position or character (`?`, a mid-string or leading
 * `*`, regex metacharacters) is ever interpreted — deliberately, so a
 * user cannot accidentally create a broader match than the one explicit,
 * documented form. This directly closes the Edge Case's own named
 * example: a pattern of "git st" never matches "git status" (no trailing
 * " *" was used, so it is compared for exact equality, which fails).
 */
class CommandAllowlistMatcher
{
    /**
     * @param  array<int, string>|null  $allowlist  A null or empty
     *     allowlist is treated identically — data-model.md §2 — never
     *     special-cased.
     */
    public function matches(?array $allowlist, string $command): bool
    {
        if (empty($allowlist)) {
            return false;
        }

        $normalizedCommand = $this->normalize($command);

        foreach ($allowlist as $pattern) {
            if (!is_string($pattern) || $pattern === '') {
                continue;
            }

            if ($this->patternMatches($pattern, $normalizedCommand)) {
                return true;
            }
        }

        return false;
    }

    private function patternMatches(string $pattern, string $normalizedCommand): bool
    {
        $normalizedPattern = $this->normalize($pattern);

        if (str_ends_with($normalizedPattern, ' *')) {
            $prefix = substr($normalizedPattern, 0, -1); // keep the trailing space, drop the '*'

            return str_starts_with($normalizedCommand, $prefix);
        }

        return $normalizedPattern === $normalizedCommand;
    }

    /**
     * Trims leading/trailing whitespace and collapses internal whitespace
     * runs to a single space — "git  status" and "git status" are the
     * same pattern.
     */
    private function normalize(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value));
    }
}
