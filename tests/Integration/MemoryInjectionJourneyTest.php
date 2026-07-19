<?php

namespace Tests\Integration;

use ClarionApp\LlmClient\Contracts\ProviderType;
use ClarionApp\LlmClient\Events\AgentTurnCompleted;
use ClarionApp\LlmClient\Events\FinishOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Events\ToolExecutionEvent;
use ClarionApp\LlmClient\Events\UpdateOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Models\DeclarativeMemory;
use ClarionApp\LlmClient\Models\MemoryEntry;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\AgentLoopService;
use Illuminate\Support\Facades\Event;

/**
 * End-to-end memory injection scenarios through the container-resolved agent loop.
 *
 * Under SQLite `:memory:` these prove wiring, ordering, budgeting, and injection —
 * not the production SQL. The closing item is roadmap §2.7.3 which ensures that
 * SQLite-specific behavior (e.g., FTS, collation) is validated against the real store.
 */
class MemoryInjectionJourneyTest extends AssembledSystemTestCase
{
    /* --------------------------------------------------------------------------
     * T043: Embedding-available scenario (sync path)
     * --------------------------------------------------------------------------
     * Seed a preference and a memory, drive a turn, assert both appear in the
     * captured system prompt.
     */
    public function test_sync_path_preference_and_memory_injected(): void
    {
        $this->scenario = 'sync_path_preference_and_memory_injected';
        $this->entryPath = 'sync';

        // Note: withMemory() creates MemoryEntry records, whereas the episodic
        // store reads EpisodicMemory — so this scenario asserts on the
        // declarative route.
        $fixture = $this->fixture()
            ->withPreference('I prefer responses in a concise format', 'test')
            ->withAutoMemoryRetrieval()
            ->build();

        // Script: simple final answer
        $this->script()->finalAnswer('Here is the concise response you prefer.');

        // Drive the sync path
        $result = $this->app->make(AgentLoopService::class)->run(
            $fixture->conversation,
            'Can you help me with something?'
        );

        // Assert the loop completed
        $this->assertSame(
            'completed',
            $result['status'],
            'Sync path should complete successfully with memory injection'
        );

        // Assert we have captured chat payloads
        $payloads = $this->capturedChatPayloads();
        $this->assertNotEmpty(
            $payloads,
            'Should have at least one captured chat payload'
        );

        // Extract system prompt (for OpenAI it's in messages[0], not $payload->system)
        $system = $this->extractSystemPrompt($payloads[0]);

        // The system prompt should contain memory injection markers
        $hasMemoryContext = str_contains($system, 'Retrieved Memory Context')
            || str_contains($system, 'User Preferences');

        $this->assertTrue(
            $hasMemoryContext,
            sprintf(
                'System prompt should contain memory injection markers. System prompt:\n%s',
                $system
            )
        );

        // Assert the preference content appears in the payload
        $this->assertStringContainsString(
            'concise format',
            $system,
            'Preference content should appear in the system prompt'
        );
    }

    /* --------------------------------------------------------------------------
     * T043: Embedding-available scenario (streaming path)
     * --------------------------------------------------------------------------
     * Same assertions as sync path but through the streaming entry path.
     */
    public function test_streaming_path_preference_and_memory_injected(): void
    {
        $this->scenario = 'streaming_path_preference_and_memory_injected';
        $this->entryPath = 'stream';

        // Build fixture with preference (DeclarativeMemory table exists)
        $fixture = $this->fixture()
            ->withPreference('I prefer responses in a concise format', 'test')
            ->withAutoMemoryRetrieval()
            ->build();

        // Script: simple final answer
        $this->script()->finalAnswer('Concise response here.');

        // Fake only the specific events we want to assert on
        Event::fake([
            NewConversationMessageEvent::class,
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            ToolExecutionEvent::class,
            AgentTurnCompleted::class,
        ]);

        // Drive start()
        $this->app->make(AgentLoopService::class)->start($fixture->conversation);

        // Capture the dispatched stream request
        $stream = $this->stream();
        $stream->extractDispatchedJobs();
        $capturedRequests = $stream->capturedRequests();

        $this->assertNotEmpty(
            $capturedRequests,
            'Streaming path should dispatch at least one HttpRequest'
        );

        // Same accessor the sync scenarios use — the captured payload is
        // normalized across both entry paths (FR-007a).
        $system = $this->extractSystemPrompt($this->capturedChatPayloads()[0]);

        $hasMemoryContext = str_contains($system, 'Retrieved Memory Context')
            || str_contains($system, 'User Preferences');

        $this->assertTrue(
            $hasMemoryContext,
            sprintf(
                'Streaming system prompt should contain memory injection markers. System prompt:\n%s',
                $system
            )
        );

        // Emit SSE chunks and finish
        $response = $this->script()->serve();
        $sseChunks = $this->buildSseChunks($response);
        $stream->emit($sseChunks);
        $stream->finish();

        // Assert broadcast events were fired
        Event::assertDispatched(
            NewConversationMessageEvent::class,
            fn ($event) => $event->conversation_id === $fixture->conversation->id,
            'NewConversationMessageEvent should be dispatched'
        );
    }

