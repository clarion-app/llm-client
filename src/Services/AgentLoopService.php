<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Contracts\ProviderType;
use ClarionApp\LlmClient\Exceptions\BudgetExceededException;
use ClarionApp\LlmClient\Exceptions\RateLimitExceededException;
use ClarionApp\LlmClient\Exceptions\PresetNotFoundException;
use ClarionApp\LlmClient\Exceptions\SchemaValidationError;
use ClarionApp\LlmClient\Exceptions\UnattendedActionRefusedException;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\ConversationHandoff;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\ManagedTask;
use ClarionApp\LlmClient\Models\ManagedTaskPart;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\McpSession;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\TaskWorkspaceEntry;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\SchemaValidator;
use ClarionApp\LlmClient\Services\StructuredOutputPresetRegistry;
use ClarionApp\LlmClient\Contracts\MemoryService as MemoryServiceContract;
use ClarionApp\LlmClient\Contracts\MemoryScope;
use ClarionApp\LlmClient\Contracts\EpisodicMemoryService as EpisodicMemoryServiceContract;
use ClarionApp\LlmClient\Contracts\DeclarativeMemoryService as DeclarativeMemoryServiceContract;
use ClarionApp\LlmClient\Events\AgentTurnCompleted;
use ClarionApp\LlmClient\Events\SchedulerTriggerRunRefused;
use ClarionApp\LlmClient\Services\MetricsRecorder;
use ClarionApp\LlmClient\ValueObjects\ContextManagementOutcome;
use ClarionApp\LlmClient\ValueObjects\ToolFailureCategory;
use ClarionApp\LlmClient\Services\AutoMemoryRetriever;
use ClarionApp\LlmClient\Services\ContextWindowBudgeter;
use ClarionApp\LlmClient\Services\ConversationCondenser;
use ClarionApp\LlmClient\Services\ToolResultCondenser;
use ClarionApp\LlmClient\ValueObjects\MemoryInjectionSection;
use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\ClarionPackageServiceProvider;
use ClarionApp\HttpQueue\HttpRequest;
use ClarionApp\LlmClient\ValueObjects\Operation;
use ClarionApp\LlmClient\ValueObjects\ActionOutcome;
use ClarionApp\LlmClient\ValueObjects\ActionType;
use ClarionApp\LlmClient\ValueObjects\BudgetWorkKind;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use ClarionApp\LlmClient\ValueObjects\RunRelation;
use ClarionApp\LlmClient\ValueObjects\ConversationWorkDecision;
use ClarionApp\LlmClient\ValueObjects\DegradationDecision;
use ClarionApp\LlmClient\ValueObjects\RateLimitDecision;
use Illuminate\Support\Str;
use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use GuzzleHttp\Client;

class AgentLoopService
{
    /**
     * The operationId of the page/text fetch operation (feature 111, US1).
     *
     * Results of this operation are wrapped in a source envelope (source.url +
     * untrusted-wrapped content) so the consulted-source manifest can be derived
     * from the run trace, and so the fetched body is treated as untrusted data.
     *
     * Public because AgentLoopStreamHandler records the same envelope on the
     * streaming path and must recognise the same operation.
     */
    public const PAGE_TEXT_OPERATION_ID = 'clarionApp.llmClient.fetchPage.getTextFromUrl';

    /**
     * The operationId prefix every coding-workspace operation shares
     * (112-coding-agent, Foundational, D2, data-model.md §4). Used only by
     * enforceCodingProjectBinding() below — checked before, and
     * independent of, isOperationPermitted()/isConfirmationRequired(), so
     * a misconfigured tools.allow can never leak cross-project access.
     */
    private const CODING_WORKSPACE_OPERATION_PREFIX = 'clarionApp.llmClient.codingWorkspace.';

    /**
     * Resolved operationId strings (112-coding-agent, tasks.md T013) for
     * the two confirmed file mutations. Public, mirroring
     * PAGE_TEXT_OPERATION_ID's own visibility, because RunTraceQuery's
     * run-trace change-report fallback (US1, data-model.md §6) reads the
     * same identifiers back out of the action content these two
     * operationIds are recorded under (see
     * codingWorkspaceChangeActionContent() below).
     */
    public const CODING_WORKSPACE_WRITE_FILE_OPERATION_ID = 'clarionApp.llmClient.codingWorkspace.writeFile';
    public const CODING_WORKSPACE_DELETE_FILE_OPERATION_ID = 'clarionApp.llmClient.codingWorkspace.deleteFile';

    private McpToolRegistry $toolRegistry;
    private McpToolExecutor $toolExecutor;
    private OperationCache $operationCache;
    private ProviderRegistry $providerRegistry;
    private MessageFormatter $messageFormatter;
    private ToolFormatter $toolFormatter;
    private SchemaValidator $schemaValidator;
    private ?StructuredOutputPresetRegistry $presetRegistry;
    private ?MemoryServiceContract $memoryService;
    private ?EpisodicMemoryServiceContract $episodicMemoryService;
    private ?DeclarativeMemoryServiceContract $declarativeMemoryService;
    private ContextWindowBudgeter $contextWindowBudgeter;
    private ?ConversationCondenser $conversationCondenser;
    private ?ToolResultCondenser $toolResultCondenser;
    private PreferenceInjector $preferenceInjector;
    private ?AutoMemoryRetriever $autoMemoryRetriever;
    private ?MetricsRecorder $metricsRecorder;
    private ?RunTraceRecorder $runTraceRecorder;
    private ConversationAgentDefinitionResolver $agentDefinitionResolver;
    private EffectiveBoundResolver $effectiveBoundResolver;
    private TaskWorkspaceQuery $taskWorkspaceQuery;
    private RunTraceQuery $runTraceQuery;
    private OwnerScopedResultFilter $ownerScopedResultFilter;

    /**
     * The raw McpToolExecutor::executeHttpCall()-shaped outcome of the
     * most recently dispatched execute_operation call (set in
     * executeApiCall(), cleared before every attempt). Consulted only by
     * dispatchExecuteOperationWithRetry() immediately after a dispatch
     * returns, to judge whether that attempt is worth retrying
     * (RetryEligibility::isTransient()) -- never read anywhere else, and
     * never populated for a call that never reached dispatch (a
     * validation refusal, an unknown operation, a coding-workspace
     * binding rejection).
     */
    private ?array $lastOperationDispatchOutcome = null;

    public function __construct(
        McpToolRegistry $toolRegistry,
        McpToolExecutor $toolExecutor,
        OperationCache $operationCache,
        ?ProviderRegistry $providerRegistry = null,
        ?MessageFormatter $messageFormatter = null,
        ?ToolFormatter $toolFormatter = null,
        ?SchemaValidator $schemaValidator = null,
        ?StructuredOutputPresetRegistry $presetRegistry = null,
        ?MemoryServiceContract $memoryService = null,
        ?EpisodicMemoryServiceContract $episodicMemoryService = null,
        ?DeclarativeMemoryServiceContract $declarativeMemoryService = null,
        ?ContextWindowBudgeter $contextWindowBudgeter = null,
        ?ConversationCondenser $conversationCondenser = null,
        ?ToolResultCondenser $toolResultCondenser = null,
        ?PreferenceInjector $preferenceInjector = null,
        ?AutoMemoryRetriever $autoMemoryRetriever = null,
        ?MetricsRecorder $metricsRecorder = null,
        ?RunTraceRecorder $runTraceRecorder = null,
        ?ConversationAgentDefinitionResolver $agentDefinitionResolver = null,
        ?EffectiveBoundResolver $effectiveBoundResolver = null,
        ?TaskWorkspaceQuery $taskWorkspaceQuery = null,
        ?RunTraceQuery $runTraceQuery = null,
        ?OwnerScopedResultFilter $ownerScopedResultFilter = null
    ) {
        $this->toolRegistry = $toolRegistry;
        $this->toolExecutor = $toolExecutor;
        $this->operationCache = $operationCache;
        $this->providerRegistry = $providerRegistry ?? new ProviderRegistry();
        $this->messageFormatter = $messageFormatter ?? new MessageFormatter();
        $this->toolFormatter = $toolFormatter ?? new ToolFormatter();
        $this->schemaValidator = $schemaValidator ?? new SchemaValidator();
        $this->presetRegistry = $presetRegistry;
        $this->memoryService = $memoryService;
        $this->episodicMemoryService = $episodicMemoryService;
        $this->declarativeMemoryService = $declarativeMemoryService;
        $this->contextWindowBudgeter = $contextWindowBudgeter ?? new ContextWindowBudgeter();
        $this->conversationCondenser = $conversationCondenser;
        $this->toolResultCondenser = $toolResultCondenser;
        $this->preferenceInjector = $preferenceInjector ?? new PreferenceInjector();
        $this->autoMemoryRetriever = $autoMemoryRetriever;
        $this->metricsRecorder = $metricsRecorder;
        $this->runTraceRecorder = $runTraceRecorder;
        $this->agentDefinitionResolver = $agentDefinitionResolver ?? new ConversationAgentDefinitionResolver(new AgentDefinitionParser());
        $this->effectiveBoundResolver = $effectiveBoundResolver ?? new EffectiveBoundResolver(new AgentHelperQuery(new AgentQuery(new AgentDefinitionParser()), new AgentDefinitionParser()));
        $this->taskWorkspaceQuery = $taskWorkspaceQuery ?? new TaskWorkspaceQuery(new ManagedTaskQuery());
        $this->runTraceQuery = $runTraceQuery ?? new RunTraceQuery();
        $this->ownerScopedResultFilter = $ownerScopedResultFilter ?? new OwnerScopedResultFilter();
    }

    /**
     * Milliseconds a step spent waiting on a human confirmation (FR-004, SC-012).
     *
     * Derived from the `paused_at` stamp written beside the pending confirmation.
     * Pre-`paused_at` messages fall back to the message's own creation time, which
     * is exact on the synchronous path — there the message is created at the pause.
     */
    private function confirmationWaitMs(array $toolData, Message $message): ?int
    {
        if (($toolData['step_id'] ?? null) === null) {
            return null;
        }

        $pausedAt = isset($toolData['paused_at'])
            ? Carbon::parse($toolData['paused_at'])
            : $message->created_at;

        if ($pausedAt === null) {
            return null;
        }

        return max(0, (int) round($pausedAt->diffInMilliseconds(now(), false)));
    }

    /**
     * The user-initiated funnel: every way a person starts model work goes
     * through here before anything is written or opened.
     *
     * Ordering is the whole contract. The gate has to run before
     * is_processing is set and before a run is opened, because a refusal that
     * happens after either one leaves the conversation wedged with an open
     * run and no path that clears it.
     *
     * @param  string|null  $existingRunId  the run a resumed conversation
     *   arrives already carrying. Passing it means a refusal CLOSES that run
     *   rather than opening a second one.
     */
    private function admitInteractiveWork(
        Conversation $conversation,
        BudgetWorkKind $kind,
        ?string $existingRunId = null,
    ): void {
        // Rate limit first: it is strictly cheaper than the budget check
        // even in its best case (zero database queries when nothing is
        // configured for the user, versus a ceiling-resolution query), and
        // the two limits are orthogonal, so there is no "most restrictive
        // governs" ordering to get backwards — whichever throws first is
        // what the caller sees.
        //
        // Skipped entirely, rather than passed as null, when the
        // conversation has no owning user: RateLimitGate::admit() takes a
        // non-nullable $userId, unlike BudgetGate::admit(), because there is
        // no installation-wide axis for a rate limit to fall back to — a
        // conversation with no owning user has no user whose allowance
        // could be consumed.
        $rateLimitDecision = null;
        if ($conversation->user_id !== null) {
            $rateLimitDecision = app(RateLimitGate::class)->admit(
                (string) $conversation->user_id,
                $kind,
                $conversation->id,
            );
        }

        $budgetDecision = app(BudgetGate::class)->admit(
            $conversation->user_id === null ? null : (string) $conversation->user_id,
            $kind,
            $conversation->id,
            null,
            $existingRunId,
        );

        // The one call site DegradationGate::evaluate() is ever reached
        // from (085-graceful-degradation, research.md D1) — immediately
        // after both admits above have already succeeded, so this can
        // never itself refuse the request (FR-003/SC-005 hold by
        // construction, not by a check this call performs). The decision
        // is stored on the gate's own scoped instance for
        // RunTraceRecorder::openRun()'s linkRun() call to read back a few
        // lines later in this same request/job.
        app(DegradationGate::class)->evaluate(
            $conversation->user_id === null ? null : (string) $conversation->user_id,
            $conversation->id,
            $rateLimitDecision ?? RateLimitDecision::noLimitConfigured(),
            $budgetDecision,
        );
    }

    /**
     * Admit a conversation being resumed after a confirmation pause, and
     * leave nothing behind if it is refused.
     *
     * Both halves of the cleanup are required and neither is optional:
     * is_processing is cleared so the conversation is usable again — exactly
     * as the "confirmation has expired" branch a few lines below already does
     * — and the inherited run id is handed to the gate so the refusal closes
     * that run stopped_early instead of opening a second one. Without them a
     * ceiling crossed during a human's pause leaves the conversation
     * permanently flagged as processing, with a run only the abandonment
     * sweep will ever close.
     */
    private function admitResumedWork(Conversation $conversation, ?string $runId): void
    {
        try {
            $this->admitInteractiveWork($conversation, BudgetWorkKind::Resumed, $runId);
        } catch (BudgetExceededException $e) {
            $conversation->update(['is_processing' => false]);

            throw $e;
        } catch (RateLimitExceededException $e) {
            // Identical cleanup, second exception type: a rate-limit
            // refusal during a resumed conversation must leave it usable
            // again, exactly as a spending-ceiling refusal already does.
            // Unlike BudgetExceededException, this writes no run record of
            // its own — the run the conversation inherited is left open
            // rather than closed as stopped_early, since a rate limit has
            // no operator-visible run record to close.
            $conversation->update(['is_processing' => false]);

            throw $e;
        }
    }

    public function start(
        Conversation $conversation,
        int $iteration = 1,
        ?string $runId = null,
        ?string $triggerMessageId = null,
    ): void {
        // Gated only when this call MINTS a run. The condition is load-bearing
        // in both directions, and both mistakes look reasonable in review:
        //
        //  - AgentLoopStreamHandler re-enters start($conversation,
        //    $iteration + 1, $this->runId) on every streaming iteration with
        //    the run id carried forward, so a non-null $runId *is* the
        //    "already executing" signal. Gating unconditionally would abandon
        //    a response the user is already reading, which the spec calls out
        //    as worse than no enforcement at all.
        //  - Dropping the gate entirely would let the streamed entry path walk
        //    straight past the ceiling, since a new run is exactly what a new
        //    request mints.
        if ($runId === null) {
            $this->admitInteractiveWork($conversation, BudgetWorkKind::Interactive);

            // Revocation check (096-agent-sharing, research.md D5) — same
            // reasoning as run()'s own entry. start() is void/streamed, so
            // the close-out happens directly and this method simply returns
            // without dispatching a stream request for a turn that never
            // begins.
            if ($this->checkSharedAgentAccessRevoked($conversation) !== null) {
                return;
            }

            // Automatic routing (102-router-pattern, US1, research.md D2) —
            // a no-op unless this is genuinely the conversation's first
            // turn and no agent is already bound.
            $this->attemptInitialRouting($conversation, Message::find($triggerMessageId)?->content ?? '');
            $this->ensureSpecialistAvailable($conversation);
        }

        // The user is engaging again, so this session is live: clear any end
        // marker set by the idle sweep, making the session eligible to end
        // (and be captured) again once it next goes quiet.
        $conversation->update(['is_processing' => true, 'ended_at' => null]);

        // Open or continue a run trace. A null $runId mints a new run (contracts §3.3).
        if ($this->runTraceRecorder !== null) {
            if ($runId === null) {
                $runId = $this->runTraceRecorder->openRun(
                    RunKind::Interactive,
                    (string) $conversation->user_id,
                    $conversation->id,
                    streamed: true,
                    model: $conversation->model,
                    agentId: $conversation->character ?? $conversation->id,
                );
                // Link the trigger message when minting a new run.
                if ($triggerMessageId !== null && $runId !== null) {
                    $this->runTraceRecorder->linkMessage(
                        $runId,
                        $triggerMessageId,
                        RunRelation::Trigger,
                    );
                }
            }
        }

        // Resolved once per dispatch, before buildToolsPayload()/
        // applyContextWindowTrim() are called (085-graceful-degradation,
        // tasks.md T001 point 1) — never re-evaluated inside this method,
        // so the whole response uses one frozen decision (FR-006/SC-003).
        $decision = app(DegradationGate::class)->forRun($runId);

        $tools = $this->buildToolsPayload($decision->withheldTools, $conversation);
        $formattedTools = $this->formatTools($conversation, $tools);
        $rawMessages = $this->buildMessagesPayload($conversation, $runId);

        // Measure context window trim timing for retroactive action recording.
        $reshapeTuple = null;
        if ($this->runTraceRecorder !== null) {
            $trimStartedAt = new \DateTimeImmutable();
            $trimmed = $this->applyContextWindowTrim($conversation, $rawMessages, null, $decision);
            $trimEndedAt = new \DateTimeImmutable();
            $reshapeTuple = [
                'started_at' => $trimStartedAt,
                'ended_at' => $trimEndedAt,
            ];
        } else {
            $trimmed = $this->applyContextWindowTrim($conversation, $rawMessages, null, $decision);
        }

        $formatted = $this->formatMessages($conversation, $trimmed);

        $this->dispatchStreamRequest($conversation, $formatted['messages'], $formattedTools, $iteration, $formatted['system'], null, $runId, $reshapeTuple, $decision->effectiveModel, $decision->effectiveServerId);
    }

    public function resume(Conversation $conversation, Message $message, bool $approved): void
    {
        $toolData = $message->tool_data;
        $pending = $toolData['pending_confirmation'] ?? null;
        $runId = $toolData['run_id'] ?? null;

        // A confirmation pause is a person deciding, not work executing, and
        // the ceiling can be crossed during it — so resuming is new model work
        // as far as enforcement is concerned, whether the call was approved or
        // declined (declining still continues the loop, telling the model what
        // happened). The cleanup either side of the throw is what run()/start()
        // do not need: this method is entered with is_processing already true
        // and a run already open, so "gate before anything is set" is not
        // available to it. Mirrors the expired-confirmation branch just below.
        $this->admitResumedWork($conversation, $runId);

        // Revocation check (096-agent-sharing, research.md D5) — the async
        // confirmation-continuation path must be refused identically to
        // resumeSync()'s own sibling call below; omitting it here would let
        // a revoked recipient's approved/declined tool call keep executing.
        // A run/step is already open at this point (the original turn's),
        // so this closes it in place rather than minting a new one.
        if ($this->checkSharedAgentAccessRevoked($conversation, $runId, $toolData['step_id'] ?? null) !== null) {
            return;
        }

        // Automatic routing / unavailability fallback (102-router-pattern,
        // US1/US4, research.md D2/D7) — mirrors resumeSync()'s own identical
        // pair of calls at the same relative point. This is the *production*
        // confirmation-continuation path (ConversationController::
        // confirmApiCall() calls resume(), never resumeSync() — confirmed no
        // controller/job calls resumeSync() at all); omitting these calls
        // here would let a confirmation approved after its bound specialist
        // was deactivated during the pending window (up to
        // confirmation_timeout, default 300s — the same time-window
        // reasoning the ancestor-chain re-check just above already applies)
        // execute and keep answering under the deactivated agent's identity
        // indefinitely, since no other call site in this turn would ever
        // trigger the fallback handoff. attemptInitialRouting() is a no-op
        // here in the overwhelming majority of cases (a pending confirmation
        // implies a prior turn already bound the conversation), but is
        // included for the same defensive-symmetry reason
        // checkSharedAgentAccessRevoked() is present at every entry point
        // regardless of whether it is expected to fire.
        $this->attemptInitialRouting(
            $conversation,
            Message::where('conversation_id', $conversation->id)->where('role', 'user')->first()?->content ?? '',
        );
        $this->ensureSpecialistAvailable($conversation);

        if (!$pending) {
            throw new \RuntimeException('No pending confirmation found on this message.');
        }

        // Check for expiration
        $expiresAt = Carbon::parse($pending['expires_at']);
        if ($expiresAt->isPast()) {
            $conversation->update(['is_processing' => false]);
            throw new \RuntimeException('Confirmation has expired.');
        }

        $toolCallId = $toolData['tool_calls'][0]['id'] ?? null;
        $iteration = ($toolData['iteration'] ?? 1) + 1;

        // 112-coding-agent (US1, data-model.md §6): only ever set by the
        // default execute_operation/api_call pause branch in
        // AgentLoopStreamHandler (which now stores it alongside run_id/
        // step_id). Used narrowly below to resolve the coding-workspace
        // ToolInvocation action this confirmation paused, so the
        // run-trace change-report fallback has a real path/operationId to
        // read back once the call is approved and executed.
        $inboundActionId = $toolData['action_id'] ?? null;

        // Close the step that spanned the confirmation pause, recording the human
        // wait portion (FR-004, SC-012). The continuation's own step is opened by
        // dispatchStreamRequest() below, so the streamed path records the same
        // shape the synchronous path does (FR-008).
        $pendingStepId = $toolData['step_id'] ?? null;
        if ($this->runTraceRecorder !== null && $pendingStepId !== null) {
            $this->runTraceRecorder->closeStep(
                $pendingStepId,
                RunEndState::Completed,
                null,
                $this->confirmationWaitMs($toolData, $message),
            );
        }

        $confirmationType = $pending['confirmation_type'] ?? 'api_call';

        // 112-coding-agent (US1, data-model.md §6): see resumeSync()'s
        // identical tracking variable, immediately above the same
        // relative point in that sibling method — a chain-check rejection
        // below (approved by the user, but blocked by a since-narrowed
        // ancestor permission) must never be recorded as an executed
        // change either.
        $apiCallExecuted = false;

        if ($approved) {
            if ($confirmationType === 'declarative_memory') {
                // Route declarative_memory proposals to applyAgentWrite with confirmation
                $type = $pending['type'] ?? '';
                $content = $pending['content'] ?? '';
                $existingId = $pending['existingId'] ?? null;

                if ($this->declarativeMemoryService !== null && $type && $content) {
                    $entry = $this->declarativeMemoryService->applyAgentWrite(
                        $conversation->user_id,
                        $type,
                        $content,
                        true,
                        $existingId
                    );
                    $resultContent = json_encode([
                        'id' => $entry->id,
                        'type' => $entry->type,
                        'content' => $entry->content,
                        'source' => $entry->source,
                        'created' => true,
                    ]);
                } else {
                    $resultContent = json_encode(['error' => 'Declarative memory service not available']);
                }

                $condensed = $this->condenseToolResult($resultContent, $conversation->id, 'declarative_memory');
                $toolData['tool_results'] = [
                    ['tool_call_id' => $toolCallId, 'content' => $condensed['content']] + array_filter($condensed, fn ($k) => in_array($k, ['reference_id', 'original_tokens', 'condensed_tokens', 'method', 'condensed']), ARRAY_FILTER_USE_KEY),
                ];
            } elseif ($confirmationType === 'scope_surface') {
                // Approving the aggregate scope acknowledgment never
                // itself writes or deletes the file that triggered it —
                // scope-surfacing is additive, never a replacement for the
                // per-file gate. The tool result tells the model the scope
                // was confirmed so it reissues that same file's operation,
                // which this time reaches its own ordinary api_call
                // confirmation (scopeSurfaceStateForRun() below is now
                // marked surfaced for this run, via the action content
                // this branch closes $inboundActionId with).
                $resultContent = json_encode([
                    'scope_confirmed' => true,
                    'files_touched_so_far' => $pending['files_touched_so_far'] ?? [],
                    'would_add' => $pending['would_add'] ?? null,
                    'message' => 'Scope confirmed. Reissue the file operation to proceed with its own confirmation.',
                ]);

                $condensed = $this->condenseToolResult($resultContent, $conversation->id, 'execute_api_call');
                $toolData['tool_results'] = [
                    ['tool_call_id' => $toolCallId, 'content' => $condensed['content']] + array_filter($condensed, fn ($k) => in_array($k, ['reference_id', 'original_tokens', 'condensed_tokens', 'method', 'condensed']), ARRAY_FILTER_USE_KEY),
                ];
            } elseif ($confirmationType === 'external_tool') {
                // 116-mcp-client-support (Foundational, research.md D6):
                // re-resolved from the local cache by operationId rather
                // than trusted from whatever the pause itself carried --
                // the same "never trust a value that sat pending" posture
                // the ancestor-chain re-check just below (the default
                // branch) already applies for a built-in operation. A miss
                // here (the tool vanished from its server, or the server
                // itself did, during the pending window) fails cleanly and
                // locally, the identical wording handleExecuteOperation()
                // already returns for a syntactically-external id with no
                // matching row.
                $externalTool = \ClarionApp\LlmClient\Models\McpClientTool::findBySyntheticId($pending['operationId'] ?? '');
                $externalServer = $externalTool?->server;

                if ($externalTool === null || $externalServer === null) {
                    $resultContent = json_encode([
                        'error' => 'This tool is no longer offered by its server. Search again for a current capability.',
                    ]);
                } else {
                    $resultContent = json_encode(app(McpClientToolExecutor::class)->execute($externalServer, $externalTool, $pending['arguments'] ?? []));
                    $apiCallExecuted = true;
                }

                $condensed = $this->condenseToolResult($resultContent, $conversation->id, 'execute_api_call');
                $toolData['tool_results'] = [
                    ['tool_call_id' => $toolCallId, 'content' => $condensed['content']] + array_filter($condensed, fn ($k) => in_array($k, ['reference_id', 'original_tokens', 'condensed_tokens', 'method', 'condensed']), ARRAY_FILTER_USE_KEY),
                ];
            } else {
                // Re-check the ancestor-chain bound at the moment of
                // execution (100-subagent-tool-restrictions, FR-004/
                // FR-005/FR-006) rather than trusting the bound that held
                // when the confirmation was first requested: a
                // confirmation can sit pending for up to
                // confirmation_timeout (default 300s), during which a
                // parent's permissions may have been narrowed. Unlike
                // handleExecuteOperation(), this method never routes
                // through it at all when resuming, so without this check
                // an already-approved, now-out-of-bounds helper call
                // would execute with zero live re-validation.
                $chain = $this->effectiveBoundResolver->check($conversation, $pending['operationId']);

                if (!$chain['allowed']) {
                    $resultContent = json_encode([
                        'error' => "Operation not permitted: ancestor agent \"{$chain['blocking_agent_name']}\" ({$chain['levels_up']} level(s) up in this delegation chain) does not permit \"{$pending['operationId']}\".",
                    ]);
                } else {
                    // Execute the confirmed API operation
                    $resultContent = $this->executeApiCall(
                        $pending['operationId'],
                        $pending['method'],
                        $pending['path'],
                        $pending['arguments'] ?? [],
                        $conversation
                    );
                    $apiCallExecuted = true;
                }

                $condensed = $this->condenseToolResult($resultContent, $conversation->id, 'execute_api_call');
                $toolData['tool_results'] = [
                    ['tool_call_id' => $toolCallId, 'content' => $condensed['content']] + array_filter($condensed, fn ($k) => in_array($k, ['reference_id', 'original_tokens', 'condensed_tokens', 'method', 'condensed']), ARRAY_FILTER_USE_KEY),
                ];
            }
        } else {
            $toolData['tool_results'] = [
                ['tool_call_id' => $toolCallId, 'content' => 'User cancelled this operation.'],
            ];
        }

        // 112-coding-agent (US1, data-model.md §6): resolve the inbound
        // coding-workspace ToolInvocation action this confirmation paused
        // — scoped narrowly to the two mutation operationIds so every
        // other confirmed operation's behavior on this (streaming,
        // production) path is completely unchanged; $inboundActionId is
        // only ever non-null for the api_call branch AgentLoopStreamHandler
        // now stamps it on. Mirrors resumeSync()'s own resolution of its
        // inbound action, immediately above the same relative point in
        // that sibling method.
        if ($this->runTraceRecorder !== null && $inboundActionId !== null
            && in_array($pending['operationId'] ?? '', [
                self::CODING_WORKSPACE_WRITE_FILE_OPERATION_ID,
                self::CODING_WORKSPACE_DELETE_FILE_OPERATION_ID,
            ], true)
        ) {
            if ($approved) {
                // An approved scope_surface confirmation is closed with a
                // distinct content shape ({confirmation_type, approved})
                // rather than the ordinary {operationId, path} change
                // shape, so RunTraceQuery::scopeSurfaceStateForRun() can
                // tell "this run already had its aggregate scope approved"
                // apart from "this run has a confirmed file change" — the
                // file itself was never written by this approval (no
                // executeApiCall() call above), so recording it as a
                // change would be wrong.
                $content = $confirmationType === 'scope_surface'
                    ? json_encode(['confirmation_type' => 'scope_surface', 'approved' => true])
                    : ($apiCallExecuted ? $this->codingWorkspaceChangeActionContent($pending['operationId'], $pending['arguments'] ?? []) : null);

                $this->runTraceRecorder->closeAction(
                    $inboundActionId,
                    ActionOutcome::Success,
                    null,
                    $content,
                );
            } else {
                $this->runTraceRecorder->closeAction(
                    $inboundActionId,
                    ActionOutcome::Failure,
                    'User declined',
                );
            }
        }

        $toolData['pending_confirmation'] = null;
        $message->update(['tool_data' => $toolData]);

        // Continue the agent loop. Resolved once, before buildToolsPayload()/
        // applyContextWindowTrim() (085-graceful-degradation, tasks.md T001
        // point 1) — resume() never mints a run of its own, so this reads
        // back whatever the original start()/run() dispatch decided.
        $decision = app(DegradationGate::class)->forRun($runId);

        $tools = $this->buildToolsPayload($decision->withheldTools, $conversation);
        $formattedTools = $this->formatTools($conversation, $tools);
        $rawMessages = $this->buildMessagesPayload($conversation, $runId);

        // Measure context window trim timing for retroactive action recording.
        $reshapeTuple = null;
        if ($this->runTraceRecorder !== null) {
            $trimStartedAt = new \DateTimeImmutable();
            $trimmed = $this->applyContextWindowTrim($conversation, $rawMessages, null, $decision);
            $trimEndedAt = new \DateTimeImmutable();
            $reshapeTuple = [
                'started_at' => $trimStartedAt,
                'ended_at' => $trimEndedAt,
            ];
        } else {
            $trimmed = $this->applyContextWindowTrim($conversation, $rawMessages, null, $decision);
        }

        $formatted = $this->formatMessages($conversation, $trimmed);
        $this->dispatchStreamRequest($conversation, $formatted['messages'], $formattedTools, $iteration, $formatted['system'], null, $runId, $reshapeTuple, $decision->effectiveModel, $decision->effectiveServerId);
    }

