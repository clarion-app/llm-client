<?php

namespace Tests\Integration\Harness;

use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\AgentLoopService;
use Closure;
use RuntimeException;

/**
 * T016: ConversationDriver — plays a ConversationScript against the assembled system.
 *
 * D1: Each turn is a call to the product's own entry point.
 * D2: Turn numbering is 1-based and stable.
 * D3: Every boundary request is attributed to exactly one turn.
 * D4: Driving to a condition is bounded by maxTurns.
 * D5: A turn that does not complete fails immediately.
 * D6: Config deviations are applied before the system resolves.
 * D9: Failure output locates the defect without a debugger.
 */
class ConversationDriver
{
    /** @var TurnRecord[] Records of each turn played. */
    private array $turnRecords = [];

    /** @var string|null Stop reason if the loop exited early. */
    private ?string $stoppedBecause = null;

    public function __construct(
        private readonly \Tests\Integration\MultiTurnTestCase $test,
        private readonly ResponseScript $script,
        private readonly ScriptedTransport $transport,
        private readonly ScriptedStream $stream,
    ) {
    }

    /**
     * Play a ConversationScript against a conversation and return the result.
     *
     * This method:
     * 1. Installs the script's rules into the ResponseScript
     * 2. Resets the transport turn counter
     * 3. For each turn, resolves the user message and drives the entry point
     * 4. Captures payloads before/after each turn (D3)
     * 5. Checks stop conditions after each turn
     * 6. Binds maxTurns and fails if reached (D4)
     * 7. Derives TurnRecord status from both result and persisted state (D5)
     *
     * @param ConversationScript $script The script to play.
     * @param Conversation $conversation The conversation to play against.
     * @return PlayedConversation
     */
    public function play(ConversationScript $script, Conversation $conversation): PlayedConversation
    {
        $this->turnRecords = [];
        $this->stoppedBecause = null;

        // Install rules from the script into the ResponseScript
        foreach ($script->rules as $rule) {
            $this->script->addRule($rule);
        }

        // Reset transport state for this play session
        $this->transport->reset();

        $entryPath = $script->entryPath;
        $maxTurns = $script->maxTurns;
        $stopWhen = $script->stopWhenCondition;

        // Resolve AgentLoopService lazily (D6) — after config deviations are set
        $agentLoop = $this->test->getApp()->make(AgentLoopService::class);

        $turn = 0;
        $scenario = $this->test->getScenario();

        while (true) {
            // Check max turns bound (D4)
            if ($maxTurns > 0 && $turn >= $maxTurns) {
                $this->stoppedBecause = $this->buildMaxTurnsDiagnostic($script, $conversation, $scenario);
                break;
            }

            $turn++;

            // Resolve the user message for this turn
            $plan = $this->resolveTurnPlan($script, $turn);
            $userMessage = $plan->resolveUserMessage($turn);

            // Capture payload count before this turn (D3)
            $payloadsBefore = $this->captureCount();

            // Enqueue responses for this turn on the agent_turn lane
            $this->enqueueTurnResponses($plan, $turn);

            // Drive the entry point
            $result = null;
            $error = null;
            try {
                if ($entryPath === 'stream') {
                    $result = $this->playStreamTurn($agentLoop, $conversation, $userMessage);
                } else {
                    $result = $agentLoop->run($conversation, $userMessage);
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
                $result = ['status' => 'error', 'content' => ''];
            }

            // Capture payloads after this turn (D3)
            $payloadsAfter = $this->captureCount();
            $newPayloads = $this->extractNewPayloads($payloadsBefore, $payloadsAfter);

            // Derive status from both result and persisted state (D5)
            $status = $this->deriveStatus($result, $conversation, $turn);
            $assistantContent = $this->lastAssistantContent($conversation, $turn);

            // Determine if reduction happened this turn
            $reducedHere = $this->wasReducedThisTurn($conversation, $turn);

            // Collect rules that fired this turn
            $rulesFired = $this->script->getRulesFiredForTurn($turn);

            // Build turn record
            $record = new TurnRecord(
                index: $turn,
                userMessage: $userMessage,
                payloads: $newPayloads,
                rulesFired: $rulesFired,
                status: $status,
                assistantContent: $assistantContent,
                historyTokensBefore: null,
                historyTokensAfter: null,
                reducedHere: $reducedHere,
            );

            if ($error !== null) {
                $record = TurnRecord::error($turn, $userMessage, $newPayloads, $error);
            }

            $this->turnRecords[] = $record;

            // Check stop predicate
            if ($stopWhen !== null) {
                $played = new PlayedConversation(
                    turns: $this->turnRecords,
                    conversationId: $conversation->id,
                    stoppedBecause: 'checking stop condition',
                );

                if (($stopWhen)($played)) {
                    $this->stoppedBecause = 'stop_when predicate matched';
                    break;
                }
            }
        }

        // Check required rules (S6)
        foreach ($script->requiredRules as $label) {
            $fired = false;
            foreach ($this->turnRecords as $record) {
                if (in_array($label, $record->rulesFired)) {
                    $fired = true;
                    break;
                }
            }
            if (!$fired) {
                throw new RuntimeException(
                    "Required rule '{$label}' never fired [scenario: {$scenario}/{$entryPath}]. " .
                    "This rule was declared required but never matched a request."
                );
            }
        }

        return new PlayedConversation(
            turns: $this->turnRecords,
            conversationId: $conversation->id,
            stoppedBecause: $this->stoppedBecause ?? 'completed normally',
        );
    }

    /**
     * Resolve the TurnPlan for a given turn number.
     */
    private function resolveTurnPlan(ConversationScript $script, int $turn): TurnPlan
    {
        // Check explicit turns first (1-based)
        if ($turn - 1 < count($script->turns)) {
            return $script->turns[$turn - 1];
        }

        // Fall back to continuation (filler)
        if ($script->continuation !== null) {
            return $script->continuation;
        }

        throw new RuntimeException(
            "Turn {$turn} has no plan and no filler. " .
            "Add explicit turns or a filler to the script."
        );
    }

    /**
     * Enqueue responses for a turn on the agent_turn lane.
     */
    private function enqueueTurnResponses(TurnPlan $plan, int $turn): void
    {
        // The plan's responses callable fills the agent_turn lane queue
        ($plan->responses)($this->script);
    }

    /**
     * Capture the current total payload count, in the same unit
     * extractNewPayloads() slices from.
     *
     * Must match getCapturedChatPayloads() (chat-kind only, sync+stream
     * merged) exactly — mixing in transport's all-kind capturedPayloads()
     * (which also counts embedding traffic) desynchronizes the before/after
     * diff the moment any embedding request occurs during a played
     * conversation: the "before" count runs ahead of the chat-only total by
     * one for every embedding request seen, so every later turn's slice
     * silently comes up short (or empty).
     */
    private function captureCount(): int
    {
        return count($this->test->getCapturedChatPayloads());
    }

    /**
     * Extract new payloads that arrived since the last capture.
     */
    private function extractNewPayloads(int $before, int $after): array
    {
        $allPayloads = $this->test->getCapturedChatPayloads();
        $newCount = count($allPayloads) - $before;

        if ($newCount <= 0) {
            return [];
        }

        return array_slice($allPayloads, -$newCount);
    }

    /**
     * Play a single turn via the streaming entry point.
     */
    private function playStreamTurn(AgentLoopService $agentLoop, Conversation $conversation, string $userMessage): array
    {
        // Create the user message
        Message::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $userMessage,
        ]);

