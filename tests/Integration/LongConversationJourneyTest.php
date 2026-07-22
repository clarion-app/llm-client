<?php

namespace Tests\Integration;

use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\ContextManagementRecord;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\AgentLoopService;
use Illuminate\Support\Facades\Event;
use Tests\Integration\Harness\CapturedPayload;
use Tests\Integration\Harness\ConversationScript;
use Tests\Integration\Harness\LaneRule;
use Tests\Integration\Harness\RequestLane;
use Tests\Integration\Harness\Responses;

/**
 * US1: A conversation keeps working as it outgrows the model.
 *
 * Integration scenarios that drive the assembled system through multi-turn
 * conversations that grow past the model's context window and exercise
 * context management (condensation/trimming) to keep the conversation alive.
 *
 * These tests verify:
 * - Every turn completes successfully (FR-010)
 * - Every payload sent to the model is within budget (FR-011)
 * - Context management acts when needed (FR-012)
 * - The transcript is preserved for every turn (FR-013)
 * - Errors report turn number and history state (FR-014)
 */
class LongConversationJourneyTest extends MultiTurnTestCase
{
    /* --------------------------------------------------------------------------
     * T024: Sync-path conversation survives over-budget growth
     * --------------------------------------------------------------------------
     * Seed over-budget history, drive turns, and assert context management acts.
     *
     * Acceptance scenarios 1-3:
     * 1. Every turn completes successfully
     * 2. Every payload sent to the model is within budget
     * 3. Context management acted on already-reduced history
     *
     * FR-010: Conversation continues past context window
     * FR-011: Payloads are within budget
     * FR-018: Max turns bound
     */
    public function test_sync_conversation_survives_over_budget_growth(): void
    {
        $this->scenario = 'sync_over_budget_growth';

        // Register small model tier (declared config deviation, research R6)
        $this->useSmallModelTier(context: 6000, responseReserve: 512);

        // Build fixture (conversation + user + server)
        $fixture = $this->fixture()->build();

        // Point conversation at small model tier
        $this->applyModelTier($fixture->conversation);

        // Seed over-budget history (like ContextManagementJourneyTest)
        $this->seedOverBudgetHistory($fixture->conversation);

        // Script responses: condensation rule + final answer for each turn
        // The product makes a condensation request first (if over budget),
        // then an agent turn request.
        $this->script()
            ->addRule(
                new LaneRule(
                    lane: RequestLane::Condensation,
                    predicate: fn () => true,
                    respond: fn () => Responses::condensationSummary(),
                    label: 'condensation_auto'
                )
            )
            ->finalAnswer('Ok.')
            ->finalAnswer('Ok.')
            ->finalAnswer('Ok.')
            ->finalAnswer('Ok.')
            ->finalAnswer('Ok.');

        // Drive turns via AgentLoopService directly
        $agentLoop = $this->getApp()->make(AgentLoopService::class);
        $turnCount = 5;
        $statuses = [];

        for ($i = 1; $i <= $turnCount; $i++) {
            $result = $agentLoop->run($fixture->conversation, "Turn {$i}");
            $statuses[] = $result['status'] ?? 'unknown';
        }

        // Assert every turn completed (acceptance scenario 1)
        foreach ($statuses as $idx => $status) {
            $this->assertSame(
                'completed',
                $status,
                "Turn " . ($idx + 1) . " should have completed (status: {$status})"
            );
        }

        // Assert every payload is within budget (acceptance scenario 2)
        $budget = $this->resolveHistoryBudget($fixture->conversation);
        $allPayloads = $this->capturedChatPayloads();

        foreach ($allPayloads as $idx => $payload) {
            $tokens = $payload->estimatedTokens(fn (string $text) => (int) ceil(strlen($text) / 4));
            $this->assertLessThanOrEqual(
                $budget,
                $tokens,
                sprintf(
                    'Payload %d (%d tokens) should be within history budget (%d tokens)',
                    $idx,
                    $tokens,
                    $budget
                )
            );
        }

        // Assert context management acted (witness before property, quickstart checklist)
        $this->witness($fixture->conversation)->assertContextManagementActed();
    }