    /**
     * The close-out a conversation work ceiling stop performs, shared by
     * every in-loop call site in this class (the tool-call loop and the
     * schema-validation retry branch in run(), and resumeSync()'s own
     * tool-call loop): identical in shape to the existing max_iterations
     * clean stop each of those methods already reaches at the bottom of
     * its own loop, so a conversation stopped for this reason stays
     * coherent and resumable the exact same way.
     *
     * $toolCalls/$toolResults are empty for the schema-validation retry
     * call site, which has no tool call in flight to answer at all.
     */
    private function stopForConversationWorkCeiling(
        Conversation $conversation,
        ?string $runId,
        ?string $currentStepId,
        array $toolCalls,
        array $toolResults,
        int $iteration,
        ConversationWorkDecision $workDecision,
    ): array {
        $assistantMessage = Message::create([
            'conversation_id' => $conversation->id,
            'content' => $workDecision->reason ?? '',
            'role' => 'assistant',
            'user' => $conversation->character,
            'responseTime' => 0,
            'tool_data' => empty($toolCalls) ? null : [
                'tool_calls' => $toolCalls,
                'tool_results' => $toolResults,
                'iteration' => $iteration,
                'pending_confirmation' => null,
            ],
        ]);

        $conversation->update(['is_processing' => false]);

        if ($this->runTraceRecorder !== null && $runId !== null) {
            if ($currentStepId !== null) {
                $this->runTraceRecorder->closeStep(
                    $currentStepId,
                    RunEndState::StoppedEarly,
                    $workDecision->reason,
                );
            }
            $this->runTraceRecorder->closeRun(
                $runId,
                RunEndState::StoppedEarly,
                $workDecision->reason,
            );
        }

        return [
            'status' => 'stopped',
            'content' => $workDecision->reason,
            'message_id' => $assistantMessage->id,
            'code' => 'conversation_work_ceiling_reached',
        ];
    }

    /**
     * The close-out a withheld-tool refusal performs, shared by every
     * in-loop call site in this class — mirrors
     * stopForConversationWorkCeiling()'s exact shape (085-graceful-
     * degradation, research.md D6): the tool the model attempted is not
     * executed, every not-yet-executed call in the batch already has a
     * synthesized tool_result by the time this is called, and the run
     * closes StoppedEarly with a distinct refusal message/code — a
     * capability the current reduced mode does not offer is refused
     * outright, never silently completed without it (FR-008/FR-009).
     */
    private function stopForWithheldTool(
        Conversation $conversation,
        ?string $runId,
        ?string $currentStepId,
        array $toolCalls,
        array $toolResults,
        int $iteration,
        DegradationDecision $decision,
    ): array {
        $reason = $decision->composeWithheldToolRefusal();

        $assistantMessage = Message::create([
            'conversation_id' => $conversation->id,
            'content' => $reason,
            'role' => 'assistant',
            'user' => $conversation->character,
            'responseTime' => 0,
            'tool_data' => [
                'tool_calls' => $toolCalls,
                'tool_results' => $toolResults,
                'iteration' => $iteration,
                'pending_confirmation' => null,
            ],
        ]);

        $conversation->update(['is_processing' => false]);

        if ($this->runTraceRecorder !== null && $runId !== null) {
            if ($currentStepId !== null) {
                $this->runTraceRecorder->closeStep(
                    $currentStepId,
                    RunEndState::StoppedEarly,
                    $reason,
                );
            }
            $this->runTraceRecorder->closeRun(
                $runId,
                RunEndState::StoppedEarly,
                $reason,
            );
        }

        return [
            'status' => 'stopped',
            'content' => $reason,
            'message_id' => $assistantMessage->id,
            'code' => 'degradation_capability_required',
        ];
    }

    /**
     * Refuse a turn on a shared (not owned) agent whose access grant has
     * been revoked since the conversation began (096-agent-sharing,
     * research.md D5, data-model.md §8, Phase 5/US3).
     *
     * A new, distinct method — never folded into admitInteractiveWork()/
     * admitResumedWork(), both of which stay void and throw-only and are
     * otherwise unmodified by this feature. Called directly from each of
     * run()'s, start()'s, resume()'s and resumeSync()'s own admission
     * points, immediately after whichever of admitInteractiveWork()/
     * admitResumedWork() that call site already reaches. Never throws: a
     * revoked-access refusal for a conversation already underway must be a
     * clean, in-band stop (contracts §5), not an HTTP 4xx/5xx.
     *
     * Only relevant when the conversation's agent is not owned by the
     * conversation's own user — an owned agent (the overwhelmingly common
     * case) or an agent-less conversation returns null immediately without
     * ever touching agent_share_grants.
     *
     * Mirrors stopForWithheldTool()'s exact close-out shape: an assistant
     * Message explaining the withdrawal, is_processing cleared, and
     * ConversationLifecycleService::end() called so the session itself
     * ends. A run/step already open (the resume()/resumeSync() call sites,
     * continuing after a confirmation pause) is closed StoppedEarly in
     * place; a turn refused before any run exists yet (run()'s/start()'s
     * brand-new-turn entry, $runId null) still gets its own run record —
     * minted and immediately closed StoppedEarly — so the refusal is never
     * invisible to the run trace.
     */
    private function checkSharedAgentAccessRevoked(
        Conversation $conversation,
        ?string $runId = null,
        ?string $currentStepId = null,
    ): ?array {
        if ($conversation->agent_id === null) {
            return null;
        }

        $agent = Agent::find($conversation->agent_id);

        if ($agent === null || $agent->user_id === $conversation->user_id) {
            return null;
        }

        if (app(AgentQuery::class)->findAccessibleAgent((string) $conversation->user_id, $conversation->agent_id) !== null) {
            return null;
        }

        $content = 'Access to this agent has been withdrawn by its owner. This conversation has ended.';
        $reason = 'agent_access_revoked: '.$content;

        $assistantMessage = Message::create([
            'conversation_id' => $conversation->id,
            'content' => $content,
            'role' => 'assistant',
            'user' => $conversation->character,
            'responseTime' => 0,
        ]);

        $conversation->update(['is_processing' => false]);

        if ($this->runTraceRecorder !== null) {
            if ($runId === null) {
                $runId = $this->runTraceRecorder->openRun(
                    RunKind::Interactive,
                    (string) $conversation->user_id,
                    $conversation->id,
                    streamed: false,
                    model: $conversation->model,
                    agentId: $conversation->character ?? $conversation->id,
                );
            } elseif ($currentStepId !== null) {
                $this->runTraceRecorder->closeStep(
                    $currentStepId,
                    RunEndState::StoppedEarly,
                    $reason,
                );
            }

            $this->runTraceRecorder->closeRun(
                $runId,
                RunEndState::StoppedEarly,
                $reason,
            );
        }

        app(ConversationLifecycleService::class)->end($conversation);

        return [
            'status' => 'stopped',
            'content' => $content,
            'message_id' => $assistantMessage->id,
            'code' => 'agent_access_revoked',
        ];
    }

    /**
     * Automatic routing to the right specialist for a conversation's very
     * first turn (102-router-pattern, US1, contracts/routing-mechanism.md
     * §2, research.md D2). A no-op unless every precondition holds:
     * $conversation->agent_id is still null, the conversation has a real
     * owner, and exactly one 'role = user' message exists for it (i.e. this
     * genuinely is the first turn, so this never re-evaluates on a later
     * message).
     *
     * Phase 3 wires only RouterService::route()'s steps 1-4 (no
     * default-handler fallback yet — Phase 6's own scope). A decision with
     * an agent binds conversation.agent_id/agent_version_id/routing_reason
     * in one call; a 'none' decision writes nothing, leaving the
     * conversation exactly as unbound as before this method ran.
     */
    private function attemptInitialRouting(Conversation $conversation, string $triggerText): void
    {
        if ($conversation->agent_id !== null || $conversation->user_id === null) {
            return;
        }

        $userMessageCount = Message::where('conversation_id', $conversation->id)
            ->where('role', 'user')
            ->count();

        if ($userMessageCount !== 1) {
            return;
        }

        $decision = app(RouterService::class)->route((string) $conversation->user_id, $triggerText);

        if ($decision->hasAgent()) {
            $conversation->update([
                'agent_id' => $decision->agentId,
                'agent_version_id' => $decision->agentVersionId,
                'routing_reason' => $decision->reason,
            ]);
        }
    }

    /**
     * D7's automatic unavailability fallback (102-router-pattern, US4,
     * contracts/routing-mechanism.md §3): when the conversation's own
     * currently-effective agent (via ConversationHandoff::
     * currentAgentIdentityFor(), which already resolves the latest handoff
     * over the conversation's original agent_id) has since been
     * deactivated, route to a fallback and record it as a new handoff row
     * with reason 'unavailable' — rather than letting a deactivated agent
     * silently keep answering, or the conversation stall.
     *
     * A no-op when the conversation has no real owner, when there is no
     * currently-effective agent identity at all, or when the
     * currently-effective agent is still active (the overwhelming common
     * case, including immediately after attemptInitialRouting() itself
     * binds a fresh — and therefore always active — agent).
     *
     * Excludes every agent already in this conversation's own handoff
     * chain (093's own cycle-prevention query, reused verbatim) plus the
     * now-unavailable agent itself. When the chain is already at its
     * configured max length, this degrades to a no-op rather than writing
     * past the bound (the documented last-resort degrade, research.md D7)
     * — mirroring handleHandoffToAgent()'s own chain-bound check, but
     * silent here since there is no caller-facing tool response to return
     * an error through.
     */
    private function ensureSpecialistAvailable(Conversation $conversation): void
    {
        if ($conversation->user_id === null) {
            return;
        }

        $current = ConversationHandoff::currentAgentIdentityFor($conversation);

        if ($current['agent_id'] === null) {
            return;
        }

        $currentAgent = Agent::find($current['agent_id']);

        if ($currentAgent === null || $currentAgent->is_active !== false) {
            return;
        }

        $chainLength = ConversationHandoff::where('conversation_id', $conversation->id)->count();

        if ($chainLength >= config('llm-client.handoff.max_chain_length', 5)) {
            return;
        }

        $excludeAgentIds = ConversationHandoff::where('conversation_id', $conversation->id)
            ->pluck('to_agent_id')
            ->push($conversation->agent_id)
            ->push($currentAgent->id)
            ->filter()
            ->all();

        $decision = app(RouterService::class)->route(
            (string) $conversation->user_id,
            $this->getLastUserMessage($conversation)?->content ?? '',
            $excludeAgentIds,
        );

        if (!$decision->hasAgent()) {
            return;
        }

        $target = Agent::find($decision->agentId);

        if ($target === null) {
            return;
        }

        $this->writeHandoffRow($conversation, $target, 'unavailable');
    }

