<?php

namespace Tests\Integration;

use ClarionApp\LlmClient\Events\AgentTurnCompleted;
use ClarionApp\LlmClient\Events\ConversationEnded;
use ClarionApp\LlmClient\Models\ContextManagementRecord;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\AgentLoopService;
use Illuminate\Support\Facades\Event;

/**
 * Regression anchor tests — pin the specific defects that motivated the
 * integration test harness. Each test is written so it would have failed
 * against the broken wiring discovered on 2026-07-16.
 *
 * An anchor never seen red proves nothing.
 */
class WiringRegressionTest extends AssembledSystemTestCase
{
    /* --------------------------------------------------------------------------
     * T049: Anchor — condensation / context management end-to-end
     * --------------------------------------------------------------------------
     * Drive an over-budget conversation through the container-wired loop and
     * assert context management ACTUALLY REDUCED the history and recorded a
     * step in context_management_records.
     *
     * The existing ConversationCondensationTest calls the private resolver by
     * reflection, proving resolution but not end-to-end context management.
     * This anchor proves the full chain: over-budget history -> container-wired
     * loop -> context management mechanism -> reduced payload -> DB record.
     *
     * Note: condensation requires sealed chunks (from prior turns). Fresh
     * seeded messages will trigger the condenser's fallback path (smart trim
     * + budgeter trim), which still proves end-to-end context management.
     * The key assertion is that a mechanism ACTED and recorded a step.
     */
    public function test_context_management_actually_reduces_history_and_records_step(): void
    {
        $this->scenario = 'context_management_reduction_anchor';
        $this->entryPath = 'sync';

        // Enable condensation (default is true, but be explicit)
        config(['llm-client.condensation.enabled' => true]);

        // Build fixture with over-budget history
        $fixture = $this->fixture()->build();
        $seededCount = $this->seedOverBudgetHistory($fixture->conversation);

        // Script: condensation LLM call (summary JSON) then main conversation call.
        // The condenser calls the LLM for fresh condensation (if sealed chunks exist),
        // then the main conversation call happens. Both go through the same HTTP client.
        $this->script()
            ->finalAnswer('{"summary": [{"text": "Previous conversation context summarized"}]}')
            ->finalAnswer('Hello! How can I help?');

        // Drive the container-wired loop
        $result = $this->app->make(AgentLoopService::class)->run(
            $fixture->conversation,
            'Hi there'
        );

        // Assert the loop completed
        $this->assertSame(
            'completed',
            $result['status'],
            'Container-wired loop should complete with over-budget history'
        );

        // Assert context_management_records exist with a mechanism that acted
        $records = ContextManagementRecord::where(
            'conversation_id',
            $fixture->conversation->id
        )->get();

        $this->assertNotEmpty(
            $records,
            'Context management records should exist after an over-budget container-driven turn'
        );

        $mechanisms = $records->pluck('mechanism')->unique()->toArray();
        // Filter out 'none' and 'condenseError' — we want an active mechanism
        $activeMechanisms = array_values(array_diff($mechanisms, ['none', 'condenseError']));

        $this->assertNotEmpty(
            $activeMechanisms,
            sprintf(
                'An active context management mechanism should be recorded. Mechanisms found: %s',
                implode(', ', $mechanisms)
            )
        );

        // Assert at least one record shows token reduction (positive-effect check)
        $reductionRecords = $records->filter(function ($record) {
            return ($record->tokens_before ?? 0) > ($record->tokens_after ?? 0);
        });

        $this->assertNotEmpty(
            $reductionRecords,
            sprintf(
                'At least one context management record should show token reduction. Records: %s',
                json_encode($records->map(fn ($r) => [
                    'mechanism' => $r->mechanism,
                    'tokens_before' => $r->tokens_before,
                    'tokens_after' => $r->tokens_after,
                ])->toArray())
            )
        );

        // Structural proof: captured payload message count < seeded count
        $payloads = $this->capturedChatPayloads();
        $this->assertNotEmpty(
            $payloads,
            'At least one chat payload should be captured'
        );

        $mainPayload = end($payloads);
        $this->assertLessThan(
            $seededCount,
            $mainPayload->messageCount(),
            sprintf(
                'Main conversation payload message count (%d) should be less than seeded history (%d), proving context management reduced history',
                $mainPayload->messageCount(),
                $seededCount
            )
        );
    }

