<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use Auth;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\CodingCommandExecution;
use ClarionApp\LlmClient\Models\CodingProject;
use ClarionApp\LlmClient\Models\CodingWorkspaceChange;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Services\CommandChangeDetector;
use ClarionApp\LlmClient\Services\DockerCommandExecutor;
use ClarionApp\LlmClient\Services\GitOperationInspector;
use ClarionApp\LlmClient\Services\LanguageRuntime;
use ClarionApp\LlmClient\Services\PathContainment;
use ClarionApp\LlmClient\Services\ResourceLimitResolver;
use ClarionApp\LlmClient\Services\WorkspaceChangeRecorder;
use ClarionApp\LlmClient\Services\WorkspaceFilePolicy;
use ClarionApp\LlmClient\Services\WorkspaceRefusalRecorder;
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
        private readonly WorkspaceRefusalRecorder $refusalRecorder = new WorkspaceRefusalRecorder(),
        private readonly WorkspaceChangeRecorder $changeRecorder = new WorkspaceChangeRecorder(),
        private readonly DockerCommandExecutor $dockerCommandExecutor = new DockerCommandExecutor(),
        private readonly ResourceLimitResolver $resourceLimitResolver = new ResourceLimitResolver(),
        private readonly CommandChangeDetector $changeDetector = new CommandChangeDetector(),
        private readonly LanguageRuntime $languageRuntime = new LanguageRuntime(),
        private readonly GitOperationInspector $gitOperationInspector = new GitOperationInspector(),
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
            return $this->containmentFailureResponse($codingProject, 'list_files', $validation['reason'] ?? 'invalid path');
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
            return $this->containmentFailureResponse($codingProject, 'search_files', $result['reason'] ?? 'invalid path');
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
            return $this->containmentFailureResponse($codingProject, 'search_content', $result['reason'] ?? 'invalid path');
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
            return $this->containmentFailureResponse($codingProject, 'read_file', $validation['reason'] ?? 'invalid path');
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

            return $this->containmentFailureResponse($codingProject, 'read_file', 'outside the registered project');
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
            return $this->containmentFailureResponse($codingProject, 'write_file', $validation['reason'] ?? 'invalid path');
        }

        $resolvedPath = $validation['resolved_path'];
        $resolvedIdentity = $validation['resolved_identity'] ?? null;

        $this->beforeResolvedPathOpen($resolvedPath);

        // 122-workspace-browser-ui, US3 (research.md D6): checked
        // immediately before the create-or-open below, whose 'c' mode
        // itself creates the file as a side effect if absent -- so this
        // is the last point at which "did this write create a new file,
        // or overwrite one that was already there" can still be
        // answered. Purely a change-record labelling signal (created vs
        // modified), never a security/identity decision -- the identity
        // check below is unaffected and unchanged.
        $existedBefore = is_file($resolvedPath);

        // Create-or-open, no truncate yet -- an overwrite's identity is
        // verified against the handle before a single byte is written.
        // 122-workspace-browser-ui, US3 (research.md D6): 'c+b', not the
        // prior write-only 'cb' -- the same handle must now also be
        // readable to capture old content before it's overwritten.
        $handle = @fopen($resolvedPath, 'c+b');
        if ($handle === false) {
            return response()->json(['error' => 'could not write file'], 422);
        }

        // 122-workspace-browser-ui, US3 (research.md D6): fstat()'d
        // unconditionally now, whether or not $resolvedIdentity !== null,
        // so its pre-write size (and, below, its pre-write content) is
        // always known for the change record -- the prior code only
        // fstat()'d when an identity fingerprint existed to compare
        // against. The identity check's own timing/ordering relative to
        // fopen() is unchanged: it still runs immediately after fstat(),
        // before a single byte is written.
        $actual = @fstat($handle);
        if ($actual === false) {
            fclose($handle);

            return response()->json(['error' => 'could not write file'], 422);
        }

        if ($resolvedIdentity !== null && !$this->identityMatches($actual, $resolvedIdentity)) {
            fclose($handle);

            return $this->containmentFailureResponse($codingProject, 'write_file', 'outside the registered project');
        }

        $threshold = (int) config('llm-client.coding_agent.file_size_threshold_bytes');

        // Old content, read from the same already-open, already-identity-
        // verified handle -- before a single byte is overwritten --
        // bounded to $threshold bytes exactly like readFile()'s own
        // oversized-file sampling, so a huge pre-existing file is never
        // fully loaded into memory just to capture "what it used to say."
        $oldContent = null;
        $oldSize = null;
        if ($existedBefore) {
            $sample = @stream_get_contents($handle, $threshold);
            $oldContent = $sample === false ? null : $sample;
            $oldSize = $actual['size'];
        }

        // The read above (when $existedBefore) advances the handle's
        // position past byte 0 -- ftruncate() does not itself move it
        // back, so without this rewind() the subsequent fwrite() would
        // resume at the read's end position, leaving a gap of NUL bytes
        // at the start of the file instead of overwriting it cleanly.
        rewind($handle);
        ftruncate($handle, 0);
        $written = @fwrite($handle, $validated['content']);
        fclose($handle);

        if ($written === false) {
            return response()->json(['error' => 'could not write file'], 422);
        }

        $attribution = $this->resolveAttribution($request, $codingProject);

        $this->changeRecorder->record(
            $codingProject,
            $validated['path'],
            $existedBefore ? 'modified' : 'created',
            $oldContent,
            $oldSize,
            substr($validated['content'], 0, $threshold),
            $written,
            $attribution['agent_id'],
            $attribution['agent_name'],
            $attribution['conversation_id'],
        );

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
            return $this->containmentFailureResponse($codingProject, 'delete_file', $validation['reason'] ?? 'invalid path');
        }

        $resolvedPath = $validation['resolved_path'];
        $resolvedIdentity = $validation['resolved_identity'] ?? null;

        $this->beforeResolvedPathOpen($resolvedPath);

        // Opened to pin/verify identity via fstat() and (122-workspace-
        // browser-ui, US3, research.md D6) to capture the file's
        // pre-deletion content for the change record -- a deliberate,
        // necessary widening of this method's I/O profile for this
        // feature; the prior "content is never read" posture was a
        // correct, deliberate spec-121 scope choice made before this
        // feature existed to need deleted-file content at all. unlink()
        // itself still has to act on the path, not this handle -- PHP has
        // no fd-based delete primitive -- so this operation's identity
        // guarantee is honestly narrower than readFile's/writeFile's: it
        // is bounded to "the wrong in-workspace entry could be removed,"
        // never "outside content exposed," since unlink() never
        // dereferences a symlink to remove its target, only the directory
        // entry it was given.
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

            return $this->containmentFailureResponse($codingProject, 'delete_file', 'outside the registered project');
        }

        // Content is read only once identity is already confirmed --
        // before the handle is closed and before unlink() runs (research.md
        // D6) -- bounded to the same threshold every other content-bearing
        // path in this package uses.
        $threshold = (int) config('llm-client.coding_agent.file_size_threshold_bytes');
        $sample = @stream_get_contents($handle, $threshold);
        $oldContent = $sample === false ? null : $sample;
        $oldSize = $actual['size'];

        fclose($handle);

        $deleted = @unlink($resolvedPath);
        if (!$deleted) {
            return response()->json(['error' => 'could not delete file'], 422);
        }

        $attribution = $this->resolveAttribution($request, $codingProject);

        $this->changeRecorder->record(
            $codingProject,
            $path,
            'deleted',
            $oldContent,
            $oldSize,
            null,
            null,
            $attribution['agent_id'],
            $attribution['agent_name'],
            $attribution['conversation_id'],
        );

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
     * POST coding-project/{project}/run-command (123-sandboxed-shell-
     * execution, US1, contracts/run-command.md §1). Runs an arbitrary
     * shell command inside a genuinely isolated Docker container, scoped
     * to this project's workspace root, via DockerCommandExecutor --
     * never Process::fromShellCommandline() directly, unlike runTests(),
     * whose own narrow pre-configured-test-command capability this
     * feature does not change (tasks.md Grounding note 2).
     *
     * Ordering: ownership (404) -- root-reachability (dedicated 403, NOT
     * containmentFailureResponse()) -- command validation (422) -- the
     * actual run -- the durable CodingCommandExecution audit row -- the
     * 200 response.
     *
     * `network_enabled` is read once from the workspace's own column and
     * used both for the container's `--network` flag and the audit row,
     * so a later policy change never rewrites what an already-run
     * command could actually reach.
     */
    public function runCommand(Request $request, string $project): JsonResponse
    {
        $codingProject = $this->findOwnedProject($project);
        if ($codingProject === null) {
            return $this->notFoundResponse('Coding project not found', 'coding_project_not_found');
        }

        // Root-reachability check reusing PathContainment::validate()'s
        // "project directory is not reachable" guard (Grounding note 7) --
        // the candidate path itself is irrelevant here (a command is not
        // scoped to any sub-path the way a file operation is), so a
        // deliberately inert '.' is passed; only the first, unconditional
        // is_dir($rootPath) guard is ever consulted for this check.
        $containment = PathContainment::validate($codingProject->root_path, '.', true);
        if (!$containment['valid'] && ($containment['reason'] ?? null) === 'project directory is not reachable') {
            // Grounding note 6: recorded via a direct
            // WorkspaceRefusalRecorder::record() call for the durable
            // audit write, but returned via this small, dedicated
            // response builder -- NOT containmentFailureResponse(),
            // whose 422/no-code shape stays completely unchanged for the
            // six existing methods that use it.
            $this->refusalRecorder->record($codingProject, 'run_command', $containment['reason']);

            return response()->json([
                'error' => 'outside the registered project',
                'code' => 'workspace_boundary_refusal',
            ], 403);
        }

        $validated = $request->validate([
            'command' => 'required|string',
        ]);
        $command = $validated['command'];

        // US4 (research.md D7): the workspace's network_enabled column is
        // read exactly once per invocation, immediately before this
        // executor call -- the same value is used both to construct the
        // --network flag and to record what that specific run's
        // CodingCommandExecution row actually reflects (data-model.md §3's
        // historical-snapshot guarantee: a LATER policy change on this
        // workspace must never rewrite this row).
        $networkEnabled = (bool) $codingProject->network_enabled;

        // 124-command-limit-controls, US1 (contracts/resource-limits.md
        // §2): resolved exactly once, immediately before the executor
        // call -- the same "read exactly once, immediately before flag
        // construction" discipline network_enabled already established
        // above, now applied to all six resource limits.
        $resolvedLimits = $this->resourceLimitResolver->resolve($codingProject);

        // 124-command-limit-controls, US4 (data-model.md §3, research.md
        // R4): a before/after snapshot pair bracketing the executor call,
        // taken UNCONDITIONALLY regardless of the eventual $result['status']
        // -- completed, stopped_timeout, stopped_disk_limit, stopped_oom,
        // and stopped_pids_limit are all diffed identically (FR-011). No
        // status branch is ever consulted before deciding whether to
        // snapshot; the diff below naturally produces an empty change list
        // when the command never actually touched the filesystem.
        $before = $this->changeDetector->snapshot($codingProject->root_path);

        $result = $this->dockerCommandExecutor->run(
            $codingProject->root_path,
            $command,
            $codingProject->id,
            (string) Auth::id(),
            $networkEnabled,
            $resolvedLimits['time_limit_seconds'],
            $resolvedLimits['memory_limit_mb'],
            $resolvedLimits['cpu_limit'],
            $resolvedLimits['pids_limit'],
            $resolvedLimits['output_cap_bytes'],
            $resolvedLimits['disk_limit_mb'],
        );

        $after = $this->changeDetector->snapshot($codingProject->root_path);

        $status = $result['status'];
        $exitCode = $result['exit_code'] ?? null;
        $timedOut = $result['timed_out'] ?? false;
        $stdout = $result['stdout'] ?? null;
        $stderr = $result['stderr'] ?? null;
        $outputTruncated = $result['output_truncated'] ?? false;
        $durationMs = $result['duration_ms'] ?? null;

        $attribution = $this->resolveAttribution($request, $codingProject);

        // 124-command-limit-controls, US4 (contracts/command-file-changes.md,
        // FR-010/FR-011): the same attribution this method already resolved
        // for the CodingCommandExecution row below is reused here -- no
        // second, parallel attribution mechanism. Each changed path is
        // written through the existing, unmodified WorkspaceChangeRecorder,
        // with oldContent/oldSize null (research.md R4 §4's one honest,
        // disclosed asymmetry with writeFile()/deleteFile()) and
        // newContent/newSize read fresh from disk, capped identically to
        // every other content-bearing path in this controller.
        $threshold = (int) config('llm-client.coding_agent.file_size_threshold_bytes');
        foreach ($this->changeDetector->diff($before, $after) as $change) {
            $newContent = null;
            $newSize = null;

            if ($change['operation'] !== 'deleted') {
                $absolutePath = rtrim($codingProject->root_path, '/').'/'.$change['path'];
                clearstatcache(true, $absolutePath);
                $stat = @stat($absolutePath);
                if ($stat !== false) {
                    $newSize = (int) $stat['size'];
                    $sample = @file_get_contents($absolutePath, false, null, 0, $threshold);
                    $newContent = $sample === false ? null : $sample;
                }
            }

            $this->changeRecorder->record(
                $codingProject,
                $change['path'],
                $change['operation'],
                null,
                null,
                $newContent,
                $newSize,
                $attribution['agent_id'],
                $attribution['agent_name'],
                $attribution['conversation_id'],
            );
        }

        CodingCommandExecution::create([
            'coding_project_id' => $codingProject->id,
            'user_id' => Auth::id(),
            'command' => $command,
            'status' => $status,
            'exit_code' => $exitCode,
            'timed_out' => $timedOut,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'output_truncated' => $outputTruncated,
            'network_enabled' => $networkEnabled,
            'duration_ms' => $durationMs,
            'agent_id' => $attribution['agent_id'],
            'agent_name' => $attribution['agent_name'],
            'conversation_id' => $attribution['conversation_id'],
        ]);

        $responseBody = [
            'status' => $status,
            'command' => $command,
            'exit_code' => $exitCode,
            'timed_out' => $timedOut,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'output_truncated' => $outputTruncated,
            'network_enabled' => $networkEnabled,
            'duration_ms' => $durationMs,
        ];

        if ($status === 'sandbox_unavailable' && isset($result['reason'])) {
            $responseBody['reason'] = $result['reason'];
        }

        return response()->json($responseBody, 200);
    }

    /**
     * POST coding-project/{project}/run-code (125-language-runtime-execution,
     * US1, contracts/run-code.md §1, Grounding notes 5/8). Reuses
     * runCommand()'s exact flow -- findOwnedProject()/root-reachability/
     * ResourceLimitResolver/changeDetector+changeRecorder/resolveAttribution()
     * -- unmodified in every step except: the request carries
     * {language, code} instead of {command}; a recognized-language guard
     * runs before the executor is ever called (422, no audit row); the
     * submitted code is passed as DockerCommandExecutor::run()'s trailing
     * $stdin argument instead of being embedded as the shell command
     * itself; a language-unavailable-detection step translates the raw
     * executor result when the fused availability guard's sentinel fires;
     * and the audit row/response body carry language+code (the `command`
     * column stores the code text) instead of a bare command.
     */
    public function runCode(Request $request, string $project): JsonResponse
    {
        $codingProject = $this->findOwnedProject($project);
        if ($codingProject === null) {
            return $this->notFoundResponse('Coding project not found', 'coding_project_not_found');
        }

        $containment = PathContainment::validate($codingProject->root_path, '.', true);
        if (!$containment['valid'] && ($containment['reason'] ?? null) === 'project directory is not reachable') {
            $this->refusalRecorder->record($codingProject, 'run_code', $containment['reason']);

            return response()->json([
                'error' => 'outside the registered project',
                'code' => 'workspace_boundary_refusal',
            ], 403);
        }

        $validated = $request->validate([
            'language' => 'required|string',
            'code' => 'required|string',
        ]);
        $language = $validated['language'];
        $code = $validated['code'];

        if (!$this->languageRuntime->isRecognized($language)) {
            return response()->json([
                'error' => "unrecognized language '{$language}'",
                'code' => 'language_unrecognized',
                'language' => $language,
            ], 422);
        }

        $networkEnabled = (bool) $codingProject->network_enabled;

        $resolvedLimits = $this->resourceLimitResolver->resolve($codingProject);

        $before = $this->changeDetector->snapshot($codingProject->root_path);

        $result = $this->dockerCommandExecutor->run(
            $codingProject->root_path,
            $this->languageRuntime->buildExecutionCommand($language),
            $codingProject->id,
            (string) Auth::id(),
            $networkEnabled,
            $resolvedLimits['time_limit_seconds'],
            $resolvedLimits['memory_limit_mb'],
            $resolvedLimits['cpu_limit'],
            $resolvedLimits['pids_limit'],
            $resolvedLimits['output_cap_bytes'],
            $resolvedLimits['disk_limit_mb'],
            $code,
        );

        $after = $this->changeDetector->snapshot($codingProject->root_path);

        $status = $result['status'];
        $exitCode = $result['exit_code'] ?? null;
        $timedOut = $result['timed_out'] ?? false;
        $stdout = $result['stdout'] ?? null;
        $stderr = $result['stderr'] ?? null;
        $outputTruncated = $result['output_truncated'] ?? false;
        $durationMs = $result['duration_ms'] ?? null;
        $reason = $result['reason'] ?? null;

        // Grounding note 8: a fused-guard sentinel on stderr with exit 127
        // is the raw executor's own honest report of a genuinely absent
        // runtime -- translated here, at the caller, into a dedicated
        // status the response/audit row both reflect. The internal
        // sentinel text itself is never leaked to the caller.
        if ($exitCode === 127 && trim((string) ($stderr ?? '')) === LanguageRuntime::LANGUAGE_UNAVAILABLE_SENTINEL) {
            $status = 'language_unavailable';
            $exitCode = null;
            $stdout = null;
            $stderr = null;
            $reason = "{$language} is not available in this workspace's configured sandbox image";
        }

        $attribution = $this->resolveAttribution($request, $codingProject);

        $threshold = (int) config('llm-client.coding_agent.file_size_threshold_bytes');
        foreach ($this->changeDetector->diff($before, $after) as $change) {
            $newContent = null;
            $newSize = null;

            if ($change['operation'] !== 'deleted') {
                $absolutePath = rtrim($codingProject->root_path, '/').'/'.$change['path'];
                clearstatcache(true, $absolutePath);
                $stat = @stat($absolutePath);
                if ($stat !== false) {
                    $newSize = (int) $stat['size'];
                    $sample = @file_get_contents($absolutePath, false, null, 0, $threshold);
                    $newContent = $sample === false ? null : $sample;
                }
            }

            $this->changeRecorder->record(
                $codingProject,
                $change['path'],
                $change['operation'],
                null,
                null,
                $newContent,
                $newSize,
                $attribution['agent_id'],
                $attribution['agent_name'],
                $attribution['conversation_id'],
            );
        }

        CodingCommandExecution::create([
            'coding_project_id' => $codingProject->id,
            'user_id' => Auth::id(),
            'command' => $code,
            'language' => $language,
            'status' => $status,
            'exit_code' => $exitCode,
            'timed_out' => $timedOut,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'output_truncated' => $outputTruncated,
            'network_enabled' => $networkEnabled,
            'duration_ms' => $durationMs,
            'agent_id' => $attribution['agent_id'],
            'agent_name' => $attribution['agent_name'],
            'conversation_id' => $attribution['conversation_id'],
        ]);

        $responseBody = [
            'status' => $status,
            'language' => $language,
            'code' => $code,
            'exit_code' => $exitCode,
            'timed_out' => $timedOut,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'output_truncated' => $outputTruncated,
            'network_enabled' => $networkEnabled,
            'duration_ms' => $durationMs,
        ];

        if (($status === 'sandbox_unavailable' || $status === 'language_unavailable') && $reason !== null) {
            $responseBody['reason'] = $reason;
        }

        return response()->json($responseBody, 200);
    }

    /**
     * GET coding-project/{project}/languages
     * (125-language-runtime-execution, US2, contracts/language-availability.md
     * §1, research.md D4). A real probe, not a fixed or assumed list
     * (FR-005): reuses DockerCommandExecutor::run() exactly as
     * runCommand()/runCode() do, with
     * LanguageRuntime::buildAvailabilityProbeCommand()'s output as the
     * command and no $stdin -- a `command -v` probe is not subject to a
     * workspace's demanding-work resource overrides, so no
     * ResourceLimitResolver call is made here. A sandbox_unavailable
     * result is propagated unchanged, the same shape runCommand()/
     * runCode() already use. This method executes no command against the
     * workspace's own files and produces no side effect, so -- unlike
     * runCommand()/runCode() -- it writes no CodingCommandExecution row
     * under any outcome (data-model.md §3).
     */
    public function languages(string $project): JsonResponse
    {
        $codingProject = $this->findOwnedProject($project);
        if ($codingProject === null) {
            return $this->notFoundResponse('Coding project not found', 'coding_project_not_found');
        }

        $result = $this->dockerCommandExecutor->run(
            $codingProject->root_path,
            $this->languageRuntime->buildAvailabilityProbeCommand(),
            $codingProject->id,
            (string) Auth::id(),
            false,
        );

        if (($result['status'] ?? null) === 'sandbox_unavailable') {
            return response()->json([
                'status' => 'sandbox_unavailable',
                'reason' => $result['reason'] ?? null,
            ], 200);
        }

        $availability = $this->languageRuntime->parseAvailabilityOutput((string) ($result['stdout'] ?? ''));

        $languages = [];
        foreach (LanguageRuntime::RECOGNIZED_LANGUAGES as $name => $spec) {
            $languages[] = [
                'name' => $name,
                'available' => $availability[$name] ?? false,
            ];
        }

        return response()->json(['languages' => $languages], 200);
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
     * GET coding-project/{project}/git-log?limit= (126-git-operations-
     * confirmation, US1, contracts/git-inspection.md §3, Grounding
     * note 6). `isGitRepository()` is checked directly, as its own first
     * step -- never via runGitCommand()'s null-collapse, which cannot
     * distinguish "not a repository" from "a real, empty repository's
     * `git log` genuinely failing with zero commits" (the latter degrades
     * to `entries: []`, never to `is_git_repo: false`).
     */
    public function gitLog(Request $request, string $project): JsonResponse
    {
        $codingProject = $this->findOwnedProject($project);
        if ($codingProject === null) {
            return $this->notFoundResponse('Coding project not found', 'coding_project_not_found');
        }

        if (!$this->gitOperationInspector->isGitRepository($codingProject->root_path)) {
            return response()->json(['is_git_repo' => false], 200);
        }

        $limit = $this->gitLogLimit($request);

        $process = $this->runGitCommand($codingProject->root_path, [
            'git', 'log', '-n', (string) $limit, '--date=iso-strict', '--format=%H|%h|%an|%ad|%s',
        ]);

        return response()->json([
            'is_git_repo' => true,
            'entries' => $this->parseGitLogOutput($process?->getOutput() ?? ''),
        ], 200);
    }

    /**
     * POST coding-project/{project}/git-commit (126-git-operations-
     * confirmation, US2, contracts/git-commit.md §1). Reaches this
     * controller only once already confirmed -- `coding.yaml`'s
     * `safety.confirmation_required` and the existing pause/resume
     * mechanism gate it upstream, exactly like every other confirmed
     * mutation on this controller.
     *
     * `GitOperationInspector::previewCommit()` is called a second time
     * here, immediately before executing (Grounding note 5's
     * belt-and-suspenders posture -- neither layer trusts the other):
     * called WITH whatever `paths` this request actually carries, never
     * with `null`, so a request that resubmits the confirmation's own
     * pinned list gets back exactly that list again (never a fresh scan
     * that could pick up an unrelated change made while the confirmation
     * was pending -- research.md D6). A request that omits `paths`
     * entirely (e.g. a direct, non-agent-driven call) still gets a fresh,
     * correct resolution the same way the confirmation preview itself
     * would have produced.
     */
    public function gitCommit(Request $request, string $project): JsonResponse
    {
        $codingProject = $this->findOwnedProject($project);
        if ($codingProject === null) {
            return $this->notFoundResponse('Coding project not found', 'coding_project_not_found');
        }

        $validated = $request->validate([
            'message' => 'required|string',
            'paths' => 'nullable|array',
            'paths.*' => 'string',
        ]);

        if (!$this->gitOperationInspector->isGitRepository($codingProject->root_path)) {
            return response()->json([
                'error' => 'This project is not a git repository.',
                'code' => 'git_not_a_repository',
            ], 422);
        }

        $preview = $this->gitOperationInspector->previewCommit($codingProject->root_path, $validated['paths'] ?? null);
        if (!($preview['ok'] ?? false)) {
            return response()->json([
                'error' => $preview['reason'] ?? 'Nothing to commit.',
                'code' => $preview['code'] ?? 'git_nothing_to_commit',
            ], 422);
        }

        $files = $preview['files'];
        $attribution = $this->resolveAttribution($request, $codingProject);

        $addProcess = $this->runGitCommandCapturingResult($codingProject->root_path, array_merge(['git', 'add', '--'], $files));
        $commitProcess = ($addProcess !== null && $addProcess->isSuccessful())
            ? $this->runGitCommandCapturingResult($codingProject->root_path, ['git', 'commit', '-m', $validated['message']])
            : null;

        $succeeded = $commitProcess !== null && $commitProcess->isSuccessful();
        $failedProcess = $commitProcess ?? $addProcess;

        $hash = null;
        if ($succeeded) {
            $hashProcess = $this->runGitCommandCapturingResult($codingProject->root_path, ['git', 'rev-parse', 'HEAD']);
            $hash = ($hashProcess !== null && $hashProcess->isSuccessful()) ? trim($hashProcess->getOutput()) : null;
        }

        CodingCommandExecution::create([
            'coding_project_id' => $codingProject->id,
            'user_id' => Auth::id(),
            'command' => $this->buildCommitCommandString($validated['message'], $files),
            'status' => $succeeded ? 'completed' : 'failed',
            'exit_code' => $failedProcess?->getExitCode(),
            'timed_out' => false,
            'stdout' => $failedProcess?->getOutput(),
            'stderr' => $failedProcess?->getErrorOutput(),
            'output_truncated' => false,
            'network_enabled' => (bool) $codingProject->network_enabled,
            'duration_ms' => null,
            'agent_id' => $attribution['agent_id'],
            'agent_name' => $attribution['agent_name'],
            'conversation_id' => $attribution['conversation_id'],
        ]);

        if (!$succeeded) {
            return response()->json([
                'error' => 'git command failed',
                'code' => 'git_command_failed',
                'stderr' => $failedProcess?->getErrorOutput() ?? '',
            ], 422);
        }

        return response()->json([
            'committed' => true,
            'hash' => $hash,
            'message' => $validated['message'],
            'files' => $files,
        ], 200);
    }

    /**
     * The human-readable, sanitized (research.md D8 -- a commit message
     * and plain file paths never carry a credential, so no sanitization
     * is actually needed here beyond quoting) reconstruction of a commit
     * invocation, shared by the executed-and-recorded row above and
     * mirrored by AgentLoopService::recordDeclinedCommandExecution()'s
     * own gitCommit branch for a declined one (data-model.md §3,
     * contracts/git-commit.md's Declined section).
     *
     * @param  string[]  $files
     */
    private function buildCommitCommandString(string $message, array $files): string
    {
        return 'git commit -m "'.addslashes($message).'" -- '.implode(' ', $files);
    }

    /**
     * POST coding-project/{project}/git-push (126-git-operations-
     * confirmation, US3, contracts/git-publish.md §1). Reaches this
     * controller only once already confirmed -- `coding.yaml`'s
     * `safety.confirmation_required` and the existing pause/resume
     * mechanism gate it upstream, exactly like gitCommit() above.
     *
     * `GitOperationInspector::previewPush()` is called a second time here,
     * immediately before executing (Grounding note 5's belt-and-suspenders
     * posture, mirrored from gitCommit()): re-derives `remote`/`branch`/
     * `remote_url_sanitized`/`creates_remote_branch` fresh, from whatever
     * this request actually carries. `pinned_head` (research.md D6) is
     * taken from the request body when present -- the exact commit an
     * already-approved confirmation named -- and only falls back to a
     * freshly-resolved local HEAD for a direct call that never went
     * through a confirmation at all.
     */
    public function gitPush(Request $request, string $project): JsonResponse
    {
        $codingProject = $this->findOwnedProject($project);
        if ($codingProject === null) {
            return $this->notFoundResponse('Coding project not found', 'coding_project_not_found');
        }

        $validated = $request->validate([
            'remote' => 'nullable|string',
            'branch' => 'nullable|string',
            'pinned_head' => 'nullable|string',
        ]);

        if (!$this->gitOperationInspector->isGitRepository($codingProject->root_path)) {
            return response()->json([
                'error' => 'This project is not a git repository.',
                'code' => 'git_not_a_repository',
            ], 422);
        }

        $preview = $this->gitOperationInspector->previewPush($codingProject, $validated['remote'] ?? null, $validated['branch'] ?? null);
        if (!($preview['ok'] ?? false)) {
            return response()->json([
                'error' => $preview['reason'] ?? 'Publishing refused.',
                'code' => $preview['code'] ?? 'git_publish_disabled',
            ], 422);
        }

        $remote = $preview['remote'];
        $branch = $preview['branch'];
        $requestedPinnedHead = $validated['pinned_head'] ?? null;
        $pinnedHead = (is_string($requestedPinnedHead) && $requestedPinnedHead !== '') ? $requestedPinnedHead : $preview['pinned_head'];

        $attribution = $this->resolveAttribution($request, $codingProject);

        $previousRemoteHead = $this->remoteBranchHeadHash($codingProject->root_path, $remote, $branch);

        $pushProcess = $this->runGitCommandCapturingResult(
            $codingProject->root_path,
            ['git', 'push', $remote, "{$pinnedHead}:refs/heads/{$branch}"],
        );
        $succeeded = $pushProcess !== null && $pushProcess->isSuccessful();
        $sanitizedStderr = $pushProcess !== null
            ? $this->gitOperationInspector->sanitizeRemoteUrl($pushProcess->getErrorOutput())
            : null;

        CodingCommandExecution::create([
            'coding_project_id' => $codingProject->id,
            'user_id' => Auth::id(),
            'command' => $this->buildPushCommandString($remote, $pinnedHead, $branch),
            'status' => $succeeded ? 'completed' : 'failed',
            'exit_code' => $pushProcess?->getExitCode(),
            'timed_out' => false,
            'stdout' => $pushProcess?->getOutput(),
            'stderr' => $sanitizedStderr,
            'output_truncated' => false,
            'network_enabled' => (bool) $codingProject->network_enabled,
            'duration_ms' => null,
            'agent_id' => $attribution['agent_id'],
            'agent_name' => $attribution['agent_name'],
            'conversation_id' => $attribution['conversation_id'],
        ]);

        if (!$succeeded) {
            return response()->json([
                'error' => 'push rejected',
                'code' => 'git_push_rejected',
                'stderr' => $sanitizedStderr ?? '',
            ], 422);
        }

        return response()->json([
            'pushed' => true,
            'remote' => $remote,
            'branch' => $branch,
            'remote_url_sanitized' => $preview['remote_url_sanitized'],
            'previous_remote_head' => $previousRemoteHead,
            'new_remote_head' => $pinnedHead,
        ], 200);
    }

    /**
     * The human-readable, D8-sanitized reconstruction of a push invocation
     * -- shared by the executed-and-recorded row above and mirrored by
     * AgentLoopService::recordDeclinedCommandExecution()'s own gitPush
     * branch for a declined one. `$remote` is sanitized defensively in
     * case a caller passed a literal, credential-bearing URL instead of a
     * configured remote name.
     */
    private function buildPushCommandString(string $remote, string $pinnedHead, string $branch): string
    {
        $sanitizedRemote = $this->gitOperationInspector->sanitizeRemoteUrl($remote);

        return "git push {$sanitizedRemote} {$pinnedHead}:refs/heads/{$branch}";
    }

    /**
     * A read-only `git ls-remote <remote> refs/heads/<branch>` -- the
     * remote branch's hash immediately before this request's own push, so
     * the response can report `previous_remote_head` honestly. Null when
     * the branch does not exist on the remote yet (this push creates it).
     */
    private function remoteBranchHeadHash(string $rootPath, string $remote, string $branch): ?string
    {
        $process = $this->runGitCommandCapturingResult($rootPath, ['git', 'ls-remote', $remote, 'refs/heads/'.$branch]);
        if ($process === null || !$process->isSuccessful()) {
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
     * POST coding-project/{project}/git-branch (126-git-operations-
     * confirmation, US4, contracts/git-branch.md §1). Reaches this
     * controller only once already confirmed -- `coding.yaml`'s
     * `safety.confirmation_required` and the existing pause/resume
     * mechanism gate it upstream, exactly like gitCommit()/gitPush()
     * above.
     *
     * Named `gitBranch()`, not `gitCreateBranch()` -- the auto-generated
     * operation catalog (Dedoc\Scramble, via ApiManager::getOperations())
     * derives each route's operationId from its controller method name,
     * and every sibling git operation here already relies on that exact
     * match (`gitCommit()` -> `...codingWorkspace.gitCommit`, `gitPush()`
     * -> `...codingWorkspace.gitPush`); a method named `gitCreateBranch()`
     * would leave `clarionApp.llmClient.codingWorkspace.gitBranch` --
     * the operationId `coding.yaml`, the
     * `CODING_WORKSPACE_GIT_BRANCH_OPERATION_ID` constant, and
     * contracts/git-branch.md all actually use -- unresolvable in the
     * live catalog.
     *
     * `GitOperationInspector::previewCreateBranch()` is called a second
     * time here, immediately before executing (Grounding note 5's
     * belt-and-suspenders posture, mirrored from gitCommit()/gitPush()):
     * `start_point` is taken from the request body as-is -- for an
     * approved confirmation this is already the pinned, resolved hash
     * (research.md D6), so re-resolving it here against that exact hash
     * is a no-op; a direct call that never went through a confirmation at
     * all (e.g. an omitted `start_point`) still gets a fresh, correct
     * resolution the same way the confirmation preview itself would have
     * produced.
     */
    public function gitBranch(Request $request, string $project): JsonResponse
    {
        $codingProject = $this->findOwnedProject($project);
        if ($codingProject === null) {
            return $this->notFoundResponse('Coding project not found', 'coding_project_not_found');
        }

        $validated = $request->validate([
            'name' => 'required|string',
            'start_point' => 'nullable|string',
        ]);

        if (!$this->gitOperationInspector->isGitRepository($codingProject->root_path)) {
            return response()->json([
                'error' => 'This project is not a git repository.',
                'code' => 'git_not_a_repository',
            ], 422);
        }

        $preview = $this->gitOperationInspector->previewCreateBranch(
            $codingProject->root_path,
            $validated['name'],
            $validated['start_point'] ?? null,
        );
        if (!($preview['ok'] ?? false)) {
            return response()->json([
                'error' => $preview['reason'] ?? 'Could not create branch.',
                'code' => $preview['code'] ?? 'git_invalid_reference',
            ], 422);
        }

        $resolvedHash = $preview['start_point_resolved']['hash'];
        $attribution = $this->resolveAttribution($request, $codingProject);

        $branchProcess = $this->runGitCommandCapturingResult(
            $codingProject->root_path,
            ['git', 'branch', $validated['name'], $resolvedHash],
        );
        $succeeded = $branchProcess !== null && $branchProcess->isSuccessful();

        CodingCommandExecution::create([
            'coding_project_id' => $codingProject->id,
            'user_id' => Auth::id(),
            'command' => $this->buildCreateBranchCommandString($validated['name'], $resolvedHash),
            'status' => $succeeded ? 'completed' : 'failed',
            'exit_code' => $branchProcess?->getExitCode(),
            'timed_out' => false,
            'stdout' => $branchProcess?->getOutput(),
            'stderr' => $branchProcess?->getErrorOutput(),
            'output_truncated' => false,
            'network_enabled' => (bool) $codingProject->network_enabled,
            'duration_ms' => null,
            'agent_id' => $attribution['agent_id'],
            'agent_name' => $attribution['agent_name'],
            'conversation_id' => $attribution['conversation_id'],
        ]);

        if (!$succeeded) {
            return response()->json([
                'error' => 'could not create branch',
                'code' => 'git_command_failed',
                'stderr' => $branchProcess?->getErrorOutput() ?? '',
            ], 422);
        }

        return response()->json([
            'branch' => $validated['name'],
            'created' => true,
            'start_point' => $resolvedHash,
        ], 200);
    }

    /**
     * The human-readable reconstruction of a branch-creation invocation --
     * shared by the executed-and-recorded row above and mirrored by
     * AgentLoopService::recordDeclinedCommandExecution()'s own gitBranch
     * branch for a declined one (data-model.md §3, contracts/git-branch.md's
     * Declined section). No sanitization is needed here (research.md D8) --
     * a branch name and a resolved commit hash never carry a credential.
     */
    private function buildCreateBranchCommandString(string $name, string $resolvedStartPoint): string
    {
        return "git branch {$name} {$resolvedStartPoint}";
    }

    /**
     * Clamps the `limit` query param against the configured default/max
     * (contracts §3): a non-numeric or non-positive value floors at the
     * default, anything above the max clamps to the max.
     */
    private function gitLogLimit(Request $request): int
    {
        $default = (int) config('llm-client.coding_agent.git.log_default_limit', 50);
        $max = (int) config('llm-client.coding_agent.git.log_max_limit', 200);

        $raw = $request->query('limit');

        if (!is_numeric($raw)) {
            return $default;
        }

        $limit = (int) $raw;

        if ($limit <= 0) {
            return $default;
        }

        return min($limit, $max);
    }

    /**
     * Parses `git log --format=%H|%h|%an|%ad|%s` output line-by-line. A
     * bounded `explode(..., 5)` guards against a pathological commit
     * subject that itself contains a `|`. Empty/missing output (including
     * a `git log` that failed outright, e.g. a real empty repository with
     * zero commits) yields an empty list, never an error.
     */
    private function parseGitLogOutput(string $output): array
    {
        $entries = [];

        foreach (preg_split('/\R/', $output) as $line) {
            if ($line === '') {
                continue;
            }

            [$hash, $shortHash, $author, $date, $subject] = array_pad(explode('|', $line, 5), 5, '');

            $entries[] = [
                'hash' => $hash,
                'short_hash' => $shortHash,
                'author' => $author,
                'date' => $date,
                'subject' => $subject,
            ];
        }

        return $entries;
    }

    /**
     * GET coding-project/{project}/changes (122-workspace-browser-ui,
     * US3, contracts/workspace-change-history-api.md, research.md D7,
     * FR-008/FR-009/FR-010/FR-011). Ownership is looked up via
     * CodingProject::withTrashed() -- DELIBERATELY, not findOwnedProject()
     * -- the one exception on this controller, so a removed workspace's
     * change history stays reviewable (FR-011). This must never be
     * "fixed" to match this controller's other methods; doing so would
     * silently reintroduce the exact gap D7 exists to close.
     */
    public function changes(Request $request, string $project): JsonResponse
    {
        $codingProject = CodingProject::withTrashed()
            ->where('id', $project)
            ->where('user_id', Auth::id())
            ->first();

        if ($codingProject === null) {
            return $this->notFoundResponse('Coding project not found', 'coding_project_not_found');
        }

        [$page, $perPage] = $this->paginationParams($request, 50, 100);

        $query = CodingWorkspaceChange::where('coding_project_id', $codingProject->id)
            ->orderBy('created_at', 'desc');

        $total = $query->count();

        $changes = $query->forPage($page, $perPage)
            ->get()
            ->map(fn (CodingWorkspaceChange $change) => [
                'id' => $change->id,
                'path' => $change->path,
                'operation' => $change->operation,
                'old_content' => $change->old_content,
                'old_content_truncated' => (bool) $change->old_content_truncated,
                'old_binary' => (bool) $change->old_binary,
                'old_size' => $change->old_size,
                'new_content' => $change->new_content,
                'new_content_truncated' => (bool) $change->new_content_truncated,
                'new_binary' => (bool) $change->new_binary,
                'new_size' => $change->new_size,
                'agent_id' => $change->agent_id,
                'agent_name' => $change->agent_name,
                'conversation_id' => $change->conversation_id,
                'created_at' => $change->created_at,
            ])
            ->values()
            ->all();

        return response()->json($this->envelope($changes, $total, $page, $perPage), 200);
    }

    /**
     * Resolve `page`/`per_page` query params against a per-endpoint
     * default and cap (122-workspace-browser-ui T006 decision -- mirrors
     * only CodingProjectController::paginationParams()'s/RunController's
     * floor/cap/default *logic*, not any shared envelope shape; this
     * controller has no cross-controller pagination helper to extract
     * into).
     *
     * @return array{0: int, 1: int} [page, perPage]
     */
    private function paginationParams(Request $request, int $default, int $cap): array
    {
        $page = max(1, (int) $request->input('page', 1));

        $perPage = (int) $request->input('per_page', $default);
        if ($perPage < 1) {
            $perPage = $default;
        }
        $perPage = min($perPage, $cap);

        return [$page, $perPage];
    }

    /**
     * The flat `{data, total, page, per_page}` envelope T006 settled on
     * for this feature's list endpoints -- deliberately NOT
     * RunController::envelope()'s nested `{data, meta: {...}}` shape.
     */
    private function envelope(array $data, int $total, int $page, int $perPage): array
    {
        return [
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * research.md D5: the X-Llm-Client-Conversation-Id header (attached
     * only by AgentLoopService::executeApiCall(), only for the two
     * coding-workspace mutation operation ids) is trusted for change-
     * record attribution only after being independently re-verified
     * against Auth::id()-owned data — this controller's own established
     * "belt-and-suspenders, neither check trusts the other" posture. Any
     * verification failure (header absent, conversation not found,
     * conversation not owned by the caller, conversation bound to a
     * different project) degrades to an entirely unattributed (null)
     * result — it never blocks or alters the mutation itself, which has
     * already succeeded by the time this runs.
     *
     * @return array{agent_id: ?string, agent_name: ?string, conversation_id: ?string}
     */
    private function resolveAttribution(Request $request, CodingProject $codingProject): array
    {
        $unattributed = ['agent_id' => null, 'agent_name' => null, 'conversation_id' => null];

        $conversationId = $request->header('X-Llm-Client-Conversation-Id');
        if (!is_string($conversationId) || $conversationId === '') {
            return $unattributed;
        }

        $conversation = Conversation::find($conversationId);
        if ($conversation === null
            || (string) $conversation->user_id !== (string) Auth::id()
            || (string) $conversation->coding_project_id !== (string) $codingProject->id) {
            return $unattributed;
        }

        $agentName = null;
        if ($conversation->agent_id !== null) {
            $agentName = Agent::where('id', $conversation->agent_id)->value('name');
        }

        return [
            'agent_id' => $conversation->agent_id,
            'agent_name' => $agentName,
            'conversation_id' => $conversation->id,
        ];
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
     * silently-allowed operation. Every call site funnels through here --
     * old and new alike -- so this is also the single seam
     * WorkspaceRefusalRecorder::record() is called from
     * (121-workspace-boundary-hardening, US2,
     * contracts/refusal-recording.md §1): a durable record of the refusal
     * is written before the response is built, independent of whether the
     * caller was an agent's own tool call or a direct, non-agent request.
     */
    private function containmentFailureResponse(CodingProject $project, string $operation, string $reason): JsonResponse
    {
        $this->refusalRecorder->record($project, $operation, $reason);

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
     * A sibling to runGitCommand() (126-git-operations-confirmation,
     * Grounding note 6) that returns the Process object regardless of
     * whether it succeeded -- for the mutating git operations, which need
     * real `stderr` text surfaced on a genuine execution-time failure.
     * runGitCommand()'s own null-on-failure collapse (used by
     * gitStatus()/gitDiff(), which have no need to distinguish "not a
     * repo" from "the command itself failed") is completely unchanged.
     * Still returns null for "not a repository" and for a Process that
     * could not even be started -- only a genuine command failure once
     * started is surfaced as a (non-null, !isSuccessful()) Process.
     */
    private function runGitCommandCapturingResult(string $rootPath, array $command): ?Process
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
