<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use Auth;
use ClarionApp\LlmClient\Models\CodingProject;
use ClarionApp\LlmClient\Services\PathContainment;
use ClarionApp\LlmClient\Services\WorkspaceFilePolicy;
use ClarionApp\LlmClient\Services\WorkspaceSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * 112-coding-agent (contracts/coding-workspace-operations.md §1-§3, US1,
 * tasks.md T024-T027). Every route below carries `{project}` as a path
 * parameter, but the value that actually governs whether an agent-driven
 * call reaches this controller at all is `$conversation->coding_project_id`,
 * enforced at the AgentLoopService seam (data-model.md §4) before any of
 * these methods ever run. This controller independently re-checks
 * `CodingProject.user_id === Auth::id()` on every method as a second,
 * standalone layer (contracts §1, research.md D2) — belt-and-suspenders,
 * neither check trusts the other.
 *
 * Every mutation (writeFile/deleteFile) reaches this controller only once
 * already confirmed — `coding.yaml`'s `safety.confirmation_required`
 * (T014) and the existing, unmodified pause/resume mechanism gate it
 * upstream. No confirmation logic lives here.
 *
 * ToolResultCondenser is applied generically to every tool result at the
 * AgentLoopService/AgentLoopStreamHandler layer (condenseToolResult()) —
 * these methods return plain, uncondensed JSON and never call the
 * condenser themselves.
 */
class CodingWorkspaceController extends Controller
{
    public function __construct(
        private readonly WorkspaceSearchService $workspaceSearchService = new WorkspaceSearchService(),
        private readonly WorkspaceFilePolicy $filePolicy = new WorkspaceFilePolicy(),
    ) {
    }

    /**
     * GET coding-project/{project}/files (contracts §1). Lists the
     * immediate entries (name, type file/dir) under the resolved,
     * contained subpath (project root when omitted).
     */
    public function listFiles(Request $request, string $project): JsonResponse
    {
        $codingProject = $this->findOwnedProject($project);
        if ($codingProject === null) {
            return $this->notFoundResponse('Coding project not found', 'coding_project_not_found');
        }

        $subpath = (string) $request->query('subpath', '');

        $validation = PathContainment::validate($codingProject->root_path, $subpath, true);
        if (!$validation['valid']) {
            return $this->containmentFailureResponse($validation['reason'] ?? 'invalid path');
        }

        $resolvedPath = $validation['resolved_path'];

        if (!is_dir($resolvedPath)) {
            return response()->json(['error' => 'not a directory'], 422);
        }

        $entries = [];
        foreach (scandir($resolvedPath) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $entries[] = [
                'name' => $entry,
                'type' => is_dir($resolvedPath.'/'.$entry) ? 'dir' : 'file',
            ];
        }

        return response()->json(['entries' => $entries], 200);
    }

    /**
     * GET coding-project/{project}/search-files (120-workspace-file-tools
     * contracts §1). Query `pattern` (required, fnmatch()-shaped glob),
     * `subpath` (optional, same containment handling as listFiles()'s own
     * subpath). Delegates to WorkspaceSearchService — a bounded list of
     * workspace-relative paths matching the pattern by name only.
     */
    public function searchFiles(Request $request, string $project): JsonResponse
    {
        $codingProject = $this->findOwnedProject($project);
        if ($codingProject === null) {
            return $this->notFoundResponse('Coding project not found', 'coding_project_not_found');
        }

        $pattern = (string) $request->query('pattern', '');
        if ($pattern === '') {
            return response()->json(['error' => 'pattern is required'], 422);
        }

        $subpath = (string) $request->query('subpath', '');

        $result = $this->workspaceSearchService->searchFiles($codingProject, $subpath, $pattern);
        if (!$result['valid']) {
            return $this->containmentFailureResponse($result['reason'] ?? 'invalid path');
        }

        return response()->json([
            'matches' => $result['matches'],
            'truncated' => $result['truncated'],
            'files_scanned' => $result['files_scanned'],
        ], 200);
    }