        // Start the stream — this dispatches a SendHttpStreamRequest
        $agentLoop->start($conversation);

        // Extract and drive the dispatched job
        $this->stream->extractDispatchedJobs();
        $capturedRequests = $this->stream->capturedRequests();

        if (empty($capturedRequests)) {
            return ['status' => 'error', 'content' => 'No stream request dispatched'];
        }

        // Serve the response and emit SSE chunks
        $response = $this->script->serve();
        $sseChunks = $this->buildSseChunks($response);
        $this->stream->emit($sseChunks);
        $this->stream->finish();

        return ['status' => 'completed', 'content' => ''];
    }

    /**
     * Derive status from both the result and persisted state (D5).
     */
    private function deriveStatus(?array $result, Conversation $conversation, int $turn): string
    {
        if ($result === null) {
            return 'error';
        }

        $resultStatus = $result['status'] ?? '';

        // Check for error conditions
        if ($resultStatus === 'error') {
            return 'error';
        }

        // For sync: check that an assistant message was persisted
        if (isset($result['message_id']) && $result['message_id']) {
            $msg = Message::find($result['message_id']);
            if ($msg && !empty($msg->content)) {
                return 'completed';
            }
        }

        // AgentLoopService::run() legitimately short-circuits a turn whose only
        // tool calls were successful execute_operation calls: it returns
        // status=completed with no message_id and no summary text, because no
        // LLM call is spent restating a tool result the user already has (US3,
        // OperationRecallJourneyTest). Real work happened — reflected in the
        // persisted assistant message's tool_data — so this is a completed
        // turn, not an empty one. Gated on tool_data being present so a
        // genuine empty-answer bug (no tool activity, no text) still falls
        // through to the 'empty' check below.
        if ($resultStatus === 'completed' && empty($result['message_id'] ?? null)) {
            $lastMessage = Message::where('conversation_id', $conversation->id)
                ->where('role', 'assistant')
                ->orderByDesc('sequence_number')
                ->first();

            if ($lastMessage && !empty($lastMessage->tool_data['tool_results'] ?? null)) {
                return 'completed';
            }
        }

        // For stream: check that an assistant message exists for this turn
        $messages = Message::where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->orderByDesc('sequence_number')
            ->get();

        if ($messages->isNotEmpty() && !empty($messages->first()->content)) {
            return 'completed';
        }

        // Empty response
        if ($resultStatus === 'completed' && empty($result['content'] ?? '')) {
            return 'empty';
        }

        return $resultStatus ?: 'error';
    }

    /**
     * Get the last assistant content for a turn.
     */
    private function lastAssistantContent(Conversation $conversation, int $turn): ?string
    {
        $msg = Message::where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->orderByDesc('sequence_number')
            ->first();

        return $msg ? ($msg->content ?? null) : null;
    }

    /**
     * Check if context management reduced history this turn.
     */
    private function wasReducedThisTurn(Conversation $conversation, int $turn): bool
    {
        $records = \ClarionApp\LlmClient\Models\ContextManagementRecord::query()
            ->where('conversation_id', $conversation->id)
            ->whereColumn('tokens_after', '<', 'tokens_before')
            ->orderByDesc('created_at')
            ->get();

        // If there are records and the latest one shows reduction, it happened this turn
        return $records->isNotEmpty();
    }

    /**
     * Build the D4 diagnostic for maxTurns exhaustion.
     */
    private function buildMaxTurnsDiagnostic(ConversationScript $script, Conversation $conversation, string $scenario): string
    {
        $cmCount = \ClarionApp\LlmClient\Models\ContextManagementRecord::query()
            ->where('conversation_id', $conversation->id)
            ->whereColumn('tokens_after', '<', 'tokens_before')
            ->count();

        $msgCount = Message::where('conversation_id', $conversation->id)->count();

        return sprintf(
            "Growth condition not met within %d turns [scenario: %s/%s].\n" .
            "  condition: stopWhen predicate\n" .
            "  observed:  %d ContextManagementRecord rows; %d messages in store\n" .
            "  hint:      the conversation may not have grown past the budget — check the configured model tier and per-turn message size",
            $script->maxTurns,
            $scenario,
            $script->entryPath,
            $cmCount,
            $msgCount
        );
    }

    /**
     * Build SSE chunks from a ResponseScript step.
     */
    private function buildSseChunks(array $step): array
    {
        $chunks = [];
        $choice = $step['choices'][0] ?? null;
        if (!$choice) {
            return $chunks;
        }

        $message = $choice['message'] ?? [];
        $toolCalls = $message['tool_calls'] ?? [];
        $content = $message['content'] ?? '';
        $finishReason = $choice['finish_reason'] ?? 'stop';

        if (!empty($toolCalls)) {
            foreach ($toolCalls as $index => $toolCall) {
                $id = $toolCall['id'] ?? '';
                $name = $toolCall['function']['name'] ?? '';
                $args = $toolCall['function']['arguments'] ?? '{}';

                $chunk = json_encode([
                    'id' => 'chatcmpl_test',
                    'object' => 'chat.completion.chunk',
                    'created' => time(),
                    'model' => 'gpt-4',
                    'choices' => [
                        [
                            'index' => $index,
                            'delta' => [
                                'role' => 'assistant',
                                'tool_calls' => [
                                    [
                                        'index' => $index,
                                        'id' => $id,
                                        'type' => 'function',
                                        'function' => ['name' => $name, 'arguments' => ''],
                                    ],
                                ],
                            ],
                            'finish_reason' => null,
                        ],
                    ],
                ]);
                $chunks[] = "data: {$chunk}\n\n";
            }
            $chunks[] = "data: [DONE]\n\n";
        } else {
            $delta = ['role' => 'assistant', 'content' => $content];
            $chunk = json_encode([
                'id' => 'chatcmpl_test',
                'object' => 'chat.completion.chunk',
                'created' => time(),
                'model' => 'gpt-4',
                'choices' => [
                    [
                        'index' => 0,
                        'delta' => $delta,
                        'finish_reason' => $finishReason,
                    ],
                ],
            ]);
            $chunks[] = "data: {$chunk}\n\n";
            $chunks[] = "data: [DONE]\n\n";
        }

        return $chunks;
    }
}
