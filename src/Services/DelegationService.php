<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Exceptions\SchemaValidationError;
use ClarionApp\LlmClient\Jobs\RunDelegationBatchMemberJob;
use ClarionApp\LlmClient\Models\Agent;
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
 *
 * 101-parallel-subagent-execution split this method's original body into
 * four private extractions -- resolveAndValidate() (refusal checks +
 * depth), createDelegationRow() (the ephemeral helper Conversation, the
 * Delegation row, and the ActionType::Delegation action open), and
 * runDelegatedTask() (composing the seed message, the nested run() call,
 * and every outcome-to-result mapping) -- plus one new public entry point,
 * runBatchMember(), so delegateBatch()/RunDelegationBatchMemberJob can
 * reuse the identical row-creation and per-member execution paths a solo
 * delegate() call already used, without a second, independently-maintained
 * copy of either recipe (contracts §1, research.md D1/D2).
 */
class DelegationService
{
    /** research.md D4 layer 2: the shared bound a batch member that never
     *  even reaches in_progress is held to -- see joinWait()'s own doc. */
    private const JOIN_WAIT_GRACE_SECONDS = 5;

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
        $resolved = $this->resolveAndValidate($parentConversation, $helperAgentId);
        if (isset($resolved['error'])) {
            return $resolved;
        }

        $delegation = $this->createDelegationRow(
            $parentConversation,
            $resolved['helperAgent'],
            $resolved['depth'],
            $task,
            $context,
            'in_progress',
            null,
        );

        $helperConversation = Conversation::find($delegation->helper_conversation_id);