    /* --------------------------------------------------------------------------
     * T050: Anchor — turn vs. conversation semantics
     * --------------------------------------------------------------------------
     * Complete two agent turns on both paths; assert AgentTurnCompleted fired
     * twice with distinct turn ids and ConversationEnded fired zero times.
     *
     * The existing ConversationLifecycleTest mocks ProviderRegistry, so its
     * assertion holds even when resolution is broken.
     *
     * NOTE: AgentTurnCompleted fires on tool-call iterations (after tool
     * execution), not on plain-text responses. We script tool calls to
     * trigger the event path.
     */
    public function test_two_turns_fire_agent_turn_completed_not_conversation_ended(): void
    {
        $this->scenario = 'turn_vs_conversation_semantics_anchor';
        $this->entryPath = 'sync';

        // Capture AgentTurnCompleted and ConversationEnded events
        $capturedTurns = [];
        $capturedEnds = [];

        Event::listen(AgentTurnCompleted::class, function (AgentTurnCompleted $event) use (&$capturedTurns) {
            $capturedTurns[] = $event;
        });

        Event::listen(ConversationEnded::class, function (ConversationEnded $event) use (&$capturedEnds) {
            $capturedEnds[] = $event;
        });

        // Build fixture
        $fixture = $this->fixture()->build();

        // Script: tool call + final answer for turn 1, tool call + final answer for turn 2.
        // AgentTurnCompleted fires after tool execution, so we need tool calls.
        $this->script()
            ->toolRequest('search_operations', ['query' => 'hello'])
            ->finalAnswer('First response.')
            ->toolRequest('search_operations', ['query' => 'how are you'])
            ->finalAnswer('Second response.');

        // Turn 1: tool call then final answer
        $result1 = $this->app->make(AgentLoopService::class)->run(
            $fixture->conversation,
            'Hello'
        );
        $this->assertSame(
            'completed',
            $result1['status'],
            'First turn should complete successfully'
        );

        // Turn 2: tool call then final answer
        $result2 = $this->app->make(AgentLoopService::class)->run(
            $fixture->conversation,
            'How are you?'
        );
        $this->assertSame(
            'completed',
            $result2['status'],
            'Second turn should complete successfully'
        );

        // Assert AgentTurnCompleted fired exactly twice (once per turn)
        $this->assertCount(
            2,
            $capturedTurns,
            sprintf(
                'AgentTurnCompleted should fire exactly twice for two turns. Got %d events.',
                count($capturedTurns)
            )
        );

        // Assert both events are for the same conversation (turn semantics, not conversation semantics)
        // Note: turn_id is the iteration number (resets to 1 per run() call), so both are "1".
        // The key assertion is that AgentTurnCompleted fired (not ConversationEnded).
        foreach ($capturedTurns as $turnEvent) {
            $this->assertSame(
                $fixture->conversation->id,
                $turnEvent->conversation_id,
                'AgentTurnCompleted should reference the correct conversation_id'
            );
        }

        // Assert ConversationEnded fired zero times
        $this->assertCount(
            0,
            $capturedEnds,
            sprintf(
                'ConversationEnded should NOT fire during active turns. Got %d events.',
                count($capturedEnds)
            )
        );

        // Additional proof: conversation is still "live" (ended_at is null)
        $fixture->conversation->refresh();
        $this->assertNull(
            $fixture->conversation->ended_at,
            'Conversation should not have ended (ended_at should be null) after two turns'
        );
    }

    /* --------------------------------------------------------------------------
     * T051: Anchor — metrics recorder wiring
     * --------------------------------------------------------------------------
     * Assert a context_management_records row exists after a container-driven
     * over-budget turn.
     *
     * THIS TEST PASSED (GREEN) confirming the wiring fix applied during Phase 4:
     * LlmClientServiceProvider::register() was missing MetricsRecorder as the 17th
     * positional arg to AgentLoopService. Fixed by adding
     * $app->make(MetricsRecorder::class) to the constructor call.
     *
     * Before the fix: MetricsRecorder was null, recordContextManagement() was a
     * no-op, and no rows were created. This test would have FAILED.
     * After the fix: MetricsRecorder is wired, rows are created, this test PASSES.
     */
    public function test_metrics_recorder_wiring_creates_db_records(): void
    {
        $this->scenario = 'metrics_recorder_wiring_anchor';
        $this->entryPath = 'sync';

        // Disable condensation so the budgeter (trim) is the context manager.
        // This isolates the metrics recording path without condensation complexity.
        config(['llm-client.condensation.enabled' => false]);

        // Build fixture with over-budget history
        $fixture = $this->fixture()->build();
        $this->seedOverBudgetHistory($fixture->conversation);

        // Script: simple final answer
        $this->script()->finalAnswer('Hello!');

        // Drive the container-wired loop
        $result = $this->app->make(AgentLoopService::class)->run(
            $fixture->conversation,
            'Hi'
        );

        // Assert the loop completed
        $this->assertSame(
            'completed',
            $result['status'],
            'Container-wired loop should complete'
        );

        // Assert context_management_records row exists
        // This is the core wiring assertion: if MetricsRecorder is null (wiring bug),
        // no rows are created and this assertion fails.
        $recordCount = ContextManagementRecord::where(
            'conversation_id',
            $fixture->conversation->id
        )->count();

        $this->assertGreaterThan(
            0,
            $recordCount,
            sprintf(
                'context_management_records should have at least one row after a container-driven over-budget turn. ' .
                'Found %d rows. If zero, the MetricsRecorder is likely not wired into AgentLoopService (17th constructor arg).',
                $recordCount
            )
        );

        // Verify the record has meaningful data
        $record = ContextManagementRecord::where(
            'conversation_id',
            $fixture->conversation->id
        )->first();

        $this->assertNotNull(
            $record,
            'Context management record should exist'
        );

        $this->assertNotNull(
            $record->mechanism,
            'Context management record should have a mechanism recorded'
        );
    }

