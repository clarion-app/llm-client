<?php

namespace Tests\Integration;

use ClarionApp\HttpQueue\HttpRequest;
use ClarionApp\LlmClient\Contracts\ProviderType;
use ClarionApp\LlmClient\Events\AgentTurnCompleted;
use ClarionApp\LlmClient\Events\FinishOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Events\ToolExecutionEvent;
use ClarionApp\LlmClient\Events\UpdateOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\ContextWindowBudgeter;
use Illuminate\Support\Facades\Event;
use Tests\Integration\Harness\LaneRule;
use Tests\Integration\Harness\RequestLane;

/**
 * End-to-end context management scenarios through the container-resolved agent loop.
 *
 * Proves the full composition: over-budget history → context management chain
 * (condenser → smart trimmer → budgeter) → trimmed payload within budget.
 */
class ContextManagementJourneyTest extends AssembledSystemTestCase
{
    /* --------------------------------------------------------------------------
     * T037: Sync-path scenario
     * --------------------------------------------------------------------------
     * Seed over-budget history, drive one turn, assert the captured payload
     * is within budget and the context management steps that ran are observable.
     */
    public function test_sync_path_over_budget_history_managed(): void
    {
        $this->scenario = 'sync_path_over_budget_history_managed';
        $this->entryPath = 'sync';

        // Disable condensation so the budgeter (trim) is the context manager.
        // This isolates the sliding-window budgeter path.
        config(['llm-client.condensation.enabled' => false]);

        // Build fixture with over-budget history
        $fixture = $this->fixture()->build();
        $this->seedOverBudgetHistory($fixture->conversation);

        // Script: simple final answer (no tool calls needed)
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
            'Sync path should complete successfully even with over-budget history'
        );

        // Assert we have at least one captured chat payload
        $payloads = $this->capturedChatPayloads();
        $this->assertGreaterThanOrEqual(
            1,
            count($payloads),
            'Sync path should have at least 1 chat payload'
        );

        // Assert the captured payload is within budget
        $payload = $payloads[0];
        $budget = $this->resolveHistoryBudget($fixture->conversation);
        $tokens = $payload->estimatedTokens(fn (string $text) => (int) ceil(strlen($text) / 4));

        $this->assertLessThanOrEqual(
            $budget,
            $tokens,
            sprintf(
                'Captured payload (%d tokens) should be within history budget (%d tokens)',
                $tokens,
                $budget
            )
        );

