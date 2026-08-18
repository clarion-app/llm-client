<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\CodingProject;
use FilesystemIterator;
use Generator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Filename and content search within a single already-registered workspace
 * (120-workspace-file-tools, Phase 3 User Story 1, data-model.md §2,
 * research.md D1-D2). Computed fresh on every call, nothing stored.
 *
 * Every candidate result is passed through PathContainment::validate()
 * with the exact same three-argument shape every existing coding-workspace
 * operation already uses — once for the requested subpath, and again for
 * every individual candidate before it is included — so search can never
 * drift from the containment guarantee read/write/delete already rely on
 * (FR-009). Traversal additionally never descends into a symlinked
 * directory and never returns a symlinked file, independent of and prior
 * to that per-result re-check (defense in depth, research.md D1).
 */
class WorkspaceSearchService
{
    /**
     * The number of leading bytes read from a candidate file to sniff
     * whether it is binary before any line-by-line streaming begins.
     */
    private const SNIFF_SAMPLE_BYTES = 8192;

    public function __construct(private readonly WorkspaceFilePolicy $filePolicy = new WorkspaceFilePolicy())
    {
    }

    /**
     * Filename-pattern search. Matches a candidate's workspace-relative
     * path OR its basename against $pattern (fnmatch()), so both an exact
     * basename and a pattern naming a directory segment work as expected.
     *
     * @return array{valid: bool, reason?: string, matches?: array, truncated?: bool, files_scanned?: int}
     */
    public function searchFiles(CodingProject $project, string $subpath, string $pattern): array
    {
        $rootCheck = PathContainment::validate($project->root_path, $subpath, true);
        if (!$rootCheck['valid']) {
            return ['valid' => false, 'reason' => $rootCheck['reason'] ?? 'invalid path'];
        }

        $projectRoot = realpath($project->root_path);
        $maxResults = (int) config('llm-client.coding_agent.search.max_results', 100);
        $maxFilesScanned = (int) config('llm-client.coding_agent.search.max_files_scanned', 5000);

        $matches = [];
        $filesScanned = 0;
        $truncated = false;

        foreach ($this->walk($rootCheck['resolved_path']) as $entry) {
            if ($filesScanned >= $maxFilesScanned) {
                $truncated = true;
                break;
            }

            if ($entry->isLink()) {
                // Defense in depth — the walk's own filter already keeps a
                // symlinked entry from ever reaching here, but this is never
                // trusted alone (research.md D1).
                continue;
            }

            $filesScanned++;

            $relativePath = $this->relativePath($projectRoot, $entry->getPathname());
            $basename = $entry->getFilename();

            if (!fnmatch($pattern, $relativePath) && !fnmatch($pattern, $basename)) {
                continue;
            }

            $check = PathContainment::validate($project->root_path, $relativePath, true);
            if (!$check['valid']) {
                // Never included, and never treated as an error for the
                // whole search (data-model.md §2) — this is also what
                // silently absorbs a candidate that vanished between being
                // yielded by the walk and this re-check.
                continue;
            }

            $matches[] = [
                'path' => $relativePath,
                'type' => $entry->isDir() ? 'dir' : 'file',
            ];

            if (count($matches) >= $maxResults) {
                $truncated = true;
                break;
            }
        }

        return [
            'valid' => true,
            'matches' => $matches,
            'truncated' => $truncated,
            'files_scanned' => $filesScanned,
        ];
    }