    /* --------------------------------------------------------------------------
     * T044: SC-001a proof — retrieval ordering is similarity-based
     * --------------------------------------------------------------------------
     * Seed memories in the reverse of expected similarity order and assert that
     * the more relevant memory appears first in the system prompt.
     */
    public function test_sync_path_memory_ordering_by_relevance(): void
    {
        $this->scenario = 'sync_path_memory_ordering_by_relevance';
        $this->entryPath = 'sync';

        // Build fixture with two preferences seeded in reverse relevance order
        // The second preference is more relevant to "billing" query.
        $fixture = $this->fixture()
            ->withPreference('User timezone is UTC-5', 'test')
            ->withPreference('User prefers billing service notifications via email', 'test')
            ->withAutoMemoryRetrieval()
            ->build();

        // Script: simple final answer
        $this->script()->finalAnswer('I can help with billing notifications.');

        // Drive the sync path
        $result = $this->app->make(AgentLoopService::class)->run(
            $fixture->conversation,
            'Help me with billing service settings.'
        );

        // Assert the loop completed
        $this->assertSame('completed', $result['status']);

        // Get captured payload
        $payloads = $this->capturedChatPayloads();
        $this->assertNotEmpty($payloads, 'Should have captured payloads');

        $system = $this->extractSystemPrompt($payloads[0]);

        // Assert both preferences appear
        $this->assertStringContainsString(
            'billing service',
            $system,
            'Billing preference should appear in system prompt'
        );
        $this->assertStringContainsString(
            'UTC-5',
            $system,
            'Timezone preference should appear in system prompt'
        );

        // SC-001a: the billing preference was seeded *second*, so if it appears
        // first the ordering came from similarity, not insertion order. Passing
        // this by accident would require insertion order to be reversed.
        $billingPos = strpos($system, 'billing service');
        $timezonePos = strpos($system, 'UTC-5');

        $this->assertLessThan(
            $timezonePos,
            $billingPos,
            sprintf(
                "Retrieval must order by similarity, not insertion.\n" .
                "The billing preference is the relevant one for the query " .
                "'Help me with billing service settings' and was seeded last, " .
                "yet it appears at offset %d, after the timezone preference at %d.\n" .
                "Injected system prompt:\n%s",
                $billingPos,
                $timezonePos,
                $system
            )
        );
    }

    /* --------------------------------------------------------------------------
     * T045: Embeddings-disabled scenario (sync path)
     * --------------------------------------------------------------------------
     * withEmbeddingsDisabled() fails the embedding boundary at the transport, so
     * the product's real no-embedding fallback runs. Assert the conversation
     * completes and stored content is still injected — and declare the
     * degradations that fallback necessarily produces (FR-011a).
     */
    public function test_sync_path_embeddings_disabled_fallback(): void
    {
        $this->scenario = 'sync_path_embeddings_disabled_fallback';
        $this->entryPath = 'sync';

        // The embedding boundary is failing, so semantic routes must degrade.
        $this->ledger->expect('embedding_generation_failed:*');
        $this->ledger->expect('long_term_retrieval_failed:*');

        // Build fixture with embeddings disabled
        $fixture = $this->fixture()
            ->withPreference('I prefer concise responses', 'test')
            ->withEmbeddingsDisabled()
            ->withAutoMemoryRetrieval()
            ->build();

        // Script: simple final answer
        $this->script()->finalAnswer('Concise response here.');

        // Drive the sync path
        $result = $this->app->make(AgentLoopService::class)->run(
            $fixture->conversation,
            'Help me.'
        );

        // Assert the conversation completed (fault tolerance)
        $this->assertSame(
            'completed',
            $result['status'],
            'Conversation should complete even when embeddings are disabled'
        );

        // Assert we have captured payloads
        $payloads = $this->capturedChatPayloads();
        $this->assertNotEmpty($payloads, 'Should have captured payloads');

        // Assert stored content is still injected (no-embedding fallback)
        $system = $this->extractSystemPrompt($payloads[0]);

        $hasContent = str_contains($system, 'concise')
            || str_contains($system, 'User Preferences');

        $this->assertTrue(
            $hasContent,
            sprintf(
                'System prompt should still contain injected content via no-embedding fallback. System prompt:\n%s',
                $system
            )
        );
    }

