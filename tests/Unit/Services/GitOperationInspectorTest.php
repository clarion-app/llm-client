<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Services\GitOperationInspector;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Unit tests for GitOperationInspector (Phase 2/Foundational, data-model.md
 * §5) — against a real, throwaway git repository created under this test's
 * own tmp directory, mirroring GitDefinitionFileReaderTest's own established
 * fixture convention exactly: `git init`, a deterministic local
 * `user.name`/`user.email`, `commit.gpgsign false`, never a mocked git
 * invocation and never a fixture pointed at this monorepo itself.
 *
 * This file covers only the two universally-shared methods added in this
 * phase: isGitRepository() and sanitizeRemoteUrl(). The four preview*()
 * methods are added incrementally by later phases, each driven by its own
 * red test extending this same file.
 */
class GitOperationInspectorTest extends TestCase
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
        $repoPath = sys_get_temp_dir().'/git_op_inspector_test_'.uniqid('', true);
        mkdir($repoPath, 0777, true);
        $this->tempRepoPaths[] = $repoPath;

        $this->runGit(['init'], $repoPath);
        $this->runGit(['config', 'user.name', $authorName], $repoPath);
        $this->runGit(['config', 'user.email', $authorEmail], $repoPath);
        $this->runGit(['config', 'commit.gpgsign', 'false'], $repoPath);

        return $repoPath;
    }

    private function createPlainDirectory(): string
    {
        $path = sys_get_temp_dir().'/git_op_inspector_plain_'.uniqid('', true);
        mkdir($path, 0777, true);
        $this->tempRepoPaths[] = $path;

        return $path;
    }

    private function runGit(array $args, string $cwd): Process
    {
        $process = new Process(array_merge(['git'], $args), $cwd);
        $process->mustRun();

        return $process;
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

    private function inspector(): GitOperationInspector
    {
        return new GitOperationInspector();
    }

    // ---------------------------------------------------------------
    // isGitRepository()
    // ---------------------------------------------------------------

    #[Test]
    public function is_git_repository_is_true_for_a_real_git_init_d_directory(): void
    {
        $repoPath = $this->createGitRepo();

        $this->assertTrue($this->inspector()->isGitRepository($repoPath));
    }

    #[Test]
    public function is_git_repository_is_false_for_a_plain_non_git_directory(): void
    {
        $plainPath = $this->createPlainDirectory();

        $this->assertFalse($this->inspector()->isGitRepository($plainPath));
    }

    #[Test]
    public function is_git_repository_is_false_for_a_path_that_does_not_exist_at_all(): void
    {
        $bogusPath = sys_get_temp_dir().'/git_op_inspector_nonexistent_'.uniqid('', true);

        $this->assertFalse($this->inspector()->isGitRepository($bogusPath));
    }

    // ---------------------------------------------------------------
    // sanitizeRemoteUrl() — research.md D8: strip userinfo between
    // `://` and the last `@` before the host; the SCP-like SSH
    // shorthand (no `://`) is untouched because that scope doesn't apply.
    // ---------------------------------------------------------------

    #[Test]
    public function sanitize_remote_url_strips_userinfo_from_an_https_url(): void
    {
        $sanitized = $this->inspector()->sanitizeRemoteUrl('https://user:token@github.com/example/repo.git');

        $this->assertSame('https://github.com/example/repo.git', $sanitized);
    }

    #[Test]
    public function sanitize_remote_url_strips_userinfo_from_an_ssh_scheme_url(): void
    {
        $sanitized = $this->inspector()->sanitizeRemoteUrl('ssh://user:pass@host/path');

        $this->assertSame('ssh://host/path', $sanitized);
    }

    #[Test]
    public function sanitize_remote_url_returns_a_url_with_no_userinfo_byte_identical(): void
    {
        $url = 'https://github.com/example/repo.git';

        $this->assertSame($url, $this->inspector()->sanitizeRemoteUrl($url));
    }

    #[Test]
    public function sanitize_remote_url_leaves_the_scp_like_ssh_shorthand_byte_identical_untouched(): void
    {
        // No `://` scheme at all — the leading `git@` here is the literal
        // SSH protocol user, not a credential to strip. research.md D8's
        // own scope ("everything between `://` and the last `@` before the
        // host") simply doesn't apply to this form.
        $url = 'git@github.com:example/repo.git';

        $this->assertSame($url, $this->inspector()->sanitizeRemoteUrl($url));
    }
}
