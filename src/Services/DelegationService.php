<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\AgentHelperAssignment;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\ValueObjects\ActionOutcome;
use ClarionApp\LlmClient\ValueObjects\ActionType;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The single write path for a delegated task hand-off (data-model.md §5,
 * research.md D1/D6/D7/D8). Mirrors AgentHelperService's role as the
 * sole owner of its own table.
 *
 * This phase deliberately ships without bounding (D3), depth-limit
 * enforcement (D4), or failure-catching (D5) -- those are additive changes
 * layered on top of this same sequence later. depth is computed and stored
 * here (cheap, needed for the row's own completeness) but not yet refused
 * on; a bare try/finally restores the ambient run id around the nested
 * run() call, but nothing here catches an exception the nested call
 * throws -- it is expected to propagate to the parent's own top-level
 * handler.
 */
class DelegationService
{
    public function __construct(
        private readonly AgentQuery $agentQuery,
        private readonly AgentLoopService $agentLoopService,
        private readonly RunTraceRecorder $runTraceRecorder,
    ) {}

    /**
     * @return array<string, mixed> JSON-encodable -- either a refusal
     *   (`{"error": ..., "message": ...}`, nothing written) or the
     *   completed-delegation result shape (contracts/
     *   delegation-protocol-meta-tool.md).
     */
    public function delegate(Conversation $parentConversation, string $helperAgentId, string $task, ?string $context): array
    {
        if ($parentConversation->agent_id === null) {
            return [
                'error' => 'no_bound_agent',
                'message' => 'This conversation is not bound to an agent, so it has no assigned helpers to delegate to.',
            ];
        }

        $currentAgentId = $parentConversation->agent_id;
        $ownerUserId = (string) $parentConversation->user_id;

        // D8: only an already-assigned, still-active helper may receive a
        // delegation -- the assignment surviving the helper's own
        // deactivation is not enough on its own.
        $hasActiveAssignment = AgentHelperAssignment::where('parent_agent_id', $currentAgentId)
            ->where('helper_agent_id', $helperAgentId)
            ->whereNull('deleted_at')
            ->exists();

        $helperAgent = $hasActiveAssignment ? $this->agentQuery->findAgent($ownerUserId, $helperAgentId) : null;

        if (!$hasActiveAssignment || $helperAgent === null || $helperAgent->is_active === false) {
            $name = $helperAgent?->name ?? $helperAgentId;

            return [
                'error' => 'not_an_assigned_helper',
                'message' => "\"{$name}\" is not one of your assigned helpers.",
            ];
        }

        // D4: depth, computed and stored -- not yet enforced.
        $enclosingDelegation = Delegation::where('helper_conversation_id', $parentConversation->id)->latest()->first();
        $depth = $enclosingDelegation !== null ? $enclosingDelegation->depth + 1 : 1;

        // D1: a brand-new, isolated ephemeral conversation for this one
        // delegation -- the exact server/model resolution recipe
        // ConversationController::store() already uses for any other
        // agent-bound conversation.
        $resolution = app(RoleResolver::class)->resolve(ModelRole::Inference, $ownerUserId);
        $serverId = $resolution->hasEffectiveModel() ? $resolution->server->id : null;
        $modelName = $resolution->hasEffectiveModel() ? $resolution->model : null;

        $helperConversation = Conversation::create([
            'user_id' => $ownerUserId,
            'server_id' => $serverId,
            'model' => $modelName,
            'character' => 'Clarion',
            'channel' => 'agent-delegation',
            'agent_id' => $helperAgent->id,
            'agent_version_id' => $helperAgent->current_version_id,
        ]);

        $parentRunId = Context::get('run_id');

        $delegation = Delegation::create([
            'parent_conversation_id' => $parentConversation->id,
            'parent_agent_id' => $currentAgentId,
            'helper_agent_id' => $helperAgent->id,
            'helper_conversation_id' => $helperConversation->id,
            'helper_agent_version_id' => $helperAgent->current_version_id,
            'owner_user_id' => $ownerUserId,
            'task' => $task,
            'context' => $context,
            'depth' => $depth,
            'status' => 'in_progress',
            'parent_run_id' => $parentRunId,
            'started_at' => now(),
        ]);

        $actionId = $this->runTraceRecorder->openAction(
            $this->currentOpenStepId($parentRunId),
            ActionType::Delegation,
            $helperAgent->name,
        );

        if ($actionId !== null) {
            $delegation->parent_action_id = $actionId;
            $delegation->save();
        }

        $composedMessage = $this->composeSeedMessage($task, $context);

        // D6: the parent's own ambient run id must survive the nested
        // run() call below -- openRun()/closeRun() (inside the helper's
        // own run()) overwrite, then unconditionally clear, the ambient
        // Context slot with no awareness of nesting.
        $enclosingRunId = Context::get('run_id');

        try {
            $rawResult = $this->agentLoopService->run($helperConversation, $composedMessage);
        } finally {
            if ($enclosingRunId !== null) {
                Context::add('run_id', $enclosingRunId);
            }
        }

        // The run id the helper's own run() call opened -- not readable
        // from Context after the call returns (closeRun() has already
        // cleared it by then), so resolved from the run trace itself,
        // scoped to the ephemeral helper conversation (one delegation, one
        // nested run, never reused).
        $helperRunId = DB::table('agent_runs')
            ->where('conversation_id', $helperConversation->id)
            ->orderByDesc('created_at')
            ->value('id');

        $content = $rawResult['content'] ?? '';
        $delegation->helper_run_id = $helperRunId;

        if (($rawResult['status'] ?? null) === 'completed') {
            $delegation->status = 'completed';
            $delegation->completed_at = now();
        }

        $delegation->outcome_summary = Str::limit((string) $content, 500);
        $delegation->save();

        $this->runTraceRecorder->closeAction($actionId, ActionOutcome::Success);

        return [
            'status' => 'completed',
            'helper' => $helperAgent->name,
            'result' => $content,
        ];
    }

    /**
     * The currently open (still in_progress) step for a run, or null --
     * used to anchor the ActionType::Delegation action on the parent's
     * own current step (research.md D7). openAction()/closeAction() both
     * no-op gracefully on a null id, so a run with no open step (or with
     * tracing disabled entirely) is handled for free.
     */
    private function currentOpenStepId(?string $runId): ?string
    {
        if ($runId === null) {
            return null;
        }

        return DB::table('agent_run_steps')
            ->where('run_id', $runId)
            ->where('end_state', RunEndState::InProgress->value)
            ->orderByDesc('position')
            ->value('id');
    }

    /**
     * The helper's sole seed message -- the entire content that crosses
     * the isolation boundary (contracts/delegation-protocol-meta-tool.md).
     * Composed as plain text, never routed through appendBoundInstructions()
     * or any other shared formatting hook, so it rides through the
     * ordinary formatting/budgeting pipeline like any other message
     * content once run() persists it.
     */
    private function composeSeedMessage(string $task, ?string $context): string
    {
        $contextSection = ($context !== null && $context !== '') ? $context : '(none provided)';

        return "You are a helper agent carrying out a task delegated to you by another\n"
            ."agent. You can see only this task and the context below — nothing else\n"
            ."from the delegating agent's own conversation. Stay within the stated task;\n"
            ."do not expand your work beyond it. If you are missing information you need\n"
            ."to complete it, say so plainly rather than guessing or inventing an answer.\n"
            ."\n"
            ."## Task\n"
            .$task."\n"
            ."\n"
            ."## Context\n"
            .$contextSection;
    }
}
