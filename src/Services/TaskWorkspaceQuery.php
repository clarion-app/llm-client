<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\ManagedTask;
use ClarionApp\LlmClient\Models\TaskWorkspaceEntry;

/**
 * 108-shared-task-workspace (data-model.md §3). Read path over
 * task_workspace_entries -- mirrors ManagedTaskQuery's own owner-scoped
 * read pattern exactly.
 */
class TaskWorkspaceQuery
{
    public function __construct(
        private readonly ManagedTaskQuery $managedTaskQuery,
    ) {}

    /**
     * research.md D5's two-step resolution: (1) is this conversation the
     * manager's own conversation (ManagedTask.conversation_id, mirroring
     * AgentLoopService::buildMessagesPayload()'s own existing
     * channel-gated read shape); (2) else, is this conversation a
     * helper's own conversation somewhere in the task's delegation tree
     * (agent_delegations.helper_conversation_id, whose managed_task_id
     * already propagates down every nested hop, 103 data-model.md §3).
     * Not itself ownership-checked -- the caller is always the
     * conversation's own already-running agent loop.
     *
     * @return string|null Null when this conversation belongs to no
     *   managed task at all.
     */
    public function resolveManagedTaskIdForConversation(string $conversationId): ?string
    {
        $managedTaskId = ManagedTask::where('conversation_id', $conversationId)->value('id');
        if ($managedTaskId !== null) {
            return $managedTaskId;
        }

        return Delegation::where('helper_conversation_id', $conversationId)
            ->whereNotNull('managed_task_id')
            ->value('managed_task_id');
    }

    /**
     * Ownership-checked read of every entry for a task, ordered oldest
     * first (data-model.md §3). Reuses ManagedTaskQuery::findManagedTask()
     * for the "absent or not-yours" check, then adds its own
     * status !== 'in_progress' check on top -- the third, deliberately
     * indistinguishable member of the null collapse, satisfying FR-016's
     * read-side refusal for a concluded task (mirrors
     * TaskWorkspaceService::recordEntry()'s identical write-side guard).
     *
     * @return array<int, array{entry_id: string, content: string, author_agent_id: string, author_agent_name: ?string, created_at: string}>|null
     *   Null when the task is absent, owned by another user, or has
     *   already concluded. `[]` for an in_progress task with no entries
     *   (a distinct, non-error outcome, US1 AC3).
     */
    public function entriesForTask(string $callerUserId, string $managedTaskId): ?array
    {
        $task = $this->managedTaskQuery->findManagedTask($callerUserId, $managedTaskId);
        if ($task === null || $task->status !== 'in_progress') {
            return null;
        }

        $entries = TaskWorkspaceEntry::where('managed_task_id', $managedTaskId)
            ->orderBy('created_at')
            ->get();

        $agentIds = $entries->pluck('author_agent_id')->filter()->unique()->values()->all();
        $names = empty($agentIds) ? [] : Agent::whereIn('id', $agentIds)->pluck('name', 'id')->all();

        return $entries->map(function (TaskWorkspaceEntry $entry) use ($names) {
            return [
                'entry_id' => $entry->id,
                'content' => $entry->content,
                'author_agent_id' => $entry->author_agent_id,
                'author_agent_name' => $names[$entry->author_agent_id] ?? null,
                'created_at' => $entry->created_at?->toJSON(),
            ];
        })->all();
    }
}