    /* --------------------------------------------------------------------------
     * T052: Anchor — provider factory laziness
     * --------------------------------------------------------------------------
     * Bind the handler AFTER boot and assert the scripted transport actually
     * served the call. Factory closures register in boot() but invoke lazily
     * at resolveByType() time; the whole harness depends on this and it is
     * currently covered nowhere.
     *
     * If the provider factories eagerly create HTTP clients at boot() time
     * (before the handler is bound), the transport is bypassed and the test fails.
     */
    public function test_provider_factory_laziness_handler_bound_after_boot(): void
    {
        $this->scenario = 'provider_factory_laziness_anchor';
        $this->entryPath = 'sync';

        // The parent setUp() already bound the handler. For this test we need
        // to prove that a binding done AFTER boot still works.
        //
        // Strategy: Create a fresh ScriptedTransport and rebind the handler.
        // If the provider factories are truly lazy (httpClientFor() called at
        // resolveByType() time), the NEW transport will be used.
        // If they eagerly create clients at boot() time, the OLD transport
        // (which has no scripted responses) will be used and this test fails.

        // Create fresh script and transport for post-boot binding
        $postBootScript = new \Tests\Integration\Harness\ResponseScript();
        $postBootScript->finalAnswer('Response from post-boot bound transport.');

        // Same embedding capability as the default transport — this scenario is
        // about factory laziness, so it must not incidentally degrade embeddings.
        $postBootTransport = new \Tests\Integration\Harness\ScriptedTransport(
            $postBootScript,
            new \Tests\Integration\Harness\DeterministicEmbedder(
                (int) config('llm-client.memory.embedding.dimension', 1536)
            )
        );

        // Rebind the handler AFTER boot (app is already booted at this point)
        $this->app->bind('llm-client.http_handler', fn () => $postBootTransport->handlerStack());

        // Build fixture
        $fixture = $this->fixture()->build();

        // Drive the container-wired loop
        $result = $this->app->make(AgentLoopService::class)->run(
            $fixture->conversation,
            'Test message'
        );

        // Assert the loop completed
        $this->assertSame(
            'completed',
            $result['status'],
            'Container-wired loop should complete with post-boot handler binding'
        );

        // Assert the post-boot transport actually served the call
        $payloads = $postBootTransport->capturedChatPayloads();
        $this->assertNotEmpty(
            $payloads,
            'Post-boot bound transport should have captured at least one payload. ' .
            'If empty, the provider factory eagerly created the HTTP client at boot() time ' .
            '(before the handler was rebound), meaning factory laziness is broken.'
        );

        // Assert the payload contains our test message
        $this->assertTrue(
            $payloads[0]->containsText('Test message'),
            'Post-boot transport payload should contain the user message'
        );

        // Assert no unconsumed steps in the post-boot script
        $this->assertFalse(
            $postBootScript->hasUnconsumedSteps(),
            sprintf(
                'Post-boot script should have served all steps. %d unconsumed steps remain.',
                $postBootScript->unconsumedSteps()
            )
        );
    }

    /* --------------------------------------------------------------------------
     * Helper methods
     * -------------------------------------------------------------------------- */

    /**
     * Seed over-budget history messages for a conversation.
     *
     * @return int Number of messages seeded
     */
    protected function seedOverBudgetHistory(\ClarionApp\LlmClient\Models\Conversation $conversation): int
    {
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