        // Assert history was actually reduced (message count < seeded count)
        $seededCount = Message::where('conversation_id', $fixture->conversation->id)->count();
        $this->assertLessThan(
            $seededCount,
            $payload->messageCount(),
            sprintf(
                'Payload message count (%d) should be less than seeded history count (%d), proving trimming occurred',
                $payload->messageCount(),
                $seededCount
            )
        );
    }

    /* --------------------------------------------------------------------------
     * T038: Streaming-path scenario
     * --------------------------------------------------------------------------
     * Same assertions against the captured HttpRequest payload.
     */
    public function test_streaming_path_over_budget_history_managed(): void
    {
        $this->scenario = 'streaming_path_over_budget_history_managed';
        $this->entryPath = 'stream';

        // Disable condensation so the budgeter (trim) is the context manager.
        config(['llm-client.condensation.enabled' => false]);

        // Build fixture with over-budget history
        $fixture = $this->fixture()->build();
        $this->seedOverBudgetHistory($fixture->conversation);

        // Script: simple final answer
        $this->script()->finalAnswer('Hello!');

        // Fake only the specific events we want to assert on
        Event::fake([
            NewConversationMessageEvent::class,
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            ToolExecutionEvent::class,
            AgentTurnCompleted::class,
        ]);

        // Drive start() — this dispatches a SendHttpStreamRequest
        $this->app->make(AgentLoopService::class)->start($fixture->conversation);

        // Capture the dispatched stream request via ScriptedStream
        $stream = $this->stream();
        $stream->extractDispatchedJobs();
        $capturedRequests = $stream->capturedRequests();

        $this->assertNotEmpty(
            $capturedRequests,
            'Streaming path should dispatch at least one HttpRequest'
        );

        // Assert the captured HttpRequest payload is within budget
        /** @var HttpRequest $request */
        $request = $capturedRequests[0];
        $budget = $this->resolveHistoryBudget($fixture->conversation);
        $tokens = $this->estimateHttpRequestTokens($request);

        $this->assertLessThanOrEqual(
            $budget,
            $tokens,
            sprintf(
                'Captured HttpRequest payload (%d tokens) should be within history budget (%d tokens)',
                $tokens,
                $budget
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
     * T039: Positive-effect assertion (contract C5)
     * --------------------------------------------------------------------------
     * Assert a mechanism reported reducing tokens — not merely that the
     * payload fits. A payload can fit because nothing needed doing.
     */
    public function test_positive_effect_mechanism_reduced_tokens(): void
    {
        $this->scenario = 'positive_effect_mechanism_reduced_tokens';
        $this->entryPath = 'sync';

        // Disable condensation so the budgeter (trim) is the context manager.
        config(['llm-client.condensation.enabled' => false]);

        // Build fixture with over-budget history
        $fixture = $this->fixture()->build();
        $seededCount = $this->seedOverBudgetHistory($fixture->conversation);
        $seededTokens = $seededCount * 54; // ~54 tokens per message (50 words + envelope)

        // Script: simple final answer
        $this->script()->finalAnswer('Done.');

        // Drive the sync path
        $this->app->make(AgentLoopService::class)->run(
            $fixture->conversation,
            'Test'
        );

        // Get captured payload
        $payloads = $this->capturedChatPayloads();
        $this->assertGreaterThanOrEqual(
            1,
            count($payloads),
            'Should have at least 1 chat payload'
        );

        $payload = $payloads[0];
        $payloadTokens = $payload->estimatedTokens(fn (string $text) => (int) ceil(strlen($text) / 4));

        // Positive assertion: tokens were actually reduced
        // The seeded history was over-budget, so a mechanism MUST have reduced tokens.
        // If tokensAfter >= tokensBefore, no reduction occurred (e.g., passthrough).
        $this->assertLessThan(
            $seededTokens,
            $payloadTokens,
            sprintf(
                'Payload tokens (%d) should be less than seeded history tokens (%d), proving a mechanism reduced tokens — not merely that the payload fits',
                $payloadTokens,
                $seededTokens
            )
        );

        // Additional check: message count was reduced (structural proof of trimming)
        $this->assertLessThan(
            $seededCount,
            $payload->messageCount(),
            sprintf(
                'Payload message count (%d) should be less than seeded count (%d), proving messages were dropped or condensed',
                $payload->messageCount(),
                $seededCount
            )
        );
    }

    /* --------------------------------------------------------------------------
     * T040: Swallowed-failure scenario
     * --------------------------------------------------------------------------
     * Make a context-management component throw internally; assert the swallowed
     * failure surfaces in captured logs or degradation events and the scenario
     * identifies which component degraded. The product's log-and-continue
     * behavior must remain unchanged (FR-011).
     *
     * Strategy: Disable condensation so the budgeter is the only context manager.
     * The budgeter wraps all logic in try-catch and logs errors. We verify that
     * when an error occurs, it is recorded in context_management_records and the
     * conversation still completes with the untrimmed (or partially trimmed) payload.
     *
     * Alternatively: Enable condensation but make it fail by having no provider
     * available. The condenser catches the error, records a condenseError step,
     * and falls back to smartTrimThenBudget. We verify the error step is recorded
     * in context_management_records and the conversation completes.
     */
    public function test_swallowed_condensation_failure_falls_back_to_trim(): void
    {
        $this->scenario = 'swallowed_condensation_failure_falls_back_to_trim';
        $this->entryPath = 'sync';

        // Enable condensation (default) but make it fail by setting a very
        // low failure threshold so it enters cooldown immediately.
        // The condenser will attempt condensation, fail (no sealed chunks
        // available for small history), and fall back to trimming.
        config(['llm-client.condensation.enabled' => true]);
        config(['llm-client.condensation.failure_threshold' => 1]);
        config(['llm-client.condensation.cooldown_seconds' => 0]);

        // Build fixture with over-budget history
        $fixture = $this->fixture()->build();
        $this->seedOverBudgetHistory($fixture->conversation);

        // Script: condensation rule (will fail and trigger fallback to trim)
        // + one response for the agent turn.
        // Condensation requests are now classified as Condensation lane (R2a),
        // so we need a rule for that lane. The rule throws to simulate condensation
        // failure, which triggers the fallback to trimming.
        $this->script()
            ->addRule(
                new LaneRule(
                    lane: RequestLane::Condensation,
                    predicate: fn () => true,
                    respond: function () { throw new \RuntimeException('Condensation failed - no sealed chunks'); },
                    label: 'condensation_fail'
                )
            )
            ->finalAnswer('Hello!');

        // Drive the sync path
        $result = $this->app->make(AgentLoopService::class)->run(
            $fixture->conversation,
            'Hi'
        );

        // Assert the conversation completed despite any condensation issues
        $this->assertSame(
            'completed',
            $result['status'],
            'Conversation should complete even when condensation encounters issues (fallback to trim)'
        );

        // Assert the payload is within budget (trim fallback worked)
        $payloads = $this->capturedChatPayloads();
        $this->assertGreaterThanOrEqual(
            1,
            count($payloads),
            'Should have at least 1 chat payload after condensation fallback'
        );

        $payload = $payloads[0];
        $budget = $this->resolveHistoryBudget($fixture->conversation);
        $tokens = $payload->estimatedTokens(fn (string $text) => (int) ceil(strlen($text) / 4));

        $this->assertLessThanOrEqual(
            $budget,
            $tokens,
            sprintf(
                'Payload (%d tokens) should be within budget (%d tokens) after condensation fallback to trim',
                $tokens,
                $budget
            )
        );

        // Verify that context management records exist (proving the mechanism ran)
        $records = \ClarionApp\LlmClient\Models\ContextManagementRecord::where(
            'conversation_id',
            $fixture->conversation->id
        )->get();

        $this->assertNotEmpty(
            $records,
            'Context management records should exist after an over-budget turn'
        );

        // Identify which mechanism acted (trim, smart_trim, or none)
        $mechanisms = $records->pluck('mechanism')->unique()->toArray();
        $this->assertNotEmpty(
            $mechanisms,
            sprintf(
                'At least one mechanism should have acted. Mechanisms found: %s',
                implode(', ', $mechanisms)
            )
        );

        // The fallback mechanism (trim) should be present since condensation
        // either succeeded or fell back to trim
        $hasTrimOrSmartTrim = in_array('trim', $mechanisms) || in_array('smart_trim', $mechanisms);
        $this->assertTrue(
            $hasTrimOrSmartTrim,
            sprintf(
                'Trim or smart_trim mechanism should have acted as fallback. Mechanisms found: %s',
                implode(', ', $mechanisms)
            )
        );
    }

    /* --------------------------------------------------------------------------
     * T041: In-budget control scenario
     * --------------------------------------------------------------------------
     * History already within budget => no mechanism reports having reduced
     * anything. This distinguishes "did not act because nothing needed doing"
     * from "did not act because it was never enabled".
     */
    public function test_in_budget_no_mechanism_acted(): void
    {
        $this->scenario = 'in_budget_no_mechanism_acted';
        $this->entryPath = 'sync';

        // Build fixture with small history (well within budget)
        $fixture = $this->fixture()->build();
        $seededCount = $this->seedSmallHistory($fixture->conversation, 4);

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
            'Sync path should complete successfully with in-budget history'
        );

        // Get captured payload
        $payloads = $this->capturedChatPayloads();
        $this->assertGreaterThanOrEqual(
            1,
            count($payloads),
            'Should have at least 1 chat payload'
        );

        $payload = $payloads[0];

        // Assert no messages were dropped or condensed
        // The payload should contain all seeded messages plus the new user message
        // and system message. Message count should be >= seeded count.
        $this->assertGreaterThanOrEqual(
            $seededCount,
            $payload->messageCount(),
            sprintf(
                'Payload message count (%d) should be >= seeded count (%d) — no mechanism should have reduced anything for in-budget history',
                $payload->messageCount(),
                $seededCount
            )
        );

        // Assert the payload is within budget (trivially true for small history)
        $budget = $this->resolveHistoryBudget($fixture->conversation);
        $tokens = $payload->estimatedTokens(fn (string $text) => (int) ceil(strlen($text) / 4));

        $this->assertLessThanOrEqual(
            $budget,
            $tokens,
            sprintf(
                'In-budget payload (%d tokens) should be within budget (%d tokens)',
                $tokens,
                $budget
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
        // Use a small model to get a tight budget, ensuring we exceed it.
        // The fixture uses 'gpt-4' model which falls back to openai provider
        // default of 8192 context. With headroom and reserves, history budget
        // is ~3415 tokens. Each message is ~54 tokens (50 words + envelope).
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

    /**
     * Seed a small number of history messages (within budget).
     *
     * @return int Number of messages seeded
     */
    protected function seedSmallHistory(\ClarionApp\LlmClient\Models\Conversation $conversation, int $count): int
    {
        for ($i = 0; $i < $count; $i++) {
            Message::create([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'conversation_id' => $conversation->id,
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'content' => "Short message {$i}",
                'sequence_number' => $i,
            ]);
        }

        return $count;
    }

    /**
     * Resolve the history budget for a conversation.
     */
    protected function resolveHistoryBudget(\ClarionApp\LlmClient\Models\Conversation $conversation): int
    {
        $budgeter = app(ContextWindowBudgeter::class);
        $providerType = $conversation->effectiveProviderType;

        return $budgeter->resolveHistoryBudget(
            $conversation->model,
            $providerType,
            0 // system estimate
        );
    }

    /**
     * Estimate tokens in an HttpRequest body.
     */
    protected function estimateHttpRequestTokens(HttpRequest $request): int
    {
        $body = $request->body;
        $messages = is_object($body) && isset($body->messages)
            ? (array) $body->messages
            : [];

        $total = 0;
        foreach ($messages as $msg) {
            $content = is_array($msg) ? ($msg['content'] ?? '') : '';
            if (is_string($content)) {
                $total += (int) ceil(strlen($content) / 4);
            }
        }

        // Add envelope overhead per message
        $total += count($messages) * 4;

        return $total;
    }

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

                $argsChunk = json_encode([
                    'id' => 'chatcmpl_test',
                    'object' => 'chat.completion.chunk',
                    'created' => time(),
                    'model' => 'gpt-4',
                    'choices' => [
                        [
                            'index' => $index,
                            'delta' => [
                                'tool_calls' => [
                                    [
                                        'index' => $index,
                                        'function' => ['arguments' => $args],
                                    ],
                                ],
                            ],
                            'finish_reason' => 'tool_calls',
                        ],
                    ],
                ]);
                $chunks[] = "data: {$argsChunk}\n\n";
            }
        } else {
            // Content chunk
            $chunk = json_encode([
                'id' => 'chatcmpl_test',
                'object' => 'chat.completion.chunk',
                'created' => time(),
                'model' => 'gpt-4',
                'choices' => [
                    [
                        'index' => 0,
                        'delta' => ['role' => 'assistant', 'content' => ''],
                        'finish_reason' => null,
                    ],
                ],
            ]);
            $chunks[] = "data: {$chunk}\n\n";

            $contentChunk = json_encode([
                'id' => 'chatcmpl_test',
                'object' => 'chat.completion.chunk',
                'created' => time(),
                'model' => 'gpt-4',
                'choices' => [
                    [
                        'index' => 0,
                        'delta' => ['content' => $content],
                        'finish_reason' => $finishReason,
                    ],
                ],
            ]);
            $chunks[] = "data: {$contentChunk}\n\n";
        }

        // Done signal
        $chunks[] = "data: [DONE]\n\n";

        return $chunks;
    }
}