    /**
     * Synchronous agent loop execution for external channel integrations.
     * Returns the final response array or a confirmation-required structure.
     *
     * @param Conversation $conversation The conversation context.
     * @param string $message The user message.
     * @param array $options Optional: ['preset' => 'decision', 'preset_params' => [...], 'schema_overrides' => [...], 'schema' => [...], 'retry_on_validation_failure' => bool, 'max_schema_retries' => int]
     */
    public function run(Conversation $conversation, string $message, array $options = []): array
    {
        // Trigger-fired work (a scheduler run with nobody present) carries
        // its own work kind and run kind throughout this method, never a
        // second, separate code path -- every existing caller that passes
        // no 'unattended' key keeps today's exact behaviour, since the two
        // conditionals below both fall back to the same values they always
        // hard-coded.
        $unattended = (bool) ($options['unattended'] ?? false);

        // First statement, before is_processing is set and before a run is
        // opened: a refusal here has to be a clean no-op, and there is no path
        // that unwinds either of those for work that never started.
        $this->admitInteractiveWork($conversation, $unattended ? BudgetWorkKind::SystemInitiated : BudgetWorkKind::Interactive);

        // Revocation check (096-agent-sharing, research.md D5): a shared
        // agent whose grant has since been revoked refuses the turn here,
        // cleanly, before is_processing is set and before a run is opened —
        // identical reasoning to admitInteractiveWork() just above.
        if (($revokedStop = $this->checkSharedAgentAccessRevoked($conversation)) !== null) {
            return $revokedStop;
        }

        // The user is engaging again, so this session is live: clear any end
        // marker set by the idle sweep, making the session eligible to end
        // (and be captured) again once it next goes quiet.
        $conversation->update(['is_processing' => true, 'ended_at' => null]);

        // Open a run trace record immediately after is_processing update.
        $runId = null;
        if ($this->runTraceRecorder !== null) {
            $runId = $this->runTraceRecorder->openRun(
                $unattended
                    ? \ClarionApp\LlmClient\ValueObjects\RunKind::SystemInitiated
                    : \ClarionApp\LlmClient\ValueObjects\RunKind::Interactive,
                (string) $conversation->user_id,
                $conversation->id,
                streamed: false,
                model: $conversation->model,
                agentId: $conversation->character ?? $conversation->id,
            );
        }

        // Resolved once per dispatch, before buildToolsPayload()/
        // applyContextWindowTrim() are called (085-graceful-degradation,
        // tasks.md T001 point 1) — never re-evaluated inside this method's
        // own loop, so every iteration of this response uses one frozen
        // decision (FR-006/SC-003).
        $decision = app(DegradationGate::class)->forRun($runId);

        // Resolve preset schema if a preset name is specified
        $presetName = $options['preset'] ?? null;
        $presetParams = $options['preset_params'] ?? null;
        $schemaOverrides = $options['schema_overrides'] ?? null;
        $presetSystemPrompt = '';

        if ($presetName && $this->presetRegistry !== null) {
            try {
                $resolvedSchema = $this->presetRegistry->resolveSchema($presetName, $presetParams, $schemaOverrides);
                // If no explicit schema was provided, use the resolved preset schema
                if (empty($options['schema'])) {
                    $options['schema'] = $resolvedSchema;
                }
                // Fetch the preset's system prompt for injection
                $preset = $this->presetRegistry->find($presetName);
                $presetSystemPrompt = $preset->getSystemPrompt();
            } catch (PresetNotFoundException $e) {
                throw new \RuntimeException(sprintf('Structured output preset "%s" not found. %s', $presetName, $e->getMessage()));
            }
        } elseif (!empty($schemaOverrides) && $this->presetRegistry !== null) {
            // schema_overrides without a preset name — treat as error
            throw new \RuntimeException('schema_overrides requires a preset name. Specify "preset" option.');
        }

        // Create the user message
        $userMessage = Message::create([
            'conversation_id' => $conversation->id,
            'content' => $message,
            'role' => 'user',
            'user' => 'User',
            'responseTime' => 0,
        ]);

        // Link the trigger message after it is created (not at openRun).
        if ($this->runTraceRecorder !== null && $runId !== null) {
            $this->runTraceRecorder->linkMessage(
                $runId,
                $userMessage->id,
                RunRelation::Trigger,
            );
        }

        // Automatic routing (102-router-pattern, US1, research.md D2) — a
        // no-op unless this is genuinely the conversation's first turn and
        // no agent is already bound.
        $this->attemptInitialRouting($conversation, $message);
        $this->ensureSpecialistAvailable($conversation);

        $maxIterations = $options['max_iterations'] ?? config('llm-client.agent_loop.max_iterations', 20);
        $deadlineAt = $options['deadline_at'] ?? null;
        $tools = $this->buildToolsPayload($decision->withheldTools, $conversation);
        $formattedTools = $this->formatTools($conversation, $tools);

        $shouldValidate = $this->schemaValidator->shouldValidate($options);
        $retryOnValidationFailure = $options['retry_on_validation_failure'] ?? false;
        $maxSchemaRetries = $options['max_schema_retries'] ?? config('llm-client.schema_validation.max_retries', 2);
        $schemaRetryCount = 0;
        $correctionPromptBuilder = new CorrectionPromptBuilder();

        // Step tracking for run trace — position is a step ordinal, not $iteration.
        // A schema-validation retry consumes an iteration without opening a step.
        $stepOrdinal = 0;
        $currentStepId = null;
        $activeActionId = null;

        // Set once a scheduler-triggered retry sequence exhausts its limit
        // (or a first attempt is never transient) and never cleared again
        // for the rest of this run -- the model still gets its own turn to
        // report what happened (below), exactly like an ordinary failed
        // tool call already does, but the run's own final close uses
        // RunEndState::Failed instead of Completed once this is set,
        // naming the exhausted action, regardless of how the model's own
        // report words the outcome.
        $unrecoverableFailureReason = null;

        try {
            // Unattended refuse-and-stop guarantee, checked before the loop
            // opens even its first step: ConversationAgentDefinitionResolver
            // degrades a since-deleted or dangling agent/version binding to
            // null rather than throwing, which is fine for an interactive
            // turn (a live user notices instructions/permissions are
            // missing) but not for a run nobody is watching -- that would
            // otherwise run un-narrowed with no bound instructions at all.
            // Thrown here so it is caught by this same try/catch's
            // UnattendedActionRefusedException handler below; no step or
            // action has been opened yet, so that handler's closeAction()/
            // closeStep() calls are no-ops and only closeRun() does
            // anything.
            if ($unattended && $this->agentDefinitionResolver->effectiveDefinitionFor($conversation) === null) {
                throw new UnattendedActionRefusedException(
                    '(no operation attempted)',
                    'No resolvable agent definition is bound to this conversation; refusing to run unattended.',
                );
            }

            for ($iteration = 1; $iteration <= $maxIterations; $iteration++) {
                // Delegation time bound (research.md D3): checked before any
                // per-iteration work for this iteration begins, mirroring the
                // max-iterations-exceeded close-out shape below exactly, but
                // with its own distinct end_reason/code.
                if ($deadlineAt !== null && now()->greaterThanOrEqualTo($deadlineAt)) {
                    $conversation->update(['is_processing' => false]);

                    if ($this->runTraceRecorder !== null && $runId !== null) {
                        if ($currentStepId !== null) {
                            $this->runTraceRecorder->closeStep(
                                $currentStepId,
                                RunEndState::StoppedEarly,
                            );
                        }
                        $this->runTraceRecorder->closeRun(
                            $runId,
                            RunEndState::StoppedEarly,
                            'Delegation time bound reached',
                        );
                    }

                    return [
                        'status' => 'error',
                        'content' => 'Delegation time bound reached',
                        'message_id' => null,
                        'code' => 'time_ceiling_reached',
                    ];
                }

                // Generate attempt group ID for this turn (shared across LLM calls, retries, and tool calls)
                $attemptGroupId = (string) \Illuminate\Support\Str::uuid();

                // Open a step only when no step is currently open for this run.
                if ($this->runTraceRecorder !== null && $runId !== null && $currentStepId === null) {
                    $stepOrdinal++;
                    $currentStepId = $this->runTraceRecorder->openStep(
                        $runId,
                        $stepOrdinal,
                        $attemptGroupId,
                    );
                }

                $rawMessages = $this->buildMessagesPayload($conversation, $runId);

                // Site 3: ContextReshape around applyContextWindowTrim in run().
                $activeActionId = null;
                if ($this->runTraceRecorder !== null && $currentStepId !== null) {
                    $activeActionId = $this->runTraceRecorder->openAction(
                        $currentStepId,
                        ActionType::ContextReshape,
                        'window_trim',
                        $attemptGroupId,
                    );
                }
                $trimmed = $this->applyContextWindowTrim($conversation, $rawMessages, $attemptGroupId, $decision);
                if ($this->runTraceRecorder !== null && $activeActionId !== null) {
                    $this->runTraceRecorder->closeAction($activeActionId, ActionOutcome::Success);
                    $activeActionId = null;
                }

                $formatted = $this->formatMessages($conversation, $trimmed);
                // Inject preset system prompt into the base system prompt if present
                // NOTE: the preset system prompt appended below is covered by the
                // injected_section_reserve in the context_window config budget.
                $systemPrompt = $formatted['system'];
                if ($presetSystemPrompt !== '') {
                    $systemPrompt = $systemPrompt . "\n\n" . $presetSystemPrompt;
                }

                // Site 1: LlmRequest around callLlmSync in run().
                $activeActionId = null;
                $modelName = $conversation->model;
                if ($modelName === null) {
                    $modelName = config('llm-client.providers.' . $conversation->effectiveProviderType->value . '.default_model');
                }
                if ($this->runTraceRecorder !== null && $currentStepId !== null) {
                    $activeActionId = $this->runTraceRecorder->openAction(
                        $currentStepId,
                        ActionType::LlmRequest,
                        $modelName,
                        $attemptGroupId,
                    );
                }
                $response = $this->callLlmSync(
                    $conversation,
                    $formatted['messages'],
                    $formattedTools,
                    $systemPrompt,
                    modelOverride: $decision->effectiveModel,
                    serverIdOverride: $decision->effectiveServerId,
                );
                if ($this->runTraceRecorder !== null && $activeActionId !== null) {
                    $this->runTraceRecorder->closeAction($activeActionId, ActionOutcome::Success);
                    $activeActionId = null;
                }

                // Record LLM usage metrics (fire-and-forget, never throws)
                $this->recordUsageMetric($conversation, $attemptGroupId, $response, $formatted['messages']);

                $choice = $response['choices'][0] ?? null;
                if (!$choice) {
                    $conversation->update(['is_processing' => false]);
                    return ['status' => 'error', 'content' => 'No response from LLM', 'message_id' => null];
                }

                $responseMessage = $choice['message'] ?? [];
                $content = $responseMessage['content'] ?? '';
                $toolCalls = $responseMessage['tool_calls'] ?? [];

                // No tool calls — plain text response
                if (empty($toolCalls)) {
                    // Validate response against schema if configured
                    $validatedContent = null;
                    $validationError = null;

                    if ($shouldValidate && !empty($options['schema'])) {
                        try {
                            $validatedContent = $this->schemaValidator->validate($content, $options['schema']);
                        } catch (SchemaValidationError $e) {
                            $validationError = $e;

                            // Check if we should retry
                            if ($retryOnValidationFailure && $schemaRetryCount < $maxSchemaRetries && !$e->isRetryExhausted()) {
                                // A schema-validation retry is a unit of
                                // agent-initiated work in its own right
                                // (research.md D2) — this branch is reached
                                // only when $toolCalls is empty, so there is
                                // no unanswered tool call to synthesize a
                                // result for; the stop is a plain refusal in
                                // place of the correction-prompt flow below.
                                $workDecision = app(ConversationWorkGate::class)->evaluate($conversation->id);
                                if ($workDecision->isStop()) {
                                    return $this->stopForConversationWorkCeiling(
                                        $conversation,
                                        $runId,
                                        $currentStepId,
                                        [],
                                        [],
                                        $iteration,
                                        $workDecision,
                                    );
                                }

                                $schemaRetryCount++;

                                // Record the retry attempt but keep the step open —
                                // a retried round stays one step (FR-011).
                                if ($this->runTraceRecorder !== null && $currentStepId !== null) {
                                    $this->runTraceRecorder->recordStepAttempt($currentStepId);
                                }

                                // Build correction prompt and inject as user message
                                $correctionPrompt = $correctionPromptBuilder->build(
                                    $e->withRetryInfo($schemaRetryCount, $maxSchemaRetries)
                                );

                                // Create correction message to feed back to LLM
                                Message::create([
                                    'conversation_id' => $conversation->id,
                                    'content' => $correctionPrompt,
                                    'role' => 'user',
                                    'user' => 'system',
                                    'responseTime' => 0,
                                ]);

                                // Continue the loop to retry — step stays open.
                                continue;
                            }

                            // Retry exhausted or disabled — throw the error
                            if ($validationError && $retryOnValidationFailure) {
                                throw $validationError->withRetryInfo($schemaRetryCount, $maxSchemaRetries);
                            }
                            throw $validationError;
                        }
                    }

                    // A response completing under a crossed reduction
                    // threshold is disclosed, never silently returned as if
                    // it were ordinary (085-graceful-degradation, FR-004,
                    // research.md D10) — the disclosure sentence is
                    // prepended to the same $content both the stored
                    // message and the returned array read below, so the
                    // two can never disagree about what the user was told.
                    $degradationBlock = null;
                    if ($decision->outcome === DegradationDecision::OUTCOME_REDUCED) {
                        $disclosure = $decision->composeDisclosure();
                        if ($disclosure !== null) {
                            $content = $disclosure.' '.$content;
                        }
                        $degradationBlock = $decision->toDisclosureArray();
                    }

                    // A routing decision, if any is still undisclosed, is
                    // announced here — after the degradation block and
                    // before the handoff block, so its own prepend happens
                    // first, leaving the handoff prepend (a more recent
                    // event, if any) landing first in the final string
                    // (102-router-pattern, US2, contracts §6's ordering).
                    $routingDisclosure = $this->composeRoutingDisclosure($conversation);
                    if ($routingDisclosure !== null) {
                        $content = $routingDisclosure.' '.$content;
                    }

                    // A handoff, if any is still undisclosed, is announced
                    // here — after the degradation block so it prepends
                    // last, landing first in the final string (093-agent-
                    // handoff, US2, contracts §2's own ordering).
                    $handoffDisclosure = $this->composeHandoffDisclosure($conversation);
                    if ($handoffDisclosure !== null) {
                        $content = $handoffDisclosure.' '.$content;
                    }

                    // A delegation, if any happened during this run, is
                    // announced last of all three prepends -- landing
                    // first in the final string (098-delegation-protocol,
                    // research.md D7), completing the degradation, then
                    // handoff, then delegation stacking order.
                    $delegationDisclosure = $this->composeDelegationDisclosure($runId);
                    if ($delegationDisclosure !== null) {
                        $content = $delegationDisclosure.' '.$content;
                    }

                    $assistantMessage = Message::create([
                        'conversation_id' => $conversation->id,
                        'content' => $content,
                        'role' => 'assistant',
                        'user' => $conversation->character,
                        'responseTime' => 0,
                        'tool_data' => $degradationBlock !== null ? ['degradation' => $degradationBlock] : null,
                    ]);

                    $agentId = $conversation->character ?? $conversation->id;

                    $conversation->update(['is_processing' => false]);

                    // Close the current step and the run. An unrecoverable
                    // scheduler-triggered action failure earlier in this
                    // same run (tracked above, action-record already
                    // reflects it) makes this final close Failed rather
                    // than Completed, regardless of how the model's own
                    // report -- already produced above -- happens to word
                    // the outcome; an ordinary run with no such failure is
                    // unaffected.
                    if ($this->runTraceRecorder !== null && $runId !== null) {
                        $finalEndState = $unrecoverableFailureReason !== null
                            ? RunEndState::Failed
                            : RunEndState::Completed;
                        $finalReason = $unrecoverableFailureReason !== null
                            ? Str::limit($unrecoverableFailureReason, 500)
                            : null;

                        if ($currentStepId !== null) {
                            $this->runTraceRecorder->closeStep(
                                $currentStepId,
                                $finalEndState,
                                $finalReason,
                            );
                        }
                        $this->runTraceRecorder->closeRun(
                            $runId,
                            $finalEndState,
                            $finalReason,
                            $assistantMessage->id,
                        );
                    }

                    // Generate title on first exchange
                    if ($conversation->title === null) {
                        $titleRequest = new \ClarionApp\LlmClient\OpenAIGenerateConversationTitleRequest($conversation);
                        $titleRequest->sendGenerateConversationTitle();
                    }

                    return [
                        'status' => 'completed',
                        'content' => $validatedContent !== null ? json_encode($validatedContent) : $content,
                        'validated' => $validatedContent,
                        'message_id' => $assistantMessage->id,
                    ] + ($degradationBlock !== null ? ['degraded' => true, 'degradation' => $degradationBlock] : []);
                }

                // Handle tool calls
                $toolResults = [];
                $pendingConfirmation = null;

                // 101-parallel-subagent-execution (US1, Grounding note item
                // 6 -- Site 2): a 2+ delegate_to_helper burst in this
                // iteration is dispatched together, ahead of the loop below
                // ever reaching any of them. null (0 or 1 such calls) means
                // the existing inline per-call path is completely
                // unaffected.
                $batchDelegationResults = $this->resolveDelegateToHelperBatchResults($toolCalls, $conversation);

                foreach ($toolCalls as $tcIndex => $toolCall) {
                    $toolName = $toolCall['function']['name'] ?? '';
                    $arguments = json_decode($toolCall['function']['arguments'] ?? '{}', true) ?: [];
                    $toolCallId = $toolCall['id'] ?? '';

                    // Checked before every individual tool-call execution,
                    // not just between iterations, so a single LLM turn
                    // requesting a large batch of tool calls can be stopped
                    // mid-batch (research.md D3). Every tool call from this
                    // point on — including the current one — is given a
                    // synthesized tool_result rather than left unanswered,
                    // mirroring the existing declined-confirmation shape.
                    $workDecision = app(ConversationWorkGate::class)->evaluate($conversation->id);
                    if ($workDecision->isStop()) {
                        foreach (array_slice($toolCalls, $tcIndex) as $unexecutedCall) {
                            $toolResults[] = [
                                'tool_call_id' => $unexecutedCall['id'] ?? '',
                                'content' => ConversationWorkDecision::UNEXECUTED_TOOL_RESULT,
                            ];
                        }

                        return $this->stopForConversationWorkCeiling(
                            $conversation,
                            $runId,
                            $currentStepId,
                            $toolCalls,
                            $toolResults,
                            $iteration,
                            $workDecision,
                        );
                    }

                    // Beside, never inside, the ConversationWorkGate check
                    // above (085-graceful-degradation, research.md D6): a
                    // called name found in the governing decision's
                    // withheldTools set stops the entire batch, mirroring
                    // the ceiling stop's own synthesized-tool_result shape,
                    // but with a distinct refusal message and code — this
                    // is a capability the current reduced mode does not
                    // offer, never a ceiling.
                    if (in_array($toolName, $decision->withheldTools, true)) {
                        foreach (array_slice($toolCalls, $tcIndex) as $unexecutedCall) {
                            $toolResults[] = [
                                'tool_call_id' => $unexecutedCall['id'] ?? '',
                                'content' => DegradationDecision::UNEXECUTED_TOOL_RESULT,
                            ];
                        }

                        return $this->stopForWithheldTool(
                            $conversation,
                            $runId,
                            $currentStepId,
                            $toolCalls,
                            $toolResults,
                            $iteration,
                            $decision,
                        );
                    }

                    // Site 2: ToolInvocation around executeMetaTool in run().
                    // A scheduler-triggered (unattended) execute_operation
                    // call is dispatched through its own bounded retry
                    // sequence below (dispatchExecuteOperationWithRetry())
                    // rather than this single open/execute/decode pairing
                    // -- every other tool call, and every interactive
                    // execute_operation call, keeps this exact one-shot
                    // shape, byte-for-byte unchanged.
                    $activeActionId = null;

                    // 101-parallel-subagent-execution (US1, contracts §1):
                    // a delegate_to_helper call that was part of a 2+ burst
                    // this iteration already has its result from the one
                    // delegateBatch() call above -- never re-executed
                    // inline through executeMetaTool()/delegate() a second
                    // time.
                    if (in_array($toolName, ['delegate_to_helper', 'assign_part'], true) && $batchDelegationResults !== null && array_key_exists($toolCallId, $batchDelegationResults)) {
                        if ($this->runTraceRecorder !== null && $currentStepId !== null) {
                            $activeActionId = $this->runTraceRecorder->openAction(
                                $currentStepId,
                                ActionType::ToolInvocation,
                                $toolName,
                                $attemptGroupId,
                            );
                        }
                        $result = json_encode($batchDelegationResults[$toolCallId]);
                        $decoded = json_decode($result, true);
                    } elseif ($unattended && $toolName === 'execute_operation') {
                        [$result, $decoded, $dispatchFailureReason] = $this->dispatchExecuteOperationWithRetry(
                            $arguments,
                            $conversation,
                            $runId,
                            $currentStepId,
                            (int) ($options['retry_limit'] ?? 0),
                            $activeActionId,
                        );

                        // The model still gets its own turn to see this
                        // result and report on it below (FR-012), exactly
                        // like an ordinary failed tool call already does;
                        // only the run's eventual close-reason changes.
                        // The first exhausted action's reason wins if more
                        // than one occurs in the same run.
                        if ($dispatchFailureReason !== null && $unrecoverableFailureReason === null) {
                            $unrecoverableFailureReason = $dispatchFailureReason;
                        }
                    } else {
                        if ($this->runTraceRecorder !== null && $currentStepId !== null) {
                            $activeActionId = $this->runTraceRecorder->openAction(
                                $currentStepId,
                                ActionType::ToolInvocation,
                                $toolName,
                                $attemptGroupId,
                            );
                        }
                        $result = $this->executeMetaTool($toolName, $arguments, $conversation, $runId, $unattended);
                        $decoded = json_decode($result, true);
                    }

                    if (is_array($decoded) && !empty($decoded['__requires_confirmation'])) {
                        $confirmationType = $decoded['confirmation_type'] ?? 'api_call';

                        if ($confirmationType === 'declarative_memory') {
                            $pendingConfirmation = [
                                'tool_name' => 'propose_declarative_memory',
                                'confirmation_type' => 'declarative_memory',
                                'type' => $decoded['type'] ?? '',
                                'content' => $decoded['content'] ?? '',
                                'existingId' => $decoded['existingId'] ?? null,
                                'expires_at' => now()->addSeconds(config('llm-client.agent_loop.confirmation_timeout', 300))->toIso8601String(),
                            ];

                            $confirmationPayload = [
                                'confirmation_type' => 'declarative_memory',
                                'type' => $decoded['type'] ?? '',
                                'content' => $decoded['content'] ?? '',
                                'existingId' => $decoded['existingId'] ?? null,
                                'expires_at' => $pendingConfirmation['expires_at'],
                            ];
                        } else {
                            // Default: execute_operation (api_call), or its
                            // scope_surface variant — same base shape, plus
                            // the three extra scope fields when present.
                            // $confirmationType is read back from $decoded
                            // above rather than hard-coded, so a third
                            // confirmation_type value survives the pause
                            // unchanged.
                            $pendingConfirmation = [
                                'tool_name' => 'execute_operation',
                                'confirmation_type' => $confirmationType,
                                'operationId' => $decoded['operationId'],
                                'method' => $decoded['method'],
                                'path' => $decoded['path'],
                                'arguments' => $decoded['parameters'] ?? [],
                                'expires_at' => now()->addSeconds(config('llm-client.agent_loop.confirmation_timeout', 300))->toIso8601String(),
                            ];

                            $confirmationPayload = [
                                'confirmation_type' => $confirmationType,
                                'operationId' => $decoded['operationId'],
                                'method' => $decoded['method'],
                                'path' => $decoded['path'],
                                'arguments' => $decoded['parameters'] ?? [],
                                'expires_at' => $pendingConfirmation['expires_at'],
                            ];

                            if ($confirmationType === 'scope_surface') {
                                $pendingConfirmation['files_touched_so_far'] = $decoded['files_touched_so_far'] ?? [];
                                $pendingConfirmation['would_add'] = $decoded['would_add'] ?? null;
                                $pendingConfirmation['threshold'] = $decoded['threshold'] ?? null;

                                $confirmationPayload['files_touched_so_far'] = $pendingConfirmation['files_touched_so_far'];
                                $confirmationPayload['would_add'] = $pendingConfirmation['would_add'];
                                $confirmationPayload['threshold'] = $pendingConfirmation['threshold'];
                            }
                        }

                        // Close the tool action as awaiting confirmation and store
                        // action_id in tool_data for the resuming process (T029b).
                        //
                        // $activeActionId is deliberately NOT nulled out
                        // here (unlike this method's other closeAction()
                        // call sites) -- this branch returns immediately
                        // below, so nulling it served no
                        // double-close-prevention purpose and only
                        // corrupted the action_id the pause message is
                        // about to be stored with, a few lines down. That
                        // silently broke resume()/resumeSync()'s "resolve
                        // the inbound paused action" step for every run's
                        // *first* confirmed write/delete (its action row
                        // was left permanently awaiting_confirmation,
                        // uncounted by
                        // RunTraceQuery::changedFilesFromRunTrace()/
                        // scopeSurfaceStateForRun()) -- found while testing
                        // scope-surfacing's running file count, which
                        // depends on every confirmed write actually being
                        // recorded, starting with the first one.
                        if ($this->runTraceRecorder !== null && $activeActionId !== null) {
                            $this->runTraceRecorder->closeAction(
                                $activeActionId,
                                ActionOutcome::AwaitingConfirmation,
                            );
                        }

                        // Store message with pending confirmation. The step stays
                        // open across the pause; step_id and paused_at let the
                        // resuming process close it with its human-wait portion
                        // (contracts §3.2, FR-004).
                        $assistantMessage = Message::create([
                            'conversation_id' => $conversation->id,
                            'content' => $content ?: '',
                            'role' => 'assistant',
                            'user' => $conversation->character,
                            'responseTime' => 0,
                            'tool_data' => [
                                'tool_calls' => $toolCalls,
                                'tool_results' => null,
                                'iteration' => $iteration,
                                'pending_confirmation' => $pendingConfirmation,
                                'run_id' => $runId,
                                'step_id' => $currentStepId,
                                'paused_at' => now()->toIso8601String(),
                                'action_id' => $activeActionId,
                            ],
                        ]);

                        return [
                            'status' => 'confirmation_required',
                            'content' => $content ?: '',
                            'message_id' => $assistantMessage->id,
                            'confirmation' => $confirmationPayload,
                        ];
                    }

                    // Close tool action on normal completion. Feature 111 (US1):
                    // record the page/text envelope (JSON) as the action content
                    // so the consulted-source manifest (T014) can read source.url.
                    //
                    // A tool call that executed without throwing can still have
                    // failed at the target's own level (a permitted operation
                    // called with a bad argument, for example) -- $decoded is
                    // already computed above, and a top-level "error" key in it
                    // is exactly what allExecuteOperationsSucceeded() a little
                    // further down this same method already treats as a failed
                    // tool result for its own auto-stop decision. Checking it
                    // here too means the action record itself never reports
                    // that failure as a success -- the two checks read the same
                    // signal for two different purposes rather than disagreeing
                    // about what "failed" means.
                    if ($this->runTraceRecorder !== null && $activeActionId !== null) {
                        $actionContent = ($toolName === 'execute_operation' && ($arguments['operationId'] ?? '') === self::PAGE_TEXT_OPERATION_ID)
                            ? $result
                            : null;
                        if (is_array($decoded) && is_string($decoded['error'] ?? null)) {
                            $this->runTraceRecorder->closeAction(
                                $activeActionId,
                                ActionOutcome::Failure,
                                Str::limit($decoded['error'], 500),
                                $actionContent,
                            );
                        } else {
                            $this->runTraceRecorder->closeAction($activeActionId, ActionOutcome::Success, null, $actionContent);
                        }
                        $activeActionId = null;
                    }

                    // A scheduler-triggered retry sequence (above) that
                    // never produced a usable result -- either the first
                    // attempt was never transient, or every retry up to
                    // retry_limit failed too -- has already had its last
                    // attempt's action closed Failure by the block just
                    // above (the same $decoded['error'] check every
                    // ordinary failed tool call goes through). It does not
                    // stop the run here: the model still gets this result
                    // fed back like any other failed tool call, so it can
                    // produce its own report (FR-012) -- $unrecoverableFailureReason,
                    // set at the dispatch site above, is what makes this
                    // run's own eventual close use RunEndState::Failed
                    // instead of Completed once the model's own turn ends
                    // the loop, regardless of how its report words the
                    // outcome.

                    // Tool executed (not a confirmation pause) — record its outcome.
                    $this->recordToolMetric($conversation, $attemptGroupId, $toolName, $decoded);

                    // Site 4: ContextReshape around condenseToolResult in run().
                    $activeActionId = null;
                    $toolResultEntry = $this->condenseToolResult($result, $conversation->id, $toolName);
                    $condenseMethod = $toolResultEntry['method'] ?? null;
                    if ($this->runTraceRecorder !== null && $currentStepId !== null) {
                        $activeActionId = $this->runTraceRecorder->openAction(
                            $currentStepId,
                            ActionType::ContextReshape,
                            $condenseMethod ?? 'passthrough',
                            null,
                        );
                    }
                    if ($this->runTraceRecorder !== null && $activeActionId !== null) {
                        $this->runTraceRecorder->closeAction($activeActionId, ActionOutcome::Success);
                        $activeActionId = null;
                    }

                    $toolResults[] = [
                        'tool_call_id' => $toolCallId,
                        'content' => $toolResultEntry['content'],
                    ] + array_filter($toolResultEntry, fn ($k) => in_array($k, ['reference_id', 'original_tokens', 'condensed_tokens', 'method', 'condensed']), ARRAY_FILTER_USE_KEY);
                }

                // Store the assistant message with tool data and continue loop
                Message::create([
                    'conversation_id' => $conversation->id,
                    'content' => $content ?: '',
                    'role' => 'assistant',
                    'user' => $conversation->character,
                    'responseTime' => 0,
                    'tool_data' => [
                        'tool_calls' => $toolCalls,
                        'tool_results' => $toolResults,
                        'iteration' => $iteration,
                        'pending_confirmation' => null,
                    ],
                ]);

                // Fire AgentTurnCompleted for scratch memory cleanup (T013)
                \Illuminate\Support\Facades\Event::dispatch(
                    new AgentTurnCompleted((string)$iteration, $conversation->id)
                );

                // Close the current step before continuing to the next iteration.
                // A new step will be opened at the top of the next loop iteration.
                if ($this->runTraceRecorder !== null && $runId !== null && $currentStepId !== null) {
                    $this->runTraceRecorder->closeStep(
                        $currentStepId,
                        RunEndState::Completed,
                    );
                    $currentStepId = null;
                }

                // If all tool calls were successful execute_operation calls,
                // stop the loop — no need for a summary response from the LLM.
                // An unattended run is excluded from this shortcut: nobody is
                // watching it happen live, so the only place a fully-successful
                // run's outcome can ever be stated is a report the model itself
                // produces, exactly like the failed and partial cases already
                // require one more turn to state their outcome. Skipping this
                // turn here would leave a fully-successful triggered run with
                // no report at all -- not merely a report indistinguishable
                // from success, but no report whatsoever.
                if (!$unattended && $this->allExecuteOperationsSucceeded($toolCalls, $toolResults)) {
                    $agentId = $conversation->character ?? $conversation->id;
                    $conversation->update(['is_processing' => false]);

                    // Close any open step and the run as completed.
                    if ($this->runTraceRecorder !== null && $runId !== null) {
                        if ($currentStepId !== null) {
                            $this->runTraceRecorder->closeStep(
                                $currentStepId,
                                RunEndState::Completed,
                            );
                        }
                        $this->runTraceRecorder->closeRun(
                            $runId,
                            RunEndState::Completed,
                        );
                    }

                    return [
                        'status' => 'completed',
                        'content' => '',
                        'message_id' => null,
                    ];
                }
            }

            // Max iterations exceeded
            $agentId = $conversation->character ?? $conversation->id;
            $conversation->update(['is_processing' => false]);

            // Close the run as stopped_early.
            if ($this->runTraceRecorder !== null && $runId !== null) {
                if ($currentStepId !== null) {
                    $this->runTraceRecorder->closeStep(
                        $currentStepId,
                        RunEndState::StoppedEarly,
                    );
                }
                $this->runTraceRecorder->closeRun(
                    $runId,
                    RunEndState::StoppedEarly,
                    'Maximum iterations reached',
                );
            }

            return [
                'status' => 'error',
                'content' => 'Maximum iterations reached',
                'message_id' => null,
                'code' => 'max_iterations',
            ];
        } catch (UnattendedActionRefusedException $e) {
            // The unattended refuse-and-stop guarantee: never re-thrown,
            // never left pending for a human who is not present to answer
            // it. Closed in the same action-before-step-before-run order
            // the generic \Throwable branch below uses, then StoppedEarly
            // rather than Failed -- this is an expected, designed-for
            // outcome, not a crash.
            $conversation->update(['is_processing' => false]);

            if ($this->runTraceRecorder !== null && $activeActionId !== null) {
                $this->runTraceRecorder->closeAction(
                    $activeActionId,
                    ActionOutcome::Failure,
                    Str::limit($e->getMessage(), 500),
                );
            }

            if ($this->runTraceRecorder !== null && $runId !== null) {
                if ($currentStepId !== null) {
                    $this->runTraceRecorder->closeStep(
                        $currentStepId,
                        RunEndState::StoppedEarly,
                        Str::limit($e->getMessage(), 500),
                    );
                }
                $this->runTraceRecorder->closeRun(
                    $runId,
                    RunEndState::StoppedEarly,
                    Str::limit($e->getMessage(), 500),
                );
            }

            // Isolated in its own inner try/catch, entirely separate from
            // the run-closing writes above: RunTraceRecorder::closeRun()'s
            // own enqueueForwarding()/broadcast() calls are isolated this
            // same way for exactly this reason -- a broadcast failure here
            // must never propagate and turn an already-closed run's return
            // value into an uncaught exception. No step existed yet when
            // the top-level guard fires before the loop's first iteration,
            // so a fresh one is opened to carry this notification action
            // and closed again immediately after; every other refusal
            // route reuses the step it already closed above, since
            // opening an action only needs a valid step id to resolve the
            // run it belongs to, not an open one.
            if ($this->runTraceRecorder !== null && $runId !== null && $conversation->user_id !== null) {
                $notifyStepId = $currentStepId ?? $this->runTraceRecorder->openStep($runId);
                $notifyActionId = $this->runTraceRecorder->openAction(
                    $notifyStepId,
                    ActionType::Notification,
                    'scheduler_trigger_run_refused',
                );

                try {
                    event(new SchedulerTriggerRunRefused(
                        (string) $conversation->user_id,
                        $runId,
                        $e->operationId,
                        $e->getMessage(),
                    ));

                    $this->runTraceRecorder->closeAction($notifyActionId, ActionOutcome::Success);
                } catch (\Throwable $notifyError) {
                    $this->runTraceRecorder->closeAction(
                        $notifyActionId,
                        ActionOutcome::Failure,
                        Str::limit($notifyError->getMessage(), 500),
                    );

                    Log::warning('AgentLoopService: failed to notify unattended run refusal', [
                        'run_id' => $runId,
                        'operation_id' => $e->operationId,
                        'error' => $notifyError->getMessage(),
                    ]);
                } finally {
                    if ($currentStepId === null) {
                        $this->runTraceRecorder->closeStep($notifyStepId, RunEndState::Completed);
                    }
                }
            }

            return [
                'status' => 'stopped_unauthorized',
                'operation_id' => $e->operationId,
                'reason' => $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            $agentId = $conversation->character ?? $conversation->id;
            $conversation->update(['is_processing' => false]);

            // Close any open action with Failure outcome before closing the step.
            if ($this->runTraceRecorder !== null && $activeActionId !== null) {
                $this->runTraceRecorder->closeAction(
                    $activeActionId,
                    ActionOutcome::Failure,
                    Str::limit($e->getMessage(), 500),
                );
            }

            // Close the run as failed. The recorder catches errors, so this is safe before rethrow.
            if ($this->runTraceRecorder !== null && $runId !== null) {
                if ($currentStepId !== null) {
                    $this->runTraceRecorder->closeStep(
                        $currentStepId,
                        RunEndState::Failed,
                    );
                }
                $this->runTraceRecorder->closeRun(
                    $runId,
                    RunEndState::Failed,
                    Str::limit($e->getMessage(), 500),
                );
            }


            throw $e;
        }
    }

    /**
     * Synchronous confirmation resolution for external channel integrations.
     */
    public function resumeSync(Conversation $conversation, Message $message, bool $approved): array
    {
        $toolData = $message->tool_data;
        $pending = $toolData['pending_confirmation'] ?? null;
        $runId = $toolData['run_id'] ?? null;

        // See resume() — the synchronous sibling is gated identically, and for
        // the same reason: a human wait of up to confirmation_timeout is a
        // window in which the ceiling can be crossed by somebody else.
        $this->admitResumedWork($conversation, $runId);

        // Revocation check (096-agent-sharing, research.md D5) — see
        // resume()'s identical call, immediately above the same relative
        // point in that sibling method.
        if (($revokedStop = $this->checkSharedAgentAccessRevoked($conversation, $runId, $toolData['step_id'] ?? null)) !== null) {
            return $revokedStop;
        }

        // Automatic routing (102-router-pattern, US1, research.md D2) —
        // defensive, mirroring checkSharedAgentAccessRevoked()'s own
        // defensive presence at this same site; a no-op unless this is
        // genuinely the conversation's first turn and no agent is already
        // bound.
        $this->attemptInitialRouting(
            $conversation,
            Message::where('conversation_id', $conversation->id)->where('role', 'user')->first()?->content ?? '',
        );
        $this->ensureSpecialistAvailable($conversation);

        if (!$pending) {
            throw new \RuntimeException('No pending confirmation found on this message.');
        }

        $expiresAt = Carbon::parse($pending['expires_at']);
        if ($expiresAt->isPast()) {
            $agentId = $conversation->character ?? $conversation->id;
            $conversation->update(['is_processing' => false]);


            throw new \RuntimeException('Confirmation has expired.');
        }

        $toolCallId = $toolData['tool_calls'][0]['id'] ?? null;
        $pendingStepId = $toolData['step_id'] ?? null;

        // Read inbound paused action id (T029c). Null if the message was paused
        // before this feature shipped — safe no-op per C13.
        $inboundActionId = $toolData['action_id'] ?? null;

        // Shared attempt group ID for this resumed turn (confirmed call + any
        // follow-on LLM calls and tool invocations in the continuation loop).
        $attemptGroupId = (string) \Illuminate\Support\Str::uuid();

        // Recover run_id and step_id from tool_data.
        // If step_id is present, close it with wait_ms (T059) — the step spans
        // the confirmation pause. If run_id is null (pre-feature), mint a fresh run.
        $currentStepId = null;
        $waitMs = $this->confirmationWaitMs($toolData, $message);
        if ($this->runTraceRecorder !== null) {
            if ($runId !== null) {
                // Close the step that spanned the confirmation pause (T059).
                if ($pendingStepId !== null) {
                    $this->runTraceRecorder->closeStep(
                        $pendingStepId,
                        RunEndState::Completed,
                        null,
                        $waitMs, // wait_ms for the human wait portion
                    );
                }
                // Open a new step for the resumed work.
                $currentStepId = $this->runTraceRecorder->openStep(
                    $runId,
                    null, // position auto-assigned
                    $attemptGroupId,
                );
            } else {
                // Pre-feature tool_data — mint a fresh run for the resumed portion.
                $runId = $this->runTraceRecorder->openRun(
                    \ClarionApp\LlmClient\ValueObjects\RunKind::Interactive,
                    (string) $conversation->user_id,
                    $conversation->id,
                    streamed: false,
                    model: $conversation->model,
                    agentId: $conversation->character ?? $conversation->id,
                );
                $currentStepId = $this->runTraceRecorder->openStep(
                    $runId,
                    null, // position auto-assigned
                    $attemptGroupId,
                );
            }
        }

        // 112-coding-agent (US1, data-model.md §6): tracks whether
        // executeApiCall() genuinely ran, so a chain-check rejection below
        // (approved by the user, but blocked by a since-narrowed ancestor
        // permission) is never recorded as an executed change either —
        // only a real execution ever produces change-report content.
        $apiCallExecuted = false;

        // Read back exactly like resume()'s identical variable,
        // immediately above the same relative point in that sibling
        // method — resumeSync() has no declarative_memory branch of its
        // own, but does need to recognize a scope_surface pause so
        // approving it never executes the underlying operation.
        $confirmationType = $pending['confirmation_type'] ?? 'api_call';

        if ($approved && $confirmationType === 'scope_surface') {
            // See resume()'s identical branch for the full rationale:
            // approving the aggregate scope acknowledgment never itself
            // writes or deletes the file that triggered it — it only lets
            // the loop continue so the model reissues that file's own
            // operation, which this time reaches its ordinary per-file
            // confirmation.
            $resultContent = json_encode([
                'scope_confirmed' => true,
                'files_touched_so_far' => $pending['files_touched_so_far'] ?? [],
                'would_add' => $pending['would_add'] ?? null,
                'message' => 'Scope confirmed. Reissue the file operation to proceed with its own confirmation.',
            ]);

            $toolData['tool_results'] = [
                ['tool_call_id' => $toolCallId, 'content' => $resultContent],
            ];
        } elseif ($approved && $confirmationType === 'external_tool') {
            // See resume()'s identical branch for the full rationale:
            // re-resolved from the local cache by operationId rather than
            // trusted from whatever the pause itself carried.
            $externalTool = \ClarionApp\LlmClient\Models\McpClientTool::findBySyntheticId($pending['operationId'] ?? '');
            $externalServer = $externalTool?->server;

            if ($externalTool === null || $externalServer === null) {
                $resultContent = json_encode([
                    'error' => 'This tool is no longer offered by its server. Search again for a current capability.',
                ]);
            } else {
                $resultContent = json_encode(app(McpClientToolExecutor::class)->execute($externalServer, $externalTool, $pending['arguments'] ?? []));
                $apiCallExecuted = true;
            }

            $this->recordToolMetric(
                $conversation,
                $attemptGroupId,
                $pending['tool_name'] ?? 'execute_operation',
                json_decode($resultContent, true),
            );

            $toolData['tool_results'] = [
                ['tool_call_id' => $toolCallId, 'content' => $resultContent],
            ];
        } elseif ($approved) {
            try {
                // See resume()'s identical re-check, immediately above the
                // same relative point in that sibling method
                // (100-subagent-tool-restrictions, FR-004/FR-005/FR-006):
                // resumeSync() also never routes through
                // handleExecuteOperation() when resuming, so the ancestor-
                // chain bound must be re-verified here rather than trusted
                // from when the confirmation was first requested.
                $chain = $this->effectiveBoundResolver->check($conversation, $pending['operationId']);

                if (!$chain['allowed']) {
                    $resultContent = json_encode([
                        'error' => "Operation not permitted: ancestor agent \"{$chain['blocking_agent_name']}\" ({$chain['levels_up']} level(s) up in this delegation chain) does not permit \"{$pending['operationId']}\".",
                    ]);
                } else {
                    $resultContent = $this->executeApiCall(
                        $pending['operationId'],
                        $pending['method'],
                        $pending['path'],
                        $pending['arguments'] ?? [],
                        $conversation
                    );
                    $apiCallExecuted = true;
                }

                // Record the confirmed operation's outcome (fire-and-forget).
                $this->recordToolMetric(
                    $conversation,
                    $attemptGroupId,
                    $pending['tool_name'] ?? 'execute_operation',
                    json_decode($resultContent, true),
                );

                $toolData['tool_results'] = [
                    ['tool_call_id' => $toolCallId, 'content' => $resultContent],
                ];
            } catch (\Throwable $e) {
                // Close inbound action with Failure on execution error.
                if ($this->runTraceRecorder !== null && $inboundActionId !== null) {
                    $this->runTraceRecorder->closeAction(
                        $inboundActionId,
                        ActionOutcome::Failure,
                        Str::limit($e->getMessage(), 500),
                    );
                }
                throw $e;
            }
        } else {
            $toolData['tool_results'] = [
                ['tool_call_id' => $toolCallId, 'content' => 'User cancelled this operation.'],
            ];
        }

        // Resolve inbound paused action (T029c). Already-instrumented tool
        // invocation was paused at AwaitingConfirmation; now close it with the
        // outcome. If $inboundActionId is null (pre-feature message), this is
        // a safe no-op per contract C13.
        if ($this->runTraceRecorder !== null && $inboundActionId !== null) {
            if ($approved) {
                // A null content for any operationId other than the two
                // coding-workspace mutations, identical to today's
                // behavior. For those two, a scope_surface approval is
                // closed with its own distinct content shape (never the
                // ordinary change shape, since no write/delete actually
                // ran) so RunTraceQuery::scopeSurfaceStateForRun() can
                // tell the two apart — see resume()'s identical branch.
                $content = $confirmationType === 'scope_surface'
                    ? json_encode(['confirmation_type' => 'scope_surface', 'approved' => true])
                    : ($apiCallExecuted ? $this->codingWorkspaceChangeActionContent($pending['operationId'] ?? '', $pending['arguments'] ?? []) : null);

                $this->runTraceRecorder->closeAction(
                    $inboundActionId,
                    ActionOutcome::Success,
                    null,
                    $content,
                );
            } else {
                $this->runTraceRecorder->closeAction(
                    $inboundActionId,
                    ActionOutcome::Failure,
                    'User declined',
                );
            }
        }

        $toolData['pending_confirmation'] = null;
        $message->update(['tool_data' => $toolData]);

        // Continue with synchronous loop. Resolved once, before
        // buildToolsPayload()/applyContextWindowTrim() are called
        // (085-graceful-degradation, tasks.md T001 point 1) — never
        // re-evaluated inside this method's own loop, so every iteration
        // of this continuation uses one frozen decision (FR-006/SC-003).
        $decision = app(DegradationGate::class)->forRun($runId);

        $maxIterations = config('llm-client.agent_loop.max_iterations', 20);
        $tools = $this->buildToolsPayload($decision->withheldTools, $conversation);
        $formattedTools = $this->formatTools($conversation, $tools);
        $iteration = ($toolData['iteration'] ?? 1) + 1;

        for (; $iteration <= $maxIterations; $iteration++) {
            $rawMessages = $this->buildMessagesPayload($conversation, $runId);

            // Site 7: ContextReshape around applyContextWindowTrim in resumeSync().
            $reshapeActionId = null;
            if ($this->runTraceRecorder !== null && $currentStepId !== null) {
                $reshapeActionId = $this->runTraceRecorder->openAction(
                    $currentStepId,
                    ActionType::ContextReshape,
                    'window_trim',
                    $attemptGroupId,
                );
            }
            $trimmed = $this->applyContextWindowTrim($conversation, $rawMessages, $attemptGroupId, $decision);
            if ($this->runTraceRecorder !== null && $reshapeActionId !== null) {
                $this->runTraceRecorder->closeAction($reshapeActionId, ActionOutcome::Success);
            }

            $formatted = $this->formatMessages($conversation, $trimmed);

            // Site 5: LlmRequest around callLlmSync in resumeSync().
            $llmActionId = null;
            $modelName = $conversation->model;
            if ($modelName === null) {
                $modelName = config('llm-client.providers.' . $conversation->effectiveProviderType->value . '.default_model');
            }
            if ($this->runTraceRecorder !== null && $currentStepId !== null) {
                $llmActionId = $this->runTraceRecorder->openAction(
                    $currentStepId,
                    ActionType::LlmRequest,
                    $modelName,
                    $attemptGroupId,
                );
            }
            $response = $this->callLlmSync(
                $conversation,
                $formatted['messages'],
                $formattedTools,
                $formatted['system'],
                modelOverride: $decision->effectiveModel,
                serverIdOverride: $decision->effectiveServerId,
            );
            if ($this->runTraceRecorder !== null && $llmActionId !== null) {
                $this->runTraceRecorder->closeAction($llmActionId, ActionOutcome::Success);
            }

            // Record LLM usage metrics (fire-and-forget, never throws)
            $this->recordUsageMetric($conversation, $attemptGroupId, $response, $formatted['messages']);

            $choice = $response['choices'][0] ?? null;
            if (!$choice) {
                $agentId = $conversation->character ?? $conversation->id;
                $conversation->update(['is_processing' => false]);


                return ['status' => 'error', 'content' => 'No response from LLM', 'message_id' => null];
            }

            $responseMessage = $choice['message'] ?? [];
            $content = $responseMessage['content'] ?? '';
            $toolCalls = $responseMessage['tool_calls'] ?? [];

            if (empty($toolCalls)) {
                // Close the step and run on completion.
                if ($this->runTraceRecorder !== null && $runId !== null) {
                    if ($currentStepId !== null) {
                        $this->runTraceRecorder->closeStep(
                            $currentStepId,
                            RunEndState::Completed,
                        );
                    }
                    $this->runTraceRecorder->closeRun(
                        $runId,
                        RunEndState::Completed,
                    );
                }

                // A response completing under a crossed reduction threshold
                // is disclosed, never silently returned as if it were
                // ordinary (085-graceful-degradation, FR-004, research.md
                // D10) — mirrors run()'s own completion-branch wiring
                // verbatim (T048), the disclosure sentence prepended to the
                // same $content both the stored message and the returned
                // array read below, so the two can never disagree about
                // what the user was told.
                $degradationBlock = null;
                if ($decision->outcome === DegradationDecision::OUTCOME_REDUCED) {
                    $disclosure = $decision->composeDisclosure();
                    if ($disclosure !== null) {
                        $content = $disclosure.' '.$content;
                    }
                    $degradationBlock = $decision->toDisclosureArray();
                }

                // A routing decision, if any is still undisclosed, is
                // announced here — after the degradation block and before
                // the handoff block, mirroring run()'s own insertion
                // verbatim (102-router-pattern, US2, contracts §6's
                // ordering).
                $routingDisclosure = $this->composeRoutingDisclosure($conversation);
                if ($routingDisclosure !== null) {
                    $content = $routingDisclosure.' '.$content;
                }

                // A handoff, if any is still undisclosed, is announced
                // here — after the degradation block so it prepends last,
                // landing first in the final string (093-agent-handoff,
                // US2, contracts §2's own ordering; mirrors run()'s own
                // wiring verbatim).
                $handoffDisclosure = $this->composeHandoffDisclosure($conversation);
                if ($handoffDisclosure !== null) {
                    $content = $handoffDisclosure.' '.$content;
                }

                $assistantMessage = Message::create([
                    'conversation_id' => $conversation->id,
                    'content' => $content,
                    'role' => 'assistant',
                    'user' => $conversation->character,
                    'responseTime' => 0,
                    'tool_data' => $degradationBlock !== null ? ['degradation' => $degradationBlock] : null,
                ]);

                $agentId = $conversation->character ?? $conversation->id;
                $conversation->update(['is_processing' => false]);


                return [
                    'status' => 'completed',
                    'content' => $content,
                    'message_id' => $assistantMessage->id,
                ] + ($degradationBlock !== null ? ['degraded' => true, 'degradation' => $degradationBlock] : []);
            }

            // Handle tool calls in the continuation
            $toolResults = [];

            // 101-parallel-subagent-execution (US1, Grounding note item 6
            // -- Site 6): the identical batch-detection check run()'s own
            // loop makes above, ahead of resumeSync()'s own,
            // structurally-equivalent loop.
            $batchDelegationResults = $this->resolveDelegateToHelperBatchResults($toolCalls, $conversation);

            foreach ($toolCalls as $tcIndex => $toolCall) {
                $toolName = $toolCall['function']['name'] ?? '';
                $arguments = json_decode($toolCall['function']['arguments'] ?? '{}', true) ?: [];

                // See the identical check in run()'s own tool-call loop:
                // checked before every individual tool-call execution so a
                // burst within one LLM turn can be stopped mid-batch, with
                // every not-yet-executed call in the batch left coherently
                // answered.
                $workDecision = app(ConversationWorkGate::class)->evaluate($conversation->id);
                if ($workDecision->isStop()) {
                    foreach (array_slice($toolCalls, $tcIndex) as $unexecutedCall) {
                        $toolResults[] = [
                            'tool_call_id' => $unexecutedCall['id'] ?? '',
                            'content' => ConversationWorkDecision::UNEXECUTED_TOOL_RESULT,
                        ];
                    }

                    return $this->stopForConversationWorkCeiling(
                        $conversation,
                        $runId,
                        $currentStepId,
                        $toolCalls,
                        $toolResults,
                        $iteration,
                        $workDecision,
                    );
                }

                // See the identical check in run()'s own tool-call loop
                // (085-graceful-degradation, research.md D6): beside, never
                // inside, the ConversationWorkGate check above.
                if (in_array($toolName, $decision->withheldTools, true)) {
                    foreach (array_slice($toolCalls, $tcIndex) as $unexecutedCall) {
                        $toolResults[] = [
                            'tool_call_id' => $unexecutedCall['id'] ?? '',
                            'content' => DegradationDecision::UNEXECUTED_TOOL_RESULT,
                        ];
                    }

                    return $this->stopForWithheldTool(
                        $conversation,
                        $runId,
                        $currentStepId,
                        $toolCalls,
                        $toolResults,
                        $iteration,
                        $decision,
                    );
                }

                // Site 6: ToolInvocation around executeMetaTool in resumeSync().
                $toolActionId = null;
                if ($this->runTraceRecorder !== null && $currentStepId !== null) {
                    $toolActionId = $this->runTraceRecorder->openAction(
                        $currentStepId,
                        ActionType::ToolInvocation,
                        $toolName,
                        $attemptGroupId,
                    );
                }

                // 101-parallel-subagent-execution (US1, contracts §1): see
                // the identical check in run()'s own tool-call loop above.
                if (in_array($toolName, ['delegate_to_helper', 'assign_part'], true) && $batchDelegationResults !== null && array_key_exists($toolCall['id'] ?? '', $batchDelegationResults)) {
                    $result = json_encode($batchDelegationResults[$toolCall['id'] ?? '']);
                } else {
                    $result = $this->executeMetaTool($toolName, $arguments, $conversation, $runId);
                }
                $decoded = json_decode($result, true);

                if (is_array($decoded) && !empty($decoded['__requires_confirmation'])) {
                    $confirmationType = $decoded['confirmation_type'] ?? 'api_call';

                    if ($confirmationType === 'declarative_memory') {
                        $pendingConfirmation = [
                            'tool_name' => 'propose_declarative_memory',
                            'confirmation_type' => 'declarative_memory',
                            'type' => $decoded['type'] ?? '',
                            'content' => $decoded['content'] ?? '',
                            'existingId' => $decoded['existingId'] ?? null,
                            'expires_at' => now()->addSeconds(config('llm-client.agent_loop.confirmation_timeout', 300))->toIso8601String(),
                        ];

                        $confirmationPayload = [
                            'confirmation_type' => 'declarative_memory',
                            'type' => $decoded['type'] ?? '',
                            'content' => $decoded['content'] ?? '',
                            'existingId' => $decoded['existingId'] ?? null,
                            'expires_at' => $pendingConfirmation['expires_at'],
                        ];
                    } else {
                        // Default: execute_operation (api_call), or its
                        // scope_surface variant — see run()'s identical
                        // construction, immediately above the same
                        // relative point in that sibling method.
                        $pendingConfirmation = [
                            'tool_name' => 'execute_operation',
                            'confirmation_type' => $confirmationType,
                            'operationId' => $decoded['operationId'],
                            'method' => $decoded['method'],
                            'path' => $decoded['path'],
                            'arguments' => $decoded['parameters'] ?? [],
                            'expires_at' => now()->addSeconds(config('llm-client.agent_loop.confirmation_timeout', 300))->toIso8601String(),
                        ];

                        $confirmationPayload = [
                            'confirmation_type' => $confirmationType,
                            'operationId' => $decoded['operationId'],
                            'method' => $decoded['method'],
                            'path' => $decoded['path'],
                            'arguments' => $decoded['parameters'] ?? [],
                            'expires_at' => $pendingConfirmation['expires_at'],
                        ];

                        if ($confirmationType === 'scope_surface') {
                            $pendingConfirmation['files_touched_so_far'] = $decoded['files_touched_so_far'] ?? [];
                            $pendingConfirmation['would_add'] = $decoded['would_add'] ?? null;
                            $pendingConfirmation['threshold'] = $decoded['threshold'] ?? null;

                            $confirmationPayload['files_touched_so_far'] = $pendingConfirmation['files_touched_so_far'];
                            $confirmationPayload['would_add'] = $pendingConfirmation['would_add'];
                            $confirmationPayload['threshold'] = $pendingConfirmation['threshold'];
                        }
                    }

                    // Close the tool action as awaiting confirmation and store
                    // action_id in tool_data for the next resume (T029b).
                    if ($this->runTraceRecorder !== null && $toolActionId !== null) {
                        $this->runTraceRecorder->closeAction(
                            $toolActionId,
                            ActionOutcome::AwaitingConfirmation,
                        );
                    }

                    // The step stays open across the pause — its duration includes
                    // the human wait (FR-004), and the resuming process closes it
                    // with the wait portion recorded. Closing it here instead would
                    // leave a second pause in the same run with no wait_ms at all,
                    // since the resumed close would hit the terminal guard.
                    $assistantMessage = Message::create([
                        'conversation_id' => $conversation->id,
                        'content' => $content ?: '',
                        'role' => 'assistant',
                        'user' => $conversation->character,
                        'responseTime' => 0,
                        'tool_data' => [
                            'tool_calls' => $toolCalls,
                            'tool_results' => null,
                            'iteration' => $iteration,
                            'pending_confirmation' => $pendingConfirmation,
                            'run_id' => $runId,
                            'step_id' => $currentStepId,
                            'paused_at' => now()->toIso8601String(),
                            'action_id' => $toolActionId,
                        ],
                    ]);

                    return [
                        'status' => 'confirmation_required',
                        'content' => $content ?: '',
                        'message_id' => $assistantMessage->id,
                        'confirmation' => $confirmationPayload,
                    ];
                }

                // Close tool action on normal completion. Feature 111 (US1):
                // record the page/text envelope (JSON) as the action content
                // so the consulted-source manifest (T014) can read source.url.
                if ($this->runTraceRecorder !== null && $toolActionId !== null) {
                    $actionContent = ($toolName === 'execute_operation' && ($arguments['operationId'] ?? '') === self::PAGE_TEXT_OPERATION_ID)
                        ? $result
                        : null;
                    $this->runTraceRecorder->closeAction($toolActionId, ActionOutcome::Success, null, $actionContent);
                }

                // Tool executed (not a confirmation pause) — record its outcome.
                $this->recordToolMetric($conversation, $attemptGroupId, $toolName, $decoded);

                // Site 8: ContextReshape around condenseToolResult in resumeSync().
                $condenseActionId = null;
                $toolResultEntry = $this->condenseToolResult($result, $conversation->id, $toolName);
                $condenseMethod = $toolResultEntry['method'] ?? null;
                if ($this->runTraceRecorder !== null && $currentStepId !== null) {
                    $condenseActionId = $this->runTraceRecorder->openAction(
                        $currentStepId,
                        ActionType::ContextReshape,
                        $condenseMethod ?? 'passthrough',
                        null,
                    );
                }
                if ($this->runTraceRecorder !== null && $condenseActionId !== null) {
                    $this->runTraceRecorder->closeAction($condenseActionId, ActionOutcome::Success);
                }

                $toolResults[] = [
                    'tool_call_id' => $toolCall['id'] ?? '',
                    'content' => $toolResultEntry['content'],
                ] + array_filter($toolResultEntry, fn ($k) => in_array($k, ['reference_id', 'original_tokens', 'condensed_tokens', 'method', 'condensed']), ARRAY_FILTER_USE_KEY);
            }

            // Close the step before continuing to the next iteration.
            if ($this->runTraceRecorder !== null && $runId !== null && $currentStepId !== null) {
                $this->runTraceRecorder->closeStep(
                    $currentStepId,
                    RunEndState::Completed,
                );
                $currentStepId = null;
            }

            Message::create([
                'conversation_id' => $conversation->id,
                'content' => $content ?: '',
                'role' => 'assistant',
                'user' => $conversation->character,
                'responseTime' => 0,
                'tool_data' => [
                    'tool_calls' => $toolCalls,
                    'tool_results' => $toolResults,
                    'iteration' => $iteration,
                    'pending_confirmation' => null,
                ],
            ]);

            // Open a new step for the next iteration.
            if ($this->runTraceRecorder !== null && $runId !== null) {
                $currentStepId = $this->runTraceRecorder->openStep(
                    $runId,
                    null, // position auto-assigned
                    $attemptGroupId,
                );
            }
        }

        // Close the run on exit — stopped early due to max iterations (FR-010).
        if ($this->runTraceRecorder !== null && $runId !== null) {
            if ($currentStepId !== null) {
                $this->runTraceRecorder->closeStep(
                    $currentStepId,
                    RunEndState::StoppedEarly,
                    'Maximum iterations reached',
                );
            }
            $this->runTraceRecorder->closeRun(
                $runId,
                RunEndState::StoppedEarly,
                'Maximum iterations reached',
            );
        }

        $agentId = $conversation->character ?? $conversation->id;
        $conversation->update(['is_processing' => false]);


        return ['status' => 'error', 'content' => 'Maximum iterations reached', 'message_id' => null];
    }

    /**
     * Apply context window trimming to the canonical message array.
     * Inserts between buildMessagesPayload() and formatMessages() — the single shared seam.
     *
     * The trimmed array is used only for the request payload; the stored transcript is untouched.
     *
     * @param Conversation $conversation The conversation context.
     * @param array $messages Canonical OpenAI-shaped message array from buildMessagesPayload().
     * @param DegradationDecision|null $decision The governing degradation decision for this
     *        response, resolved once by the caller via DegradationGate::forRun() — when
     *        'reduced', resolves the effective model and scales the history budget
     *        (085-graceful-degradation, research.md D4/D5).
     * @return array Trimmed canonical message array.
     */
    private function applyContextWindowTrim(Conversation $conversation, array $messages, ?string $attemptGroupId = null, ?DegradationDecision $decision = null): array
    {
        // A rung's substitute_server_id, when set, must govern here too —
        // not only at dispatch (callLlmSync()/dispatchStreamRequest()) —
        // since the token estimator below is resolved from whichever
        // provider is about to actually receive the request; trimming
        // against the wrong provider's tokenizer would silently mis-size
        // the budget (085-graceful-degradation, research.md D4a).
        $server = $decision?->effectiveServerId !== null
            ? (Server::find($decision->effectiveServerId) ?? $conversation->server)
            : $conversation->server;
        $providerType = $server !== null
            ? ($conversation->provider_override ?? $server->provider_type)
            : $conversation->effectiveProviderType;

        // Resolve the provider and build the estimator closure.
        $provider = $this->providerRegistry->resolveByType($providerType, $server);
        $model = $decision?->effectiveModel ?? $conversation->model;
        $estimator = fn (string $text) => $provider->countTokens($text, $model);

        /** @var \ClarionApp\LlmClient\ValueObjects\ContextManagementOutcome $outcome */
        $outcome = new ContextManagementOutcome(
            contextCapacity: 0,
            historyBudget: 0,
            tokensBefore: 0,
            tokensAfter: 0,
            model: null,
            providerType: null,
        );

        // The degradation ladder's history_budget_ratio lever
        // (085-graceful-degradation, research.md D5): resolve the
        // ordinary model-aware budget first, then scale it by the
        // governing rung's ratio — never a second trimming algorithm,
        // only a different number handed to the existing one. Composes
        // correctly with a simultaneously-substituted model because
        // $model above is already the substitute's, so the budget being
        // scaled here is the substitute's own, not the original model's.
        $historyBudgetOverride = null;
        if ($decision !== null && $decision->historyBudgetRatio !== null) {
            $overrideSystemEstimate = 0;
            if (!empty($messages) && ($messages[0]['role'] ?? null) === 'system') {
                $overrideSystemEstimate = $estimator((string) ($messages[0]['content'] ?? ''));
            }
            $ordinaryHistoryBudget = $this->contextWindowBudgeter->resolveHistoryBudget($model, $providerType, $overrideSystemEstimate);
            $historyBudgetOverride = (int) bcmul((string) $ordinaryHistoryBudget, (string) $decision->historyBudgetRatio, 0);
        }

        // Try condensation first if available, then fall back to trimming
        if ($this->conversationCondenser) {
            $result = $this->conversationCondenser->condenseOrTrim(
                $messages,
                $model,
                $providerType,
                $estimator,
                $conversation->id,
                $historyBudgetOverride,
                $server,
                $outcome
            );
        } else {
            $result = $this->contextWindowBudgeter->trim(
                $messages,
                $model,
                $providerType,
                $estimator,
                $conversation->id,
                $outcome,
                $historyBudgetOverride,
            );
        }

        // Record context management metrics (fire-and-forget, never throws).
        if ($this->metricsRecorder !== null) {
            $this->recordContextManagementMetric(
                $conversation,
                $attemptGroupId,
                $outcome
            );
        }

        return $result;
    }

    /**
     * Format messages using MessageFormatter for the conversation's effective provider type.
     * Uses provider_override if set, otherwise falls back to server provider_type.
     */
    private function formatMessages(Conversation $conversation, array $messages): array
    {
        // A bound conversation's instructions are appended onto the raw
        // messages array's own system-role entry BEFORE provider
        // formatting — never only onto the post-formatting $formatted['system']
        // result. MessageFormatter's OpenAI/LlamaCpp pass-through branch
        // always returns an empty 'system' string and keeps the system
        // message inline in 'messages', so appending only after
        // formatForProvider() would silently drop the bound instructions
        // for those provider families (090-agent-version-binding, T027).
        $definition = $this->agentDefinitionResolver->effectiveDefinitionFor($conversation);
        if ($definition !== null) {
            $messages = $this->appendBoundInstructions($messages, $definition->instructions);
        }

        $providerType = $conversation->effectiveProviderType;
        return $this->messageFormatter->formatForProvider($messages, $providerType);
    }

    /**
     * Appends a bound agent version's instructions onto the first
     * system-role entry in $messages, or prepends a new one if none exists
     * (matching buildMessagesPayload()'s own convention of always placing
     * its system entry first when present). No-op when $instructions is
     * effectively absent from the caller's perspective — callers only
     * invoke this when a bound AgentDefinition was actually resolved.
     */
    private function appendBoundInstructions(array $messages, string $instructions): array
    {
        foreach ($messages as $index => $message) {
            $role = is_array($message) ? ($message['role'] ?? null) : ($message->role ?? null);
            if ($role === 'system') {
                if (is_array($message)) {
                    $messages[$index]['content'] = ($message['content'] ?? '') . "\n\n" . $instructions;
                } else {
                    $messages[$index]->content = ($message->content ?? '') . "\n\n" . $instructions;
                }

                return $messages;
            }
        }

        array_unshift($messages, ['role' => 'system', 'content' => $instructions]);

        return $messages;
    }

    /**
     * Format tools using ToolFormatter for the conversation's effective provider type.
     * Uses provider_override if set, otherwise falls back to server provider_type.
     */
    private function formatTools(Conversation $conversation, array $tools): array
    {
        $providerType = $conversation->effectiveProviderType;
        return $this->toolFormatter->formatForProvider($tools, $providerType);
    }

    /**
     * Make a synchronous (non-streaming) LLM API call.
     * Delegates to the resolved provider based on the conversation's effective provider type.
     */
    private function callLlmSync(
        Conversation $conversation,
        array $messages,
        array $tools,
        string $system = '',
        ?string $responseFormat = null,
        ?string $modelOverride = null,
        ?string $serverIdOverride = null,
    ): array {
        // A rung's substitute_server_id, when set, must actually change
        // dispatch (085-graceful-degradation, research.md D4a) — a
        // since-deleted substitute falls back to the conversation's own
        // server, never throws, matching DegradationGate::forRun()'s own
        // tolerance for a since-deleted governing rung (research.md D3).
        $server = $serverIdOverride !== null
            ? (Server::find($serverIdOverride) ?? $conversation->server)
            : $conversation->server;
        if (!$server) {
            throw new \RuntimeException('No LLM server configured');
        }

        // Re-derived from the RESOLVED $server, not $conversation->server —
        // Conversation::effectiveProviderType is always anchored to the
        // conversation's own server, which would silently ignore a
        // substitute server naming a different provider family.
        $providerType = $conversation->provider_override ?? $server->provider_type;
        $provider = $this->providerRegistry->resolveByType($providerType, $server);

        // Use the degradation ladder's substitute model, then the
        // provider-specific default model when the conversation names none.
        $model = $modelOverride ?? $conversation->model;
        if ($model === null) {
            $model = config('llm-client.providers.' . $providerType->value . '.default_model');
        }

        $options = [
            'model' => $model,
            'temperature' => 1.0,
        ];

        // Pass system prompt for providers that support it (Anthropic)
        if ($system !== '') {
            $options['system'] = $system;
        }

        // Pass response_format for JSON mode support
        if (isset($responseFormat) && $responseFormat !== null) {
            $options['response_format'] = $responseFormat;
        }

        return $provider->chat($messages, $tools, $options);
    }

    /**
     * @param array $withheld Tool names to exclude from the returned payload —
     *        the degradation ladder's governing rung, if any
     *        (085-graceful-degradation, research.md D6). Additive, default
     *        [] — every existing call site continues to receive the full,
     *        unfiltered tool set when no reduction applies.
     * @param ?Conversation $conversation 103-manager-agent (Grounding note
     *        item 6, research.md D1/D2): when given and
     *        $conversation->channel === 'managed-task', plan_parts/
     *        assign_part are appended -- a manager's own helper
     *        conversations (channel = 'agent-delegation') and every
     *        ordinary interactive conversation never see them. Optional
     *        and defaulted to null so call sites outside the four
     *        run()/resumeSync() entry points remain unaffected.
     */
    public function buildToolsPayload(array $withheld = [], ?Conversation $conversation = null): array
    {
        $tools = [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'list_applications',
                    'description' => 'List all available API applications/packages that can be interacted with. Call this first to discover what applications are available.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => new \stdClass(),
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'execute_operation',
                    'description' => 'Execute an API operation. Pass the operationId from search_operations and a structured parameters object with optional "path", "query", and "body" sub-objects containing the respective parameters.',
                    'parameters' => $this->buildExecuteOperationSchema(),
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'search_operations',
                    'description' => 'Search API operations by natural language intent. Returns ranked results with operation IDs, summaries, methods, paths, and parameter schemas.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => [
                                'type' => 'string',
                                'description' => 'Natural language description of what you want to do (e.g., "create a contact", "list tasks")',
                            ],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'memory_create',
                    'description' => 'Create or update an entry in the memory store. Supports three scopes: scratch (ephemeral, discarded after this turn), short_term (persists across turns in this session), long_term (persists across sessions with LRU eviction).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'scope' => [
                                'type' => 'string',
                                'enum' => ['scratch', 'short_term', 'long_term'],
                                'description' => 'Memory scope: scratch (per-turn), short_term (per-session), long_term (persistent)',
                            ],
                            'key' => [
                                'type' => 'string',
                                'description' => 'Optional key for direct lookup (max 64 chars). Auto-generated UUID if omitted.',
                            ],
                            'content' => [
                                'type' => 'string',
                                'description' => 'The content to store',
                            ],
                        ],
                        'required' => ['scope', 'content'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'memory_read',
                    'description' => 'Read a memory entry by key or UUID. Updates last_accessed_at for LRU tracking.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'scope' => [
                                'type' => 'string',
                                'enum' => ['scratch', 'short_term', 'long_term'],
                                'description' => 'Memory scope',
                            ],
                            'identifier' => [
                                'type' => 'string',
                                'description' => 'Entry key or UUID',
                            ],
                        ],
                        'required' => ['scope', 'identifier'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'memory_search',
                    'description' => 'Search memory entries within a scope. Supports key_prefix (prefix match on key) and content (full-text search) modes.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'scope' => [
                                'type' => 'string',
                                'enum' => ['scratch', 'short_term', 'long_term'],
                                'description' => 'Memory scope',
                            ],
                            'query' => [
                                'type' => 'string',
                                'description' => 'Search query string',
                            ],
                            'mode' => [
                                'type' => 'string',
                                'enum' => ['key_prefix', 'content'],
                                'description' => 'Search mode: key_prefix (default) or content',
                            ],
                            'limit' => [
                                'type' => 'integer',
                                'description' => 'Maximum results (default 20, max 100)',
                            ],
                        ],
                        'required' => ['scope', 'query'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'memory_delete',
                    'description' => 'Delete a memory entry by key or UUID.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'scope' => [
                                'type' => 'string',
                                'enum' => ['scratch', 'short_term', 'long_term'],
                                'description' => 'Memory scope',
                            ],
                            'identifier' => [
                                'type' => 'string',
                                'description' => 'Entry key or UUID',
                            ],
                        ],
                        'required' => ['scope', 'identifier'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'propose_declarative_memory',
                    'description' => 'Propose a new declarative memory (fact, preference, or rule) to the user for confirmation. Nothing is persisted until the user explicitly confirms. Use this when you infer a new fact or preference about the user, or when you want to suggest a behavioral rule.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => [
                                'type' => 'string',
                                'enum' => ['fact', 'preference', 'rule'],
                                'description' => 'Type of declarative memory: fact (objective information), preference (user preference), or rule (binding behavioral constraint)',
                            ],
                            'content' => [
                                'type' => 'string',
                                'description' => 'The content to propose (e.g., "User prefers dark mode", "Always confirm before destructive actions")',
                            ],
                            'existingId' => [
                                'type' => 'string',
                                'description' => 'Optional: UUID of an existing entry to update (for inferred updates). Omit for new entries.',
                            ],
                        ],
                        'required' => ['type', 'content'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'handoff_to_agent',
                    'description' => 'Hand this conversation off to a different agent that is better suited to continue it. The receiving agent takes over from this point forward, governed solely by its own permissions — the user is told plainly that the handoff happened. Use this when the current request would be better served by a different, specific agent you know the id of.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'agent_id' => [
                                'type' => 'string',
                                'description' => 'The id of the agent to hand this conversation off to.',
                            ],
                        ],
                        'required' => ['agent_id'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'delegate_to_helper',
                    'description' => 'Hand a self-contained piece of work to one of your assigned helpers. State exactly what is needed and what context applies — the helper sees only what you state here, nothing else from this conversation. Waits for the helper\'s outcome (success, partial result, or failure) before returning. Use this for a bounded task a narrower, specialized helper is better suited to carry out on your behalf, not to hand off the whole conversation (use handoff_to_agent for that).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'helper_agent_id' => [
                                'type' => 'string',
                                'description' => 'The id of one of your own assigned helpers (see the Known Helpers section, if present). Delegating to any other agent is refused.',
                            ],
                            'task' => [
                                'type' => 'string',
                                'description' => 'What is needed — the self-contained statement of work for the helper to carry out.',
                            ],
                            'context' => [
                                'type' => 'string',
                                'description' => 'Optional. The specific context the helper needs to carry out the task. Only what you include here is visible to the helper — nothing else from this conversation crosses the boundary.',
                            ],
                        ],
                        'required' => ['helper_agent_id', 'task'],
                    ],
                ],
            ],
        ];

        // 103-manager-agent (US1, contracts/manager-agent-meta-tools.md
        // §1/§2, research.md D1): only inside the manager's own dedicated
        // conversation -- never inside a helper's own conversation, and
        // never in an ordinary interactive conversation.
        if ($conversation !== null && $conversation->channel === 'managed-task') {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'plan_parts',
                    'description' => 'Break the task into one or more distinct, self-contained parts, each assignable to a single helper. If the task genuinely does not need splitting, call this with exactly one part covering the whole task rather than inventing an artificial subdivision. May be called again later if you discover the task needs further breakdown — new parts are added, existing parts are never removed or renumbered.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'parts' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'description' => [
                                            'type' => 'string',
                                            'description' => 'What is needed for this part — self-contained enough to hand to a helper as its task.',
                                        ],
                                    ],
                                    'required' => ['description'],
                                ],
                                'minItems' => 1,
                            ],
                        ],
                        'required' => ['parts'],
                    ],
                ],
            ];

            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'assign_part',
                    'description' => 'Assign one part of the task to one of your assigned helpers — for a first attempt, a correction (send back to the same helper stating what was wrong), or a reassignment (send to a different helper after the first could not complete it). State exactly what is needed and what context applies, same as delegating any bounded task — the helper sees only what you state here. Refused if this part is already accepted, or already has an assignment outstanding.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'part_id' => [
                                'type' => 'string',
                                'description' => 'The part being assigned (from plan_parts).',
                            ],
                            'helper_agent_id' => [
                                'type' => 'string',
                                'description' => 'The id of one of your own assigned helpers.',
                            ],
                            'task' => [
                                'type' => 'string',
                                'description' => 'What is needed. For a correction, state specifically what was wrong with the prior attempt.',
                            ],
                            'context' => [
                                'type' => 'string',
                                'description' => 'Optional. For a correction, include the prior attempt\'s own output so the helper can see what to fix — nothing carries over automatically.',
                            ],
                        ],
                        'required' => ['part_id', 'helper_agent_id', 'task'],
                    ],
                ],
            ];

            // 103-manager-agent (US2, contracts/manager-agent-meta-tools.md
            // §3): verbatim schema, gated identically to plan_parts/
            // assign_part above.
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'accept_part',
                    'description' => 'Mark a part done because its most recent result actually satisfies what was asked. Only call this after reviewing the result — do not accept a part that is incomplete, incorrect, or off-task; use assign_part again instead to request a correction.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'part_id' => [
                                'type' => 'string',
                            ],
                        ],
                        'required' => ['part_id'],
                    ],
                ],
            ];

            // 103-manager-agent (US5, contracts/manager-agent-meta-tools.md
            // §4): verbatim schema, gated identically to plan_parts/
            // assign_part/accept_part above.
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'report_shortfall',
                    'description' => 'Report that a part cannot be completed — after reassignment to another suitable helper was tried and also fell short, or was not possible, and adapting the part\'s scope was not workable either. This closes the part; its shortfall will be named honestly in the final response rather than presented as done.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'part_id' => [
                                'type' => 'string',
                            ],
                            'reason' => [
                                'type' => 'string',
                                'description' => 'A specific, honest account of why this part could not be completed — named in the final response verbatim or near-verbatim.',
                            ],
                        ],
                        'required' => ['part_id', 'reason'],
                    ],
                ],
            ];

            // 103-manager-agent (US3, contracts/manager-agent-meta-tools.md
            // §5): verbatim schema, gated identically to plan_parts/
            // assign_part/accept_part/report_shortfall above.
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'finalize_task',
                    'description' => 'Conclude the managed task and deliver the final response — a single, coherent answer to the original task, not a list of separate worker outputs. Every part must be accepted or reported as a shortfall before you can finalize; if any part conflicts with another (e.g. contradictory conclusions), name the conflict in the response rather than silently picking one.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'final_response' => [
                                'type' => 'string',
                                'description' => 'The single coherent answer to the original task.',
                            ],
                            'shortfall_note' => [
                                'type' => 'string',
                                'description' => 'Optional. Required if any part is reported_as_shortfall — a plain, specific account of what fell short and why.',
                            ],
                        ],
                        'required' => ['final_response'],
                    ],
                ],
            ];
        }

        // 108-shared-task-workspace (US1, contracts/task-workspace-meta-tool.md
        // §1, research.md D5): gated on resolveManagedTaskIdForConversation()
        // returning non-null -- deliberately NOT the channel === 'managed-task'
        // gate the 103 block above uses. This widens the audience to every
        // helper conversation nested under a managed task's tree (any
        // nesting depth), not only the manager's own conversation
        // (mutation-checklist row 9's own named risk -- reusing the
        // manager-only gate here would silently exclude every helper).
        if ($conversation !== null && $this->taskWorkspaceQuery->resolveManagedTaskIdForConversation($conversation->id) !== null) {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'record_task_note',
                    'description' => 'Add a finding, decision, or open question to this task\'s shared workspace, visible to every other agent currently working on the same task. Use it to record something teammates should not have to re-derive themselves — do not use it for your own private scratch work. Entries cannot be edited or removed once written; if you change your mind, record a new entry rather than trying to correct an old one.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'content' => [
                                'type' => 'string',
                                'description' => 'The finding, decision, or open question, written so a teammate with no other context can understand it.',
                            ],
                        ],
                        'required' => ['content'],
                    ],
                ],
            ];
        }

        if (empty($withheld)) {
            return $tools;
        }

        return array_values(array_filter(
            $tools,
            fn (array $tool) => !in_array($tool['function']['name'] ?? null, $withheld, true),
        ));
    }

    /**
     * Build the execute_operation tool parameters schema.
     *
     * The schema is deliberately generic. One execute_operation tool serves every
     * operation in a turn, so it cannot describe any single operation's parameters
     * without misdescribing all the others. Per-operation schemas reach the LLM
     * through the "Known Operations" prompt section and search_operations results
     * instead; this schema only fixes the {path, query, body} envelope.
     *
     * @return array The parameters schema for execute_operation
     */
    private function buildExecuteOperationSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'operationId' => [
                    'type' => 'string',
                    'description' => 'The operationId from search_operations',
                ],
                'parameters' => [
                    'type' => 'object',
                    'description' => 'Operation parameters as a structured object with optional path, query, and body sub-objects. Use the parameter schema given for this operationId in the Known Operations section or in the search_operations results.',
                    'properties' => [
                        'path' => [
                            'type' => 'object',
                            'description' => 'Path parameters for URL substitution (e.g., {"id": "123"} for /contacts/{id})',
                            'properties' => new \stdClass(),
                            'additionalProperties' => true,
                        ],
                        'query' => [
                            'type' => 'object',
                            'description' => 'Query string parameters (e.g., {"search": "john", "page": "1"})',
                            'properties' => new \stdClass(),
                            'additionalProperties' => true,
                        ],
                        'body' => [
                            'type' => 'object',
                            'description' => 'Request body fields for POST/PUT/PATCH operations',
                            'properties' => new \stdClass(),
                            'additionalProperties' => true,
                        ],
                    ],
                ],
            ],
            'required' => ['operationId'],
        ];
    }

    /**
     * Record LLM usage for a completed request (fire-and-forget; never throws).
     *
     * @param array $response  Unified LLM response (may include a 'usage' key)
     * @param array $messages  The formatted request messages, for estimation fallback
     */
    private function recordUsageMetric(Conversation $conversation, string $attemptGroupId, array $response, array $messages): void
    {
        if ($this->metricsRecorder === null) {
            return;
        }

        $this->metricsRecorder->recordUsage(
            conversationId: $conversation->id,
            userId: (string) $conversation->user_id,
            attemptGroupId: $attemptGroupId,
            providerUsage: $response['usage'] ?? [],
            inputText: $this->concatMessageText($messages),
            outputText: $response['choices'][0]['message']['content'] ?? '',
            model: $conversation->model,
            providerType: $conversation->effectiveProviderType?->value,
            agentId: $conversation->character ?: null,
        );
    }

    /**
     * Record the outcome of a single tool invocation (fire-and-forget; never throws).
     *
     * Success/failure is derived from the decoded tool result: meta tools signal
     * failure by returning a JSON payload containing an "error" key.
     *
     * @param mixed $decoded  The json_decode()'d tool result
     */
    private function recordToolMetric(Conversation $conversation, string $attemptGroupId, string $toolName, mixed $decoded): void
    {
        if ($this->metricsRecorder === null) {
            return;
        }

        $error = (is_array($decoded) && isset($decoded['error'])) ? (string) $decoded['error'] : null;

        $this->metricsRecorder->recordToolInvocation(
            conversationId: $conversation->id,
            userId: (string) $conversation->user_id,
            attemptGroupId: $attemptGroupId,
            toolName: $toolName,
            success: $error === null,
            failureCategory: $error === null ? null : ToolFailureCategory::fromErrorMessage($error),
            agentId: $conversation->character ?: null,
        );
    }

    /**
     * Record context management outcome (fire-and-forget; never throws).
     *
     * Skipped entirely when the `context_management_metrics.enabled` config
     * flag is false, or when the MetricsRecorder is not injected.
     */
    private function recordContextManagementMetric(Conversation $conversation, ?string $attemptGroupId, ContextManagementOutcome $outcome): void
    {
        if ($this->metricsRecorder === null) {
            return;
        }

        if (!(config('llm-client.context_management_metrics.enabled', true))) {
            return;
        }

        $this->metricsRecorder->recordContextManagement(
            conversationId: $conversation->id,
            userId: (string) $conversation->user_id,
            attemptGroupId: $attemptGroupId,
            outcome: $outcome,
        );
    }

    /**
     * Concatenate message text for token estimation. Non-string content
     * (e.g. multimodal parts) contributes nothing to the character count.
     */
    private function concatMessageText(array $messages): string
    {
        return implode("\n", array_map(
            fn ($m) => is_string($m['content'] ?? null) ? $m['content'] : '',
            $messages
        ));
    }

    public function executeMetaTool(string $toolName, array $arguments, Conversation $conversation, ?string $runId = null, bool $unattended = false): string
    {
        return match ($toolName) {
            'list_applications' => $this->handleListApplications(),
            'execute_operation' => $this->handleExecuteOperation($arguments, $conversation, $runId, $unattended),
            'search_operations' => $this->handleSearchOperations($arguments, $conversation),
            'memory_create' => $this->handleMemoryCreate($arguments, $conversation),
            'memory_read' => $this->handleMemoryRead($arguments, $conversation),
            'memory_search' => $this->handleMemorySearch($arguments, $conversation),
            'memory_delete' => $this->handleMemoryDelete($arguments, $conversation),
            'propose_declarative_memory' => $this->handleProposeDeclarativeMemory($arguments, $conversation),
            'handoff_to_agent' => $this->handleHandoffToAgent($arguments, $conversation),
            'delegate_to_helper' => $this->handleDelegateToHelper($arguments, $conversation),
            'plan_parts' => $this->handlePlanParts($arguments, $conversation),
            'assign_part' => $this->handleAssignPart($arguments, $conversation),
            'accept_part' => $this->handleAcceptPart($arguments, $conversation),
            'report_shortfall' => $this->handleReportShortfall($arguments, $conversation),
            'finalize_task' => $this->handleFinalizeTask($arguments, $conversation),
            'record_task_note' => $this->handleRecordTaskNote($arguments, $conversation),
            default => json_encode(['error' => "Unknown tool: {$toolName}"]),
        };
    }

    private function handleMemoryCreate(array $arguments, Conversation $conversation): string
    {
        if ($this->memoryService === null) {
            return json_encode(['error' => 'Memory service not available']);
        }

        $scopeValue = $arguments['scope'] ?? '';
        $scope = MemoryScope::tryFrom($scopeValue);
        if (!$scope) {
            return json_encode(['error' => 'Invalid scope. Must be scratch, short_term, or long_term']);
        }

        $content = $arguments['content'] ?? '';
        if ($content === '') {
            return json_encode(['error' => 'content is required']);
        }

        $key = $arguments['key'] ?? null;
        $agent_id = $conversation->character ?? $conversation->id;
        $user_id = $conversation->user_id;
        $conversation_id = $conversation->id;

        // For scratch scope, turn_id is required - use current iteration
        $turn_id = $arguments['turn_id'] ?? null;

        try {
            $entry = $this->memoryService->create(
                $scope,
                $agent_id,
                $user_id,
                $conversation_id,
                $turn_id,
                $key,
                $content
            );

            return json_encode([
                'id' => $entry->id,
                'key' => $entry->key,
                'scope' => $entry->scope->value,
                'created' => true,
            ]);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }

    private function handleMemoryRead(array $arguments, Conversation $conversation): string
    {
        if ($this->memoryService === null) {
            return json_encode(['error' => 'Memory service not available']);
        }

        $scopeValue = $arguments['scope'] ?? '';
        $scope = MemoryScope::tryFrom($scopeValue);
        if (!$scope) {
            return json_encode(['error' => 'Invalid scope']);
        }

        $identifier = $arguments['identifier'] ?? '';
        if ($identifier === '') {
            return json_encode(['error' => 'identifier is required']);
        }

        $agent_id = $conversation->character ?? $conversation->id;

        $entry = $this->memoryService->read($scope, $agent_id, $identifier);
        if (!$entry) {
            return json_encode(['found' => false, 'error' => 'Entry not found']);
        }

        return json_encode([
            'id' => $entry->id,
            'key' => $entry->key,
            'scope' => $entry->scope->value,
            'content' => $entry->content,
            'created_at' => $entry->created_at?->toIso8601String(),
            'updated_at' => $entry->updated_at?->toIso8601String(),
        ]);
    }

    private function handleMemorySearch(array $arguments, Conversation $conversation): string
    {
        if ($this->memoryService === null) {
            return json_encode(['error' => 'Memory service not available']);
        }

        $scopeValue = $arguments['scope'] ?? '';
        $scope = MemoryScope::tryFrom($scopeValue);
        if (!$scope) {
            return json_encode(['error' => 'Invalid scope']);
        }

        $query = $arguments['query'] ?? '';
        if ($query === '') {
            return json_encode(['error' => 'query is required']);
        }

        $mode = $arguments['mode'] ?? 'key_prefix';
        $limit = (int) ($arguments['limit'] ?? config('llm-client.memory.search_default_limit', 20));

        $agent_id = $conversation->character ?? $conversation->id;

        $entries = $this->memoryService->search($scope, $agent_id, $query, $mode, $limit);

        $results = array_map(function ($entry) {
            return [
                'id' => $entry->id,
                'key' => $entry->key,
                'scope' => $entry->scope->value,
                'content' => $entry->content,
                'last_accessed_at' => $entry->last_accessed_at?->toIso8601String(),
            ];
        }, $entries);

        return json_encode(['results' => $results, 'count' => count($results)]);
    }

    private function handleMemoryDelete(array $arguments, Conversation $conversation): string
    {
        if ($this->memoryService === null) {
            return json_encode(['error' => 'Memory service not available']);
        }

        $scopeValue = $arguments['scope'] ?? '';
        $scope = MemoryScope::tryFrom($scopeValue);
        if (!$scope) {
            return json_encode(['error' => 'Invalid scope']);
        }

        $identifier = $arguments['identifier'] ?? '';
        if ($identifier === '') {
            return json_encode(['error' => 'identifier is required']);
        }

        $agent_id = $conversation->character ?? $conversation->id;

        $deleted = $this->memoryService->delete($scope, $agent_id, $identifier);

        return json_encode(['deleted' => $deleted]);
    }

    private function handleListApplications(): string
    {
        $packages = ClarionPackageServiceProvider::getPackageDescriptions();
        $apps = [];
        foreach ($packages as $name => $meta) {
            $apps[] = [
                'name' => $name,
                'description' => $meta['description'] ?? $name,
            ];
        }
        return json_encode($apps);
    }

    private function handleSearchOperations(array $arguments, Conversation $conversation): string
    {
        $query = $arguments['query'] ?? '';
        if (empty($query)) {
            return json_encode(['error' => 'query parameter is required']);
        }

        // Silently truncate long queries to a safe length
        $query = mb_substr($query, 0, 500);

        // 109-agent-as-capability (Phase 3/US1, data-model.md §5, Grounding
        // note 3): offerings matching the query must remain findable via
        // search_operations regardless of the real operation index's own
        // state -- appended into EVERY return path below, not only the
        // happy path.
        $offeringResults = $this->matchingCapabilityOfferingResults($query, $conversation);

        // 116-mcp-client-support (Foundational): cached external tools
        // matching the query must remain findable via search_operations
        // regardless of the real operation index's own state, exactly like
        // matchingCapabilityOfferingResults() just above -- appended into
        // EVERY return path below alongside $offeringResults, never only
        // the happy path.
        $externalToolResults = $this->matchingExternalToolResults($query, $conversation);

        // Graceful degradation: check table existence before search
        $searchService = app(OperationsSearchService::class);

        if (!$searchService->tableExists()) {
            return json_encode([
                'hint' => 'Search index is not available. Run reindex command first.',
                'results' => array_merge($offeringResults, $externalToolResults),
            ]);
        }

        $results = $searchService->search($query);

        if (empty($results)) {
            // Check if the table exists but is empty vs no matches
            try {
                $count = \Illuminate\Support\Facades\DB::table('operation_search_index')->count();
                if ($count === 0) {
                    return json_encode([
                        'hint' => "Search index is empty. Run 'php artisan llm-client:reindex' first.",
                        'results' => array_merge($offeringResults, $externalToolResults),
                    ]);
                }
                // Table has data but query returned no matches
                return json_encode([
                    'hint' => 'No operations matched your query. Try broader search terms or use list_applications to browse available applications.',
                    'results' => array_merge($offeringResults, $externalToolResults),
                ]);
            } catch (\Throwable $e) {
                // Fallback if count fails
                return json_encode([
                    'hint' => 'Search index is not available. Run reindex command first.',
                    'results' => array_merge($offeringResults, $externalToolResults),
                ]);
            }
        }

        // Format results - decode paramSchema from JSON string to array using safe helper
        $formatted = [];
        foreach ($results as $row) {
            $type = $row->type ?? 'operation';

            if ($type === 'prompt') {
                $formatted[] = [
                    'type' => 'prompt',
                    'id' => $row->operationId,
                    'package' => $row->package_name,
                    'summary' => $row->summary,
                    'content' => $row->promptContent,
                ];
            } else {
                $formatted[] = [
                    'type' => 'operation',
                    'operationId' => $row->operationId,
                    'summary' => $row->summary,
                    'method' => $row->method,
                    'path' => $row->path,
                    'paramSchema' => OperationsSearchService::safeDecodeParamSchema($row->paramSchema),
                ];
            }
        }

        $formatted = array_merge($formatted, $offeringResults, $externalToolResults);

        return json_encode(['results' => $formatted]);
    }

    /**
     * 109-agent-as-capability (Phase 3/US1, data-model.md §5, Grounding
     * note 3): a plain, non-indexed filter over CapabilityOffering rows
     * eligible to this conversation's own bound (caller) agent, matched
     * against capability_name/capability_description -- offering counts
     * per installation are small (research.md D2), so no full-text index
     * table is needed here, unlike operation_search_index's real-operation
     * case. Formatted via CapabilityCatalogMerger::formatOffering() (the
     * SAME recipe entriesFor() uses) plus the 'type' => 'operation'
     * wrapper every $formatted entry above already carries, so the two
     * call sites can never independently drift out of agreement about the
     * entry shape.
     *
     * @return array<int, array<string, mixed>>
     */
    private function matchingCapabilityOfferingResults(string $query, Conversation $conversation): array
    {
        if ($conversation->agent_id === null) {
            return [];
        }

        $needle = '%'.$query.'%';

        $offerings = \ClarionApp\LlmClient\Models\CapabilityOffering::where('caller_agent_id', $conversation->agent_id)
            ->where(function ($q) use ($needle) {
                $q->where('capability_name', 'like', $needle)
                    ->orWhere('capability_description', 'like', $needle);
            })
            ->get();

        return $offerings
            ->map(fn (\ClarionApp\LlmClient\Models\CapabilityOffering $offering) => array_merge(
                ['type' => 'operation'],
                CapabilityCatalogMerger::formatOffering($offering),
            ))
            ->values()
            ->all();
    }

    /**
     * 116-mcp-client-support (Foundational, research.md D4/D7): the sibling
     * of matchingCapabilityOfferingResults() immediately above for a
     * different non-OpenAPI source -- a plain, non-indexed filter over
     * cached McpClientTool rows belonging to a server this conversation's
     * own user is eligible for (McpClientServer::eligibleFor(), own rows
     * plus every installation-scoped row -- never another user's), matched
     * against the tool's own name/description. Read entirely from the
     * local cache a discovery refresh already populated -- this method
     * never talks to a configured server itself, the same "search never
     * calls a transport" guarantee matchingCapabilityOfferingResults()'s
     * own CapabilityOffering read already gets for free. Formatted via
     * McpClientToolCatalogMerger::formatTool() plus the identical
     * 'type' => 'operation' wrapper every other $formatted entry already
     * carries.
     *
     * @return array<int, array<string, mixed>>
     */
    private function matchingExternalToolResults(string $query, Conversation $conversation): array
    {
        if ($conversation->user_id === null) {
            return [];
        }

        $needle = '%'.$query.'%';

        $tools = \ClarionApp\LlmClient\Models\McpClientTool::query()
            ->active()
            ->whereHas('server', function ($q) use ($conversation) {
                $q->eligibleFor((string) $conversation->user_id);
            })
            ->where(function ($q) use ($needle) {
                $q->where('name', 'like', $needle)
                    ->orWhere('description', 'like', $needle);
            })
            ->with('server')
            ->get();

        return $tools
            ->filter(fn (\ClarionApp\LlmClient\Models\McpClientTool $tool) => $tool->server !== null)
            ->map(fn (\ClarionApp\LlmClient\Models\McpClientTool $tool) => array_merge(
                ['type' => 'operation'],
                McpClientToolCatalogMerger::formatTool($tool, $tool->server),
            ))
            ->values()
            ->all();
    }

    private function handleExecuteOperation(array $arguments, Conversation $conversation, ?string $runId = null, bool $unattended = false): string
    {
        $operationId = $arguments['operationId'] ?? '';
        if (empty($operationId)) {
            return json_encode(['error' => 'operationId is required']);
        }

        $params = $arguments['parameters'] ?? [];

        // 112-coding-agent (Foundational, D2, data-model.md §4): every
        // coding-workspace operation's own `project` path argument is
        // cross-checked against $conversation->coding_project_id here,
        // BEFORE anything else in this method runs — independent of, and
        // checked before, isOperationPermitted()/isConfirmationRequired(),
        // so a misconfigured or overly broad tools.allow can never leak
        // cross-project access. Runs for every operation under the
        // coding-workspace prefix, so a newly added operation under that
        // prefix is covered automatically.
        if (str_starts_with($operationId, self::CODING_WORKSPACE_OPERATION_PREFIX)) {
            $rejection = $this->enforceCodingProjectBinding($conversation, $params);
            if ($rejection !== null) {
                return $rejection;
            }
        }

        // 109-agent-as-capability (Phase 3/US1, contracts/
        // capability-agent-call.md "Dispatch", research.md D1): a
        // synthetic capability-offering operationId is the offering's own
        // UUID primary key -- a single indexed lookup, checked BEFORE the
        // ApiManager/ApiCallValidator path below, since a synthetic id
        // would never resolve there. A hit routes to the nested
        // capability-agent call and returns immediately; a miss (the
        // overwhelmingly common case) falls through to today's exact code,
        // unchanged.
        $offering = \ClarionApp\LlmClient\Models\CapabilityOffering::find($operationId);
        if ($offering !== null) {
            $result = app(DelegationService::class)->invokeAsCapability($conversation, $offering, $params['input'] ?? '');

            return json_encode($result);
        }

        // 116-mcp-client-support (Foundational, research.md D5): an O(1)
        // indexed lookup on McpClientTool's own unique
        // synthetic_operation_id column, checked BEFORE the operation-
        // cache/ApiManager resolution below, at the same cost class already
        // accepted for CapabilityOffering just above. Deliberately NOT an
        // early return like the capability-offering check above it: an
        // external tool call must still pass through every remaining line
        // of this method exactly as a built-in operation already does --
        // the bound-agent-version narrowing, the delegation-chain
        // narrowing, and the unattended refuse-and-stop guarantee -- so
        // only the two lines resolving $method/$pathTemplate and the
        // validator call itself branch on whether this lookup hit; every
        // line after that is byte-for-byte the same, unconditional code
        // regardless of which branch ran.
        $externalTool = \ClarionApp\LlmClient\Models\McpClientTool::findBySyntheticId($operationId);
        $externalServer = $externalTool?->server;

        if ($externalTool !== null && str_starts_with($operationId, 'mcp:') && $externalServer === null) {
            // The cached tool row survived its server's own removal (a
            // soft-deleted McpClientServer, since the cascade-delete
            // foreign key only fires on a real row deletion) -- treated
            // identically to a syntactically-external id with no matching
            // row at all: a clean, local "no longer offered" result, never
            // a fall-through to ApiManager's own "unknown operation" error.
            $externalTool = null;
        }

        if ($externalTool === null && str_starts_with($operationId, 'mcp:')) {
            return json_encode(['error' => 'This tool is no longer offered by its server. Search again for a current capability.']);
        }

        if ($externalTool !== null) {
            $method = 'MCP_EXTERNAL';
            $pathTemplate = "/mcp-client/{$externalServer->id}/{$externalTool->name}";
            $validation = app(McpClientCallValidator::class)->validate($operationId, $method, $pathTemplate);
        } else {
            // Check cache first — skip ApiManager lookup on hit
            $cached = $this->operationCache->get($conversation->id, $operationId);
            if ($cached) {
                $method = $cached['method'];
                $pathTemplate = $cached['path'];
            } else {
                $details = ApiManager::getOperationDetails($operationId);
                if (empty((array) $details)) {
                    return json_encode(['error' => "Unknown operation: {$operationId}"]);
                }

                // Cache the resolved operation details
                $this->operationCache->put($conversation->id, $operationId, $details);

                $method = strtoupper($details['method'] ?? 'GET');
                $pathTemplate = $details['path'] ?? '';
            }

            // Check confirmation/rejection
            $validation = ApiCallValidator::validate($operationId, $method, $pathTemplate);
        }

        // A bound agent version's own tools.deny/safety.* narrows what the
        // installation-wide check alone would have allowed — it never
        // widens past it (090-agent-version-binding, T028, contracts §3).
        // No-op when the conversation is unbound.
        $boundDefinition = $this->agentDefinitionResolver->effectiveDefinitionFor($conversation);
        if ($boundDefinition !== null && $validation['status'] !== ApiCallValidator::STATUS_REJECT) {
            if (!$boundDefinition->isOperationPermitted($operationId)) {
                $validation = [
                    'status' => ApiCallValidator::STATUS_REJECT,
                    'reason' => 'Operation not permitted by the agent version this conversation is bound to.',
                ];
            } elseif ($boundDefinition->isConfirmationRequired($operationId) && $validation['status'] !== ApiCallValidator::STATUS_CONFIRM) {
                $validation['status'] = ApiCallValidator::STATUS_CONFIRM;
            }
        }

        // A helper's own attempt is also bound by the CURRENT permissions
        // of every ancestor in the specific delegation chain that routed
        // the work to it — checked live, on every attempt, not only at
        // assignment time (100-subagent-tool-restrictions, FR-004/FR-005/
        // FR-006). Never overwrites an installation-denylist rejection
        // already found upstream; a chain-derived rejection does override
        // a prior `confirm` status (reject wins).
        if ($validation['status'] !== ApiCallValidator::STATUS_REJECT) {
            $chain = $this->effectiveBoundResolver->check($conversation, $operationId);
            if (!$chain['allowed']) {
                $validation = [
                    'status' => ApiCallValidator::STATUS_REJECT,
                    'reason' => "Operation not permitted: ancestor agent \"{$chain['blocking_agent_name']}\" ({$chain['levels_up']} level(s) up in this delegation chain) does not permit \"{$operationId}\".",
                ];
            }
        }

        // Unattended refuse-and-stop guarantee (scheduler-triggered runs):
        // inserted after the delegation-chain check above, which can itself
        // upgrade $validation['status'] to STATUS_REJECT ("reject wins",
        // overriding a prior confirm) -- checking any earlier would let an
        // unattended run miss a chain-derived rejection and hand it back to
        // the model as ordinary tool-result data below. A rejection always
        // stops the run outright rather than being fed back for the model
        // to try something else; a confirmation-required operation either
        // was pre-authorized in advance (checked once, against the bound
        // agent's own declared list, never via a live prompt) or the run
        // stops here too -- it is never auto-approved and never left
        // pending for nobody to answer.
        if ($unattended) {
            if ($validation['status'] === ApiCallValidator::STATUS_REJECT) {
                throw new UnattendedActionRefusedException(
                    $operationId,
                    "Action outside the permitted set: {$operationId}.",
                );
            }

            if ($validation['status'] === ApiCallValidator::STATUS_CONFIRM) {
                if ($boundDefinition !== null && $boundDefinition->isUnattendedAuthorized($operationId)) {
                    // Proceed exactly as if already confirmed -- falls
                    // through to executeApiCall() below without ever
                    // constructing the __requires_confirmation marker.
                    $validation['status'] = ApiCallValidator::STATUS_ALLOW;
                } else {
                    throw new UnattendedActionRefusedException(
                        $operationId,
                        "Action requires confirmation and was not pre-authorized for unattended execution: {$operationId}.",
                    );
                }
            }
        }

        if ($validation['status'] === 'reject') {
            return json_encode(['error' => $validation['reason'] ?? 'Operation rejected']);
        }

        if ($validation['status'] === 'confirm') {
            // For the two coding-workspace mutations only, surface the
            // run's aggregate scope BEFORE the ordinary per-file marker
            // below, when admitting this file would newly cross
            // scope_surface_threshold_files and this run has not already
            // had an approved scope_surface confirmation. Additive, never
            // a replacement — a run whose actual scope never crosses the
            // threshold falls straight through to the ordinary marker
            // exactly as before.
            $scopeSurfaceMarker = $this->codingWorkspaceScopeSurfaceMarker($operationId, $method, $pathTemplate, $params, $runId, $conversation);
            if ($scopeSurfaceMarker !== null) {
                return $scopeSurfaceMarker;
            }

            // 116-mcp-client-support (Foundational, research.md D6):
            // server_name is the server's own CONFIGURED name (set by
            // whoever added the server, at store() time) -- never the
            // tool's own untrusted name/description -- so the confirmation
            // prompt can name which external server will carry this out
            // without ever surfacing server-supplied text as the thing
            // that names it.
            if ($externalTool !== null) {
                return json_encode([
                    '__requires_confirmation' => true,
                    'confirmation_type' => 'external_tool',
                    'operationId' => $operationId,
                    'method' => $method,
                    'path' => $pathTemplate,
                    'server_name' => $externalServer->name,
                    'tool_name' => $externalTool->name,
                    'parameters' => $params,
                ]);
            }

            // Return a special marker — the stream handler will detect this and suspend
            return json_encode([
                '__requires_confirmation' => true,
                'confirmation_type' => 'api_call',
                'operationId' => $operationId,
                'method' => $method,
                'path' => $pathTemplate,
                'parameters' => $params,
            ]);
        }

        // Execute directly
        return $this->executeApiCall($operationId, $method, $pathTemplate, $params, $conversation);
    }

    /**
     * The scope-surfacing check layered in front of the ordinary per-file
     * writeFile/deleteFile confirmation marker. Returns the
     * scope_surface-typed marker JSON when admitting this call's own
     * target file would newly cross
     * config('llm-client.coding_agent.scope_surface_threshold_files') and
     * this run has not already had an approved scope_surface
     * confirmation (RunTraceQuery::scopeSurfaceStateForRun()); returns
     * null otherwise, so the caller falls through to the ordinary
     * api_call marker unchanged (both for every non-mutation operation
     * and for a run whose actual scope never crosses the threshold).
     *
     * A null $runId (no run trace recorder configured, or a call outside
     * a tracked run) is treated the same as "nothing touched yet" would
     * be pointless to query — scope-surfacing is simply skipped, exactly
     * as every other run-trace-derived feature in this class degrades
     * when $this->runTraceRecorder/$runId is unavailable.
     */
    private function codingWorkspaceScopeSurfaceMarker(string $operationId, string $method, string $pathTemplate, array $params, ?string $runId, Conversation $conversation): ?string
    {
        if ($runId === null) {
            return null;
        }

        if ($operationId !== self::CODING_WORKSPACE_WRITE_FILE_OPERATION_ID
            && $operationId !== self::CODING_WORKSPACE_DELETE_FILE_OPERATION_ID) {
            return null;
        }

        $filePath = $params['body']['path'] ?? $params['query']['path'] ?? null;
        if (!is_string($filePath) || $filePath === '') {
            return null;
        }

        $state = $this->runTraceQuery->scopeSurfaceStateForRun((string) $conversation->user_id, $runId);
        if ($state['already_surfaced']) {
            return null;
        }

        $threshold = (int) config('llm-client.coding_agent.scope_surface_threshold_files', 8);
        $touchedCount = count($state['touched_paths']);
        if (($touchedCount + 1) <= $threshold) {
            return null;
        }

        return json_encode([
            '__requires_confirmation' => true,
            'confirmation_type' => 'scope_surface',
            'operationId' => $operationId,
            'method' => $method,
            'path' => $pathTemplate,
            'parameters' => $params,
            'files_touched_so_far' => $state['touched_paths'],
            'would_add' => $filePath,
            'threshold' => $threshold,
        ]);
    }

    /**
     * The coding-workspace project-binding guard (112-coding-agent,
     * Foundational, D2, data-model.md §4). `$params` is the raw
     * `execute_operation` tool-call `parameters` argument (the
     * {path, query, body} shape McpToolExecutor::extractArguments() also
     * reads) — the requested project id is read directly from
     * `$params['path']['project']`, never from the resolved HTTP path,
     * since this check must run before the path template is even
     * substituted.
     *
     * Returns a hard-reject JSON string (the same shape an unpermitted
     * operation returns) when the conversation has no bound project or
     * the requested project does not match it; returns null when the
     * call may proceed to the ordinary isOperationPermitted()/
     * isConfirmationRequired() checks.
     */
    private function enforceCodingProjectBinding(Conversation $conversation, array $params): ?string
    {
        $requestedProject = $params['path']['project'] ?? null;

        if ($conversation->coding_project_id === null
            || (string) $conversation->coding_project_id !== (string) $requestedProject) {
            return json_encode(['error' => 'Operation rejected: this conversation is not bound to the requested project.']);
        }

        return null;
    }

    /**
     * 112-coding-agent (US1, data-model.md §6): the action content a
     * confirmed writeFile/deleteFile call is closed with once approved and
     * executed, so RunTraceQuery's run-trace change-report fallback
     * (RunTraceQuery::changedFilesFromRunTrace()) can read the operationId
     * and target path back out of the run trace for a non-git-backed
     * project — mirroring the existing PAGE_TEXT_OPERATION_ID
     * envelope-content precedent (executeApiCall()), but scoped to
     * exactly these two operationIds so every other confirmed operation's
     * action content is completely unaffected.
     *
     * $arguments is the confirmed call's own {path, query, body}
     * parameters (the same shape enforceCodingProjectBinding() reads
     * `path.project` from) — writeFile carries its target file path under
     * `body.path` (contracts §2 body {path, content}), deleteFile under
     * `query.path` (contracts §2 query path).
     *
     * Returns null for any other operationId, and is only ever called
     * from the approved branch of resume()/resumeSync() — a declined
     * confirmation never reaches this, and never executeApiCall(), so it
     * is never recorded as an executed change (data-model.md §6).
     */
    private function codingWorkspaceChangeActionContent(string $operationId, array $arguments): ?string
    {
        if ($operationId !== self::CODING_WORKSPACE_WRITE_FILE_OPERATION_ID
            && $operationId !== self::CODING_WORKSPACE_DELETE_FILE_OPERATION_ID) {
            return null;
        }

        $path = $arguments['body']['path'] ?? $arguments['query']['path'] ?? null;
        if (!is_string($path) || $path === '') {
            return null;
        }

        return json_encode(['operationId' => $operationId, 'path' => $path]);
    }

    /**
     * Wrap a page/text fetch result in a source envelope (feature 111, US1/US5).
     *
     * The envelope (data-model.md §3) carries the fetch input URL (source.url —
     * the url argument of the page/text call, never rewritten), the optional
     * page title (source.title — parsed from the page, never fabricated), the
     * body text wrapped in the untrusted-response delimiters (content), and a
     * reference_id set by ToolResultCondenser when the content is condensed.
     *
     * Static and pure so it can be exercised without constructing the (heavy)
     * AgentLoopService; the condenser is injected so callers can pass their
     * configured instance.
     *
     * @return array{source: array{url: string, title: string|null}, content: string, reference_id: string|null}
     */
    public static function buildPageTextEnvelope(string $url, ?string $title, string $body, string $conversationId, ?ToolResultCondenser $condenser = null): array
    {
        $blockBuilder = new RubricJudgmentPromptBuilder();
        $wrapped = $blockBuilder->untrustedResponseBlock($body, []);

        $condenser = $condenser ?? new ToolResultCondenser();
        $condensed = $condenser->condense($conversationId, 'execute_operation', $wrapped);

        return [
            'source' => ['url' => $url, 'title' => $title],
            'content' => $condensed['content'],
            'reference_id' => $condensed['reference_id'] ?? null,
        ];
    }

    public function executeApiCall(string $operationId, string $method, string $pathTemplate, array $params, Conversation $conversation): string
    {
        // research.md D3: the branch that matters most — execute_operation
        // is the path every ordinary chat turn, and therefore every
        // eval-run case, actually uses. Checked before any session/token
        // resolution is attempted, so a null-user eval-run conversation
        // never falls through to executeHttpCall()'s own token-minting
        // guard.
        if (Context::get('eval_run_simulating_tools', false)) {
            $schema = $this->toolRegistry->inputSchemaForOperationId($operationId);
            $result = $this->toolExecutor->simulateCall(['inputSchema' => $schema ?? []]);
            $this->lastOperationDispatchOutcome = $result;

            return $this->extractResultContent($result);
        }

        $session = $this->getOrCreateSession($conversation);
        $resolved = $this->toolExecutor->extractArguments($params, $pathTemplate);
        $result = $this->toolExecutor->executeHttpCall($method, $resolved['path'], $resolved['query'], $resolved['body'], $session);
        $this->lastOperationDispatchOutcome = $result;
        $raw = $this->extractResultContent($result);

        // Foundational (security-critical, D5, FR-010/FR-011/FR-012): every
        // execute_operation GET result, for every agent bound to the
        // conversation, is run through OwnerScopedResultFilter before
        // anything else -- including feature 111's own envelope-wrapping
        // branch immediately below -- ever sees it, so a foreign-owned row
        // can never survive by being wrapped inside an envelope the filter
        // never inspected. $conversation->user_id is the same identity
        // getOrCreateSession() already resolved above to mint this call's
        // bearer token -- no new identity lookup. Never throws: a body
        // that does not decode to an array is unchanged (json_decode()
        // returns null for it and $raw !== 'null', so the block below is
        // skipped entirely).
        $decoded = json_decode($raw, true);
        if ($decoded !== null || $raw === 'null') {
            $filtered = $this->ownerScopedResultFilter->apply($decoded, (string) $conversation->user_id);
            $raw = json_encode($filtered, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        // Feature 111 (US1/US5): wrap page/text results in a source envelope so
        // the consulted-source manifest can be derived and the body is treated
        // as untrusted data. Other operations pass through unchanged.
        if ($operationId === self::PAGE_TEXT_OPERATION_ID) {
            // buildExecuteOperationSchema() declares `parameters` as
            // {path, query, body} sub-objects, and extractArguments() reads
            // only that shape — so for this POST the fetch URL arrives under
            // `body`. A flat `url` is accepted as a last-resort fallback for a
            // caller that passes parameters unstructured.
            $url = (string) ($resolved['body']['url'] ?? $resolved['query']['url'] ?? $params['url'] ?? '');
            $envelope = self::buildPageTextEnvelope($url, null, $raw, $conversation->id, $this->toolResultCondenser);

            return json_encode($envelope, JSON_UNESCAPED_SLASHES);
        }

        return $raw;
    }

    /**
     * Bounded per-action retry for a scheduler-triggered (unattended)
     * execute_operation call: the same operationId/arguments are
     * re-dispatched, same as SpendingCeilingReached's own single-purpose
     * event is fired from one isolated site, up to $retryLimit further
     * times, but only while each failed attempt is transport-level
     * transient (RetryEligibility::isTransient()). Every attempt gets its
     * own ToolInvocation action row, all sharing one attempt_group_id, so
     * an eventual success still leaves the earlier failures visible in
     * the action record rather than hidden behind it.
     *
     * A permission/authorization refusal (UnattendedActionRefusedException)
     * is thrown by executeMetaTool()/handleExecuteOperation() itself,
     * before any dispatch is attempted, and is deliberately left
     * uncaught here -- it unwinds straight past this method to run()'s
     * own outer catch, exactly once, never retried. $activeActionId is
     * taken by reference, not returned, specifically so that unwind still
     * leaves the caller's own copy pointing at the just-opened action --
     * the same variable run()'s own UnattendedActionRefusedException
     * handler already closes Failure for the single-attempt case, so a
     * refusal reached through a retry-eligible operation's first attempt
     * is closed exactly the same way, not left permanently open.
     *
     * @param-out ?string $activeActionId
     * @return array{0: string, 1: ?array, 2: ?string} [the final
     *   attempt's JSON result string, its decoded form, and a
     *   run-failure reason once the failure was never transient or the
     *   retry limit is exhausted -- null while the run should continue
     *   exactly as it does for a single successful (or interactive) tool
     *   call]
     */
    private function dispatchExecuteOperationWithRetry(
        array $arguments,
        Conversation $conversation,
        ?string $runId,
        ?string $currentStepId,
        int $retryLimit,
        ?string &$activeActionId,
    ): array {
        $retryGroupId = (string) Str::uuid();
        $attempts = 0;
        $result = '';
        $decoded = null;

        while (true) {
            $activeActionId = null;
            if ($this->runTraceRecorder !== null && $currentStepId !== null) {
                $activeActionId = $this->runTraceRecorder->openAction(
                    $currentStepId,
                    ActionType::ToolInvocation,
                    'execute_operation',
                    $retryGroupId,
                );
            }

            $this->lastOperationDispatchOutcome = null;
            $result = $this->executeMetaTool('execute_operation', $arguments, $conversation, $runId, true);
            $attempts++;
            $decoded = json_decode($result, true);

            $failed = is_array($decoded)
                && empty($decoded['__requires_confirmation'] ?? false)
                && is_string($decoded['error'] ?? null);

            if (!$failed) {
                return [$result, $decoded, null];
            }

            $outcome = $this->lastOperationDispatchOutcome ?? ['status' => null];

            if (RetryEligibility::isTransient($outcome) && $attempts <= $retryLimit) {
                if ($this->runTraceRecorder !== null && $activeActionId !== null) {
                    $this->runTraceRecorder->closeAction(
                        $activeActionId,
                        ActionOutcome::Failure,
                        Str::limit((string) $decoded['error'], 500),
                    );
                }

                continue;
            }

            $operationId = (string) ($arguments['operationId'] ?? 'execute_operation');
            $reason = sprintf(
                '%s failed after %d attempt(s): %s',
                $operationId,
                $attempts,
                (string) $decoded['error'],
            );

            return [$result, $decoded, $reason];
        }
    }

    /**
     * Check if all tool calls in this turn were successful execute_operation calls.
     * When true, the agent loop can stop without asking the LLM for a summary.
     */
    public function allExecuteOperationsSucceeded(array $toolCalls, array $toolResults): bool
    {
        if (empty($toolCalls) || empty($toolResults)) {
            return false;
        }

        foreach ($toolCalls as $toolCall) {
            if (($toolCall['function']['name'] ?? '') !== 'execute_operation') {
                return false;
            }
        }

        foreach ($toolResults as $result) {
            $decoded = json_decode($result['content'] ?? '', true);
            if (is_array($decoded) && isset($decoded['error'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Builds a "Known Operations" section for the system prompt.
     *
     * Returns null when the cache has no entries for this conversation.
     * Returns a formatted markdown section with operation details otherwise.
     *
     * @param Conversation $conversation The conversation context
     * @return string|null The formatted section or null if empty
     */
    private function buildKnownOperationsSection(Conversation $conversation): ?string
    {
        $entries = $this->operationCache->getEntries($conversation->id, 20);

        // 109-agent-as-capability (Phase 3/US1, data-model.md §5,
        // research.md D2): eligible capability offerings are
        // unconditionally pre-seeded here, merged in BEFORE the
        // empty($entries) check below -- so a caller with zero cached real
        // operations but >=1 eligible offering still gets a "Known
        // Operations" section on its very first turn, with no prior
        // search_operations call needed (Acceptance Scenario 1).
        $offeringEntries = app(CapabilityCatalogMerger::class)->entriesFor($conversation);
        if (!empty($offeringEntries)) {
            $entries = array_merge($entries, $offeringEntries);
        }

        if (empty($entries)) {
            return null;
        }

        $lines = [];
        $lines[] = '';
        $lines[] = '## Known Operations';
        $lines[] = '';

        foreach ($entries as $entry) {
            $operationId = $entry['operationId'] ?? 'unknown';
            $method = strtoupper($entry['method'] ?? 'GET');
            $path = $entry['path'] ?? '/';
            $summary = $entry['summary'] ?? '';
            $paramSchema = $entry['paramSchema'] ?? null;

            $lines[] = "**{$operationId}** ({$method} {$path})";
            $lines[] = "  - Summary: {$summary}";

            if ($paramSchema && is_array($paramSchema)) {
                $params = json_encode($paramSchema, JSON_UNESCAPED_SLASHES);
                $lines[] = "  - Parameters: {$params}";
            } else {
                $lines[] = "  - Parameters: none";
            }
            $lines[] = '';
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * Builds a "Known Helpers" section for the system prompt
     * (098-delegation-protocol, contracts/delegation-protocol-meta-tool.md)
     * -- mirrors buildKnownOperationsSection()'s exact shape. Returns null
     * when the conversation has no bound agent, or that agent has no
     * active assigned helpers.
     */
    private function buildKnownHelpersSection(Conversation $conversation): ?string
    {
        if ($conversation->agent_id === null || $conversation->user_id === null) {
            return null;
        }

        $helpers = app(AgentHelperQuery::class)->helpersFor((string) $conversation->user_id, $conversation->agent_id);

        if ($helpers === null) {
            return null;
        }

        $active = $helpers->filter(fn ($row) => $row->helper_status === 'active');

        if ($active->isEmpty()) {
            return null;
        }

        $lines = [];
        $lines[] = '';
        $lines[] = '## Known Helpers';
        $lines[] = '';

        foreach ($active as $row) {
            $lines[] = "**{$row->helper_agent_id}** — {$row->helper_name}";
            $lines[] = "  - Purpose: {$row->helper_purpose}";
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * Builds a "Known Specialists" section for the system prompt
     * (102-router-pattern, Phase 5/US3, contracts/routing-mechanism.md §7)
     * -- mirrors buildKnownHelpersSection()'s exact shape. Returns null
     * when the conversation has no bound agent, no user, or the caller
     * owns no other active agent besides the one currently assigned.
     *
     * Lets the currently-assigned specialist's own turn-time reasoning
     * discover every other real candidate id/name so it can call the
     * existing handoff_to_agent meta-tool (093, unchanged) when the user
     * indicates a poor fit or the topic has shifted -- no new mechanism is
     * introduced. Each candidate's instructions are read from its own
     * current AgentVersion's raw_definition, parsed the same way
     * ConversationAgentDefinitionResolver resolves a bound definition; a
     * candidate whose current version is missing or fails to parse is
     * skipped rather than aborting the whole section.
     *
     * Reconciliation finding (102-router-pattern): the excluded agent must
     * be the conversation's CURRENT effective identity
     * (ConversationHandoff::currentAgentIdentityFor(), the same helper
     * ensureSpecialistAvailable() and Message's own attribution listener
     * already use), never the raw, immutable $conversation->agent_id --
     * that column records only the *original* binding and is never
     * updated by a handoff (093's append-only design, data-model.md §3).
     * Using it here meant that after any handoff (a user correction, a
     * topic-change reassignment, or D7's automatic unavailability
     * fallback), every subsequent turn's system prompt excluded the
     * WRONG agent: the original (no-longer-acting) agent stayed hidden
     * from the list forever, while the actually-acting specialist saw
     * itself listed as one of its own "Known Specialists" -- a target
     * handleHandoffToAgent()'s own cycle check happens to reject if ever
     * actually called, but confusing and wrong to present at all.
     */
    private function buildKnownSpecialistsSection(Conversation $conversation): ?string
    {
        if ($conversation->agent_id === null || $conversation->user_id === null) {
            return null;
        }

        $currentAgentId = ConversationHandoff::currentAgentIdentityFor($conversation)['agent_id'];

        $others = app(AgentQuery::class)->listActiveForUser((string) $conversation->user_id)
            ->reject(fn ($a) => $a->id === $currentAgentId);

        if ($others->isEmpty()) {
            return null;
        }

        $lines = [];
        $lines[] = '';
        $lines[] = '## Known Specialists';
        $lines[] = "If the user indicates the current specialist isn't the right fit, or the conversation's topic has shifted to something better covered below, call handoff_to_agent with the best-matching id.";
        $lines[] = '';

        foreach ($others as $agent) {
            $version = $agent->currentVersion;

            if ($version === null) {
                continue;
            }

            try {
                $instructions = app(AgentDefinitionParser::class)->parse($version->raw_definition)->instructions;
            } catch (\Throwable) {
                continue;
            }

            $lines[] = "**{$agent->id}** — {$agent->name}";
            $lines[] = '  - Focus: '.mb_substr($instructions, 0, 200);
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * Builds a "Combined Helper Results" section for the system prompt
     * (099-result-aggregation, contracts/result-aggregation-meta-tool.md
     * §3, data-model.md §5) -- mirrors buildKnownHelpersSection()'s exact
     * "query fresh per call, append only when non-empty" shape. Returns
     * null when no run id is available, or ResultAggregationService::
     * combineForRun() itself returns null (fewer than two delegations on
     * this run have reported a structured result). Recomputed fresh on
     * every call -- never cached -- so a delegation completed mid-turn is
     * reflected on the very next call within the same run.
     *
     * 109-agent-as-capability (FR-003): calls combineForRun() with
     * $callerFacing = true -- this section is injected directly into the
     * CALLING agent's own system prompt, so a capability-offering-
     * originated delegation must never contribute a `helper_agent_name`
     * here, exactly like composeDelegationDisclosure()'s own origin
     * filter (Phase 3). Two or more capability-agent calls completing on
     * the same run, with no delegate_to_helper call involved at all,
     * would otherwise still leak the offered agent's name into this
     * section.
     */
    private function buildCombinedHelperResultsSection(?string $runId): ?string
    {
        if ($runId === null) {
            return null;
        }

        $combined = app(ResultAggregationService::class)->combineForRun($runId, callerFacing: true);

        if ($combined === null) {
            return null;
        }

        $lines = [];
        $lines[] = '';
        $lines[] = '## Combined Helper Results';
        $lines[] = '';
        $lines[] = 'The following facts were produced by your helpers this turn:';

        foreach ($combined['combined_output'] as $key => $value) {
            $contributorNames = [];
            foreach ($combined['contributors'] as $contributor) {
                if (is_array($contributor['output'] ?? null) && array_key_exists($key, $contributor['output'])) {
                    $contributorNames[] = $contributor['helper_agent_name'] ?? 'a retired agent';
                }
            }

            $quotedNames = implode(', ', array_map(fn ($name) => "\"{$name}\"", $contributorNames));
            $lines[] = "- {$key}: ".json_encode($value)." (from {$quotedNames})";
        }

        if (!empty($combined['conflicts'])) {
            $lines[] = '';
            $lines[] = '⚠ Conflicting values — not resolved automatically:';

            foreach ($combined['conflicts'] as $conflict) {
                $valueParts = array_map(
                    fn ($entry) => json_encode($entry['value'])." (from \"{$entry['helper_agent_name']}\")",
                    $conflict['values'],
                );
                $lines[] = "- {$conflict['key']}: ".implode(' vs ', $valueParts);
            }
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * Builds a "Task Progress" section for the system prompt
     * (103-manager-agent, research.md D9, data-model.md §4/§2's
     * accepted_summary column) -- mirrors buildKnownHelpersSection()/
     * buildCombinedHelperResultsSection()'s exact "query fresh per call,
     * append only when non-empty" shape. Returns null when
     * $managedTaskId is null (every conversation outside a managed task)
     * or the task has no parts yet (before the first plan_parts call).
     *
     * Lists every part's own sequence/description/current state, and --
     * for accepted parts only -- the part's own accepted_summary (never
     * the full result_output, the same "summary, not raw content" choice
     * buildCombinedHelperResultsSection() already makes for its own
     * contributors view), plus the shortfall reason for a part reported
     * as a shortfall. This is what keeps FR-014's "track the state of
     * every part" and FR-007's "assemble a single coherent response"
     * reliable across a long task's many RunManagedTaskStepJob
     * invocations even once the generic condensation/tool_result_
     * condensation config (unmodified, still applying underneath) has
     * trimmed early assign_part/accept_part tool-result messages out of
     * the raw history -- without this section, a manager many rounds in
     * could lose sight of an already-accepted part's own summary and
     * either omit it from finalize_task's own final_response or,
     * short of the server-side guard (D4) refusing it outright, reason
     * as though the part were still open.
     *
     * The assembled section is passed through ContentSanitizer::
     * truncate() against llm-client.manager.context_budget_bytes (default
     * 24576) so the manager's own accumulating context cannot grow
     * unboundedly across a long task -- the same concern 099's own
     * combined-results view already solved for a single run. When
     * truncation actually occurred (ContentSanitizer::isTruncated()),
     * append one explicit line noting that some part summaries may have
     * been dropped from this section specifically -- not that those
     * parts' own state changed -- since a truncated *prompt section* must
     * never be mistaken for a lost *database* record (GET
     * /managed-tasks/{id}/parts remains the authoritative source).
     */
    private function buildManagedTaskProgressSection(?string $managedTaskId): ?string
    {
        if ($managedTaskId === null) {
            return null;
        }

        $parts = ManagedTaskPart::where('managed_task_id', $managedTaskId)
            ->orderBy('sequence')
            ->get();

        if ($parts->isEmpty()) {
            return null;
        }

        $lines = [];
        $lines[] = '';
        $lines[] = '## Task Progress';
        $lines[] = '';

        foreach ($parts as $part) {
            $lines[] = "**Part {$part->sequence}** ({$part->id}) — {$part->state}";
            $lines[] = "  - Description: {$part->description}";

            if ($part->state === 'accepted' && $part->accepted_summary !== null) {
                $lines[] = "  - Accepted result: {$part->accepted_summary}";
            }

            if ($part->state === 'reported_as_shortfall' && $part->shortfall_reason !== null) {
                $lines[] = "  - Shortfall: {$part->shortfall_reason}";
            }
        }

        $section = implode(PHP_EOL, $lines);

        $sanitizer = app(ContentSanitizer::class);
        $cap = (int) config('llm-client.manager.context_budget_bytes', 24576);
        $truncated = $sanitizer->truncate($section, $cap);

        if ($sanitizer->isTruncated($truncated)) {
            $truncated .= PHP_EOL . PHP_EOL
                . '(This progress section was truncated to fit the context budget -- some parts\' summaries above may be incomplete or missing. This does not change any part\'s actual state; see GET /managed-tasks/{id}/parts for the authoritative record.)';
        }

        return $truncated;
    }

    /**
     * 108-shared-task-workspace (US1, contracts/task-workspace-meta-tool.md
     * §2, research.md D6). Mirrors buildManagedTaskProgressSection()'s
     * exact shape (null early-return, implode(PHP_EOL, $lines),
     * ContentSanitizer::truncate() against its own independently-sized
     * config cap, isTruncated()-gated notice) -- but for the WIDENED
     * audience task-workspace-meta-tool.md §1's own gate uses (the
     * manager's own conversation AND every helper conversation nested
     * under a managed task's tree), not the manager-only audience the
     * progress section keeps. Entries render strictly oldest-first and
     * are never reordered on read (US5/FR-010 forbids promoting a
     * contradicted entry).
     */
    private function buildSharedTaskWorkspaceSection(?string $managedTaskId): ?string
    {
        if ($managedTaskId === null) {
            return null;
        }

        $entries = TaskWorkspaceEntry::where('managed_task_id', $managedTaskId)
            ->orderBy('created_at')
            ->get();

        if ($entries->isEmpty()) {
            return null;
        }

        $agentIds = $entries->pluck('author_agent_id')->filter()->unique()->values()->all();
        $names = empty($agentIds) ? [] : Agent::whereIn('id', $agentIds)->pluck('name', 'id')->all();

        $lines = [];
        $lines[] = '';
        $lines[] = '## Shared Task Notes';
        $lines[] = '';

        foreach ($entries as $entry) {
            $authorName = $names[$entry->author_agent_id] ?? $entry->author_agent_id;
            $timestamp = $entry->created_at?->utc()->format('Y-m-d H:i') . ' UTC';
            $lines[] = "- [{$timestamp}, {$authorName}]: {$entry->content}";
        }

        $section = implode(PHP_EOL, $lines);

        $sanitizer = app(ContentSanitizer::class);
        $cap = (int) config('llm-client.task_workspace.context_budget_bytes', 8192);
        $truncated = $sanitizer->truncate($section, $cap);

        if ($sanitizer->isTruncated($truncated)) {
            $truncated .= PHP_EOL . PHP_EOL
                . '(This section was truncated to fit the context budget -- see GET /managed-tasks/{id}/workspace for the authoritative full record.)';
        }

        return $truncated;
    }

    /**
     * Build the auto-retrieved memory section for injection into the system prompt.
     * Uses AutoMemoryRetriever when available, falls back to PreferenceInjector.
     *
     * @return string|null The formatted memory section or null if empty
     */
    private function buildAutoMemorySection(Conversation $conversation): ?string
    {
        $lastUserMessage = $this->getLastUserMessage($conversation);

        if ($this->autoMemoryRetriever && $this->autoMemoryRetriever->isEnabled() && $lastUserMessage) {
            $userId = (string) $conversation->user_id;
            // Must match how every other memory call site derives agent_id
            // (:1162, :1208, :1245, :1279) — MemoryService::search() filters on
            // agent_id alone, so a shared literal like 'default' would both miss
            // entries written under the conversation-id fallback and pool every
            // characterless conversation, across users, under one key.
            $agentId = (string) ($conversation->character ?? $conversation->id);
            $turnKey = sprintf('%s:%s', $conversation->id, $lastUserMessage->id);
            $query = $lastUserMessage->content;

            // retrieveWithMetrics() delegates to retrieve() and records latency,
            // tokens, and degradation events on cache misses only.
            $result = $this->autoMemoryRetriever->retrieveWithMetrics($turnKey, $userId, $agentId, $query);
            $section = $this->autoMemoryRetriever->formatInjectionSection($result);

            if (!$section->isEmpty()) {
                return "\n" . $section->rawText;
            }

            return null;
        }

        // Fallback: use PreferenceInjector when AutoMemoryRetriever is unavailable
        $preferenceSection = $this->preferenceInjector->assemble((string) $conversation->user_id);
        return $preferenceSection;
    }

    /**
     * Get the most recent user message in a conversation.
     */
    private function getLastUserMessage(Conversation $conversation): ?Message
    {
        return Message::where('conversation_id', $conversation->id)
            ->where('role', 'user')
            ->latest('created_at')
            ->first();
    }

    /**
     * Build the conversation history portion of the payload — everything except
     * the system message.
     *
     * Split out from buildMessagesPayload() so background work (chunk
     * pre-warming) can partition and hash exactly the array the request path
     * partitions, without paying for system-prompt assembly. Reading the Message
     * rows directly is not equivalent: tool calls expand into separate tool
     * result entries here, so a naive read produces different chunk boundaries.
     *
     * @return list<array{role: string, content: string|null, tool_calls?: array, tool_call_id?: string}>
     */
    public function buildHistoryMessages(Conversation $conversation): array
    {
        $dbMessages = Message::where('conversation_id', $conversation->id)
            ->orderBy('created_at')
            ->get();

        $payload = [];

        foreach ($dbMessages as $msg) {
            if ($msg->tool_data && !empty($msg->tool_data['tool_calls'])) {
                // Assistant message with tool calls
                $assistantMsg = [
                    'role' => 'assistant',
                    'content' => $msg->content ?: null,
                    'tool_calls' => $msg->tool_data['tool_calls'],
                ];
                $payload[] = $assistantMsg;

                // Tool result messages
                if (!empty($msg->tool_data['tool_results'])) {
                    foreach ($msg->tool_data['tool_results'] as $result) {
                        $payload[] = [
                            'role' => 'tool',
                            'tool_call_id' => $result['tool_call_id'],
                            'content' => $result['content'],
                        ];
                    }
                }
            } else {
                // Regular message (user, assistant text, system)
                $payload[] = [
                    'role' => strtolower($msg->role),
                    'content' => $msg->content,
                ];
            }
        }

        return $payload;
    }

    public function buildMessagesPayload(Conversation $conversation, ?string $runId = null): array
    {
        $payload = [];

        $systemPrompt = config('llm-client.agent_loop.system_prompt', '');

        // Append auto-retrieved memory section (replaces PreferenceInjector + episodic recall).
        // Falls back to PreferenceInjector when AutoMemoryRetriever is not wired.
        if ($conversation->user_id !== null) {
            $memorySection = $this->buildAutoMemorySection($conversation);
            if ($memorySection !== null) {
                $systemPrompt .= $memorySection;
            }
        }

        // Append "Known Operations" section when cache has entries
        $knownOpsSection = $this->buildKnownOperationsSection($conversation);
        if ($knownOpsSection !== null) {
            $systemPrompt .= $knownOpsSection;
        }

        // Append "Known Helpers" section when the bound agent has at
        // least one active assigned helper (098-delegation-protocol).
        $knownHelpersSection = $this->buildKnownHelpersSection($conversation);
        if ($knownHelpersSection !== null) {
            $systemPrompt .= $knownHelpersSection;
        }

        // Append "Known Specialists" section when the caller owns at
        // least one other active agent besides the one already assigned
        // (102-router-pattern, contracts §7) -- lets the acting agent's
        // own turn-time reasoning discover a mid-conversation reassignment
        // target for the existing handoff_to_agent meta-tool (093).
        $knownSpecialistsSection = $this->buildKnownSpecialistsSection($conversation);
        if ($knownSpecialistsSection !== null) {
            $systemPrompt .= $knownSpecialistsSection;
        }

        // Append "Combined Helper Results" section when the current run
        // has two or more delegations reporting a structured result
        // (099-result-aggregation, research.md D5). $runId is the caller's
        // own already-in-scope run id (run()/resumeSync()/the streaming
        // dispatch path) -- not read from the ambient Context 'run_id'
        // slot, since that slot is not reliably cleared between calls in
        // every caller (e.g. metrics-only reconstruction callers) and
        // reading it here would risk attributing one call's combined
        // section to a stale, unrelated run.
        $combinedHelperResultsSection = $this->buildCombinedHelperResultsSection($runId);
        if ($combinedHelperResultsSection !== null) {
            $systemPrompt .= $combinedHelperResultsSection;
        }

        // Append "Task Progress" section for a managed-task conversation
        // (103-manager-agent, research.md D9) -- $managedTaskId is looked
        // up from $conversation only when channel === 'managed-task', the
        // same "outside the mechanism, contributes nothing" shape every
        // other channel-gated section here already has (Grounding note
        // item 8). ManagedTask.conversation_id is unique, so this is a
        // single indexed lookup, not a query per part.
        $managedTaskId = $conversation->channel === 'managed-task'
            ? ManagedTask::where('conversation_id', $conversation->id)->value('id')
            : null;
        $managedTaskProgressSection = $this->buildManagedTaskProgressSection($managedTaskId);
        if ($managedTaskProgressSection !== null) {
            $systemPrompt .= $managedTaskProgressSection;
        }

        // Append "Shared Task Notes" section (108-shared-task-workspace,
        // research.md D5/D6) for the WIDENED audience -- the manager's own
        // conversation AND every helper conversation nested under a
        // managed task's tree. Deliberately a SEPARATE, independently
        // resolved value from $managedTaskId above (Grounding note item
        // 6): that local is null for every helper conversation (it is
        // gated on channel === 'managed-task', true only for the manager's
        // own conversation), so reusing it here would silently exclude
        // every helper conversation from ever seeing this section --
        // reintroducing 103's manager-only gate through the back door.
        $sharedWorkspaceTaskId = $this->taskWorkspaceQuery->resolveManagedTaskIdForConversation($conversation->id);
        $sharedTaskWorkspaceSection = $this->buildSharedTaskWorkspaceSection($sharedWorkspaceTaskId);
        if ($sharedTaskWorkspaceSection !== null) {
            $systemPrompt .= $sharedTaskWorkspaceSection;
        }

        if (!empty($systemPrompt)) {
            $payload[] = [
                'role' => 'system',
                'content' => $systemPrompt,
            ];
        }

        return array_merge($payload, $this->buildHistoryMessages($conversation));
    }

    private function dispatchStreamRequest(
        Conversation $conversation,
        array $messages,
        array $tools,
        int $iteration,
        string $system = '',
        ?string $responseFormat = null,
        ?string $runId = null,
        ?array $reshapeTuple = null,
        ?string $modelOverride = null,
        ?string $serverIdOverride = null,
    ): void {
        // A rung's substitute_server_id, when set, must actually change
        // dispatch (085-graceful-degradation, research.md D4a) — a
        // since-deleted substitute falls back to the conversation's own
        // server, never throws. EndpointResolver reads the resolved
        // $server's own provider_type internally, so no separate provider
        // type override is needed here (unlike callLlmSync()).
        $server = $serverIdOverride !== null
            ? (Server::find($serverIdOverride) ?? $conversation->server)
            : Server::find($conversation->server_id);
        $resolver = app(EndpointResolver::class);

        $body = new \stdClass();
        $body->temperature = 1.0;
        $body->model = $modelOverride ?? $conversation->model;
        $body->stream = true;
        $body->messages = $messages;

        // Include system prompt for providers that support it (Anthropic)
        if ($system !== '') {
            $body->system = $system;
        }

        // Include response_format for JSON mode support
        if (isset($responseFormat) && $responseFormat !== null) {
            $body->response_format = $responseFormat;
        }

        if (!empty($tools)) {
            $body->tools = $tools;
        }

        $request = new HttpRequest();
        $request->url = $resolver->urlFor($server, Operation::ChatStream);
        $request->method = "POST";
        $request->headers = $resolver->headersFor($server, Operation::ChatStream);
        $request->body = $body;

        Log::info('AgentLoopService: sending request to LLM', [
            'url' => $request->url,
            'model' => $body->model,
            'iteration' => $iteration,
            'tools_count' => count($tools),
            'messages_count' => count($messages),
            'body' => json_encode($body, JSON_PRETTY_PRINT),
        ]);

        // Open a step for this streaming model call — the single funnel every
        // streaming dispatch passes through. This deliberately includes queue
        // wait in the step's duration.
        $stepId = null;
        $attemptGroupId = (string) Str::uuid();
        $actionId = null;
        if ($this->runTraceRecorder !== null && $runId !== null) {
            // Derive step position from 1 + COUNT(*) for the run.
            $stepId = $this->runTraceRecorder->openStep($runId, null, $attemptGroupId);

            // Write context reshape action retroactively if trim ran before step creation.
            if ($reshapeTuple !== null && $stepId !== null) {
                $this->runTraceRecorder->recordCompletedAction(
                    $stepId,
                    \ClarionApp\LlmClient\ValueObjects\ActionType::ContextReshape,
                    \ClarionApp\LlmClient\ValueObjects\ActionOutcome::Success,
                    $reshapeTuple['started_at'],
                    $reshapeTuple['ended_at'],
                    'window_trim',
                    $attemptGroupId,
                    null,
                    null,
                    $reshapeTuple['content'] ?? null,
                );
            }

            // Open LLM request action — spans from dispatch to finish().
            if ($stepId !== null) {
                $actionId = $this->runTraceRecorder->openAction(
                    $stepId,
                    \ClarionApp\LlmClient\ValueObjects\ActionType::LlmRequest,
                    $conversation->model,
                    $attemptGroupId,
                );
            }
        }

        $data = json_encode([
            'conversation_id' => $conversation->id,
            'iteration' => $iteration,
            'run_id' => $runId,
            'step_id' => $stepId,
            'action_id' => $actionId,
        ]);

        SendHttpStreamRequest::dispatch(
            $request,
            "ClarionApp\\LlmClient\\AgentLoopStreamHandler",
            $data
        );
    }

    private function getOrCreateSession(Conversation $conversation): McpSession
    {
        $session = McpSession::where('user_id', $conversation->user_id)->first();
        if (!$session) {
            $session = McpSession::create([
                'user_id' => $conversation->user_id,
                'protocol_version' => '2025-03-26',
            ]);
        }
        return $session;
    }

    private function extractResultContent(array $result): string
    {
        if (!empty($result['content'])) {
            return $result['content'][0]['text'] ?? json_encode($result['content']);
        }
        return json_encode($result);
    }

    /**
     * Handle the propose_declarative_memory tool call.
     *
     * Returns a __requires_confirmation marker so the agent loop pauses
     * and awaits user confirmation. Nothing is persisted at this point.
     */
    private function handleProposeDeclarativeMemory(array $arguments, Conversation $conversation): string
    {
        $type = $arguments['type'] ?? '';
        $content = $arguments['content'] ?? '';
        $existingId = $arguments['existingId'] ?? null;

        if (!in_array($type, ['fact', 'preference', 'rule'], true)) {
            return json_encode(['error' => 'Invalid type. Must be fact, preference, or rule']);
        }

        if ($content === '') {
            return json_encode(['error' => 'content is required']);
        }

        // Return __requires_confirmation marker with confirmation_type: 'declarative_memory'
        // This is transient — nothing is persisted yet
        return json_encode([
            '__requires_confirmation' => true,
            'confirmation_type' => 'declarative_memory',
            'type' => $type,
            'content' => $content,
            'existingId' => $existingId,
        ]);
    }

    /**
     * Hand this conversation off to a different agent (093-agent-handoff,
     * contracts §1). This phase's own deliberately minimal body — checks 1,
     * 3, and 7 only (agent_id presence, existence/ownership, and the write
     * itself). Checks 2 (system-owned conversation guard), 4 (activation),
     * 5 (chain membership/cycle), and 6 (chain bound) are added by later
     * phases as new early-return checks inserted into this same method,
     * never rewriting this phase's own checks.
     */
    private function handleHandoffToAgent(array $arguments, Conversation $conversation): string
    {
        $targetAgentId = $arguments['agent_id'] ?? null;
        if (empty($targetAgentId)) {
            return json_encode(['error' => 'agent_id is required']);
        }

        if ($conversation->user_id === null) {
            return json_encode(['error' => 'Handoff is not available for this conversation.']);
        }

        $target = app(AgentQuery::class)->findAgent($conversation->user_id, $targetAgentId);
        if ($target === null) {
            return json_encode(['error' => 'Agent not found or not available to hand off to.']);
        }

        if ($target->is_active === false) {
            return json_encode(['error' => "The agent \"{$target->name}\" is deactivated and cannot receive a handoff."]);
        }

        $chainMembers = ConversationHandoff::where('conversation_id', $conversation->id)
            ->pluck('to_agent_id')
            ->push($conversation->agent_id)
            ->filter()
            ->all();
        if (in_array($target->id, $chainMembers, true)) {
            return json_encode(['error' => "This conversation has already been handled by \"{$target->name}\" — handing off to it again would create a loop."]);
        }

        $chainLength = ConversationHandoff::where('conversation_id', $conversation->id)->count();
        if ($chainLength >= config('llm-client.handoff.max_chain_length', 5)) {
            return json_encode(['error' => "This conversation has reached its handoff limit ({$chainLength}) and cannot be handed off again."]);
        }

        $handoff = $this->writeHandoffRow($conversation, $target);

        return json_encode([
            'success' => true,
            'handed_off_to' => $target->name,
            'agent_id' => $target->id,
        ]);
    }

    /**
     * The write $handleHandoffToAgent() and $ensureSpecialistAvailable()
     * both share (102-router-pattern, contracts §5): compute the chain's
     * next position from its current tail, then create the
     * ConversationHandoff row. $reason distinguishes an ordinary,
     * agent-initiated handoff (null, unchanged behavior) from an automatic
     * unavailability fallback ('unavailable', D7).
     */
    private function writeHandoffRow(Conversation $conversation, Agent $target, ?string $reason = null): ConversationHandoff
    {
        $from = ConversationHandoff::where('conversation_id', $conversation->id)
            ->orderByDesc('position')
            ->value('to_agent_id') ?? $conversation->agent_id;
        $position = 1 + ConversationHandoff::where('conversation_id', $conversation->id)->count();

        return ConversationHandoff::create([
            'conversation_id' => $conversation->id,
            'position' => $position,
            'from_agent_id' => $from,
            'to_agent_id' => $target->id,
            'to_agent_version_id' => $target->current_version_id,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }

    /**
     * Hand a self-contained task to one of this conversation's own
     * assigned helpers (098-delegation-protocol, contracts/
     * delegation-protocol-meta-tool.md). Presence-checks
     * `helper_agent_id`/`task` (mirrors handleHandoffToAgent()'s own
     * `agent_id` presence check above) and delegates the real work to
     * DelegationService::delegate() -- eligibility, isolation, and the
     * nested run() call all live there.
     */
    private function handleDelegateToHelper(array $arguments, Conversation $conversation): string
    {
        $helperAgentId = $arguments['helper_agent_id'] ?? null;
        if (empty($helperAgentId)) {
            return json_encode(['error' => 'helper_agent_id is required']);
        }

        $task = $arguments['task'] ?? null;
        if (empty($task)) {
            return json_encode(['error' => 'task is required']);
        }

        $context = $arguments['context'] ?? null;

        $result = app(DelegationService::class)->delegate($conversation, $helperAgentId, $task, $context);

        return json_encode($result);
    }

    /**
     * 103-manager-agent (US1, contracts/manager-agent-meta-tools.md §1).
     * Only ever reached inside a channel === 'managed-task' conversation
     * (buildToolsPayload() gates plan_parts on exactly that), so a missing
     * ManagedTask row here means something has gone wrong with that gate
     * rather than an ordinary refusal a model is expected to see routinely.
     */
    private function handlePlanParts(array $arguments, Conversation $conversation): string
    {
        $managedTask = ManagedTask::where('conversation_id', $conversation->id)->first();
        if ($managedTask === null) {
            return json_encode(['error' => 'not_a_managed_task', 'message' => 'plan_parts is only usable inside a managed task.']);
        }

        $parts = $arguments['parts'] ?? null;
        if (empty($parts) || !is_array($parts)) {
            return json_encode(['error' => 'parts is required']);
        }

        $descriptions = [];
        foreach ($parts as $part) {
            $description = is_array($part) ? ($part['description'] ?? null) : null;
            if (empty($description)) {
                return json_encode(['error' => 'each part requires a description']);
            }
            $descriptions[] = $description;
        }

        $created = app(ManagerService::class)->planParts($managedTask, $descriptions);

        return json_encode(array_map(fn (ManagedTaskPart $p) => [
            'part_id' => $p->id,
            'sequence' => $p->sequence,
            'description' => $p->description,
        ], $created));
    }

    /**
     * 103-manager-agent (US1, contracts/manager-agent-meta-tools.md §2).
     * Pulls required args exactly as handleDelegateToHelper() does, then
     * delegates the real work -- including the full FR-014/FR-009
     * transactional guard -- to ManagerService::assignPart().
     */
    private function handleAssignPart(array $arguments, Conversation $conversation): string
    {
        $managedTask = ManagedTask::where('conversation_id', $conversation->id)->first();
        if ($managedTask === null) {
            return json_encode(['error' => 'not_a_managed_task', 'message' => 'assign_part is only usable inside a managed task.']);
        }

        $partId = $arguments['part_id'] ?? null;
        if (empty($partId)) {
            return json_encode(['error' => 'part_id is required']);
        }

        $helperAgentId = $arguments['helper_agent_id'] ?? null;
        if (empty($helperAgentId)) {
            return json_encode(['error' => 'helper_agent_id is required']);
        }

        $task = $arguments['task'] ?? null;
        if (empty($task)) {
            return json_encode(['error' => 'task is required']);
        }

        $context = $arguments['context'] ?? null;

        $part = ManagedTaskPart::where('managed_task_id', $managedTask->id)->where('id', $partId)->first();
        if ($part === null) {
            return json_encode(['error' => 'unknown_part', 'message' => 'The named part could not be found for this managed task.']);
        }

        $result = app(ManagerService::class)->assignPart($managedTask, $part, $helperAgentId, $task, $context);

        return json_encode($result);
    }

    /**
     * 103-manager-agent (US2, contracts/manager-agent-meta-tools.md §3).
     * Pulls the required arg exactly as handlePlanParts()/handleAssignPart()
     * do, then surfaces ManagerService::acceptPartRefusal()'s own
     * structured refusal reason directly (the same guard acceptPart()
     * itself consults) before ever calling the void acceptPart() write.
     */
    private function handleAcceptPart(array $arguments, Conversation $conversation): string
    {
        $managedTask = ManagedTask::where('conversation_id', $conversation->id)->first();
        if ($managedTask === null) {
            return json_encode(['error' => 'not_a_managed_task', 'message' => 'accept_part is only usable inside a managed task.']);
        }

        $partId = $arguments['part_id'] ?? null;
        if (empty($partId)) {
            return json_encode(['error' => 'part_id is required']);
        }

        $part = ManagedTaskPart::where('managed_task_id', $managedTask->id)->where('id', $partId)->first();
        if ($part === null) {
            return json_encode(['error' => 'unknown_part', 'message' => 'The named part could not be found for this managed task.']);
        }

        $refusal = app(ManagerService::class)->acceptPartRefusal($part);
        if ($refusal !== null) {
            return json_encode($refusal);
        }

        app(ManagerService::class)->acceptPart($managedTask, $part);

        return json_encode(['part_id' => $part->id, 'state' => 'accepted']);
    }

    /**
     * 103-manager-agent (US5, contracts/manager-agent-meta-tools.md §4).
     * Pulls the required args exactly as handlePlanParts()/
     * handleAssignPart()/handleAcceptPart() do, then surfaces
     * ManagerService::reportShortfallRefusal()'s own structured refusal
     * reason directly (the same "already finalized" guard
     * admitAssignmentRound() and acceptPartRefusal() both consult) before
     * ever calling the void reportShortfall() write.
     */
    private function handleReportShortfall(array $arguments, Conversation $conversation): string
    {
        $managedTask = ManagedTask::where('conversation_id', $conversation->id)->first();
        if ($managedTask === null) {
            return json_encode(['error' => 'not_a_managed_task', 'message' => 'report_shortfall is only usable inside a managed task.']);
        }

        $partId = $arguments['part_id'] ?? null;
        if (empty($partId)) {
            return json_encode(['error' => 'part_id is required']);
        }

        $reason = $arguments['reason'] ?? null;
        if (empty($reason)) {
            return json_encode(['error' => 'reason is required']);
        }

        $part = ManagedTaskPart::where('managed_task_id', $managedTask->id)->where('id', $partId)->first();
        if ($part === null) {
            return json_encode(['error' => 'unknown_part', 'message' => 'The named part could not be found for this managed task.']);
        }

        $refusal = app(ManagerService::class)->reportShortfallRefusal($part);
        if ($refusal !== null) {
            return json_encode($refusal);
        }

        app(ManagerService::class)->reportShortfall($managedTask, $part, $reason);

        return json_encode(['part_id' => $part->id, 'state' => 'reported_as_shortfall']);
    }

    /**
     * 103-manager-agent (US3, contracts/manager-agent-meta-tools.md §5).
     * Pulls the required arg exactly as handlePlanParts()/handleAssignPart()/
     * handleAcceptPart() do, then surfaces ManagerService::finalizeRefusal()'s
     * own structured refusal reason directly (the same guard finalize()
     * itself consults) before ever calling the void finalize() write.
     */
    private function handleFinalizeTask(array $arguments, Conversation $conversation): string
    {
        $managedTask = ManagedTask::where('conversation_id', $conversation->id)->first();
        if ($managedTask === null) {
            return json_encode(['error' => 'not_a_managed_task', 'message' => 'finalize_task is only usable inside a managed task.']);
        }

        $finalResponse = $arguments['final_response'] ?? null;
        if (empty($finalResponse)) {
            return json_encode(['error' => 'final_response is required']);
        }

        $shortfallNote = $arguments['shortfall_note'] ?? null;

        $refusal = app(ManagerService::class)->finalizeRefusal($managedTask, $shortfallNote);
        if ($refusal !== null) {
            return json_encode($refusal);
        }

        app(ManagerService::class)->finalize($managedTask, $finalResponse, $shortfallNote);
        $managedTask->refresh();

        return json_encode(['managed_task_id' => $managedTask->id, 'status' => $managedTask->status]);
    }

    /**
     * 108-shared-task-workspace (US1, contracts/task-workspace-meta-tool.md
     * §1). Only ever reached when buildToolsPayload() has actually
     * injected record_task_note (resolveManagedTaskIdForConversation()
     * !== null for this exact conversation) -- a null resolution here
     * would mean a race against the task's own forced-finalize between
     * tool injection and this call, handled defensively rather than
     * assumed unreachable (research.md D5's own liveness argument).
     *
     * The empty-content check runs BEFORE the task_concluded check (the
     * contract's own ordering) using the identical ContentSanitizer::
     * truncate() call TaskWorkspaceService::recordEntry() itself makes --
     * deliberately duplicated rather than shared, mirroring
     * handleFinalizeTask()'s own "compute the refusal check, then call
     * the write separately" shape above.
     */
    private function handleRecordTaskNote(array $arguments, Conversation $conversation): string
    {
        $managedTaskId = $this->taskWorkspaceQuery->resolveManagedTaskIdForConversation($conversation->id);
        if ($managedTaskId === null) {
            return json_encode(['error' => 'not_a_managed_task', 'message' => 'record_task_note is only usable inside a managed task.']);
        }

        $content = (string) ($arguments['content'] ?? '');
        $truncated = app(ContentSanitizer::class)->truncate($content, (int) config('llm-client.task_workspace.max_entry_bytes'));
        if ($truncated === '') {
            return json_encode(['error' => 'empty_content', 'message' => 'content must not be empty.']);
        }

        $managedTask = ManagedTask::find($managedTaskId);
        $entry = $managedTask !== null
            ? app(TaskWorkspaceService::class)->recordEntry($managedTask, $conversation->agent_id, $content)
            : null;

        if ($entry === null) {
            return json_encode(['error' => 'task_concluded', 'message' => 'This task has already concluded; its shared workspace is no longer available.']);
        }

        return json_encode(['entry_id' => $entry->id, 'recorded_at' => $entry->created_at?->toJSON()]);
    }

    /**
     * 101-parallel-subagent-execution (US1, contracts §1, Grounding note
     * item 6): scans one iteration's own tool_calls for delegate_to_helper
     * entries ahead of either of run()'s/resumeSync()'s per-tool-call
     * loops actually reaching any of them. Zero or one such entries take
     * the existing inline path completely unchanged -- this returns null
     * and DelegationService::delegateBatch() is never called (US1 AC3,
     * "zero added latency"). Two or more are dispatched together via one
     * delegateBatch() call with the full ordered set; the returned
     * per-call results (keyed by tool_call_id, contracts §1) are threaded
     * back into each delegate_to_helper call's own original loop position
     * by the caller -- every other tool call in the same iteration still
     * executes through the unmodified loop body, unaffected.
     *
     * 103-manager-agent (research.md D2, Grounding note item 6): widened
     * to count assign_part calls alongside delegate_to_helper ones -- both
     * feed the SAME DelegationService::delegateBatch() call, so a turn
     * mixing the two types (in any combination, 2+ total) is dispatched
     * together in exactly one delegateBatch() call, never two. An
     * assign_part entry is admitted through ManagerService::
     * admitAssignmentRound() (the identical guard assignPart()'s own solo
     * path uses) BEFORE being added to the merged calls array -- a call
     * refused at that stage never reaches delegateBatch() at all, and its
     * refusal is returned directly, keyed by its own tool_call_id, exactly
     * as an out-of-band delegate_to_helper refusal already is via
     * resolveAndValidate(). Every admitted assign_part call carries its
     * own managed_task_id/part_id so createDelegationRow() stamps them.
     *
     * @param array<int, array<string, mixed>> $toolCalls
     * @return array<string, array<string, mixed>>|null
     */
    private function resolveDelegateToHelperBatchResults(array $toolCalls, Conversation $conversation): ?array
    {
        $relevantCalls = array_values(array_filter(
            $toolCalls,
            fn (array $toolCall) => in_array($toolCall['function']['name'] ?? '', ['delegate_to_helper', 'assign_part'], true),
        ));

        if (count($relevantCalls) < 2) {
            return null;
        }

        $managedTask = $conversation->channel === 'managed-task'
            ? ManagedTask::where('conversation_id', $conversation->id)->first()
            : null;

        $results = [];
        $calls = [];
        $partIdByToolCallId = [];

        foreach ($relevantCalls as $toolCall) {
            $name = $toolCall['function']['name'] ?? '';
            $arguments = json_decode($toolCall['function']['arguments'] ?? '{}', true) ?: [];
            $toolCallId = $toolCall['id'] ?? '';

            if ($name === 'assign_part') {
                if ($managedTask === null) {
                    $results[$toolCallId] = ['error' => 'not_a_managed_task', 'message' => 'assign_part is only usable inside a managed task.'];
                    continue;
                }

                $part = ManagedTaskPart::where('managed_task_id', $managedTask->id)
                    ->where('id', $arguments['part_id'] ?? null)
                    ->first();

                if ($part === null) {
                    $results[$toolCallId] = ['error' => 'unknown_part', 'message' => 'The named part could not be found for this managed task.'];
                    continue;
                }

                $refusal = app(ManagerService::class)->admitAssignmentRound($managedTask, $part);
                if ($refusal !== null) {
                    $results[$toolCallId] = $refusal;
                    continue;
                }

                $partIdByToolCallId[$toolCallId] = $part->id;

                $calls[] = [
                    'tool_call_id' => $toolCallId,
                    'helper_agent_id' => $arguments['helper_agent_id'] ?? null,
                    'task' => $arguments['task'] ?? null,
                    'context' => $arguments['context'] ?? null,
                    'managed_task_id' => $managedTask->id,
                    'part_id' => $part->id,
                ];
            } else {
                $calls[] = [
                    'tool_call_id' => $toolCallId,
                    'helper_agent_id' => $arguments['helper_agent_id'] ?? null,
                    'task' => $arguments['task'] ?? null,
                    'context' => $arguments['context'] ?? null,
                ];
            }
        }

        if (!empty($calls)) {
            $batchResults = app(DelegationService::class)->delegateBatch($conversation, $calls);

            foreach ($batchResults as $toolCallId => $result) {
                $results[$toolCallId] = $result;

                if (isset($result['delegation_id'], $partIdByToolCallId[$toolCallId])) {
                    ManagedTaskPart::where('id', $partIdByToolCallId[$toolCallId])
                        ->update(['current_delegation_id' => $result['delegation_id']]);
                }
            }
        }

        return $results;
    }

    /**
     * Compose the user-facing disclosure sentence for a routing decision on
     * this conversation, and mark it disclosed in the same call
     * (102-router-pattern, US2, contracts §6). Returns null when there is
     * nothing to disclose — a conversation whose routing_reason is null
     * (never routed, or pre-feature), or whose routing decision has already
     * been disclosed on an earlier turn.
     *
     * Resolves the handling agent via Agent::withTrashed()->find() plus the
     * null-safe ?-> operator, mirroring composeHandoffDisclosure()'s own
     * posture — the agent may have since been soft-deleted, and this must
     * never throw and crash the entire turn's response.
     */
    public function composeRoutingDisclosure(Conversation $conversation): ?string
    {
        if ($conversation->routing_reason === null || $conversation->routing_disclosed_at !== null) {
            return null;
        }

        $agentName = Agent::withTrashed()->find($conversation->agent_id)?->name ?? 'a retired agent';

        $sentence = match ($conversation->routing_reason) {
            'automatic' => "This conversation is being handled by \"{$agentName}\", automatically matched to your request.",
            'default' => "This conversation is being handled by \"{$agentName}\", the default handler — no specialist was a clear match.",
            'explicit' => "This conversation is being handled by \"{$agentName}\", as you requested.",
            default => "This conversation is being handled by \"{$agentName}\".",
        };

        $conversation->update(['routing_disclosed_at' => now()]);

        return $sentence;
    }

    /**
     * Compose the user-facing disclosure sentence for any undisclosed
     * handoff(s) on this conversation, and mark them disclosed in the same
     * call (093-agent-handoff, US2, contracts §2, research.md D6). Returns
     * null when there is nothing to disclose — the ordinary case for a
     * conversation that has never been handed off, or whose most recent
     * handoff has already been disclosed.
     *
     * Resolves the receiving agent via Agent::withTrashed()->find() plus
     * the null-safe ?-> operator — never an unguarded Agent::find()->name
     * — because the receiving agent may have been soft-deleted between the
     * handoff succeeding and this disclosure firing on a later turn,
     * and an unguarded ->name access on a null lookup would throw and
     * crash the entire turn's response, not just the disclosure.
     * withTrashed() still surfaces a since-retired agent's real name
     * (FR-005 — a past handoff must remain plainly visible even after the
     * named agent is later retired; mirrors §3's own read-endpoint
     * behavior for the same case), so the generic "a retired agent"
     * fallback only fires when the row is genuinely unresolvable (no
     * matching Agent row at all, trashed or not), never merely because
     * the agent has since been deactivated or soft-deleted.
     */
    public function composeHandoffDisclosure(Conversation $conversation): ?string
    {
        $undisclosed = ConversationHandoff::where('conversation_id', $conversation->id)
            ->whereNull('disclosed_at')
            ->orderBy('position')
            ->get();

        if ($undisclosed->isEmpty()) {
            return null;
        }

        $latest = $undisclosed->last();
        $name = Agent::withTrashed()->find($latest->to_agent_id)?->name ?? 'a retired agent';
        $sentence = $latest->reason === 'unavailable'
            ? "The previous specialist became unavailable; this conversation has moved to \"{$name}\"."
            : "This conversation has been handed off to \"{$name}\".";

        ConversationHandoff::where('conversation_id', $conversation->id)
            ->whereNull('disclosed_at')
            ->update(['disclosed_at' => now()]);

        return $sentence;
    }

    /**
     * Compose the user-facing disclosure sentence for every delegation
     * made during this run (098-delegation-protocol, research.md D7).
     * Scoped by run, not by an ever-disclosed flag -- unlike handoff's
     * "only the most recent redirect matters" semantics, every delegation
     * this run made should be named, so each run composes its own
     * disclosure exactly once, from exactly the delegations that happened
     * during it.
     */
    public function composeDelegationDisclosure(?string $runId): ?string
    {
        if ($runId === null) {
            return null;
        }

        // 109-agent-as-capability (Phase 3/US1, FR-003, contracts/
        // capability-agent-call.md): a capability-offering-originated
        // Delegation row must NEVER be announced back to the calling
        // agent -- doing so would itself be exactly the "indication that a
        // capability entry is backed by another agent" FR-003 forbids.
        // Ordinary delegate_to_helper delegations are unaffected (every
        // pre-existing row defaults to this origin).
        $names = Delegation::where('parent_run_id', $runId)
            ->where('origin', 'delegate_to_helper')
            ->get()
            ->map(fn (Delegation $delegation) => Agent::withTrashed()->find($delegation->helper_agent_id)?->name ?? 'a retired agent')
            ->unique()
            ->values();

        if ($names->isEmpty()) {
            return null;
        }

        $quoted = $names->map(fn ($name) => "\"{$name}\"")->all();
        $last = array_pop($quoted);
        $joined = empty($quoted) ? $last : implode(', ', $quoted).' and '.$last;

        return "This response included work delegated to {$joined}.";
    }

    /**
     * Condense a tool result if it exceeds the configured token threshold.
     * Returns an array with 'content' and optional metadata fields.
     */
    private function condenseToolResult(string $result, string $conversationId, string $toolName = 'unknown'): array
    {
        if (!$this->toolResultCondenser || !config('llm-client.tool_result_condensation.enabled', false)) {
            return ['content' => $result];
        }

        $condensed = $this->toolResultCondenser->condense($conversationId, $toolName, $result);

        return [
            'content' => $condensed['content'],
            'reference_id' => $condensed['reference_id'] ?? null,
            'original_tokens' => $condensed['original_tokens'] ?? null,
            'condensed_tokens' => $condensed['condensed_tokens'] ?? null,
            'method' => $condensed['method'] ?? null,
            'condensed' => $condensed['condensed'] ?? false,
        ];
    }
}