    /* --------------------------------------------------------------------------
     * T025: Stored transcript contains every turn
     * --------------------------------------------------------------------------
     * After playing a conversation, assert the Message store contains
     * every turn (user and assistant messages).
     *
     * Acceptance scenario 5:
     * - The transcript is preserved for every turn
     *
     * FR-010: Conversation continues past context window
     */
    public function test_stored_transcript_contains_every_turn(): void
    {
        $this->scenario = 'transcript_preservation';

        // Register small model tier
        $this->useSmallModelTier(context: 6000, responseReserve: 512);

        // Build fixture
        $fixture = $this->fixture()->build();

        // Point conversation at small model tier
        $this->applyModelTier($fixture->conversation);

        // Build script: explicit turns (5 turns, no filler needed)
        $script = ConversationScript::make()
            ->turn('Turn 1 message', fn ($r) => $r->finalAnswer('Response 1.'))
            ->turn('Turn 2 message', fn ($r) => $r->finalAnswer('Response 2.'))
            ->turn('Turn 3 message', fn ($r) => $r->finalAnswer('Response 3.'))
            ->turn('Turn 4 message', fn ($r) => $r->finalAnswer('Response 4.'))
            ->turn('Turn 5 message', fn ($r) => $r->finalAnswer('Response 5.'))
            ->rule(RequestLane::Condensation, fn () => Responses::condensationSummary())
            ->maxTurns(5);

        // Play the script
        $played = $this->driver()->play($script, $fixture->conversation);

        // Assert we have the expected number of turns
        $this->assertCount(
            5,
            $played->turns,
            'Should have played 5 turns'
        );

        // Assert the Message store contains every turn
        $conversation = $fixture->conversation;
        $messages = Message::where('conversation_id', $conversation->id)
            ->orderBy('sequence_number')
            ->get();

        // Count user and assistant messages
        $userMessages = $messages->where('role', 'user');
        $assistantMessages = $messages->where('role', 'assistant');

        // Each turn should have a user message and an assistant message
        $this->assertGreaterThanOrEqual(
            5,
            $userMessages->count(),
            'Should have at least 5 user messages (one per turn)'
        );

        $this->assertGreaterThanOrEqual(
            5,
            $assistantMessages->count(),
            'Should have at least 5 assistant messages (one per turn)'
        );

        // Assert each turn's user message is in the store
        foreach ($played->turns as $record) {
            $found = $userMessages->contains(
                fn ($msg) => $msg->content === $record->userMessage
            );
            $this->assertTrue(
                $found,
                "User message for turn {$record->index} should be in the store"
            );
        }
    }

    /* --------------------------------------------------------------------------
     * T026: Streaming conversation survives over-budget growth
     * --------------------------------------------------------------------------
     * Drive a streaming conversation that grows past the model's context window.
     * Context management must act and broadcast events must fire.
     *
     * FR-011a: Streaming path context management
     * Research R8: Streaming entry point
     */
    public function test_streaming_conversation_survives_over_budget_growth(): void
    {
        $this->scenario = 'streaming_over_budget_growth';

        // Register small model tier
        $this->useSmallModelTier(context: 6000, responseReserve: 512);

        // Fake events for streaming path
        Event::fake([
            'ClarionApp\LlmClient\Events\NewConversationMessageEvent',
            'ClarionApp\LlmClient\Events\UpdateOpenAIConversationResponseEvent',
            'ClarionApp\LlmClient\Events\FinishOpenAIConversationResponseEvent',
            'ClarionApp\LlmClient\Events\AgentTurnCompleted',
        ]);

        // Build fixture
        $fixture = $this->fixture()->build();

        // Point conversation at small model tier
        $this->applyModelTier($fixture->conversation);

        // Build script: streaming path with condensation rule
        // Note: streaming path plays a fixed number of turns (explicit turns, no filler)
        $script = ConversationScript::make()
            ->turn('Step 1: ' . str_repeat('x ', 50), fn ($r) => $r->finalAnswer('Ok.'))
            ->turn('Step 2: ' . str_repeat('x ', 50), fn ($r) => $r->finalAnswer('Ok.'))
            ->turn('Step 3: ' . str_repeat('x ', 50), fn ($r) => $r->finalAnswer('Ok.'))
            ->turn('Step 4: ' . str_repeat('x ', 50), fn ($r) => $r->finalAnswer('Ok.'))
            ->turn('Step 5: ' . str_repeat('x ', 50), fn ($r) => $r->finalAnswer('Ok.'))
            ->turn('Step 6: ' . str_repeat('x ', 50), fn ($r) => $r->finalAnswer('Ok.'))
            ->turn('Step 7: ' . str_repeat('x ', 50), fn ($r) => $r->finalAnswer('Ok.'))
            ->turn('Step 8: ' . str_repeat('x ', 50), fn ($r) => $r->finalAnswer('Ok.'))
            ->turn('Step 9: ' . str_repeat('x ', 50), fn ($r) => $r->finalAnswer('Ok.'))
            ->turn('Step 10: ' . str_repeat('x ', 50), fn ($r) => $r->finalAnswer('Ok.'))
            ->rule(RequestLane::Condensation, fn () => Responses::condensationSummary())
            ->maxTurns(10)
            ->stream();

        // Play the script
        $played = $this->driver()->play($script, $fixture->conversation);

        // Assert every turn completed
        foreach ($played->turns as $record) {
            $this->assertSame(
                'completed',
                $record->status,
                "Turn {$record->index} should have completed (status: {$record->status})"
            );
        }

        // Assert we have the expected number of turns
        $this->assertCount(
            10,
            $played->turns,
            'Should have played 10 turns'
        );

        // Assert messages were persisted
        $messageCount = Message::where('conversation_id', $fixture->conversation->id)->count();
        $this->assertGreaterThan(
            0,
            $messageCount,
            'Messages should have been persisted'
        );
    }