        return $this->runDelegatedTask($delegation, $resolved['helperAgent'], $helperConversation);
    }

    /**
     * 101-parallel-subagent-execution (US1, contracts §1, research.md
     * D1/D3, tasks.md T019). $calls is the ordered list of one turn
     * iteration's delegate_to_helper tool calls, each
     * `{tool_call_id, helper_agent_id, task, context}`.
     *
     * Every call is validated up front via resolveAndValidate() -- a
     * refusal is recorded immediately, keyed by that call's own
     * tool_call_id, with no Delegation row and no job ever dispatched for
     * it. Every valid call gets its row created via the shared
     * createDelegationRow(), status 'queued', sharing one batch_id
     * generated once per invocation. Only once every valid row exists are
     * the jobs dispatched, so the per-batch ceiling is always evaluated
     * against the batch's true final size.
     *
     * @param array<int, array{tool_call_id: string, helper_agent_id: string, task: string, context: ?string}> $calls
     * @return array<string, array<string, mixed>> keyed by, and in, the
     *   original $calls order (contracts §1's ordering guarantee) --
     *   regardless of which member actually finished first.
     */
    public function delegateBatch(Conversation $parentConversation, array $calls): array
    {
        $results = [];
        $validRows = [];
        $batchId = null;

        foreach ($calls as $call) {
            $toolCallId = $call['tool_call_id'];

            $resolved = $this->resolveAndValidate($parentConversation, $call['helper_agent_id']);
            if (isset($resolved['error'])) {
                $results[$toolCallId] = $resolved;
                continue;
            }

            if ($batchId === null) {
                $batchId = (string) Str::uuid();
            }

            $validRows[$toolCallId] = $this->createDelegationRow(
                $parentConversation,
                $resolved['helperAgent'],
                $resolved['depth'],
                $call['task'],
                $call['context'] ?? null,
                'queued',
                $batchId,
            );
        }

        if (!empty($validRows)) {
            $queue = config('llm-client.delegation.concurrency.queue', 'delegation-batches');

            // Every valid row already exists BEFORE any job is dispatched
            // -- the per-batch/installation ceiling counts DelegationConcurrencyGate
            // evaluates are always against the batch's true final size.
            foreach ($validRows as $delegation) {
                RunDelegationBatchMemberJob::dispatch($delegation->id)->onQueue($queue);
            }

            $this->joinWait($validRows);

            foreach ($validRows as $toolCallId => $delegation) {
                $results[$toolCallId] = $this->sixFieldResultFromRow(Delegation::find($delegation->id));
            }
        }

        // Reassemble in the ORIGINAL $calls order regardless of the order
        // refusals/completions landed in $results above.
        $ordered = [];
        foreach ($calls as $call) {
            $ordered[$call['tool_call_id']] = $results[$call['tool_call_id']];
        }

        return $ordered;
    }

    /**
     * 101-parallel-subagent-execution (T018d): the public entry point
     * RunDelegationBatchMemberJob::handle() calls once DelegationConcurrencyGate
     * has admitted this member. The row and its helper Agent/Conversation
     * already exist by this point -- created up front by delegateBatch()
     * via the shared createDelegationRow() -- so this loads them back and
     * runs the exact same per-member execution path (runDelegatedTask())
     * a solo delegate() call already uses, guaranteeing identical
     * partial-failure handling and identical effort/spend accounting
     * (research.md D4/D5) between the batch and solo paths.
     */
    public function runBatchMember(Delegation $delegation): void
    {
        $helperAgent = $this->agentQuery->findAgent($delegation->owner_user_id, $delegation->helper_agent_id)
            ?? Agent::withTrashed()->find($delegation->helper_agent_id);
        $helperConversation = Conversation::find($delegation->helper_conversation_id);

        $this->runDelegatedTask($delegation, $helperAgent, $helperConversation);
    }

    /**
     * 101-parallel-subagent-execution (T017/contracts §5): the job's own
     * failed() hook -- fired when the queue worker kills the job for
     * exceeding its own $timeout, or when an exception escapes handle()
     * before runDelegatedTask()'s own try/catch ever gets a chance to
     * write a terminal row. No-op against a row that is already terminal
     * by some other path (runDelegatedTask() itself already finished, or
     * the parent's own join-wait deadline / the stale-batch sweep already
     * force-finalized it).
     */
    public function recordBatchMemberTimeoutOrFailure(string $delegationId, \Throwable $e): void
    {
        $delegation = Delegation::find($delegationId);
        if ($delegation === null || in_array($delegation->status, ['completed', 'exhausted', 'failed'], true)) {
            return;
        }

        $reason = str_contains(strtolower($e->getMessage()), 'timeout') || str_contains(strtolower(get_class($e)), 'timeout')
            ? 'timeout'
            : 'exception';

        $resultSummary = $reason === 'timeout'
            ? 'The delegation was terminated for exceeding its own time limit.'
            : 'The delegation failed due to an unexpected error.';
        $resultUndone = 'Everything -- the task could not be completed.';

        $delegation->status = 'failed';
        $delegation->completed_at = now();
        $delegation->outcome_summary = Str::limit($e->getMessage(), 500);
        $delegation->result_status = 'failure';
        $delegation->result_reason = $reason;
        $delegation->result_summary = $resultSummary;
        $delegation->result_output = null;
        $delegation->result_undone = $resultUndone;
        $delegation->result_truncated = false;
        $delegation->save();

        if ($delegation->parent_action_id !== null) {
            $sixFieldResult = [
                'status' => 'failure',
                'summary' => $resultSummary,
                'output' => null,
                'undone' => $resultUndone,
                'truncated' => false,
                'reason' => $reason,
            ];

            $this->runTraceRecorder->closeAction(
                $delegation->parent_action_id,
                ActionOutcome::Failure,
                Str::limit($e->getMessage(), 500),
                json_encode($sixFieldResult),
            );
        }
    }

    /**
     * 101-parallel-subagent-execution (research.md D4 layer 2, contracts
     * §1): force-finalizes a batch member that never reached a terminal
     * status within delegateBatch()'s own join-wait bound, or that
     * llm-client:resolve-stalled-delegation-batches finds still stale.
     * Idempotent against a row that is already terminal by the time either
     * caller reaches it (the parent's own join-wait deadline check and the
     * sweep can both race to finalize the same row).
     */
    public function forceFinalizeBatchJoinTimeout(Delegation $delegation): void
    {
        if (in_array($delegation->status, ['completed', 'exhausted', 'failed'], true)) {
            return;
        }

        $resultSummary = 'The batch join-wait deadline passed before this member reached a terminal status.';
        $resultUndone = 'Everything -- the task could not be completed.';

        $delegation->status = 'exhausted';
        $delegation->completed_at = now();
        $delegation->outcome_summary = $resultSummary;
        $delegation->result_status = 'failure';
        $delegation->result_reason = 'batch_join_timeout';
        $delegation->result_summary = $resultSummary;
        $delegation->result_output = null;
        $delegation->result_undone = $resultUndone;
        $delegation->result_truncated = false;
        $delegation->save();

        if ($delegation->parent_action_id !== null) {
            $sixFieldResult = [
                'status' => 'failure',
                'summary' => $resultSummary,
                'output' => null,
                'undone' => $resultUndone,
                'truncated' => false,
                'reason' => 'batch_join_timeout',
            ];

            $this->runTraceRecorder->closeAction(
                $delegation->parent_action_id,
                ActionOutcome::Unfinished,
                null,
                json_encode($sixFieldResult),
            );
        }
    }

    /**
     * 101-parallel-subagent-execution (T018a): the refusal checks +
     * depth computation shared by delegate() and delegateBatch() -- the
     * original delegate()'s own L52-99, unchanged in behavior.
     *
     * @return array{error: string, message: string}|array{helperAgent: Agent, depth: int}
     */
    private function resolveAndValidate(Conversation $parentConversation, string $helperAgentId): array
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

        return ['helperAgent' => $helperAgent, 'depth' => $depth];
    }

    /**
     * 101-parallel-subagent-execution (T018b): the ephemeral helper
     * Conversation creation, the Delegation row creation, and the
     * ActionType::Delegation action open -- the original delegate()'s own
     * L101-145, now the ONE shared write path for row+action creation so
     * delegate()/delegateBatch() cannot silently drift apart.
     * Parameterized on $status/$batchId: delegate() calls this with
     * status: 'in_progress'/batchId: null (its own existing behavior,
     * unchanged); delegateBatch() calls it with status: 'queued'/batchId:
     * the batch's own freshly-generated id.
     */
    private function createDelegationRow(
        Conversation $parentConversation,
        Agent $helperAgent,
        int $depth,
        string $task,
        ?string $context,
        string $status,
        ?string $batchId,
    ): Delegation {
        $ownerUserId = (string) $parentConversation->user_id;

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
            'parent_agent_id' => $parentConversation->agent_id,
            'helper_agent_id' => $helperAgent->id,
            'helper_conversation_id' => $helperConversation->id,
            'helper_agent_version_id' => $helperAgent->current_version_id,
            'owner_user_id' => $ownerUserId,
            'task' => $task,
            'context' => $context,
            'depth' => $depth,
            'status' => $status,
            'batch_id' => $batchId,
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

        return $delegation;
    }

    /**
     * 101-parallel-subagent-execution (T018c): composing the seed
     * message, the try/catch/finally around the nested run() call, and
     * every outcome-to-Delegation-column/six-field-result mapping -- the
     * original delegate()'s own L147-467, unchanged in behavior, now
     * called by delegate() for the solo path exactly as before and by
     * runBatchMember() for a concurrent batch member.
     *
     * @return array<string, mixed>
     */
    private function runDelegatedTask(Delegation $delegation, Agent $helperAgent, Conversation $helperConversation): array
    {
        $actionId = $delegation->parent_action_id;

        $composedMessage = $this->composeSeedMessage($delegation->task, $delegation->context);

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

            // 099-result-aggregation (research.md D2, checked FIRST, before
            // the generic \Throwable handling below): a SchemaValidationError
            // means the helper's own final answer either never conformed to
            // the mandatory delegation_result schema, or was empty --
            // getRawContent() is the only field that distinguishes the two
            // (User Story 5 AC1 vs AC2).
            if ($e instanceof SchemaValidationError) {
                $resultReason = trim($e->getRawContent()) === '' ? 'no_output' : 'malformed_output';
                $resultSummary = $resultReason === 'no_output'
                    ? 'The helper produced no output at all.'
                    : "The helper's output did not conform to the required result schema.";
            } else {
                $resultReason = 'exception';
                $resultSummary = 'The delegation failed due to an unexpected error.';
            }

            // FR-007: a failure never carries content that could be mistaken
            // for genuine output -- no content was ever produced on either
            // of these paths, so result_output stays null.
            $resultUndone = 'Everything -- the task could not be completed.';

            $delegation->result_status = 'failure';
            $delegation->result_reason = $resultReason;
            $delegation->result_summary = $resultSummary;
            $delegation->result_output = null;
            $delegation->result_undone = $resultUndone;
            $delegation->result_truncated = false;
            $delegation->save();

            $sixFieldResult = [
                'status' => 'failure',
                'summary' => $resultSummary,
                'output' => null,
                'undone' => $resultUndone,
                'truncated' => false,
                'reason' => $resultReason,
            ];

            $this->runTraceRecorder->closeAction($actionId, ActionOutcome::Failure, $failureReason, json_encode($sixFieldResult));

            return array_merge(
                ['delegation_id' => $delegation->id, 'helper' => $helperAgent->name],
                $sixFieldResult,
            );
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

                // 099-result-aggregation Phase 7 gap fix (tasks.md T048,
                // contracts §2's invariant): the tool-result's own `output`
                // key must come from the PRE-truncation validated array,
                // never from json_decode()-ing the (possibly truncated)
                // stored string -- truncation can cut mid-JSON-object, and
                // json_decode() on a cut string silently returns null,
                // which would make a truncated success/partial result's
                // `output` indistinguishable from a genuine failure's
                // `output: null`. The DB column ($resultOutput, above)
                // stays truncated exactly as before; only the in-memory
                // tool-result's `output` changes source.
                $decodedOutput = $validated['output'] ?? [];
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

        // 100-subagent-tool-restrictions (US4, research.md D5, contracts
        // §4): a 'confirmation_required' return from the nested run() call
        // means the helper's own only viable next operation required
        // explicit confirmation -- something no one is present to answer
        // inside a delegated run. This must be diagnosable as its own
        // distinct result_reason, not folded into the generic 'no_output'
        // fallback below (which would make it indistinguishable from a
        // provider reply with no choices at all). The operation was never
        // executed on this path, so result_output stays null exactly like
        // every other failure branch (FR-007).
        if (($rawResult['status'] ?? null) === 'confirmation_required') {
            $resultSummary = 'This action requires your explicit confirmation and could not be completed automatically.';
            $resultUndone = 'Everything -- the task could not be completed.';

            $delegation->status = 'failed';
            $delegation->completed_at = now();
            $delegation->outcome_summary = $resultSummary;
            $delegation->result_status = 'failure';
            $delegation->result_reason = 'confirmation_required';
            $delegation->result_output = null;
            $delegation->result_summary = $resultSummary;
            $delegation->result_undone = $resultUndone;
            $delegation->result_truncated = false;
            $delegation->save();

            $sixFieldResult = [
                'status' => 'failure',
                'summary' => $resultSummary,
                'output' => null,
                'undone' => $resultUndone,
                'truncated' => false,
                'reason' => 'confirmation_required',
            ];

            $this->runTraceRecorder->closeAction($actionId, ActionOutcome::Failure, $resultSummary, json_encode($sixFieldResult));

            return array_merge(
                ['delegation_id' => $delegation->id, 'helper' => $helperAgent->name],
                $sixFieldResult,
            );
        }

        // Neither completed nor a recognized ceiling: every other shape
        // run() can return without throwing -- 'No response from LLM' (a
        // provider reply with no choices), an 'agent_access_revoked' stop
        // -- is a non-completion, and must reach the parent as one.
        // Reporting it as 'completed' would hand the parent a failure
        // message dressed as a result and leave the Delegation row
        // permanently in_progress, contradicting both FR-008/SC-004 and
        // data-model.md §1's own "updated once to a terminal state after
        // it returns (or throws)".
        $failureReason = Str::limit(
            (string) ($content !== '' ? $content : 'The helper\'s run ended without producing a result.'),
            500,
        );

        // 099-result-aggregation (Grounding note item 6, research.md D3):
        // the provider-returned-no-choices-at-all case (and any other
        // non-completing, non-ceiling-coded return) is reported as
        // result_reason: 'no_output' -- schema validation was never reached
        // on this path either, so result_output stays null (FR-007).
        $resultSummary = 'The helper\'s run ended without producing a result.';
        $resultUndone = 'Everything -- the task could not be completed.';

        $delegation->status = 'failed';
        $delegation->completed_at = now();
        $delegation->outcome_summary = $failureReason;
        $delegation->result_status = 'failure';
        $delegation->result_reason = 'no_output';
        $delegation->result_output = null;
        $delegation->result_summary = $resultSummary;
        $delegation->result_undone = $resultUndone;
        $delegation->result_truncated = false;
        $delegation->save();

        $sixFieldResult = [
            'status' => 'failure',
            'summary' => $resultSummary,
            'output' => null,
            'undone' => $resultUndone,
            'truncated' => false,
            'reason' => 'no_output',
        ];

        $this->runTraceRecorder->closeAction($actionId, ActionOutcome::Failure, $failureReason, json_encode($sixFieldResult));

        return array_merge(
            ['delegation_id' => $delegation->id, 'helper' => $helperAgent->name],
            $sixFieldResult,
        );
    }

    /**
     * 101-parallel-subagent-execution (research.md D1/D3/D4 layer 2,
     * contracts §1): bounded-polls $validRows (keyed by tool_call_id) until
     * every row reaches a terminal status, then force-finalizes whatever
     * is still not, unconditionally -- matching research.md D4 layer 2
     * verbatim: "If any member is still non-terminal when this deadline
     * passes, the parent force-finalizes it directly... and then proceeds
     * exactly as if that member had reported failure on its own." There is
     * no carve-out for the case where NO member of the batch ever showed
     * any progress at all (Phase 7 Polish reconciliation, 2026-08-14):
     * an earlier revision of this method returned early without
     * finalizing anything whenever every row was still 'queued' at the
     * deadline, reasoning that it could not distinguish "the queue is
     * genuinely down" from "nothing has looked at this batch yet". That
     * distinction is real, but it does not justify skipping
     * force-finalization -- doing so left every row 'queued' indefinitely
     * (until the scheduled resolve-stalled-delegation-batches sweep,
     * layer 3, eventually got to it, up to stale_after_minutes later) while
     * delegateBatch() itself still returned immediately once its own
     * bounded deadline passed, reconstructing a six-field result from a
     * row that was never terminal -- every result_* column null, since
     * they are only ever populated on a terminal transition. That is a
     * dishonest, not merely delayed, account of the member's outcome
     * (violates FR-004/FR-005/FR-010's "the parent must eventually receive
     * a COMBINED result", not a combined result standing in front of rows
     * still mid-flight). Whether the queue is down or merely hasn't looked
     * yet, this method's own bounded deadline (below) has already passed
     * either way, so the correct action is the same one layer 2 always
     * takes for a non-terminal row at its deadline: force-finalize it
     * honestly as 'exhausted'/'batch_join_timeout'. The scheduled sweep
     * (layer 3) remains the backstop for the case THIS layer cannot cover
     * at all -- the parent's own process dying before this method ever
     * gets to run its own deadline check -- not for the case where it does
     * run but finds zero progress.
     *
     * The bound is deliberately NOT a flat `now() + max_seconds + grace`
     * measured from dispatch time -- under a real async worker (or a test
     * double simulating one, e.g. a selectively-faked Bus job), a member
     * that is admitted late but still within a legitimate wait for a
     * ceiling slot to free (FR-006) must not be punished for the time it
     * spent merely queued. Instead: a row still 'queued' is bounded by a
     * SMALL, fixed grace period alone ("queue pickup latency the
     * per-member $timeout itself does not need to account for, since that
     * timer only starts once a worker actually begins the job"); once a
     * row is observed 'in_progress' or terminal, ITS OWN deadline (if
     * still running) becomes admission-time + the per-member max_seconds +
     * the same grace period, tracked from the moment this loop first
     * observes it. This grace-period bound applies identically whether one
     * member of the batch has shown progress or none has -- the wait is
     * never open-ended in either case.
     *
     * @param array<string, Delegation> $validRows
     */
    private function joinWait(array $validRows): void
    {
        $ids = array_values(array_map(fn (Delegation $d) => $d->id, $validRows));
        if (empty($ids)) {
            return;
        }

        $maxSeconds = (int) config('llm-client.delegation.max_seconds', 120);
        $graceSeconds = self::JOIN_WAIT_GRACE_SECONDS;
        $pollIntervalMs = max(1, (int) config('llm-client.delegation.concurrency.join_poll_interval_ms', 200));

        $admissionDeadline = now()->addSeconds($graceSeconds);
        $runDeadlines = [];

        while (true) {
            $rows = Delegation::whereIn('id', $ids)->get(['id', 'status']);
            $now = now();
            $anyStillWaiting = false;

            foreach ($rows as $row) {
                if (in_array($row->status, ['completed', 'exhausted', 'failed'], true)) {
                    continue;
                }

                if ($row->status === 'in_progress') {
                    if (!isset($runDeadlines[$row->id])) {
                        $runDeadlines[$row->id] = $now->copy()->addSeconds($maxSeconds + $graceSeconds);
                    }
                    if ($now->lt($runDeadlines[$row->id])) {
                        $anyStillWaiting = true;
                    }
                    continue;
                }

                // Still 'queued' -- bounded by the shared admission grace
                // period alone, per this method's own doc above.
                if ($now->lt($admissionDeadline)) {
                    $anyStillWaiting = true;
                }
            }

            if (!$anyStillWaiting) {
                break;
            }

            usleep($pollIntervalMs * 1000);
        }

        // Unconditional: force-finalize whatever is still non-terminal once
        // the loop's own bounded deadline passes, regardless of whether any
        // member of the batch ever showed progress (see this method's own
        // doc above -- the zero-progress case is not a reason to leave rows
        // 'queued' indefinitely).
        $stillPending = Delegation::whereIn('id', $ids)
            ->whereIn('status', ['queued', 'in_progress'])
            ->get();

        foreach ($stillPending as $delegation) {
            $this->forceFinalizeBatchJoinTimeout($delegation);
        }
    }

    /**
     * 101-parallel-subagent-execution: converts a terminal Delegation row's
     * own result_* columns back into the six-field delegate_to_helper
     * tool-result shape (contracts §1) plus delegation_id/helper -- the
     * same shape runDelegatedTask() returns in-process, reconstructed here
     * from the persisted row because a batch member's own execution
     * happens inside a separate job dispatch, decoupled from
     * delegateBatch()'s own return.
     */
    private function sixFieldResultFromRow(Delegation $delegation): array
    {
        $decodedOutput = $delegation->result_output !== null ? json_decode($delegation->result_output, true) : null;

        $helperName = Agent::withTrashed()->find($delegation->helper_agent_id)?->name ?? $delegation->helper_agent_id;

        return [
            'delegation_id' => $delegation->id,
            'helper' => $helperName,
            'status' => $delegation->result_status,
            'summary' => $delegation->result_summary,
            'output' => $decodedOutput,
            'undone' => $delegation->result_undone,
            'truncated' => (bool) $delegation->result_truncated,
            'reason' => $delegation->result_reason,
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
