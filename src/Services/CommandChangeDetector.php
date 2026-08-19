<?php

namespace ClarionApp\LlmClient\Services;

use FilesystemIterator;
use Generator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * 124-command-limit-controls, US4 (data-model.md §3, research.md R4).
 *
 * A shell command decides for itself what files to touch, so unlike
 * CodingWorkspaceController::writeFile()/deleteFile() -- which know a
 * mutation directly because they perform it themselves through an already-
 * open, already-identity-verified handle -- a command's effect on the
 * workspace can only be discovered afterward, by comparison. This service
 * is that comparison: a before/after directory snapshot pair, diffed into
 * the same created/modified/deleted vocabulary
 * WorkspaceChangeRecorder::record() already accepts.
 *
 * snapshot()'s traversal mirrors WorkspaceSearchService::walk()'s own
 * realpath-based, symlink-skipping discipline exactly -- a symlinked entry
 * is never followed, so nothing outside the workspace root a symlink
 * happens to point at can ever be recorded, and nothing about a path is
 * cached or trusted across calls (PathContainment's own containment-
 * boundary discipline, D1 of 120-workspace-file-tools).
 *
 * Both the snapshot pair and the diffed result are ephemeral -- never
 * persisted by this class itself (data-model.md §3). Only the caller
 * (CodingWorkspaceController::runCommand()) turns a diffed change into a
 * durable row, via the existing, unmodified WorkspaceChangeRecorder.
 */
class CommandChangeDetector
{
    /**
     * Walks $rootPath and records (mtime, size) per relative file path.
     * Directories and symlinks are never recorded themselves -- only the
     * regular files reachable without crossing a symlink boundary, exactly
     * matching WorkspaceSearchService::walk()'s own filter.
     *
     * A root that does not exist (or is not a directory) yields an empty
     * snapshot rather than throwing -- the same "absorb, never fail the
     * whole operation" posture PathContainment/WorkspaceSearchService
     * already use for a vanished or unreadable path.
     *
     * @return array<string, array{mtime: int, size: int}>
     */
    public function snapshot(string $rootPath): array
    {
        $resolvedRoot = realpath($rootPath);
        if ($resolvedRoot === false || !is_dir($resolvedRoot)) {
            return [];
        }

        $snapshot = [];

        foreach ($this->walk($resolvedRoot) as $entry) {
            if (!$entry->isFile()) {
                continue;
            }

            // PHP caches stat() results per path for the lifetime of the
            // process. A command's own mutation happens through Docker in
            // a completely separate process, so the "after" snapshot for a
            // path this same PHP process already stat()'d during the
            // "before" snapshot must never be served from that stale
            // cache -- clearstatcache() is required here, not optional,
            // or a genuinely modified file could be silently missed.
            clearstatcache(true, $entry->getPathname());

            $stat = @stat($entry->getPathname());
            if ($stat === false) {
                // Vanished between being yielded and being stat()'d --
                // silently absorbed, exactly like WorkspaceSearchService's
                // own re-check does for a candidate that disappears
                // mid-walk.
                continue;
            }

            $relativePath = $this->relativePath($resolvedRoot, $entry->getPathname());

            $snapshot[$relativePath] = [
                'mtime' => (int) $stat['mtime'],
                'size' => (int) $stat['size'],
            ];
        }

        return $snapshot;
    }

    /**
     * Diffs two snapshots into a list of changes:
     *  - present after, not before -> created
     *  - present before, not after -> deleted
     *  - present in both, differing (mtime, size) -> modified
     *  - present in both, identical (mtime, size) -> no entry at all
     *
     * @param  array<string, array{mtime: int, size: int}>  $before
     * @param  array<string, array{mtime: int, size: int}>  $after
     * @return list<array{path: string, operation: string}>
     */
    public function diff(array $before, array $after): array
    {
        $changes = [];

        foreach ($after as $path => $state) {
            if (!array_key_exists($path, $before)) {
                $changes[] = ['path' => $path, 'operation' => 'created'];

                continue;
            }

            if ($before[$path] !== $state) {
                $changes[] = ['path' => $path, 'operation' => 'modified'];
            }

            // Identical (mtime, size) on both sides -- no entry at all,
            // not even a zero-effect one (data-model.md §3's one
            // documented, honest imprecision: two different contents that
            // happen to share both mtime and size within one invocation
            // are indistinguishable from "unchanged" without a full
            // content hash, which this mechanism deliberately does not
            // pay for).
        }

        foreach ($before as $path => $state) {
            if (!array_key_exists($path, $after)) {
                $changes[] = ['path' => $path, 'operation' => 'deleted'];
            }
        }

        return $changes;
    }

    private function walk(string $resolvedRoot): Generator
    {
        $filtered = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($resolvedRoot, FilesystemIterator::SKIP_DOTS),
            static fn (SplFileInfo $current): bool => !$current->isLink(),
        );

        $iterator = new RecursiveIteratorIterator($filtered, RecursiveIteratorIterator::SELF_FIRST);

        foreach ($iterator as $entry) {
            yield $entry;
        }
    }

    private function relativePath(string $projectRoot, string $absolutePath): string
    {
        $normalizedRoot = rtrim($projectRoot, '/');

        return ltrim(substr($absolutePath, strlen($normalizedRoot)), '/');
    }
}
