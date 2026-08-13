<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Exceptions\AgentFileUnreadableException;
use ClarionApp\LlmClient\ValueObjects\GitCommitInfo;
use Symfony\Component\Process\Process;

/**
 * Reads a linked definition file's working-tree content and, best-effort,
 * the most recent git commit that touched it (contracts §12, research.md
 * D8/D11).
 *
 * readWorkingTreeContent() always reads the file's exact current bytes on
 * disk via plain file_get_contents() — never `git show HEAD:<path>` — so
 * an uncommitted edit is visible immediately (the exact "edited outside
 * the product" scenario spec.md's Edge Cases section names).
 *
 * latestCommitFor() shells out to `git log` via Symfony\Process's
 * array-argument constructor — never Process::fromShellCommandline() or
 * shell_exec()/exec() — because $repositoryPath/$filePath are stored,
 * reusable strings an attacker with write access to an agent's link
 * configuration could otherwise attempt to weaponize through shell
 * interpolation. It never throws: no commit touching the file yet, an
 * invalid repository path, or the `git` binary being absent are all
 * reported the same way — a null return — since commit attribution is
 * best-effort and must never block a file_sync version from being
 * recorded.
 */
class GitDefinitionFileReader
{
    /**
     * @throws AgentFileUnreadableException
     */
    public function readWorkingTreeContent(string $repositoryPath, string $filePath): string
    {
        $fullPath = rtrim($repositoryPath, '/').'/'.ltrim($filePath, '/');

        if (! is_file($fullPath) || ! is_readable($fullPath)) {
            throw new AgentFileUnreadableException(
                sprintf('The file "%s" could not be read.', $fullPath)
            );
        }

        $content = @file_get_contents($fullPath);

        if ($content === false) {
            throw new AgentFileUnreadableException(
                sprintf('The file "%s" could not be read.', $fullPath)
            );
        }

        return $content;
    }

    /**
     * Best-effort — returns null (never throws) when the process fails,
     * produces empty output, or the file has never been committed.
     */
    public function latestCommitFor(string $repositoryPath, string $filePath): ?GitCommitInfo
    {
        if (! is_dir($repositoryPath)) {
            return null;
        }

        $process = new Process(
            ['git', 'log', '-1', '--format=%H|%an|%at', '--', $filePath],
            $repositoryPath
        );

        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        $output = trim($process->getOutput());

        if ($output === '') {
            return null;
        }

        $parts = explode('|', $output, 3);

        if (count($parts) !== 3) {
            return null;
        }

        [$hash, $authorName, $timestamp] = $parts;

        return new GitCommitInfo(
            hash: $hash,
            authorName: $authorName,
            committedAt: new \DateTimeImmutable('@'.$timestamp),
        );
    }
}
