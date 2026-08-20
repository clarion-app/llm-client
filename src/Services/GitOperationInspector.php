<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\CodingProject;
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
     * 126-git-operations-confirmation, US3 (data-model.md §5, contracts/
     * git-publish.md §1). Order: not-a-repository -> `$project->
     * network_enabled` (`git_publish_disabled`, checked before the
     * repository's own remotes are inspected at all -- research.md D5) ->
     * the resolved remote (defaulting to "origin" when `$remote` is
     * omitted) must actually be configured (`git_no_remote_configured` --
     * a distinct code from the disabled case, FR-012) -> the resolved
     * branch (defaulting to the current branch when `$branch` is omitted)
     * must resolve to a local commit (`git_invalid_reference`) -> a
     * successful result carries the sanitized remote URL (D8), the
     * resolved `remote`/`branch`/`pinned_head` (the exact local commit a
     * caller pins into a replayed push -- D6), the commits the remote does
     * not have yet, and whether this push would create the remote branch.
     *
     * `commits_ahead`/`creates_remote_branch` are derived via a read-only
     * `git ls-remote` against the configured remote (never a `git fetch`,
     * which would write to this repository's own remote-tracking refs --
     * this service mutates nothing, matching every other `preview*()`
     * method here).
     *
     * @return array{ok: bool, code?: string, reason?: string, remote?: string, branch?: string, remote_url_sanitized?: string, pinned_head?: string, commits_ahead?: array<int, array{hash: string, short_hash: string, subject: string}>, creates_remote_branch?: bool}
     */
    public function previewPush(CodingProject $project, ?string $remote, ?string $branch): array
    {
        $rootPath = $project->root_path;

        if (!$this->isGitRepository($rootPath)) {
            return [
                'ok' => false,
                'code' => 'git_not_a_repository',
                'reason' => 'This project is not a git repository.',
            ];
        }

        if ($project->network_enabled !== true) {
            return [
                'ok' => false,
                'code' => 'git_publish_disabled',
                'reason' => 'Publishing is not enabled for this workspace.',
            ];
        }

        $remoteName = ($remote !== null && $remote !== '') ? $remote : 'origin';
        $remoteUrl = $this->remoteUrlFor($rootPath, $remoteName);

        if ($remoteUrl === null) {
            return [
                'ok' => false,
                'code' => 'git_no_remote_configured',
                'reason' => 'No shared location is configured for this workspace.',
            ];
        }

        $branchName = ($branch !== null && $branch !== '') ? $branch : $this->currentBranchName($rootPath);
        $localHash = $branchName !== null ? $this->resolveLocalRef($rootPath, $branchName) : null;

        if ($branchName === null || $localHash === null) {
            return [
                'ok' => false,
                'code' => 'git_invalid_reference',
                'reason' => 'Could not resolve the branch to push.',
            ];
        }

        $remoteBranchHash = $this->remoteBranchHash($rootPath, $remoteName, $branchName);
        $createsRemoteBranch = $remoteBranchHash === null;

        return [
            'ok' => true,
            'remote' => $remoteName,
            'branch' => $branchName,
            'remote_url_sanitized' => $this->sanitizeRemoteUrl($remoteUrl),
            'pinned_head' => $localHash,
            'commits_ahead' => $this->commitsAhead($rootPath, $localHash, $remoteBranchHash),
            'creates_remote_branch' => $createsRemoteBranch,
        ];
    }

    /**
     * `git remote get-url <name>` -- null when the remote is not
     * configured at all (or the command otherwise fails), never throws.
     */
    private function remoteUrlFor(string $rootPath, string $remoteName): ?string
    {
        $process = new Process(['git', 'remote', 'get-url', $remoteName], $rootPath);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        $url = trim($process->getOutput());

        return $url === '' ? null : $url;
    }

    /**
     * `git rev-parse --abbrev-ref HEAD` -- null on failure (e.g. an
     * unborn/empty repository) rather than throwing.
     */
    private function currentBranchName(string $rootPath): ?string
    {
        $process = new Process(['git', 'rev-parse', '--abbrev-ref', 'HEAD'], $rootPath);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        $branch = trim($process->getOutput());

        return $branch === '' ? null : $branch;
    }

    /**
     * Resolves $ref (a branch name, in every caller here) to the local
     * commit hash it currently points at -- null when it does not resolve
     * to anything.
     */
    private function resolveLocalRef(string $rootPath, string $ref): ?string
    {
        $process = new Process(['git', 'rev-parse', '--verify', $ref], $rootPath);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        $hash = trim($process->getOutput());

        return $hash === '' ? null : $hash;
    }

    /**
     * 126-git-operations-confirmation, US4 (data-model.md §5, contracts/
     * git-branch.md §1, Grounding note 17 -- the field names below match
     * §2/contracts, not §5's own `start_point_hash`/`start_point_subject`
     * shorthand). Order: not-a-repository -> the resolved start point
     * (defaulting to HEAD when `$startPoint` is omitted) must resolve to a
     * real commit (`git_invalid_reference`) -> `$name` must not already
     * name an existing local branch (`git_branch_already_exists`) -> a
     * successful result carries `branch_name` and `start_point_resolved`
     * (the exact hash/short_hash/subject a caller pins into a later,
     * approved `git branch` call -- D6).
     *
     * @return array{ok: bool, code?: string, reason?: string, branch_name?: string, start_point_resolved?: array{hash: string, short_hash: string, subject: string}}
     */
    public function previewCreateBranch(string $rootPath, string $name, ?string $startPoint): array
    {
        if (!$this->isGitRepository($rootPath)) {
            return [
                'ok' => false,
                'code' => 'git_not_a_repository',
                'reason' => 'This project is not a git repository.',
            ];
        }

        $requestedStartPoint = ($startPoint !== null && $startPoint !== '') ? $startPoint : 'HEAD';
        $resolvedHash = $this->resolveLocalRef($rootPath, $requestedStartPoint);

        if ($resolvedHash === null) {
            return [
                'ok' => false,
                'code' => 'git_invalid_reference',
                'reason' => 'Could not resolve the starting point for the new branch.',
            ];
        }

        if ($this->localBranchExists($rootPath, $name)) {
            return [
                'ok' => false,
                'code' => 'git_branch_already_exists',
                'reason' => 'A branch with this name already exists.',
            ];
        }

        return [
            'ok' => true,
            'branch_name' => $name,
            'start_point_resolved' => $this->describeCommit($rootPath, $resolvedHash),
        ];
    }

    /**
     * `git show-ref --verify --quiet refs/heads/<name>` -- true only when a
     * local branch by exactly this name already exists.
     */
    private function localBranchExists(string $rootPath, string $name): bool
    {
        $process = new Process(['git', 'show-ref', '--verify', '--quiet', 'refs/heads/'.$name], $rootPath);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * The {hash, short_hash, subject} triple for an already-resolved
     * commit -- the same shape commitsAhead() derives per-commit, here for
     * exactly one already-known hash.
     *
     * @return array{hash: string, short_hash: string, subject: string}
     */
    private function describeCommit(string $rootPath, string $hash): array
    {
        $process = new Process(['git', 'log', '-1', '--format=%H|%h|%s', $hash], $rootPath);
        $process->run();

        if (!$process->isSuccessful()) {
            return ['hash' => $hash, 'short_hash' => $hash, 'subject' => ''];
        }

        $line = trim($process->getOutput());
        [$fullHash, $shortHash, $subject] = array_pad(explode('|', $line, 3), 3, '');

        return [
            'hash' => $fullHash !== '' ? $fullHash : $hash,
            'short_hash' => $shortHash,
            'subject' => $subject,
        ];
    }

    /**
     * A read-only `git ls-remote <remoteName> refs/heads/<branchName>` --
     * never a `git fetch`, so this repository's own remote-tracking refs
     * are never written to. Null when the branch does not exist on the
     * remote yet (empty output) or the remote is unreachable.
     */
    private function remoteBranchHash(string $rootPath, string $remoteName, string $branchName): ?string
    {
        $process = new Process(['git', 'ls-remote', $remoteName, 'refs/heads/'.$branchName], $rootPath);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        $output = trim($process->getOutput());
        if ($output === '') {
            return null;
        }

        $firstLine = strtok($output, "\r\n");
        $parts = preg_split('/\s+/', $firstLine ?: '', 2);

        return $parts[0] ?? null;
    }

    /**
     * The commits `$localHash` has that `$remoteBranchHash` does not --
     * every reachable commit at all when the remote branch does not exist
     * yet (`$remoteBranchHash === null`), matching contracts/git-publish.md
     * §1's "every commit on the local branch not already on
     * `<remote>/<branch>` (or every commit at all, if the remote branch
     * does not exist)".
     *
     * @return array<int, array{hash: string, short_hash: string, subject: string}>
     */
    private function commitsAhead(string $rootPath, string $localHash, ?string $remoteBranchHash): array
    {
        $range = $remoteBranchHash === null ? $localHash : "{$remoteBranchHash}..{$localHash}";

        $process = new Process(['git', 'log', $range, '--format=%H|%h|%s'], $rootPath);
        $process->run();

        if (!$process->isSuccessful()) {
            return [];
        }

        $commits = [];
        foreach (preg_split('/\R/', trim($process->getOutput())) as $line) {
            if ($line === '') {
                continue;
            }

            [$hash, $shortHash, $subject] = array_pad(explode('|', $line, 3), 3, '');
            $commits[] = ['hash' => $hash, 'short_hash' => $shortHash, 'subject' => $subject];
        }

        return $commits;
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