    /**
     * GET coding-project/{project}/search-content (120-workspace-file-tools
     * contracts §2). Query `query` (required, plain case-insensitive
     * substring term), `pattern` (optional, scopes which files' content is
     * searched), `subpath` (optional). Delegates to WorkspaceSearchService
     * — bounded matches with contextual line/snippet.
     */
    public function searchContent(Request $request, string $project): JsonResponse
    {
        $codingProject = $this->findOwnedProject($project);
        if ($codingProject === null) {
            return $this->notFoundResponse('Coding project not found', 'coding_project_not_found');
        }

        $query = (string) $request->query('query', '');
        if ($query === '') {
            return response()->json(['error' => 'query is required'], 422);
        }

        $subpath = (string) $request->query('subpath', '');
        $pattern = $request->query('pattern');
        $pattern = $pattern !== null ? (string) $pattern : null;

        $result = $this->workspaceSearchService->searchContent($codingProject, $subpath, $query, $pattern);
        if (!$result['valid']) {
            return $this->containmentFailureResponse($result['reason'] ?? 'invalid path');
        }

        return response()->json([
            'matches' => $result['matches'],
            'truncated' => $result['truncated'],
            'files_scanned' => $result['files_scanned'],
            'skipped_binary_count' => $result['skipped_binary_count'],
        ], 200);
    }

    /**
     * GET coding-project/{project}/file (contracts §3, 120-workspace-file-tools
     * data-model.md §3, T019). Returns file content. A file over
     * WorkspaceFilePolicy's configured size threshold is never fully
     * loaded into memory — only its first threshold bytes are ever read —
     * and comes back marked truncated: true. Binary content (sniffed via
     * WorkspaceFilePolicy::isBinary(), the same policy US1's content
     * search already uses, so the two paths agree, FR-008) is reported
     * distinctly, with no content key at all, never embedded as if it
     * were prose, since raw binary bytes cannot safely round-trip through
     * a JSON string. binary: true and truncated: true are never both
     * present on the same result.
     */
    public function readFile(Request $request, string $project): JsonResponse
    {
        $codingProject = $this->findOwnedProject($project);
        if ($codingProject === null) {
            return $this->notFoundResponse('Coding project not found', 'coding_project_not_found');
        }

        $path = (string) $request->query('path', '');
        if ($path === '') {
            return response()->json(['error' => 'path is required'], 422);
        }

        $validation = PathContainment::validate($codingProject->root_path, $path, true);
        if (!$validation['valid']) {
            return $this->containmentFailureResponse($validation['reason'] ?? 'invalid path');
        }

        $resolvedPath = $validation['resolved_path'];
        $resolvedIdentity = $validation['resolved_identity'] ?? null;

        // Nothing else runs between an approved location and opening it --
        // everything from here on acts on the open handle, never on the
        // path string again.
        $this->beforeResolvedPathOpen($resolvedPath);

        $handle = @fopen($resolvedPath, 'rb');
        if ($handle === false) {
            return response()->json(['error' => 'not found'], 422);
        }

        $actual = @fstat($handle);
        if ($actual === false) {
            fclose($handle);

            return response()->json(['error' => 'not found'], 422);
        }

        if ($resolvedIdentity !== null && !$this->identityMatches($actual, $resolvedIdentity)) {
            fclose($handle);

            return $this->containmentFailureResponse('outside the registered project');
        }

        $threshold = (int) config('llm-client.coding_agent.file_size_threshold_bytes');
        $size = $actual['size'];

        if ($size > $threshold) {
            $sample = @fread($handle, $threshold);
            fclose($handle);

            if ($sample === false) {
                return response()->json(['error' => 'not found'], 422);
            }

            if ($this->filePolicy->isBinary($sample)) {
                return response()->json([
                    'path' => $path,
                    'binary' => true,
                    'truncated' => false,
                    'size' => $size,
                ], 200);
            }

            return response()->json([
                'path' => $path,
                'binary' => false,
                'truncated' => true,
                'size' => $size,
                'content' => $sample,
            ], 200);
        }

        rewind($handle);
        $content = @stream_get_contents($handle);
        fclose($handle);

        if ($content === false) {
            return response()->json(['error' => 'not found'], 422);
        }

        if ($this->filePolicy->isBinary($content)) {
            return response()->json([
                'path' => $path,
                'binary' => true,
                'truncated' => false,
                'size' => strlen($content),
            ], 200);
        }

        return response()->json([
            'path' => $path,
            'binary' => false,
            'truncated' => false,
            'content' => $content,
        ], 200);
    }

