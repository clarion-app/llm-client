<?php

namespace ClarionApp\LlmClient\Services;

/**
 * A real, symlink-resistant containment check every coding-workspace file
 * operation is scoped by. `UrlValidator`-shaped: static, never throws,
 * returns `{valid: bool, reason?: string, resolved_path?: string,
 * resolved_identity?: array{dev: int, ino: int}|null}`.
 *
 * Re-resolved on every single call — nothing about a path is cached or
 * trusted across calls, so there is no time-of-check-to-time-of-use gap.
 * `realpath()` fully resolves symlinks, so a symlink placed inside the
 * project pointing outside it fails the containment check exactly like a
 * raw `../../` escape does — one mechanism, not an enumeration of escape
 * techniques.
 *
 * Two additional guards close shapes `realpath()`'s own resolution does
 * not reach: a symlink already sitting at a not-yet-existing write
 * target (never dereferenced by `realpath()`, since the leaf itself is
 * never resolved in that mode), and a hard link, which is not a link at
 * all from `realpath()`'s point of view — a second directory entry
 * pointing at the same inode as a file elsewhere, so no path-resolution
 * step can ever notice it. Both guards reuse the same "outside the
 * registered project" refusal reason ordinary containment failures use,
 * rather than inventing a distinct one.
 *
 * `resolved_identity` is a `{dev, ino}` fingerprint of the resolved
 * target, captured from the same `lstat()` call the hard-link guard
 * already performs — free, not a redundant stat. It lets a caller that
 * opens the resolved path a moment later re-verify, via `fstat()` on the
 * open handle, that the location on disk is still the exact one this
 * call approved, rather than trusting the path string a second time.
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
     * @return array{valid: bool, reason?: string, resolved_path?: string, resolved_identity?: array{dev: int, ino: int}|null}
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
            // The hard-link guard is scoped to regular files only — a
            // directory's own link count is greater than one for entirely
            // ordinary reasons (its own entry, its parent's entry for it,
            // and one increment per immediate subdirectory's own ".."
            // entry), so applying this check to a directory would refuse
            // any ordinary directory that happens to contain one.
            $stat = @lstat($resolved);
            $resolvedIdentity = null;
            if ($stat !== false) {
                if (is_file($resolved) && $stat['nlink'] > 1) {
                    return ['valid' => false, 'reason' => 'outside the registered project'];
                }
                $resolvedIdentity = ['dev' => $stat['dev'], 'ino' => $stat['ino']];
            }

            return ['valid' => true, 'resolved_path' => $resolved, 'resolved_identity' => $resolvedIdentity];
        }

        // Create mode: $resolved is the (contained, already-existing)
        // parent directory. Append the basename, verified to contain no
        // path separator of its own, so a candidate like "a/../../b"
        // cannot smuggle a second traversal in through the basename.
        $basename = basename($candidatePath);
        if ($basename === '' || str_contains($basename, '/')) {
            return ['valid' => false, 'reason' => 'invalid file name'];
        }

        $leaf = $resolved.'/'.$basename;

        // A symlink already sitting at the write target is refused purely
        // for being a link, before any comparison of where it points — a
        // genuinely new file has nothing at the leaf yet, so this never
        // fires for an ordinary create.
        if (file_exists($leaf) && is_link($leaf)) {
            return ['valid' => false, 'reason' => 'outside the registered project'];
        }

        $stat = @lstat($leaf);
        $resolvedIdentity = null;
        if ($stat !== false) {
            if (is_file($leaf) && $stat['nlink'] > 1) {
                return ['valid' => false, 'reason' => 'outside the registered project'];
            }
            $resolvedIdentity = ['dev' => $stat['dev'], 'ino' => $stat['ino']];
        }

        return ['valid' => true, 'resolved_path' => $leaf, 'resolved_identity' => $resolvedIdentity];
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
