<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\ManagedTask;
use ClarionApp\LlmClient\Models\TaskWorkspaceEntry;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * 108-shared-task-workspace (data-model.md §4). The sole write-path
 * owner for task_workspace_entries, mirroring ManagerService's role for
 * managed_tasks/managed_task_parts.
 *
 * Phase 2 (Foundational) added recordEntry() only. Phase 8 (US6) adds
 * trimToCap() (FR-011/FR-013 -- count-cap eviction, lock-guarded per
 * research.md D3) and discardForTask() (FR-014/FR-015/FR-016 -- bulk
 * delete on conclusion, called from ManagerService::finalize()/
 * finalizeWithShortfall()).
 */
class TaskWorkspaceService
{
    public function __construct(
        private readonly ContentSanitizer $contentSanitizer,
    ) {}

    /**
     * Refuses (returns null, no row written) when $task->status !==
     * 'in_progress' (FR-016), or when $content is empty after
     * ContentSanitizer::truncate() against max_entry_bytes (an empty
     * entry records nothing worth attributing -- distinct from silent
     * truncation of a non-empty entry, which IS recorded). Otherwise
     * inserts a TaskWorkspaceEntry with owner_user_id denormalized from
     * $task at write time (data-model.md §1), then attempts the
     * size-cap trim (research.md D3) -- a write must never fail or
     * stall because of this housekeeping step.
     */
    public function recordEntry(ManagedTask $task, string $authorAgentId, string $content): ?TaskWorkspaceEntry
    {
        if ($task->status !== 'in_progress') {
            return null;
        }

        $truncated = $this->contentSanitizer->truncate(
            $content,
            (int) config('llm-client.task_workspace.max_entry_bytes')
        );

        if ($truncated === '') {
            return null;
        }

        $entry = TaskWorkspaceEntry::create([
            'managed_task_id' => $task->id,
            'owner_user_id' => $task->owner_user_id,
            'author_agent_id' => $authorAgentId,
            'content' => $truncated,
        ]);

        $this->trimToCap($task->id);

        return $entry;
    }

    /**
     * data-model.md §4/research.md D3. Counts this task's entries; if
     * over config('llm-client.task_workspace.max_entries'), deletes the
     * oldest (count - max_entries) rows by created_at ASC -- never
     * inspecting content (FR-013's "uniformly applied," keeping FR-010's
     * guarantee independent of this method, since it never looks at
     * content at all -- it cannot selectively favor one side of a
     * disagreement).
     *
     * Guarded by the exact Cache::lock idiom OperationCache::withLock()
     * established (two-catch: no LockProvider -> run unsynchronized;
     * lock busy -> skip the trim rather than block the caller). The
     * failure mode of a skipped/raced trim is, at worst, one extra row
     * surviving past the cap momentarily, self-corrected on the next
     * write -- never a correctness bug (research.md D3).
     */
    public function trimToCap(string $managedTaskId): void
    {
        $this->withTrimLock($managedTaskId, function () use ($managedTaskId) {
            $maxEntries = (int) config('llm-client.task_workspace.max_entries');
            $count = TaskWorkspaceEntry::where('managed_task_id', $managedTaskId)->count();

            $excess = $count - $maxEntries;
            if ($excess <= 0) {
                return;
            }

            $staleIds = TaskWorkspaceEntry::where('managed_task_id', $managedTaskId)
                ->orderBy('created_at')
                ->limit($excess)
                ->pluck('id');

            TaskWorkspaceEntry::whereIn('id', $staleIds)->delete();
        });
    }

    /**
     * FR-014/FR-015/FR-016, data-model.md §4. Bulk-deletes every entry
     * for a task -- called from exactly two places (research.md D2/D7):
     * ManagerService::finalize() and ManagerService::finalizeWithShortfall(),
     * covering completion, forced failure (round/wall-clock ceiling), and
     * abandonment (the stale sweep) identically, since those two methods
     * are the only places ManagedTask.status ever reaches a terminal
     * value.
     */
    public function discardForTask(string $managedTaskId): void
    {
        TaskWorkspaceEntry::where('managed_task_id', $managedTaskId)->delete();
    }

    /**
     * The exact two-catch lock idiom OperationCache::withLock() (L91-114)
     * established, reused verbatim (Grounding note item 8): no
     * LockProvider on the configured store -> run unsynchronized; lock
     * busy within lock_wait seconds -> also run unsynchronized (skip the
     * trim, keep the write) rather than block the caller.
     */
    private function withTrimLock(string $managedTaskId, callable $fn): mixed
    {
        $lockKey = "task-workspace:{$managedTaskId}:trim";
        $lockSeconds = 5;
        $lockWait = (int) config('llm-client.task_workspace.lock_wait', 3);

        try {
            $lock = Cache::lock($lockKey, $lockSeconds);
        } catch (\Throwable) {
            // Store cannot provide locks (no LockProvider) -- run unsynchronized.
            return $fn();
        }

        try {
            return $lock->block($lockWait, $fn);
        } catch (LockTimeoutException) {
            return $fn();
        }
    }
}