    /**
     * POST coding-project/{project}/file (contracts §2). Body
     * {path, content}. Creates the file if absent, overwrites if present.
     * Containment is checked against the target's PARENT directory since
     * the target may not yet exist.
     */
    public function writeFile(Request $request, string $project): JsonResponse
    {
        $codingProject = $this->findOwnedProject($project);
        if ($codingProject === null) {
            return $this->notFoundResponse('Coding project not found', 'coding_project_not_found');
        }

        $validated = $request->validate([
            'path' => 'required|string',
            'content' => 'required|string',
        ]);

        $validation = PathContainment::validate($codingProject->root_path, $validated['path'], false);
        if (!$validation['valid']) {
            return $this->containmentFailureResponse($validation['reason'] ?? 'invalid path');
        }

        $resolvedPath = $validation['resolved_path'];
        $resolvedIdentity = $validation['resolved_identity'] ?? null;

        $this->beforeResolvedPathOpen($resolvedPath);

        // Create-or-open, no truncate yet -- an overwrite's identity is
        // verified against the handle before a single byte is written.
        $handle = @fopen($resolvedPath, 'cb');
        if ($handle === false) {
            return response()->json(['error' => 'could not write file'], 422);
        }

        if ($resolvedIdentity !== null) {
            $actual = @fstat($handle);
            if ($actual === false || !$this->identityMatches($actual, $resolvedIdentity)) {
                fclose($handle);

                return $this->containmentFailureResponse('outside the registered project');
            }
        }

        ftruncate($handle, 0);
        $written = @fwrite($handle, $validated['content']);
        fclose($handle);

        if ($written === false) {
            return response()->json(['error' => 'could not write file'], 422);
        }

        return response()->json([
            'path' => $validated['path'],
            'written' => true,
            'bytes' => $written,
        ], 200);
    }

    /**
     * DELETE coding-project/{project}/file (contracts §2). Query `path`
     * (required, target must already exist).
     */
    public function deleteFile(Request $request, string $project): JsonResponse
    {
        $codingProject = $this->findOwnedProject($project);
        if ($codingProject === null) {
            return $this->notFoundResponse('Coding project not found', 'coding_project_not_found');
        }

        $path = (string) $request->query('path', '');
        if ($path === '') {
            return response()->json(['error' => 'path is required'], 422);
        }

        $validation = PathContainment::validate($codingProject->root_path, $path, true);
        if (!$validation['valid']) {
            return $this->containmentFailureResponse($validation['reason'] ?? 'invalid path');
        }

        $resolvedPath = $validation['resolved_path'];
        $resolvedIdentity = $validation['resolved_identity'] ?? null;

        $this->beforeResolvedPathOpen($resolvedPath);

        // Opened only to pin/verify identity via fstat() -- content is
        // never read. unlink() itself still has to act on the path, not
        // this handle -- PHP has no fd-based delete primitive -- so this
        // operation's guarantee is honestly narrower than readFile's/
        // writeFile's: it is bounded to "the wrong in-workspace entry
        // could be removed," never "outside content exposed," since
        // unlink() never dereferences a symlink to remove its target, only
        // the directory entry it was given.
        $handle = @fopen($resolvedPath, 'rb');
        if ($handle === false) {
            return response()->json(['error' => 'not found'], 422);
        }

        $actual = @fstat($handle);
        fclose($handle);

        if ($actual === false) {
            return response()->json(['error' => 'not found'], 422);
        }

        if ($resolvedIdentity !== null && !$this->identityMatches($actual, $resolvedIdentity)) {
            return $this->containmentFailureResponse('outside the registered project');
        }

        $deleted = @unlink($resolvedPath);
        if (!$deleted) {
            return response()->json(['error' => 'could not delete file'], 422);
        }

        return response()->json([
            'path' => $path,
            'deleted' => true,
        ], 200);
    }

    /**
     * POST coding-project/{project}/run-tests (contracts §3, D5,
     * data-model.md §5). Exit-code-derived honesty: `passed`/`exit_code`
     * are read from `Process::getExitCode()` — never parsed or inferred
     * from `stdout`/`stderr` text.
     */
    public function runTests(Request $request, string $project): JsonResponse
    {
        $codingProject = $this->findOwnedProject($project);
        if ($codingProject === null) {
            return $this->notFoundResponse('Coding project not found', 'coding_project_not_found');
        }

        if ($codingProject->test_command === null) {
            // The process is never started (data-model.md §5).
            return response()->json([
                'status' => 'no_tests_configured',
                'command' => null,
            ], 200);
        }

        $timeoutSeconds = (int) config('llm-client.coding_agent.test_timeout_seconds', 120);

        $process = Process::fromShellCommandline($codingProject->test_command, $codingProject->root_path);
        $process->setTimeout($timeoutSeconds > 0 ? $timeoutSeconds : null);

        $timedOut = false;

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            $timedOut = true;
        } catch (\Throwable $e) {
            // The process itself failed to start entirely (D5) — distinct
            // from a process that started and exited nonzero.
            return response()->json([
                'status' => 'could_not_run',
                'command' => $codingProject->test_command,
                'reason' => $e->getMessage(),
            ], 200);
        }

