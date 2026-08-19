<?php

namespace ClarionApp\LlmClient\Services;

/**
 * The two universally-shared git inspection primitives every mutating and
 * read-only git operation in this feature relies on (data-model.md §5):
 * `isGitRepository()` (checked before any other git operation is attempted
 * against a workspace's root path — D4) and `sanitizeRemoteUrl()` (used
 * defensively wherever a remote URL or raw git stderr text is ever
 * returned to a caller, so an embedded credential never leaks — D8).
 *
 * A small, stateless service with no constructor dependencies, mirroring
 * `GitDefinitionFileReader`'s own shape exactly. The four `preview*()`
 * methods this service will eventually also carry (one per mutating story,
 * US2-US5) are deliberately not present yet — each is added incrementally,
 * driven by its own story's own red test.
 */
class GitOperationInspector
{
    /**
     * True only when $rootPath both exists and contains a `.git` entry
     * (directory, for an ordinary repository, or file, for a worktree
     * whose `.git` is a pointer file) — never throws, even for a path
     * that does not exist at all.
     */
    public function isGitRepository(string $rootPath): bool
    {
        $gitPath = rtrim($rootPath, '/').'/.git';

        return file_exists($gitPath);
    }

    /**
     * Strips the userinfo component ("user:pass@" or "user@") between a
     * URL's `://` scheme separator and its host, for any URL that has a
     * `://` scheme at all (research.md D8). A URL with no `://` — the
     * SCP-like SSH shorthand `git@host:path`, whose leading `git@` is the
     * literal SSH protocol user rather than a credential — is returned
     * completely untouched, since D8's own stated scope ("everything
     * between `://` and the last `@` before the host") does not apply to
     * that form at all.
     */
    public function sanitizeRemoteUrl(string $url): string
    {
        $schemeSeparatorPosition = strpos($url, '://');

        if ($schemeSeparatorPosition === false) {
            return $url;
        }

        $schemeEnd = $schemeSeparatorPosition + 3;
        $afterScheme = substr($url, $schemeEnd);

        $slashPosition = strpos($afterScheme, '/');
        $authority = $slashPosition === false ? $afterScheme : substr($afterScheme, 0, $slashPosition);
        $remainder = $slashPosition === false ? '' : substr($afterScheme, $slashPosition);

        $lastAtPosition = strrpos($authority, '@');

        if ($lastAtPosition === false) {
            return $url;
        }

        $host = substr($authority, $lastAtPosition + 1);

        return substr($url, 0, $schemeEnd).$host.$remainder;
    }
}
