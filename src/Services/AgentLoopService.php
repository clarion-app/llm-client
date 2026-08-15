<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Contracts\ProviderType;
use ClarionApp\LlmClient\Exceptions\BudgetExceededException;
use ClarionApp\LlmClient\Exceptions\RateLimitExceededException;
use ClarionApp\LlmClient\Exceptions\PresetNotFoundException;
use ClarionApp\LlmClient\Exceptions\SchemaValidationError;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\ConversationHandoff;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\McpSession;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\SchemaValidator;
use ClarionApp\LlmClient\Services\StructuredOutputPresetRegistry;
use ClarionApp\LlmClient\Contracts\MemoryService as MemoryServiceContract;
use ClarionApp\LlmClient\Contracts\MemoryScope;
use ClarionApp\LlmClient\Contracts\EpisodicMemoryService as EpisodicMemoryServiceContract;
use ClarionApp\LlmClient\Contracts\DeclarativeMemoryService as DeclarativeMemoryServiceContract;
use ClarionApp\LlmClient\Events\AgentTurnCompleted;
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
        ?EffectiveBoundResolver $effectiveBoundResolver = null
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

        $tools = $this->buildToolsPayload($decision->withheldTools);
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

        $toolData['pending_confirmation'] = null;
        $message->update(['tool_data' => $toolData]);

        // Continue the agent loop. Resolved once, before buildToolsPayload()/
        // applyContextWindowTrim() (085-graceful-degradation, tasks.md T001
        // point 1) — resume() never mints a run of its own, so this reads
        // back whatever the original start()/run() dispatch decided.
        $decision = app(DegradationGate::class)->forRun($runId);

        $tools = $this->buildToolsPayload($decision->withheldTools);
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
        // First statement, before is_processing is set and before a run is
        // opened: a refusal here has to be a clean no-op, and there is no path
        // that unwinds either of those for work that never started.
        $this->admitInteractiveWork($conversation, BudgetWorkKind::Interactive);

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
                \ClarionApp\LlmClient\ValueObjects\RunKind::Interactive,
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
        $tools = $this->buildToolsPayload($decision->withheldTools);
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

        try {
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

                    // Close the current step and the run as completed.
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
                            null,
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
                    $activeActionId = null;
                    if ($this->runTraceRecorder !== null && $currentStepId !== null) {
                        $activeActionId = $this->runTraceRecorder->openAction(
                            $currentStepId,
                            ActionType::ToolInvocation,
                            $toolName,
                            $attemptGroupId,
                        );
                    }

                    // 101-parallel-subagent-execution (US1, contracts §1):
                    // a delegate_to_helper call that was part of a 2+ burst
                    // this iteration already has its result from the one
                    // delegateBatch() call above -- never re-executed
                    // inline through executeMetaTool()/delegate() a second
                    // time.
                    if ($toolName === 'delegate_to_helper' && $batchDelegationResults !== null && array_key_exists($toolCallId, $batchDelegationResults)) {
                        $result = json_encode($batchDelegationResults[$toolCallId]);
                    } else {
                        $result = $this->executeMetaTool($toolName, $arguments, $conversation);
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
                            // Default: execute_operation (api_call)
                            $pendingConfirmation = [
                                'tool_name' => 'execute_operation',
                                'confirmation_type' => 'api_call',
                                'operationId' => $decoded['operationId'],
                                'method' => $decoded['method'],
                                'path' => $decoded['path'],
                                'arguments' => $decoded['parameters'] ?? [],
                                'expires_at' => now()->addSeconds(config('llm-client.agent_loop.confirmation_timeout', 300))->toIso8601String(),
                            ];

                            $confirmationPayload = [
                                'confirmation_type' => 'api_call',
                                'operationId' => $decoded['operationId'],
                                'method' => $decoded['method'],
                                'path' => $decoded['path'],
                                'arguments' => $decoded['parameters'] ?? [],
                                'expires_at' => $pendingConfirmation['expires_at'],
                            ];
                        }

                        // Close the tool action as awaiting confirmation and store
                        // action_id in tool_data for the resuming process (T029b).
                        if ($this->runTraceRecorder !== null && $activeActionId !== null) {
                            $this->runTraceRecorder->closeAction(
                                $activeActionId,
                                ActionOutcome::AwaitingConfirmation,
                            );
                            $activeActionId = null;
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

                    // Close tool action on normal completion.
                    if ($this->runTraceRecorder !== null && $activeActionId !== null) {
                        $this->runTraceRecorder->closeAction($activeActionId, ActionOutcome::Success);
                        $activeActionId = null;
                    }

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
                if ($this->allExecuteOperationsSucceeded($toolCalls, $toolResults)) {
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

        if ($approved) {
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
                $this->runTraceRecorder->closeAction(
                    $inboundActionId,
                    ActionOutcome::Success,
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
        $tools = $this->buildToolsPayload($decision->withheldTools);
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
                if ($toolName === 'delegate_to_helper' && $batchDelegationResults !== null && array_key_exists($toolCall['id'] ?? '', $batchDelegationResults)) {
                    $result = json_encode($batchDelegationResults[$toolCall['id'] ?? '']);
                } else {
                    $result = $this->executeMetaTool($toolName, $arguments, $conversation);
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
                        // Default: execute_operation (api_call)
                        $pendingConfirmation = [
                            'tool_name' => 'execute_operation',
                            'confirmation_type' => 'api_call',
                            'operationId' => $decoded['operationId'],
                            'method' => $decoded['method'],
                            'path' => $decoded['path'],
                            'arguments' => $decoded['parameters'] ?? [],
                            'expires_at' => now()->addSeconds(config('llm-client.agent_loop.confirmation_timeout', 300))->toIso8601String(),
                        ];

                        $confirmationPayload = [
                            'confirmation_type' => 'api_call',
                            'operationId' => $decoded['operationId'],
                            'method' => $decoded['method'],
                            'path' => $decoded['path'],
                            'arguments' => $decoded['parameters'] ?? [],
                            'expires_at' => $pendingConfirmation['expires_at'],
                        ];
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

                // Close tool action on normal completion.
                if ($this->runTraceRecorder !== null && $toolActionId !== null) {
                    $this->runTraceRecorder->closeAction($toolActionId, ActionOutcome::Success);
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
     */
    public function buildToolsPayload(array $withheld = []): array
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

    public function executeMetaTool(string $toolName, array $arguments, Conversation $conversation): string
    {
        return match ($toolName) {
            'list_applications' => $this->handleListApplications(),
            'execute_operation' => $this->handleExecuteOperation($arguments, $conversation),
            'search_operations' => $this->handleSearchOperations($arguments),
            'memory_create' => $this->handleMemoryCreate($arguments, $conversation),
            'memory_read' => $this->handleMemoryRead($arguments, $conversation),
            'memory_search' => $this->handleMemorySearch($arguments, $conversation),
            'memory_delete' => $this->handleMemoryDelete($arguments, $conversation),
            'propose_declarative_memory' => $this->handleProposeDeclarativeMemory($arguments, $conversation),
            'handoff_to_agent' => $this->handleHandoffToAgent($arguments, $conversation),
            'delegate_to_helper' => $this->handleDelegateToHelper($arguments, $conversation),
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

    private function handleSearchOperations(array $arguments): string
    {
        $query = $arguments['query'] ?? '';
        if (empty($query)) {
            return json_encode(['error' => 'query parameter is required']);
        }

        // Silently truncate long queries to a safe length
        $query = mb_substr($query, 0, 500);

        // Graceful degradation: check table existence before search
        $searchService = app(OperationsSearchService::class);

        if (!$searchService->tableExists()) {
            return json_encode([
                'hint' => 'Search index is not available. Run reindex command first.',
                'results' => [],
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
                        'results' => [],
                    ]);
                }
                // Table has data but query returned no matches
                return json_encode([
                    'hint' => 'No operations matched your query. Try broader search terms or use list_applications to browse available applications.',
                    'results' => [],
                ]);
            } catch (\Throwable $e) {
                // Fallback if count fails
                return json_encode([
                    'hint' => 'Search index is not available. Run reindex command first.',
                    'results' => [],
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

        return json_encode(['results' => $formatted]);
    }

    private function handleExecuteOperation(array $arguments, Conversation $conversation): string
    {
        $operationId = $arguments['operationId'] ?? '';
        if (empty($operationId)) {
            return json_encode(['error' => 'operationId is required']);
        }

        $params = $arguments['parameters'] ?? [];

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

        if ($validation['status'] === 'reject') {
            return json_encode(['error' => $validation['reason'] ?? 'Operation rejected']);
        }

        if ($validation['status'] === 'confirm') {
            // Return a special marker — the stream handler will detect this and suspend
            return json_encode([
                '__requires_confirmation' => true,
                'operationId' => $operationId,
                'method' => $method,
                'path' => $pathTemplate,
                'parameters' => $params,
            ]);
        }

        // Execute directly
        return $this->executeApiCall($operationId, $method, $pathTemplate, $params, $conversation);
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

            return $this->extractResultContent($result);
        }

        $session = $this->getOrCreateSession($conversation);
        $resolved = $this->toolExecutor->extractArguments($params, $pathTemplate);
        $result = $this->toolExecutor->executeHttpCall($method, $resolved['path'], $resolved['query'], $resolved['body'], $session);

        return $this->extractResultContent($result);
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
     */
    private function buildCombinedHelperResultsSection(?string $runId): ?string
    {
        if ($runId === null) {
            return null;
        }

        $combined = app(ResultAggregationService::class)->combineForRun($runId);

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
     * @param array<int, array<string, mixed>> $toolCalls
     * @return array<string, array<string, mixed>>|null
     */
    private function resolveDelegateToHelperBatchResults(array $toolCalls, Conversation $conversation): ?array
    {
        $delegateCalls = array_values(array_filter(
            $toolCalls,
            fn (array $toolCall) => ($toolCall['function']['name'] ?? '') === 'delegate_to_helper',
        ));

        if (count($delegateCalls) < 2) {
            return null;
        }

        $calls = array_map(function (array $toolCall) {
            $arguments = json_decode($toolCall['function']['arguments'] ?? '{}', true) ?: [];

            return [
                'tool_call_id' => $toolCall['id'] ?? '',
                'helper_agent_id' => $arguments['helper_agent_id'] ?? null,
                'task' => $arguments['task'] ?? null,
                'context' => $arguments['context'] ?? null,
            ];
        }, $delegateCalls);

        return app(DelegationService::class)->delegateBatch($conversation, $calls);
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

        $names = Delegation::where('parent_run_id', $runId)
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
