<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\ManagedTask;
use ClarionApp\LlmClient\Models\TaskWorkspaceEntry;

/**
 * 108-shared-task-workspace (data-model.md §4). The sole write-path
 * owner for task_workspace_entries, mirroring ManagerService's role for
 * managed_tasks/managed_task_parts.
 *
 * Phase 2 (Foundational) adds recordEntry() only. trimToCap() (FR-011/
 * FR-013) and discardForTask() (FR-014/FR-015/FR-016) are added in Phase
 * 8 (US6).
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
     * $task at write time (data-model.md §1).
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

        return TaskWorkspaceEntry::create([
            'managed_task_id' => $task->id,
            'owner_user_id' => $task->owner_user_id,
            'author_agent_id' => $authorAgentId,
            'content' => $truncated,
        ]);
    }
}