        return response()->json([
            'status' => 'completed',
            'command' => $codingProject->test_command,
            'exit_code' => $process->getExitCode(),
            'passed' => $process->getExitCode() === 0,
            'timed_out' => $timedOut,
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
        ], 200);
    }

    /**
     * GET coding-project/{project}/git-status (contracts §1, D6).
     * `{is_git_repo: false}` when `.git`/the `git` binary is unavailable —
     * never an error.
     */
    public function gitStatus(Request $request, string $project): JsonResponse
    {
        $codingProject = $this->findOwnedProject($project);
        if ($codingProject === null) {
            return $this->notFoundResponse('Coding project not found', 'coding_project_not_found');
        }

        $process = $this->runGitCommand($codingProject->root_path, ['git', 'status', '--porcelain=v1']);
        if ($process === null) {
            return response()->json(['is_git_repo' => false], 200);
        }

        return response()->json([
            'is_git_repo' => true,
            'porcelain' => $process->getOutput(),
        ], 200);
    }

    /**
     * GET coding-project/{project}/git-diff (contracts §1, D6). Same
     * shape/degradation as gitStatus().
     */
    public function gitDiff(Request $request, string $project): JsonResponse
    {
        $codingProject = $this->findOwnedProject($project);
        if ($codingProject === null) {
            return $this->notFoundResponse('Coding project not found', 'coding_project_not_found');
        }

        $process = $this->runGitCommand($codingProject->root_path, ['git', 'diff']);
        if ($process === null) {
            return response()->json(['is_git_repo' => false], 200);
        }

        return response()->json([
            'is_git_repo' => true,
            'diff' => $process->getOutput(),
        ], 200);
    }

    /**
     * Ownership-scoped project lookup shared by every method above — the
     * controller's own independent second layer (contracts §1), never
     * trusting the loop-level project-binding check alone.
     */
    private function findOwnedProject(string $id): ?CodingProject
    {
        return CodingProject::where('id', $id)
            ->where('user_id', Auth::id())
            ->first();
    }

    /**
     * A PathContainment::validate() failure — every reason it returns
     * ("path traversal", "outside the registered project", "not found",
     * "project directory is not reachable", "invalid file name") is
     * reported the same way: a 422 naming the reason, never a 500 or a
     * silently-allowed operation.
     */
    private function containmentFailureResponse(string $reason): JsonResponse
    {
        return response()->json(['error' => $reason], 422);
    }

    /**
     * A no-op hook every production caller runs straight through,
     * positioned as the last thing that happens between an approved
     * location and the fopen() that follows it. It exists purely so a
     * test can simulate the on-disk location being swapped out from
     * under an already-approved path in that narrow window -- production
     * code never overrides it.
     */
    protected function beforeResolvedPathOpen(string $resolvedPath): void
    {
    }

    /**
     * Compares a freshly-fstat()'d handle's {dev, ino} against the
     * fingerprint PathContainment::validate() captured at approval time.
     */
    private function identityMatches(array $actualStat, array $resolvedIdentity): bool
    {
        return $actualStat['dev'] === $resolvedIdentity['dev'] && $actualStat['ino'] === $resolvedIdentity['ino'];
    }

    /**
     * `GitDefinitionFileReader`-shaped (Grounding note 3): array-form
     * `Process`, explicit `$cwd`, never throws. Null return means
     * "not a git repository (or git is unavailable)" — gitStatus()/
     * gitDiff() both degrade that to `{is_git_repo: false}`, never an
     * error.
     */
    private function runGitCommand(string $rootPath, array $command): ?Process
    {
        if (!is_dir($rootPath.'/.git')) {
            return null;
        }

        try {
            $process = new Process($command, $rootPath);
            $process->run();
        } catch (\Throwable) {
            return null;
        }

        if (!$process->isSuccessful()) {
            return null;
        }

        return $process;
    }

    /**
     * The uniform "not found" body shape (matches SequenceController's/
     * ManagedTaskController's own precedent) -- every controller in this
     * package declares its own private copy, there is no shared
     * base-class helper.
     */
    private function notFoundResponse(string $error, string $code): JsonResponse
    {
        return response()->json([
            'error' => $error,
            'code' => $code,
        ], 404);
    }
}