    /**
     * Content search. Streams each eligible candidate line-by-line
     * (fopen/fgets) — never file_get_contents() — so memory use stays
     * bounded per line rather than per file. A candidate classified binary
     * by WorkspaceFilePolicy::isBinary() (first 8KB sample) is skipped
     * entirely and counted; a match inside a file WorkspaceFilePolicy::
     * isOversized() reports oversized is still found and returned, only
     * tagged, since streaming never needs to buffer a whole file.
     *
     * @return array{valid: bool, reason?: string, matches?: array, truncated?: bool, files_scanned?: int, skipped_binary_count?: int}
     */
    public function searchContent(CodingProject $project, string $subpath, string $query, ?string $pattern = null): array
    {
        $rootCheck = PathContainment::validate($project->root_path, $subpath, true);
        if (!$rootCheck['valid']) {
            return ['valid' => false, 'reason' => $rootCheck['reason'] ?? 'invalid path'];
        }

        $projectRoot = realpath($project->root_path);
        $maxResults = (int) config('llm-client.coding_agent.search.max_results', 100);
        $maxFilesScanned = (int) config('llm-client.coding_agent.search.max_files_scanned', 5000);
        $maxMatchesPerFile = (int) config('llm-client.coding_agent.search.max_matches_per_file', 5);

        $matches = [];
        $filesScanned = 0;
        $skippedBinaryCount = 0;
        $truncated = false;

        foreach ($this->walk($rootCheck['resolved_path']) as $entry) {
            if ($filesScanned >= $maxFilesScanned) {
                $truncated = true;
                break;
            }

            if ($entry->isLink() || $entry->isDir()) {
                // Directories are never searched as content; symlinks are
                // never followed (defense in depth, as above).
                continue;
            }

            $filesScanned++;

            $relativePath = $this->relativePath($projectRoot, $entry->getPathname());

            if ($pattern !== null && $pattern !== '' && !fnmatch($pattern, $relativePath)) {
                continue;
            }

            $check = PathContainment::validate($project->root_path, $relativePath, true);
            if (!$check['valid']) {
                continue;
            }

            $absolutePath = $check['resolved_path'];

            // A candidate that vanished, or became unreadable, between the
            // walk yielding it and this read is skipped, never fatal
            // (spec.md Edge Case, data-model.md §2 "mid-scan file changes").
            $handle = @fopen($absolutePath, 'rb');
            if ($handle === false) {
                continue;
            }

            $sample = @fread($handle, self::SNIFF_SAMPLE_BYTES);
            if ($sample === false) {
                fclose($handle);
                continue;
            }

            if ($this->filePolicy->isBinary($sample)) {
                $skippedBinaryCount++;
                fclose($handle);
                continue;
            }

            $isOversized = $this->filePolicy->isOversized($absolutePath);

            rewind($handle);

            $matchesInFile = 0;
            $lineNumber = 0;
            $stopWholeSearch = false;

            while (($line = fgets($handle)) !== false) {
                $lineNumber++;

                if (stripos($line, $query) === false) {
                    continue;
                }

                $matches[] = [
                    'path' => $relativePath,
                    'line' => $lineNumber,
                    'snippet' => rtrim($line, "\r\n"),
                    'file_oversized' => $isOversized,
                ];
                $matchesInFile++;

                if ($matchesInFile >= $maxMatchesPerFile) {
                    // Caps this one file's contribution without stopping
                    // the whole search — move on to the next candidate.
                    break;
                }

                if (count($matches) >= $maxResults) {
                    $truncated = true;
                    $stopWholeSearch = true;
                    break;
                }
            }

            fclose($handle);

            if ($stopWholeSearch) {
                break;
            }
        }

        return [
            'valid' => true,
            'matches' => $matches,
            'truncated' => $truncated,
            'files_scanned' => $filesScanned,
            'skipped_binary_count' => $skippedBinaryCount,
        ];
    }

    /**
     * A single recursive walk shared by both search methods. Symlinked
     * entries (file or directory) are excluded via a filter that governs
     * recursion itself — a plain in-loop `isLink()` skip alone would not
     * stop RecursiveIteratorIterator from still descending into a
     * symlinked directory's own children, since that decision is made by
     * the filtered iterator's hasChildren()/getChildren(), not by the
     * caller's loop body (research.md D1: "never descending into a
     * symlinked directory").
     *
     * @return Generator<SplFileInfo>
     */
    private function walk(string $resolvedRoot): Generator
    {
        if (!is_dir($resolvedRoot)) {
            return;
        }

        $filtered = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($resolvedRoot, FilesystemIterator::SKIP_DOTS),
            static fn (SplFileInfo $current): bool => !$current->isLink(),
        );

        $iterator = new RecursiveIteratorIterator($filtered, RecursiveIteratorIterator::SELF_FIRST);

        foreach ($iterator as $entry) {
            yield $entry;
        }
    }

    /**
     * The entry's path relative to the project's own canonical root —
     * always computed against the whole project root, never against a
     * narrower `subpath` starting point (data-model.md §2).
     */
    private function relativePath(string $projectRoot, string $absolutePath): string
    {
        $normalizedRoot = rtrim($projectRoot, '/');

        return ltrim(substr($absolutePath, strlen($normalizedRoot)), '/');
    }
}
