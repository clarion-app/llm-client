<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\AgentHelperAssignment;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\ValueObjects\ActionOutcome;
use ClarionApp\LlmClient\ValueObjects\ActionType;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\RunKind;
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
    private ContentSanitizer $contentSanitizer;

    public function __construct(
        private readonly AgentQuery $agentQuery,
        private readonly AgentLoopService $agentLoopService,
        private readonly RunTraceRecorder $runTraceRecorder,
        ?ContentSanitizer $contentSanitizer = null,
    ) {
        $this->contentSanitizer = $contentSanitizer ?? app(ContentSanitizer::class);
    }

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

        // D4: depth, computed and enforced. Ordered by started_at, never a
        // bare latest(): agent_delegations has no created_at column (the
        // model is $timestamps = false with its own started_at/completed_at,
        // data-model.md §1), and Eloquent's argument-less latest() would
        // emit `order by created_at desc` -- silently tolerated by SQLite
        // (an unresolvable double-quoted identifier degrades to a string
        // literal) but a hard "Unknown column in 'order clause'" error on
        // MySQL/MariaDB, i.e. on every real delegation in production.
        $enclosingDelegation = Delegation::where('helper_conversation_id', $parentConversation->id)
            ->latest('started_at')
            ->first();
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
            // 099-result-aggregation (research.md D1): every delegated
            // helper's final answer is schema-validated against the
            // mandatory delegation_result shape, with its own retry ceiling
            // distinct from the delegation's own iteration/time bounds.
            'preset' => 'delegation_result',
            'retry_on_validation_failure' => true,
            'max_schema_retries' => config('llm-client.delegation.max_result_schema_retries', 2),
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
            // A helper run that opened and then threw still has its own,
            // fully-traced (Failed-closed) run row -- linking it here keeps
            // FR-012 recoverability true of a failed delegation too, not
            // just a completed one (data-model.md §1: helper_run_id is
            // "null only if run tracing is disabled").
            $delegation->helper_run_id = $this->helperRunIdFor($helperConversation->id);
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

        $content = $rawResult['content'] ?? '';
        $delegation->helper_run_id = $this->helperRunIdFor($helperConversation->id);

        // D3: a ceiling-reached return from the nested run() maps to a
        // terminal 'exhausted' Delegation.status -- distinct from a
        // genuine failure (Phase 6), never Failure on the trace row.
        $exhaustionReasons = [
            'max_iterations' => 'iteration_limit',
            'time_ceiling_reached' => 'time_limit',
        ];
        $code = $rawResult['code'] ?? null;

        if (($rawResult['status'] ?? null) === 'completed') {
            // 099-result-aggregation (data-model.md §3, research.md D3): the
            // schema-validated result -- not the raw content string -- is
            // the source of truth for every result_* column and for the
            // revised delegate_to_helper tool-result shape.
            $validated = $rawResult['validated'] ?? [];
            $validatedStatus = $validated['status'] ?? null;

            $reasonForStatus = [
                'success' => null,
                'partial' => 'helper_reported',
                'failure' => 'helper_reported',
            ];

            $resultStatus = $validatedStatus;
            $resultReason = $reasonForStatus[$validatedStatus] ?? null;
            $resultSummary = $validated['summary'] ?? null;
            $resultUndone = $validated['undone'] ?? '';

            if ($resultStatus === 'failure') {
                // FR-007: a failure never carries content that could be
                // mistaken for genuine output, regardless of whatever the
                // helper's own output object contained.
                $resultOutput = null;
                $resultTruncated = false;
                $decodedOutput = null;
            } else {
                $resultOutput = $this->contentSanitizer->truncate(
                    json_encode($validated['output'] ?? []),
                    config('llm-client.delegation.result_output_cap_bytes', 8192),
                );
                $resultTruncated = $this->contentSanitizer->isTruncated($resultOutput);
                $decodedOutput = json_decode($resultOutput, true);
            }

            $delegation->status = 'completed';
            $delegation->completed_at = now();
            $delegation->outcome_summary = Str::limit((string) $content, 500);
            $delegation->result_status = $resultStatus;
            $delegation->result_reason = $resultReason;
            $delegation->result_summary = $resultSummary;
            $delegation->result_output = $resultOutput;
            $delegation->result_undone = $resultUndone;
            $delegation->result_truncated = $resultTruncated;
            $delegation->save();

            $sixFieldResult = [
                'status' => $resultStatus,
                'summary' => $resultSummary,
                'output' => $decodedOutput,
                'undone' => $resultUndone,
                'truncated' => $resultTruncated,
                'reason' => $resultReason,
            ];

            $this->runTraceRecorder->closeAction($actionId, ActionOutcome::Success, null, json_encode($sixFieldResult));

            return array_merge(
                ['delegation_id' => $delegation->id, 'helper' => $helperAgent->name],
                $sixFieldResult,
            );
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

            // 099-result-aggregation (FR-016): a bound-exceeded delegation
            // is System-detected partial success -- result_output stays
            // null since schema validation was never reached on an
            // exhausted run (research.md D3).
            $resultSummary = Str::limit($partialResult, 500);
            $resultUndone = "Reached its {$incompleteBecause} before finishing.";

            $delegation->status = 'exhausted';
            $delegation->completed_at = now();
            $delegation->outcome_summary = $resultSummary;
            $delegation->result_status = 'partial';
            $delegation->result_reason = 'bound_exceeded';
            $delegation->result_output = null;
            $delegation->result_summary = $resultSummary;
            $delegation->result_undone = $resultUndone;
            $delegation->result_truncated = false;
            $delegation->save();

            $sixFieldResult = [
                'status' => 'partial',
                'summary' => $resultSummary,
                'output' => null,
                'undone' => $resultUndone,
                'truncated' => false,
                'reason' => 'bound_exceeded',
            ];

            $this->runTraceRecorder->closeAction(
                $actionId,
                ActionOutcome::Unfinished,
                null,
                json_encode($sixFieldResult),
            );

            return array_merge(
                ['delegation_id' => $delegation->id, 'helper' => $helperAgent->name],
                $sixFieldResult,
            );
        }

        // Neither completed nor a recognized ceiling: every other shape
        // run() can return without throwing -- 'No response from LLM' (a
        // provider reply with no choices), a 'confirmation_required' pause
        // no one can ever answer inside a delegated run, an
        // 'agent_access_revoked' stop -- is a non-completion, and must
        // reach the parent as one. Reporting it as 'completed' would hand
        // the parent a failure message dressed as a result and leave the
        // Delegation row permanently in_progress, contradicting both
        // FR-008/SC-004 and data-model.md §1's own "updated once to a
        // terminal state after it returns (or throws)".
        $failureReason = Str::limit(
            (string) ($content !== '' ? $content : 'The helper\'s run ended without producing a result.'),
            500,
        );

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
    }

    /**
     * The run id the helper's own run() call opened -- not readable from
     * Context after the call returns (closeRun() has already cleared it by
     * then), so resolved from the run trace itself, scoped to the ephemeral
     * helper conversation (one delegation, one nested run, never reused).
     */
    private function helperRunIdFor(string $helperConversationId): ?string
    {
        // Scoped to the INTERACTIVE run and to the OLDEST one, never simply
        // the newest run on the conversation: a helper conversation starts
        // untitled, so run()'s own first-exchange title generation opens a
        // second, system_initiated run against the very same conversation a
        // moment later. Taking the newest row therefore linked the title
        // job instead of the helper's own work -- pointing the RunDiagram
        // drill-down at the wrong trace, and (worse) breaking
        // DelegationQuery::costForRun()'s transitive walk, which looks for
        // further delegations whose parent_run_id is this id and would
        // never match a title run.
        return DB::table('agent_runs')
            ->where('conversation_id', $helperConversationId)
            ->where('kind', RunKind::Interactive->value)
            ->orderBy('created_at')
            ->value('id');
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