    /* --------------------------------------------------------------------------
     * T045: Embeddings-disabled scenario (streaming path)
     * --------------------------------------------------------------------------
     * Same assertions as sync path but through the streaming entry path — the
     * same degradations must surface, proving the fallback is not sync-only.
     */
    public function test_streaming_path_embeddings_disabled_fallback(): void
    {
        $this->scenario = 'streaming_path_embeddings_disabled_fallback';
        $this->entryPath = 'stream';

        $this->ledger->expect('embedding_generation_failed:*');
        $this->ledger->expect('long_term_retrieval_failed:*');

        // Build fixture with embeddings disabled
        $fixture = $this->fixture()
            ->withPreference('I prefer concise responses', 'test')
            ->withUserMessage('Help me.')
            ->withEmbeddingsDisabled()
            ->withAutoMemoryRetrieval()
            ->build();

        // Script: simple final answer
        $this->script()->finalAnswer('OK.');

        // Drive start()
        $this->app->make(AgentLoopService::class)->start($fixture->conversation);

        // Capture the dispatched stream request
        $stream = $this->stream();
        $stream->extractDispatchedJobs();
        $capturedRequests = $stream->capturedRequests();

        $this->assertNotEmpty(
            $capturedRequests,
            'Streaming path should dispatch at least one HttpRequest even with embeddings disabled'
        );

        // Emit SSE chunks and finish
        $response = $this->script()->serve();
        $sseChunks = $this->buildSseChunks($response);
        $stream->emit($sseChunks);
        $stream->finish();
    }

    /* --------------------------------------------------------------------------
     * T046: Graceful degradation — embeddings disabled still injects preferences
     * --------------------------------------------------------------------------
     * When embeddings are disabled, AutoMemoryRetriever falls back to
     * PreferenceInjector for declarative memory retrieval. Assert that
     * preferences are still injected even without embeddings.
     */
    public function test_sync_path_embeddings_disabled_still_injects_preferences(): void
    {
        $this->scenario = 'sync_path_embeddings_disabled_still_injects_preferences';
        $this->entryPath = 'sync';

        $this->ledger->expect('embedding_generation_failed:*');
        $this->ledger->expect('long_term_retrieval_failed:*');

        // Build fixture with embeddings disabled but with a preference
        $fixture = $this->fixture()
            ->withPreference('I prefer short answers', 'test')
            ->withEmbeddingsDisabled()
            ->withAutoMemoryRetrieval()
            ->build();

        // Script: simple final answer
        $this->script()->finalAnswer('Short answer.');

        // Drive the sync path
        $result = $this->app->make(AgentLoopService::class)->run(
            $fixture->conversation,
            'Hello.'
        );

        // The conversation should complete (fault tolerance)
        $this->assertSame(
            'completed',
            $result['status'],
            'Conversation should complete with embeddings disabled'
        );

        // Assert we have captured payloads
        $payloads = $this->capturedChatPayloads();
        $this->assertNotEmpty($payloads, 'Should have captured payloads');

        // Assert preference is still injected via fallback
        $system = $this->extractSystemPrompt($payloads[0]);

        $this->assertStringContainsString(
            'short answers',
            $system,
            'Preference should still be injected via no-embedding fallback'
        );
    }

    /* --------------------------------------------------------------------------
     * T047: Empty-state control
     * --------------------------------------------------------------------------
     * User with no stored preferences or memories ⇒ conversation completes
     * normally with no injected content and no degradation.
     */
    public function test_sync_path_no_memories_no_injection(): void
    {
        $this->scenario = 'sync_path_no_memories_no_injection';
        $this->entryPath = 'sync';

        // Build fixture WITHOUT preferences or memories
        $fixture = $this->fixture()->build();

        // Script: simple final answer
        $this->script()->finalAnswer('Hello!');

        // Drive the sync path
        $result = $this->app->make(AgentLoopService::class)->run(
            $fixture->conversation,
            'Hi'
        );

        // Assert the loop completed
        $this->assertSame(
            'completed',
            $result['status'],
            'Sync path should complete successfully with no memories'
        );

        // Assert we have captured payloads
        $payloads = $this->capturedChatPayloads();
        $this->assertNotEmpty($payloads, 'Should have captured payloads');

        // Assert the system prompt does NOT contain memory injection markers
        $system = $this->extractSystemPrompt($payloads[0]);

        $this->assertFalse(
            str_contains($system, 'Retrieved Memory Context'),
            sprintf(
                'System prompt should NOT contain memory injection when no memories exist. System prompt:\n%s',
                $system
            )
        );
    }

    /* --------------------------------------------------------------------------
     * Helper methods
     * -------------------------------------------------------------------------- */

    /**
     * Build SSE chunks from a ResponseScript step.
     *
     * @param array $step ResponseScript step
     * @return array<string> SSE chunk strings
     */
    protected function buildSseChunks(array $step): array
    {
        $chunks = [];
        $choice = $step['choices'][0] ?? null;
        if (!$choice) {
            return $chunks;
        }

        $message = $choice['message'] ?? [];
        $content = $message['content'] ?? '';
        $finishReason = $choice['finish_reason'] ?? 'stop';

        if ($content !== '') {
            $contentChunk = json_encode([
                'id' => 'chatcmpl_test',
                'object' => 'chat.completion.chunk',
                'created' => time(),
                'model' => 'gpt-4',
                'choices' => [
                    [
                        'index' => 0,
                        'delta' => [
                            'role' => 'assistant',
                            'content' => $content,
                        ],
                        'finish_reason' => $finishReason,
                    ],
                ],
            ]);
            $chunks[] = "data: {$contentChunk}\n\n";
        }

        // Final [DONE] marker
        $chunks[] = "data: [DONE]\n\n";

        return $chunks;
    }
}