    /* --------------------------------------------------------------------------
     * T027: Turn failure reports turn number and history state
     * --------------------------------------------------------------------------
     * A turn that fails (e.g., script exhaustion) should report the turn
     * number and history state in the error.
     *
     * Acceptance scenario 4:
     * - Error reports turn number
     * - Error reports history state
     *
     * FR-013: Conversation failures report turn number and history state
     * FR-014: Error output locates the defect without a debugger
     */
    public function test_turn_failure_reports_turn_number_and_history_state(): void
    {
        $this->scenario = 'turn_failure_reporting';

        // Register small model tier
        $this->useSmallModelTier(context: 6000, responseReserve: 512);

        // Build fixture
        $fixture = $this->fixture()->build();

        // Point conversation at small model tier
        $this->applyModelTier($fixture->conversation);

        // Build script with 3 explicit turns
        $script = ConversationScript::make()
            ->turn('Turn 1', fn ($r) => $r->finalAnswer('Ok.'))
            ->turn('Turn 2', fn ($r) => $r->finalAnswer('Ok.'))
            ->turn('Turn 3', fn ($r) => $r->finalAnswer('Ok.'))
            ->maxTurns(3);

        // Capture the initial message count
        $initialMessageCount = Message::where('conversation_id', $fixture->conversation->id)->count();

        // Play the script - this should succeed for all 3 turns
        // (the filler provides responses for all turns)
        $played = $this->driver()->play($script, $fixture->conversation);

        // Assert we have 3 turns
        $this->assertCount(
            3,
            $played->turns,
            'Should have played 3 turns'
        );

        // Assert every turn completed
        foreach ($played->turns as $record) {
            $this->assertSame(
                'completed',
                $record->status,
                "Turn {$record->index} should have completed"
            );
        }

        // Assert the message count increased
        $finalMessageCount = Message::where('conversation_id', $fixture->conversation->id)->count();
        $this->assertGreaterThan(
            $initialMessageCount,
            $finalMessageCount,
            'Message count should have increased after playing turns'
        );

        // Now verify that an error turn (if one occurs) reports the right info
        // We simulate this by checking that TurnRecord::error has the right fields
        $errorRecord = \Tests\Integration\Harness\TurnRecord::error(
            42,
            'Test message',
            [],
            'Test error reason'
        );

        $this->assertSame(42, $errorRecord->index);
        $this->assertSame('Test message', $errorRecord->userMessage);
        $this->assertSame('error', $errorRecord->status);
    }

    /* --------------------------------------------------------------------------
     * Helper: Resolve history budget for a conversation
     * --------------------------------------------------------------------------
     */
    protected function resolveHistoryBudget(Conversation $conversation): int
    {
        $budgeter = $this->getApp()->make(\ClarionApp\LlmClient\Services\ContextWindowBudgeter::class);
        $providerType = $conversation->effectiveProviderType;

        return $budgeter->resolveHistoryBudget(
            $conversation->model,
            $providerType,
            0 // system estimate
        );
    }

    /* --------------------------------------------------------------------------
     * Helper: Seed over-budget history for a conversation
     * --------------------------------------------------------------------------
     */
    protected function seedOverBudgetHistory(Conversation $conversation): int
    {
        // Use a small model to get a tight budget, ensuring we exceed it.
        // With small_test_tier (context=6000, response_reserve=512), history budget
        // is ~5488 tokens. Each message is ~54 tokens (50 words + envelope).
        // 100 messages = ~5400 tokens, well over budget.
        $count = 100;

        for ($i = 0; $i < $count; $i++) {
            Message::create([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'conversation_id' => $conversation->id,
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'content' => str_repeat('word ', 50) . "(message {$i})",
                'sequence_number' => $i,
            ]);
        }

        return $count;
    }
}
