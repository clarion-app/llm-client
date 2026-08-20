<?php

namespace ClarionApp\LlmClient\Services;

use Symfony\Component\Process\Process;

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

    /**
     * 126-git-operations-confirmation, US2 (data-model.md §5, contracts/
     * git-commit.md §1). Order: not-a-repository -> resolve the effective
     * file list (omitted `$paths` = every path `git status --porcelain=v1`
     * currently reports as changed or untracked; an explicit `$paths`
     * scopes the result to exactly those, verbatim -- never re-intersected
     * against what is actually dirty) -> any explicit path outside the
     * working tree is rejected from inside this method itself (T016's
     * resolved-contradiction note supersedes contracts/git-commit.md's
     * prose on this one point -- never via
     * containmentFailureResponse()/WorkspaceRefusalRecorder, which this
     * stateless, constructor-dependency-free service cannot call anyway)
     * -> an empty resolved list is refused as nothing to commit -> a
     * successful result carries the resolved file list and a
     * `git diff --stat` summary scoped to exactly those files, so an
     * unrelated change elsewhere in the tree never bleeds into what is
     * about to be shown/pinned.
     *
     * @param  string[]|null  $paths
     * @return array{ok: bool, code?: string, reason?: string, files?: string[], diff_stat?: string}
     */
    public function previewCommit(string $rootPath, ?array $paths): array
    {
        if (!$this->isGitRepository($rootPath)) {
            return [
                'ok' => false,
                'code' => 'git_not_a_repository',
                'reason' => 'This project is not a git repository.',
            ];
        }

        if ($paths !== null) {
            foreach ($paths as $path) {
                if ($this->isPathOutsideWorkingTree($rootPath, $path)) {
                    return [
                        'ok' => false,
                        'code' => 'git_path_outside_workspace',
                        'reason' => 'One or more paths are outside the project workspace.',
                    ];
                }
            }

            $resolvedFiles = array_values($paths);
        } else {
            $resolvedFiles = $this->changedOrUntrackedPaths($rootPath);
        }

        if (empty($resolvedFiles)) {
            return [
                'ok' => false,
                'code' => 'git_nothing_to_commit',
                'reason' => 'There is nothing to commit.',
            ];
        }

        return [
            'ok' => true,
            'files' => $resolvedFiles,
            'diff_stat' => $this->diffStatFor($rootPath, $resolvedFiles),
        ];
    }

    /**
     * Every path `git status --porcelain=v1` currently reports, tracked-
     * modified and untracked alike -- omitted `$paths`' own resolution
     * (contracts/git-commit.md §1). A rename line's " -> " arrow is
     * resolved to its new-name side; any surrounding quoting porcelain
     * applies to an unusual filename is stripped.
     *
     * @return string[]
     */
    private function changedOrUntrackedPaths(string $rootPath): array
    {
        $process = new Process(['git', 'status', '--porcelain=v1'], $rootPath);
        $process->run();

        if (!$process->isSuccessful()) {
            return [];
        }

        $paths = [];
        foreach (preg_split('/\R/', $process->getOutput()) as $line) {
            if ($line === '') {
                continue;
            }

            $path = substr($line, 3);
            if (str_contains($path, ' -> ')) {
                [, $path] = explode(' -> ', $path, 2);
            }

            $paths[] = trim($path, '"');
        }

        return $paths;
    }

    /**
     * `git diff --stat`, scoped to exactly the resolved file list -- so an
     * unrelated change elsewhere in the working tree never bleeds into the
     * summary a caller is about to confirm.
     *
     * @param  string[]  $files
     */
    private function diffStatFor(string $rootPath, array $files): string
    {
        $process = new Process(array_merge(['git', 'diff', '--stat', '--'], $files), $rootPath);
        $process->run();

        return $process->isSuccessful() ? trim($process->getOutput()) : '';
    }

    /**
     * True when $path, resolved against $rootPath (an absolute path taken
     * as-is; a relative one resolved against the root), normalizes to
     * somewhere outside $rootPath itself. A manual '.'/'..' collapse
     * rather than realpath(), since the rejected path in the common case
     * (an agent-supplied path that does not exist on disk at all) has
     * nothing for realpath() to resolve.
     */
    private function isPathOutsideWorkingTree(string $rootPath, string $path): bool
    {
        $normalizedRoot = $this->normalizeAbsolutePath(rtrim($rootPath, '/'));
        $candidate = str_starts_with($path, '/') ? $path : rtrim($rootPath, '/').'/'.$path;
        $normalizedCandidate = $this->normalizeAbsolutePath($candidate);

        return $normalizedCandidate !== $normalizedRoot
            && !str_starts_with($normalizedCandidate, $normalizedRoot.'/');
    }

    /**
     * Collapses '.'/'..' segments in an absolute, '/'-separated path
     * without touching the filesystem.
     */
    private function normalizeAbsolutePath(string $path): string
    {
        $stack = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($stack);
                continue;
            }
            $stack[] = $segment;
        }

        return '/'.implode('/', $stack);
    }
}
