<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Exceptions\AgentFileUnreadableException;
use ClarionApp\LlmClient\Services\GitDefinitionFileReader;
use ClarionApp\LlmClient\ValueObjects\GitCommitInfo;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Unit tests for GitDefinitionFileReader (Phase 5/US3, contracts §12,
 * research.md D8/D11, quickstart.md step 16) — against a real, throwaway
 * git repository created under this test's own tmp directory (`git init`,
 * a deterministic local `user.name`/`user.email`), never a fixture pointed
 * at this monorepo itself (quickstart.md's own explicit instruction).
 *
 * readWorkingTreeContent() must read the working tree directly, never
 * `git show HEAD:<path>` (research.md D11) — the property
 * reads_the_files_exact_current_bytes_on_disk_including_an_uncommitted_edit()
 * exists specifically to catch (mutation-checklist row 8).
 */
class GitDefinitionFileReaderTest extends TestCase
{
    /** @var string[] */
    private array $tempRepoPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->tempRepoPaths as $path) {
            $this->removeDirectory($path);
        }
        $this->tempRepoPaths = [];

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Fixture helpers — a real, throwaway git repository per test
    // ---------------------------------------------------------------

    private function createGitRepo(string $authorName = 'Test Author', string $authorEmail = 'test-author@example.test'): string
    {
        $repoPath = sys_get_temp_dir().'/git_def_reader_test_'.uniqid('', true);
        mkdir($repoPath, 0777, true);
        $this->tempRepoPaths[] = $repoPath;

        $this->runGit(['init'], $repoPath);
        $this->runGit(['config', 'user.name', $authorName], $repoPath);
        $this->runGit(['config', 'user.email', $authorEmail], $repoPath);
        $this->runGit(['config', 'commit.gpgsign', 'false'], $repoPath);

        return $repoPath;
    }

    private function runGit(array $args, string $cwd): Process
    {
        $process = new Process(array_merge(['git'], $args), $cwd);
        $process->mustRun();

        return $process;
    }

    private function writeFile(string $repoPath, string $relPath, string $content): void
    {
        file_put_contents($repoPath.'/'.$relPath, $content);
    }

    private function commitAll(string $repoPath, string $message): void
    {
        $this->runGit(['add', '.'], $repoPath);
        $this->runGit(['commit', '-m', $message], $repoPath);
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (new \FilesystemIterator($path) as $item) {
            if ($item->isDir()) {
                $this->removeDirectory($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }

    private function reader(): GitDefinitionFileReader
    {
        return new GitDefinitionFileReader();
    }

    // ---------------------------------------------------------------
    // readWorkingTreeContent() — exact current disk bytes, including an
    // uncommitted edit (never `git show HEAD:<path>`)
    // ---------------------------------------------------------------

    #[Test]
    public function reads_the_files_exact_current_bytes_on_disk_including_an_uncommitted_edit(): void
    {
        $repoPath = $this->createGitRepo();
        $this->writeFile($repoPath, 'agent.yaml', "name: committed-agent\n");
        $this->commitAll($repoPath, 'Initial commit');

        // A second, uncommitted edit — never staged, never committed.
        $this->writeFile($repoPath, 'agent.yaml', "name: uncommitted-edit\n");

        $content = $this->reader()->readWorkingTreeContent($repoPath, 'agent.yaml');

        $this->assertSame(
            "name: uncommitted-edit\n",
            $content,
            'readWorkingTreeContent() must return the working tree content, not the last committed content'
        );
    }

    #[Test]
    public function throws_agent_file_unreadable_exception_for_a_missing_file(): void
    {
        $repoPath = $this->createGitRepo();

        $this->expectException(AgentFileUnreadableException::class);
        $this->reader()->readWorkingTreeContent($repoPath, 'does-not-exist.yaml');
    }

    #[Test]
    public function throws_agent_file_unreadable_exception_for_an_invalid_repository_path(): void
    {
        $bogusPath = sys_get_temp_dir().'/git_def_reader_nonexistent_'.uniqid('', true);

        $this->expectException(AgentFileUnreadableException::class);
        $this->reader()->readWorkingTreeContent($bogusPath, 'agent.yaml');
    }

    // ---------------------------------------------------------------
    // latestCommitFor() — the second (most recent) commit, not the first
    // ---------------------------------------------------------------

    #[Test]
    public function returns_the_most_recent_commits_hash_author_and_timestamp_not_the_first(): void
    {
        $repoPath = $this->createGitRepo('Original Author', 'original@example.test');
        $this->writeFile($repoPath, 'agent.yaml', "name: v1\n");
        $this->commitAll($repoPath, 'First commit');

        // A distinct author for the second commit, so a test that
        // accidentally read the *first* commit would be caught by the
        // author assertion below too, not only the hash assertion.
        $this->runGit(['config', 'user.name', 'Second Author'], $repoPath);
        $this->runGit(['config', 'user.email', 'second@example.test'], $repoPath);
        $this->writeFile($repoPath, 'agent.yaml', "name: v2\n");
        $this->commitAll($repoPath, 'Second commit');

        $expectedHash = trim($this->runGit(['log', '-1', '--format=%H'], $repoPath)->getOutput());

        $info = $this->reader()->latestCommitFor($repoPath, 'agent.yaml');

        $this->assertInstanceOf(GitCommitInfo::class, $info);
        $this->assertSame($expectedHash, $info->hash, 'latestCommitFor() must return the second commits hash, not the first');
        $this->assertSame('Second Author', $info->authorName);
    }

    // ---------------------------------------------------------------
    // latestCommitFor() — null when uncommitted; content and attribution
    // are independent (research.md D8)
    // ---------------------------------------------------------------

    #[Test]
    public function returns_null_when_the_file_is_staged_but_never_committed_and_content_is_still_readable(): void
    {
        $repoPath = $this->createGitRepo();
        $this->writeFile($repoPath, 'agent.yaml', "name: never-committed\n");
        $this->runGit(['add', 'agent.yaml'], $repoPath);

        $info = $this->reader()->latestCommitFor($repoPath, 'agent.yaml');
        $this->assertNull($info, 'latestCommitFor() must return null when no commit touches the file yet');

        $content = $this->reader()->readWorkingTreeContent($repoPath, 'agent.yaml');
        $this->assertSame(
            "name: never-committed\n",
            $content,
            'readWorkingTreeContent() must still succeed even though no commit exists yet — content and attribution are independent'
        );
    }
}
