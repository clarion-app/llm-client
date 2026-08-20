<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Models\CodingProject;
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

    /**
     * A real, local `git init --bare` repository used as a `file://`-scheme
     * remote stand-in (tasks.md Repository map / Grounding note 7,
     * research.md's Testing section) -- no live network access anywhere in
     * this file.
     */
    private function createBareRemote(): string
    {
        $path = sys_get_temp_dir().'/git_op_inspector_bare_'.uniqid('', true);
        mkdir($path, 0777, true);
        $this->tempRepoPaths[] = $path;

        $this->runGit(['init', '--bare'], $path);

        return $path;
    }

    private function currentBranch(string $repoPath): string
    {
        return trim($this->runGit(['rev-parse', '--abbrev-ref', 'HEAD'], $repoPath)->getOutput());
    }

    /**
     * Pushes $repoPath's current HEAD to $barePath directly (never through
     * the method under test, which must never itself mutate anything) and
     * points the bare remote's own HEAD symref at that branch, so a later
     * `git rev-parse HEAD` against the bare repo resolves deterministically
     * regardless of git's configured default branch name.
     */
    private function pushToBareRemote(string $repoPath, string $barePath, string $branch): void
    {
        $this->runGit(['push', 'origin', "HEAD:refs/heads/{$branch}"], $repoPath);
        $this->runGit(['symbolic-ref', 'HEAD', "refs/heads/{$branch}"], $barePath);
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

    // ---------------------------------------------------------------
    // previewCommit() — Phase 4/US2, data-model.md §5, contracts/
    // git-commit.md. The out-of-workspace-path rejection lives inside
    // this method itself and returns the same plain {ok:false, code:...}
    // refusal shape as every other precondition here — never via
    // containmentFailureResponse()/WorkspaceRefusalRecorder, which this
    // stateless, constructor-dependency-free service cannot call anyway
    // (T016's resolved-contradiction note supersedes contracts/
    // git-commit.md's contrary prose on this one point).
    // ---------------------------------------------------------------

    #[Test]
    public function preview_commit_with_omitted_paths_resolves_every_currently_changed_or_untracked_path(): void
    {
        $repoPath = $this->createGitRepo();
        file_put_contents($repoPath.'/tracked.txt', "line one\n");
        $this->runGit(['add', 'tracked.txt'], $repoPath);
        $this->runGit(['commit', '-m', 'Initial commit'], $repoPath);

        // One modified, already-tracked file...
        file_put_contents($repoPath.'/tracked.txt', "line one\nline two\n");
        // ...and one brand-new, never-tracked file.
        file_put_contents($repoPath.'/untracked.txt', "new file\n");

        $result = $this->inspector()->previewCommit($repoPath, null);

        $this->assertTrue($result['ok'] ?? null, 'omitted paths against a dirty tree must succeed');
        $this->assertEqualsCanonicalizing(
            ['tracked.txt', 'untracked.txt'],
            $result['files'] ?? null,
            'omitted paths must resolve to every currently changed-or-untracked path, tracked and untracked alike'
        );
    }

    #[Test]
    public function preview_commit_with_an_explicit_paths_subset_scopes_the_result_to_exactly_those(): void
    {
        $repoPath = $this->createGitRepo();
        file_put_contents($repoPath.'/a.txt', "a\n");
        file_put_contents($repoPath.'/b.txt', "b\n");
        $this->runGit(['add', '.'], $repoPath);
        $this->runGit(['commit', '-m', 'Initial commit'], $repoPath);

        // Both files change, but only one is named explicitly.
        file_put_contents($repoPath.'/a.txt', "a changed\n");
        file_put_contents($repoPath.'/b.txt', "b changed\n");

        $result = $this->inspector()->previewCommit($repoPath, ['a.txt']);

        $this->assertTrue($result['ok'] ?? null);
        $this->assertSame(['a.txt'], $result['files'] ?? null, 'an explicit paths subset must scope the result to exactly those paths, never b.txt too');
    }

    #[Test]
    public function preview_commit_rejects_a_path_outside_the_working_tree(): void
    {
        $repoPath = $this->createGitRepo();
        file_put_contents($repoPath.'/inside.txt', "inside\n");
        $this->runGit(['add', 'inside.txt'], $repoPath);
        $this->runGit(['commit', '-m', 'Initial commit'], $repoPath);
        file_put_contents($repoPath.'/inside.txt', "inside changed\n");

        // An absolute path well outside the repo — unambiguous regardless
        // of whether the containment check is realpath- or string-based,
        // and requires no file to actually exist there.
        $outsidePath = sys_get_temp_dir().'/git_op_inspector_outside_'.uniqid('', true).'.txt';

        $result = $this->inspector()->previewCommit($repoPath, [$outsidePath]);

        $this->assertFalse($result['ok'] ?? true);
        $this->assertSame('git_path_outside_workspace', $result['code'] ?? null);
    }

    #[Test]
    public function preview_commit_on_a_clean_tree_reports_nothing_to_commit(): void
    {
        $repoPath = $this->createGitRepo();
        file_put_contents($repoPath.'/only.txt', "only\n");
        $this->runGit(['add', 'only.txt'], $repoPath);
        $this->runGit(['commit', '-m', 'Initial commit'], $repoPath);

        // No further edits — the tree is clean.
        $result = $this->inspector()->previewCommit($repoPath, null);

        $this->assertFalse($result['ok'] ?? true);
        $this->assertSame('git_nothing_to_commit', $result['code'] ?? null);
    }

    #[Test]
    public function preview_commit_against_a_non_git_directory_reports_not_a_repository(): void
    {
        $plainPath = $this->createPlainDirectory();

        $result = $this->inspector()->previewCommit($plainPath, null);

        $this->assertFalse($result['ok'] ?? true);
        $this->assertSame('git_not_a_repository', $result['code'] ?? null);
    }

    #[Test]
    public function preview_commit_every_refusal_code_is_mutually_distinct(): void
    {
        // A cheap structural pin: previewCommit()'s three refusal paths
        // must each carry their own distinct code, never a shared/generic
        // one a caller could not tell apart.
        $notARepo = $this->inspector()->previewCommit($this->createPlainDirectory(), null);

        $cleanRepo = $this->createGitRepo();
        file_put_contents($cleanRepo.'/only.txt', "only\n");
        $this->runGit(['add', 'only.txt'], $cleanRepo);
        $this->runGit(['commit', '-m', 'Initial commit'], $cleanRepo);
        $nothingToCommit = $this->inspector()->previewCommit($cleanRepo, null);

        $dirtyRepo = $this->createGitRepo();
        file_put_contents($dirtyRepo.'/inside.txt', "inside\n");
        $this->runGit(['add', 'inside.txt'], $dirtyRepo);
        $this->runGit(['commit', '-m', 'Initial commit'], $dirtyRepo);
        file_put_contents($dirtyRepo.'/inside.txt', "changed\n");
        $outsidePath = sys_get_temp_dir().'/git_op_inspector_outside_'.uniqid('', true).'.txt';
        $pathOutsideWorkspace = $this->inspector()->previewCommit($dirtyRepo, [$outsidePath]);

        $codes = [$notARepo['code'] ?? null, $nothingToCommit['code'] ?? null, $pathOutsideWorkspace['code'] ?? null];
        $this->assertSame(3, count(array_unique($codes)), 'every refusal path must carry its own distinct code');
    }

    #[Test]
    public function preview_commit_diff_stat_matches_real_git_diff_stat_output_for_a_known_fixture_change(): void
    {
        $repoPath = $this->createGitRepo();
        file_put_contents($repoPath.'/file.txt', "line one\nline two\nline three\n");
        $this->runGit(['add', 'file.txt'], $repoPath);
        $this->runGit(['commit', '-m', 'Initial commit'], $repoPath);

        file_put_contents($repoPath.'/file.txt', "line one\nline two CHANGED\nline three\nline four\n");

        // Deliberately a single, already-tracked modified file (no
        // untracked file in this fixture) so plain `git diff --stat`
        // itself is the unambiguous ground truth to compare against.
        $expectedDiffStat = trim($this->runGit(['diff', '--stat'], $repoPath)->getOutput());

        $result = $this->inspector()->previewCommit($repoPath, null);

        $this->assertTrue($result['ok'] ?? null);
        $this->assertSame($expectedDiffStat, $result['diff_stat'] ?? null, "diff_stat's format must match real `git diff --stat` output exactly");
    }

    // ---------------------------------------------------------------
    // previewPush() -- Phase 5/US3, data-model.md §5
    // ("previewPush(CodingProject $project, ?string $remote, ?string
    // $branch): array"), contracts/git-publish.md. Every case below uses a
    // real local `git init --bare` repository as a `file://`-scheme remote
    // stand-in -- never a mocked git invocation, never a live network
    // remote of any kind.
    // ---------------------------------------------------------------

    #[Test]
    public function preview_push_with_network_disabled_is_refused_before_the_repositorys_remotes_are_even_inspected(): void
    {
        $repoPath = $this->createGitRepo();
        file_put_contents($repoPath.'/file.txt', "one\n");
        $this->runGit(['add', 'file.txt'], $repoPath);
        $this->runGit(['commit', '-m', 'Initial commit'], $repoPath);

        // Deliberately NO remote configured at all (research.md D5): if the
        // disabled check were accidentally ordered after remote
        // inspection, this would instead surface as
        // git_no_remote_configured, which the assertion below would catch.
        $project = new CodingProject(['root_path' => $repoPath, 'network_enabled' => false]);

        $result = (new GitOperationInspector())->previewPush($project, null, null);

        $this->assertFalse($result['ok'] ?? true);
        $this->assertSame('git_publish_disabled', $result['code'] ?? null);
    }

    #[Test]
    public function preview_push_with_network_enabled_and_no_remote_configured_is_a_distinct_refusal_from_the_disabled_case(): void
    {
        $repoPath = $this->createGitRepo();
        file_put_contents($repoPath.'/file.txt', "one\n");
        $this->runGit(['add', 'file.txt'], $repoPath);
        $this->runGit(['commit', '-m', 'Initial commit'], $repoPath);

        $project = new CodingProject(['root_path' => $repoPath, 'network_enabled' => true]);

        $result = (new GitOperationInspector())->previewPush($project, null, null);

        $this->assertFalse($result['ok'] ?? true);
        $this->assertSame('git_no_remote_configured', $result['code'] ?? null);
        $this->assertNotSame(
            'git_publish_disabled',
            $result['code'] ?? null,
            'FR-012: "no remote configured" and "publishing disabled" must never be conflated into one code'
        );
    }

    #[Test]
    public function preview_push_against_a_non_git_directory_reports_not_a_repository(): void
    {
        // 126-git-operations-confirmation, Polish (T047 reconciliation
        // finding): previewCommit()/previewCreateBranch()/
        // previewRewriteHistory() each already had a dedicated
        // not-a-repository unit test above -- previewPush() did not, so
        // quickstart's own mutation checklist row 1 ("remove the
        // is_git_repo pre-check from gitCommit/gitBranch/
        // gitRewriteHistory/gitPush") had no automated case actually
        // exercising the gitPush quarter of that claim. previewPush()'s
        // own not-a-repository check runs before network_enabled is even
        // read (matching every other precondition here), so a plain
        // (non-git) directory refuses identically regardless of the
        // project's network policy.
        $plainPath = $this->createPlainDirectory();
        $project = new CodingProject(['root_path' => $plainPath, 'network_enabled' => true]);

        $result = (new GitOperationInspector())->previewPush($project, null, null);

        $this->assertFalse($result['ok'] ?? true);
        $this->assertSame('git_not_a_repository', $result['code'] ?? null);
    }

    #[Test]
    public function preview_push_happy_path_reports_a_sanitized_remote_url_and_creates_remote_branch_true_when_the_remote_branch_does_not_yet_exist(): void
    {
        $repoPath = $this->createGitRepo();
        file_put_contents($repoPath.'/file.txt', "one\n");
        $this->runGit(['add', 'file.txt'], $repoPath);
        $this->runGit(['commit', '-m', 'Initial commit'], $repoPath);
        $firstHash = trim($this->runGit(['rev-parse', 'HEAD'], $repoPath)->getOutput());

        file_put_contents($repoPath.'/file.txt', "one\ntwo\n");
        $this->runGit(['add', 'file.txt'], $repoPath);
        $this->runGit(['commit', '-m', 'Second commit'], $repoPath);
        $secondHash = trim($this->runGit(['rev-parse', 'HEAD'], $repoPath)->getOutput());

        $barePath = $this->createBareRemote();
        $remoteUrl = 'file://'.$barePath;
        $this->runGit(['remote', 'add', 'origin', $remoteUrl], $repoPath);
        $branch = $this->currentBranch($repoPath);

        $project = new CodingProject(['root_path' => $repoPath, 'network_enabled' => true]);

        $result = (new GitOperationInspector())->previewPush($project, 'origin', $branch);

        $this->assertTrue($result['ok'] ?? null);
        $this->assertSame(
            $remoteUrl,
            $result['remote_url_sanitized'] ?? null,
            'remote_url_sanitized must correctly derive from the configured remote (via sanitizeRemoteUrl())'
        );
        $this->assertEqualsCanonicalizing(
            [$firstHash, $secondHash],
            array_column($result['commits_ahead'] ?? [], 'hash'),
            'commits_ahead must list exactly the local-only commits -- the remote has none of them yet'
        );
        $this->assertTrue(
            $result['creates_remote_branch'] ?? null,
            'no branch has ever been pushed to this empty bare remote -- this push would create it'
        );
    }

    #[Test]
    public function preview_push_reports_creates_remote_branch_false_once_the_remote_branch_already_exists(): void
    {
        $repoPath = $this->createGitRepo();
        file_put_contents($repoPath.'/file.txt', "one\n");
        $this->runGit(['add', 'file.txt'], $repoPath);
        $this->runGit(['commit', '-m', 'Initial commit'], $repoPath);

        $barePath = $this->createBareRemote();
        $remoteUrl = 'file://'.$barePath;
        $this->runGit(['remote', 'add', 'origin', $remoteUrl], $repoPath);
        $branch = $this->currentBranch($repoPath);

        // The branch is pushed directly (never through the method under
        // test) so it already exists on the remote by the time
        // previewPush() is called.
        $this->pushToBareRemote($repoPath, $barePath, $branch);

        file_put_contents($repoPath.'/file.txt', "one\ntwo\n");
        $this->runGit(['add', 'file.txt'], $repoPath);
        $this->runGit(['commit', '-m', 'Second commit'], $repoPath);
        $secondHash = trim($this->runGit(['rev-parse', 'HEAD'], $repoPath)->getOutput());

        $project = new CodingProject(['root_path' => $repoPath, 'network_enabled' => true]);

        $result = (new GitOperationInspector())->previewPush($project, 'origin', $branch);

        $this->assertTrue($result['ok'] ?? null);
        $this->assertFalse(
            $result['creates_remote_branch'] ?? null,
            'the branch already exists on the remote -- this push updates it, it does not create it'
        );
        $this->assertSame(
            [$secondHash],
            array_column($result['commits_ahead'] ?? [], 'hash'),
            'commits_ahead must now list only the single commit made after the remote already had the branch'
        );
    }

    // ---------------------------------------------------------------
    // previewCreateBranch() -- Phase 6/US4, data-model.md §5, contracts/
    // git-branch.md. A successful result carries `branch_name` and
    // `start_point_resolved: {hash, short_hash, subject}` -- the exact
    // extra-field shape contracts/git-branch.md's confirmation marker
    // uses (Grounding note 17: §2/contracts are canonical over §5's own
    // `start_point_hash`/`start_point_subject` shorthand, which is drift
    // -- previewCommit/previewPush were already implemented against
    // §2/contracts' field names in Phases 4/5, not §5's table).
    // ---------------------------------------------------------------

    #[Test]
    public function preview_create_branch_with_omitted_start_point_resolves_to_the_repos_current_head(): void
    {
        $repoPath = $this->createGitRepo();
        file_put_contents($repoPath.'/file.txt', "one\n");
        $this->runGit(['add', 'file.txt'], $repoPath);
        $this->runGit(['commit', '-m', 'Initial commit'], $repoPath);
        $headHash = trim($this->runGit(['rev-parse', 'HEAD'], $repoPath)->getOutput());
        $headShortHash = trim($this->runGit(['rev-parse', '--short', 'HEAD'], $repoPath)->getOutput());
        $headSubject = trim($this->runGit(['log', '-1', '--format=%s'], $repoPath)->getOutput());

        $result = $this->inspector()->previewCreateBranch($repoPath, 'feature/x', null);

        $this->assertTrue($result['ok'] ?? null, 'omitting start_point against a valid repo must succeed');
        $this->assertSame('feature/x', $result['branch_name'] ?? null);
        $this->assertSame(
            $headHash,
            $result['start_point_resolved']['hash'] ?? null,
            'an omitted start_point must resolve to the repo\'s current HEAD, not some other ref'
        );
        $this->assertSame($headShortHash, $result['start_point_resolved']['short_hash'] ?? null);
        $this->assertSame($headSubject, $result['start_point_resolved']['subject'] ?? null);
    }

    #[Test]
    public function preview_create_branch_with_an_explicit_start_point_resolves_that_specific_commit_not_head(): void
    {
        $repoPath = $this->createGitRepo();
        file_put_contents($repoPath.'/file.txt', "one\n");
        $this->runGit(['add', 'file.txt'], $repoPath);
        $this->runGit(['commit', '-m', 'First commit'], $repoPath);
        $firstHash = trim($this->runGit(['rev-parse', 'HEAD'], $repoPath)->getOutput());
        $firstShortHash = trim($this->runGit(['rev-parse', '--short', 'HEAD'], $repoPath)->getOutput());

        file_put_contents($repoPath.'/file.txt', "one\ntwo\n");
        $this->runGit(['add', 'file.txt'], $repoPath);
        $this->runGit(['commit', '-m', 'Second commit'], $repoPath);
        $secondHash = trim($this->runGit(['rev-parse', 'HEAD'], $repoPath)->getOutput());

        $result = $this->inspector()->previewCreateBranch($repoPath, 'feature/y', $firstHash);

        $this->assertTrue($result['ok'] ?? null);
        $this->assertSame(
            $firstHash,
            $result['start_point_resolved']['hash'] ?? null,
            'an explicit start_point must resolve to that specific commit, never HEAD (the second commit)'
        );
        $this->assertNotSame($secondHash, $result['start_point_resolved']['hash'] ?? null);
        $this->assertSame($firstShortHash, $result['start_point_resolved']['short_hash'] ?? null);
        $this->assertSame('First commit', $result['start_point_resolved']['subject'] ?? null);
    }

    #[Test]
    public function preview_create_branch_with_an_unresolvable_start_point_reports_an_invalid_reference(): void
    {
        $repoPath = $this->createGitRepo();
        file_put_contents($repoPath.'/file.txt', "one\n");
        $this->runGit(['add', 'file.txt'], $repoPath);
        $this->runGit(['commit', '-m', 'Initial commit'], $repoPath);

        $result = $this->inspector()->previewCreateBranch($repoPath, 'feature/z', 'no-such-ref-at-all');

        $this->assertFalse($result['ok'] ?? true);
        $this->assertSame('git_invalid_reference', $result['code'] ?? null);
    }

    #[Test]
    public function preview_create_branch_with_a_name_that_already_exists_reports_branch_already_exists(): void
    {
        $repoPath = $this->createGitRepo();
        file_put_contents($repoPath.'/file.txt', "one\n");
        $this->runGit(['add', 'file.txt'], $repoPath);
        $this->runGit(['commit', '-m', 'Initial commit'], $repoPath);
        $this->runGit(['branch', 'already-exists'], $repoPath);

        $result = $this->inspector()->previewCreateBranch($repoPath, 'already-exists', null);

        $this->assertFalse($result['ok'] ?? true);
        $this->assertSame('git_branch_already_exists', $result['code'] ?? null);
    }

    #[Test]
    public function preview_create_branch_against_a_non_git_directory_reports_not_a_repository(): void
    {
        $plainPath = $this->createPlainDirectory();

        $result = $this->inspector()->previewCreateBranch($plainPath, 'feature/x', null);

        $this->assertFalse($result['ok'] ?? true);
        $this->assertSame('git_not_a_repository', $result['code'] ?? null);
    }

    // ---------------------------------------------------------------
    // previewRewriteHistory() -- Phase 7/US5, data-model.md §5, research.md
    // D7, contracts/git-rewrite-history.md. A successful result carries
    // `commits_removed_from_branch: [{hash, short_hash, subject,
    // published}]`, `uncommitted_changes_would_be_discarded`, and
    // `discarded_paths` -- the exact field names contracts/git-rewrite-
    // history.md and data-model.md §2 use (Grounding note 17: canonical
    // over §5's own `target_hash`/`commits_removed`/`discards_uncommitted`
    // shorthand, which is drift).
    //
    // Two independently-computed warnings (D7): (1) commits removed from
    // the branch is computed identically for all three modes, since reset
    // always moves the branch pointer regardless of what happens to the
    // working tree; (2) uncommitted work discarded is computed ONLY for
    // reset_hard against a genuinely dirty tree beforehand -- reset_soft/
    // reset_mixed never touch working-tree content, and a clean-tree
    // reset_hard has nothing to discard either.
    // ---------------------------------------------------------------

    /**
     * Builds a repo with a base commit plus two further commits ahead of
     * it (so `target: "HEAD~2"` resolves to the base), then an uncommitted
     * edit to the same already-tracked file -- the shared fixture every
     * case below that needs "two commits ahead of a base, plus a dirty
     * tree" starts from.
     *
     * @return array{repoPath: string, baseHash: string, secondHash: string, thirdHash: string}
     */
    private function createRepoWithTwoCommitsAheadAndADirtyTree(): array
    {
        $repoPath = $this->createGitRepo();

        file_put_contents($repoPath.'/file.txt', "one\n");
        $this->runGit(['add', 'file.txt'], $repoPath);
        $this->runGit(['commit', '-m', 'Base commit'], $repoPath);
        $baseHash = trim($this->runGit(['rev-parse', 'HEAD'], $repoPath)->getOutput());

        file_put_contents($repoPath.'/file.txt', "one\ntwo\n");
        $this->runGit(['add', 'file.txt'], $repoPath);
        $this->runGit(['commit', '-m', 'Second commit'], $repoPath);
        $secondHash = trim($this->runGit(['rev-parse', 'HEAD'], $repoPath)->getOutput());

        file_put_contents($repoPath.'/file.txt', "one\ntwo\nthree\n");
        $this->runGit(['add', 'file.txt'], $repoPath);
        $this->runGit(['commit', '-m', 'Third commit'], $repoPath);
        $thirdHash = trim($this->runGit(['rev-parse', 'HEAD'], $repoPath)->getOutput());

        // An uncommitted edit to the same already-tracked file -- the
        // dirty-tree state every reset_hard-discard case needs.
        file_put_contents($repoPath.'/file.txt', "one\ntwo\nthree\nuncommitted\n");

        return compact('repoPath', 'baseHash', 'secondHash', 'thirdHash');
    }

    #[Test]
    public function commits_removed_from_branch_is_identical_across_all_three_modes_against_the_same_target(): void
    {
        ['repoPath' => $repoPath, 'secondHash' => $secondHash, 'thirdHash' => $thirdHash]
            = $this->createRepoWithTwoCommitsAheadAndADirtyTree();

        $soft = $this->inspector()->previewRewriteHistory($repoPath, 'reset_soft', 'HEAD~2');
        $mixed = $this->inspector()->previewRewriteHistory($repoPath, 'reset_mixed', 'HEAD~2');
        $hard = $this->inspector()->previewRewriteHistory($repoPath, 'reset_hard', 'HEAD~2');

        $this->assertTrue($soft['ok'] ?? null);
        $this->assertTrue($mixed['ok'] ?? null);
        $this->assertTrue($hard['ok'] ?? null);

        $expected = [$secondHash, $thirdHash];
        $softHashes = array_column($soft['commits_removed_from_branch'] ?? [], 'hash');
        $mixedHashes = array_column($mixed['commits_removed_from_branch'] ?? [], 'hash');
        $hardHashes = array_column($hard['commits_removed_from_branch'] ?? [], 'hash');

        $this->assertEqualsCanonicalizing($expected, $softHashes, 'reset_soft must report the same removed commits as every other mode');
        $this->assertEqualsCanonicalizing($expected, $mixedHashes, 'reset_mixed must report the same removed commits as every other mode');
        $this->assertEqualsCanonicalizing($expected, $hardHashes, 'reset_hard must report the same removed commits as every other mode');
    }

    #[Test]
    public function uncommitted_changes_would_be_discarded_is_populated_only_for_reset_hard_against_a_dirty_tree(): void
    {
        ['repoPath' => $repoPath] = $this->createRepoWithTwoCommitsAheadAndADirtyTree();

        $soft = $this->inspector()->previewRewriteHistory($repoPath, 'reset_soft', 'HEAD~2');
        $mixed = $this->inspector()->previewRewriteHistory($repoPath, 'reset_mixed', 'HEAD~2');
        $hard = $this->inspector()->previewRewriteHistory($repoPath, 'reset_hard', 'HEAD~2');

        $this->assertFalse($soft['uncommitted_changes_would_be_discarded'] ?? null, 'reset_soft never touches working-tree content');
        $this->assertSame([], $soft['discarded_paths'] ?? null);

        $this->assertFalse($mixed['uncommitted_changes_would_be_discarded'] ?? null, 'reset_mixed never touches working-tree content');
        $this->assertSame([], $mixed['discarded_paths'] ?? null);

        $this->assertTrue($hard['uncommitted_changes_would_be_discarded'] ?? null, 'reset_hard against a genuinely dirty tree must warn of discard');
        $this->assertSame(['file.txt'], $hard['discarded_paths'] ?? null);
    }

    #[Test]
    public function a_clean_tree_reset_hard_reports_no_discard_at_all(): void
    {
        $repoPath = $this->createGitRepo();
        file_put_contents($repoPath.'/file.txt', "one\n");
        $this->runGit(['add', 'file.txt'], $repoPath);
        $this->runGit(['commit', '-m', 'Base commit'], $repoPath);
        file_put_contents($repoPath.'/file.txt', "one\ntwo\n");
        $this->runGit(['add', 'file.txt'], $repoPath);
        $this->runGit(['commit', '-m', 'Second commit'], $repoPath);
        // No further edits -- the tree is clean.

        $result = $this->inspector()->previewRewriteHistory($repoPath, 'reset_hard', 'HEAD~1');

        $this->assertTrue($result['ok'] ?? null);
        $this->assertFalse($result['uncommitted_changes_would_be_discarded'] ?? null, 'a clean tree has nothing for reset_hard to discard, even in reset_hard mode');
        $this->assertSame([], $result['discarded_paths'] ?? null);
    }

    #[Test]
    public function a_removed_commit_already_pushed_is_flagged_published_distinctly_from_a_never_pushed_one(): void
    {
        $repoPath = $this->createGitRepo();
        file_put_contents($repoPath.'/file.txt', "one\n");
        $this->runGit(['add', 'file.txt'], $repoPath);
        $this->runGit(['commit', '-m', 'Base commit'], $repoPath);
        $baseHash = trim($this->runGit(['rev-parse', 'HEAD'], $repoPath)->getOutput());

        $barePath = $this->createBareRemote();
        $this->runGit(['remote', 'add', 'origin', 'file://'.$barePath], $repoPath);

        file_put_contents($repoPath.'/file.txt', "one\ntwo\n");
        $this->runGit(['add', 'file.txt'], $repoPath);
        $this->runGit(['commit', '-m', 'Published commit'], $repoPath);
        $publishedHash = trim($this->runGit(['rev-parse', 'HEAD'], $repoPath)->getOutput());

        $branch = $this->currentBranch($repoPath);
        // Pushes the published commit to the bare remote directly -- this
        // also updates the local repo's own remote-tracking ref
        // (refs/remotes/origin/<branch>), which `published` is computed
        // against.
        $this->pushToBareRemote($repoPath, $barePath, $branch);

        file_put_contents($repoPath.'/file.txt', "one\ntwo\nthree\n");
        $this->runGit(['add', 'file.txt'], $repoPath);
        $this->runGit(['commit', '-m', 'Unpublished commit'], $repoPath);
        $unpublishedHash = trim($this->runGit(['rev-parse', 'HEAD'], $repoPath)->getOutput());

        $result = $this->inspector()->previewRewriteHistory($repoPath, 'reset_soft', $baseHash);

        $this->assertTrue($result['ok'] ?? null);
        $byHash = [];
        foreach ($result['commits_removed_from_branch'] ?? [] as $entry) {
            $byHash[$entry['hash']] = $entry;
        }

        $this->assertArrayHasKey($publishedHash, $byHash);
        $this->assertArrayHasKey($unpublishedHash, $byHash);
        $this->assertTrue($byHash[$publishedHash]['published'] ?? null, 'a removed commit already on the remote-tracking ref must be flagged published');
        $this->assertFalse($byHash[$unpublishedHash]['published'] ?? null, 'a removed commit never pushed anywhere must be flagged unpublished, in the same result set');
    }

    #[Test]
    public function preview_rewrite_history_with_an_unresolvable_target_reports_an_invalid_reference(): void
    {
        $repoPath = $this->createGitRepo();
        file_put_contents($repoPath.'/file.txt', "one\n");
        $this->runGit(['add', 'file.txt'], $repoPath);
        $this->runGit(['commit', '-m', 'Initial commit'], $repoPath);

        $result = $this->inspector()->previewRewriteHistory($repoPath, 'reset_hard', 'no-such-ref-at-all');

        $this->assertFalse($result['ok'] ?? true);
        $this->assertSame('git_invalid_reference', $result['code'] ?? null);
    }

    #[Test]
    public function preview_rewrite_history_against_a_non_git_directory_reports_not_a_repository(): void
    {
        $plainPath = $this->createPlainDirectory();

        $result = $this->inspector()->previewRewriteHistory($plainPath, 'reset_hard', 'HEAD');

        $this->assertFalse($result['ok'] ?? true);
        $this->assertSame('git_not_a_repository', $result['code'] ?? null);
    }
}
