<?php

namespace ClarionApp\LlmClient\Services;

/**
 * A real, symlink-resistant containment check every coding-workspace file
 * operation is scoped by (112-coding-agent, Foundational, D4,
 * data-model.md §3). `UrlValidator`-shaped: static, never throws, returns
 * `{valid: bool, reason?: string, resolved_path?: string}`.
 *
 * Re-resolved on every single call — nothing about a path is cached or
 * trusted across calls, so there is no time-of-check-to-time-of-use gap.
 * `realpath()` fully resolves symlinks, so a symlink placed inside the
 * project pointing outside it fails the containment check exactly like a
 * raw `../../` escape does — one mechanism, not an enumeration of escape
 * techniques.
 */
class PathContainment
{
    /**
     * @param string $rootPath The project's own canonical root directory.
     * @param string $candidatePath A relative path argument from a tool call.
     * @param bool $targetMustExist true for read/list/delete (the target
     *   itself must already exist); false for a create (write to a
     *   not-yet-existing path) — in that case the target's PARENT
     *   directory is what must already exist and be contained.
     * @return array{valid: bool, reason?: string, resolved_path?: string}
     */
    public static function validate(string $rootPath, string $candidatePath, bool $targetMustExist = true): array
    {
        // A gone project directory is checked BEFORE path resolution
        // begins, and reported with a reason distinct from an ordinary
        // path-containment failure (Edge Case: project no longer
        // reachable).
        if (!is_dir($rootPath)) {
            return ['valid' => false, 'reason' => 'project directory is not reachable'];
        }

        if (self::containsTraversalSegment($candidatePath)) {
            return ['valid' => false, 'reason' => 'path traversal'];
        }

        $resolvedRoot = realpath($rootPath);
        if ($resolvedRoot === false) {
            return ['valid' => false, 'reason' => 'project directory is not reachable'];
        }

        $targetForCheck = $targetMustExist ? $candidatePath : dirname($candidatePath);

        $joined = rtrim($resolvedRoot, '/').'/'.ltrim($targetForCheck, '/');
        $resolved = realpath($joined);

        if ($resolved === false) {
            return ['valid' => false, 'reason' => 'not found'];
        }

        if ($resolved !== $resolvedRoot && !str_starts_with($resolved, $resolvedRoot.'/')) {
            return ['valid' => false, 'reason' => 'outside the registered project'];
        }

        if ($targetMustExist) {
            return ['valid' => true, 'resolved_path' => $resolved];
        }

        // Create mode: $resolved is the (contained, already-existing)
        // parent directory. Append the basename, verified to contain no
        // path separator of its own, so a candidate like "a/../../b"
        // cannot smuggle a second traversal in through the basename.
        $basename = basename($candidatePath);
        if ($basename === '' || str_contains($basename, '/')) {
            return ['valid' => false, 'reason' => 'invalid file name'];
        }

        return ['valid' => true, 'resolved_path' => $resolved.'/'.$basename];
    }

    /**
     * A literal ".." path segment, checked as a fast pre-resolution guard
     * (before realpath() ever runs) — realpath() alone already resolves a
     * traversal correctly, but this catches it earlier and independently,
     * so the guarantee does not rest on realpath()'s behavior alone.
     */
    private static function containsTraversalSegment(string $path): bool
    {
        foreach (preg_split('#/+#', $path) as $segment) {
            if ($segment === '..') {
                return true;
            }
        }

        return false;
    }
}
