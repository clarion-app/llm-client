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
 * research.md D1/D3/D4/D5/D6/D7/D8). Mirrors AgentHelperService's role as
 * the sole owner of its own table.
 *
 * Bounding (D3) caps the nested run() call's own iterations/deadline;
 * depth (D4) is computed, stored, and refused on before the nested call is
 * ever made; a try/catch/finally wraps the nested run() call itself -- the
 * catch (D5) maps any thrown exception to a terminal 'failed' Delegation
 * and a structured failure result instead of letting it propagate to the
 * parent's own loop, while the finally block unconditionally restores the
 * ambient run id (D6) regardless of which of try/catch ran.
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

        // D4: depth, computed and enforced.
        $enclosingDelegation = Delegation::where('helper_conversation_id', $parentConversation->id)->latest()->first();
        $depth = $enclosingDelegation !== null ? $enclosingDelegation->depth + 1 : 1;

        if ($depth > config('llm-client.delegation.max_chain_depth', 5)) {
            return [
                'error' => 'delegation_depth_exceeded',
                'message' => 'This delegation chain has reached its maximum depth and cannot delegate any further.',
            ];
        }

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

        // D3: the delegation-specific bounds -- an $options override on the
        // nested run() call only, never mutating the shared config the
        // parent's own outer loop reads.
        $delegationOptions = [
            'max_iterations' => config('llm-client.delegation.max_iterations', 10),
            'deadline_at' => now()->addSeconds(config('llm-client.delegation.max_seconds', 120)),
        ];

        try {
            $rawResult = $this->agentLoopService->run($helperConversation, $composedMessage, $delegationOptions);
        } catch (\Throwable $e) {
            // D5: a thrown exception from the nested run() call must never
            // propagate to the parent's own loop -- caught here and mapped
            // to a terminal 'failed' Delegation, matching the ordinary
            // ceiling/completion outcomes' own shape (FR-008/FR-012).
            $failureReason = Str::limit($e->getMessage(), 500);

            $delegation->status = 'failed';
            $delegation->completed_at = now();
            $delegation->outcome_summary = $failureReason;
            $delegation->save();

            $this->runTraceRecorder->closeAction($actionId, ActionOutcome::Failure, $failureReason);

            return [
                'status' => 'failed',
                'helper' => $helperAgent->name,
                'error' => $failureReason,
            ];
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

        // D3: a ceiling-reached return from the nested run() maps to a
        // terminal 'exhausted' Delegation.status -- distinct from a
        // genuine failure (Phase 6), never Failure on the trace row.
        $exhaustionReasons = [
            'max_iterations' => 'iteration_limit',
            'time_ceiling_reached' => 'time_limit',
        ];
        $code = $rawResult['code'] ?? null;

        if (($rawResult['status'] ?? null) === 'completed') {
            $delegation->status = 'completed';
            $delegation->completed_at = now();
            $delegation->outcome_summary = Str::limit((string) $content, 500);
            $delegation->save();

            $this->runTraceRecorder->closeAction($actionId, ActionOutcome::Success);

            return [
                'status' => 'completed',
                'helper' => $helperAgent->name,
                'result' => $content,
            ];
        }

        if ($code !== null && array_key_exists($code, $exhaustionReasons)) {
            $incompleteBecause = $exhaustionReasons[$code];

            // The helper's own last assistant content, if any, else empty
            // (contracts/delegation-protocol-meta-tool.md) -- every prior
            // iteration's assistant/tool messages are already persisted on
            // the helper's own conversation by the time run() returns.
            $partialResult = (string) ($helperConversation->messages()
                ->where('role', 'assistant')
                ->orderByDesc('created_at')
                ->value('content') ?? '');

            $delegation->status = 'exhausted';
            $delegation->completed_at = now();
            $delegation->outcome_summary = Str::limit($partialResult, 500);
            $delegation->save();

            $this->runTraceRecorder->closeAction(
                $actionId,
                ActionOutcome::Unfinished,
                null,
                "Delegation exhausted its {$incompleteBecause} before the helper could finish.",
            );

            return [
                'status' => 'exhausted',
                'helper' => $helperAgent->name,
                'partial_result' => $partialResult,
                'incomplete_because' => $incompleteBecause,
            ];
        }

        // Neither completed nor a recognized ceiling -- left in_progress,
        // a known, disclosed limitation until Phase 6 (US5) adds failure
        // catching around the nested run() call.
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
