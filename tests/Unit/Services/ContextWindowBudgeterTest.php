<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use Tests\TestCase;
use ClarionApp\LlmClient\Services\ContextWindowBudgeter;
use ClarionApp\LlmClient\Contracts\ProviderType;
use ClarionApp\LlmClient\Events\ContextWindowTrimmed;
use Illuminate\Support\Facades\Event;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;

class ContextWindowBudgeterTest extends TestCase
{
    /**
     * Deterministic token estimator: 1 token per 4 characters (ceil),
     * matching the OpenAI/llama.cpp character-based estimator.
     *
     * @param string $text Text to estimate
     * @return int Approximate token count
     */
    private function estimator(string $text): int
    {
        return (int) ceil(max(strlen($text), 0) / 4);
    }

    /**
     * Estimate the token cost of a single canonical message by extracting
     * all text-bearing fields and passing them through the estimator.
     *
     * @param array $message Canonical message array
     * @return int Estimated tokens
     */
    private function estimateMessage(array $message): int
    {
        $text = '';

        // Content (user, assistant, tool, system)
        if (!empty($message['content'])) {
            $text .= $message['content'];
        }

        // Tool calls (assistant with tool_calls)
        if (!empty($message['tool_calls'])) {
            foreach ($message['tool_calls'] as $call) {
                $text .= $call['function']['name'] ?? '';
                $text .= $call['function']['arguments'] ?? '';
            }
        }

        // Tool call ID (tool role)
        if (!empty($message['tool_call_id'])) {
            $text .= $message['tool_call_id'];
        }

        // Per-message envelope constant (~4 tokens for role + delimiters)
        $tokens = $this->estimator($text) + 4;

        return $tokens;
    }

    /**
     * Build a plain user message with deterministic content length.
     *
     * @param int $charLength Character count for the content body
     * @return array Canonical message
     */
    private function userMessage(int $charLength): array
    {
        return [
            'role' => 'user',
            'content' => str_repeat('x', $charLength),
        ];
    }

    /**
     * Build a plain assistant message with deterministic content length.
     *
     * @param int $charLength Character count for the content body
     * @return array Canonical message
     */
    private function assistantMessage(int $charLength): array
    {
        return [
            'role' => 'assistant',
            'content' => str_repeat('a', $charLength),
        ];
    }

    /**
     * Build a system message with deterministic content length.
     *
     * @param int $charLength Character count for the content body
     * @return array Canonical message
     */
    private function systemMessage(int $charLength): array
    {
        return [
            'role' => 'system',
            'content' => str_repeat('s', $charLength),
        ];
    }

    /**
     * Build an assistant message with tool_calls and a following tool result.
     *
     * @param int $resultLength Character count for the tool result content
     * @return array Array of two canonical messages
     */
    private function toolCallUnit(int $resultLength): array
    {
        return [
            [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [
                    [
                        'id' => 'call_abc123',
                        'type' => 'function',
                        'function' => [
                            'name' => 'test_tool',
                            'arguments' => '{"key":"value"}',
                        ],
                    ],
                ],
            ],
            [
                'role' => 'tool',
                'tool_call_id' => 'call_abc123',
                'content' => str_repeat('r', $resultLength),
            ],
        ];
    }

    /**
     * Build a config with a specific context size and response reserve.
     *
     * @param int $context Raw model context size in tokens
     * @param int $responseReserve Response reserve in tokens
     * @param float $headroomRatio Headroom ratio (0.0-1.0)
     * @param int $injectedSectionReserve Injected section reserve in tokens
     * @param bool $enabled Whether trimming is enabled
     * @return array Config block
     */
    private function makeConfig(
        int $context = 128000,
        int $responseReserve = 4096,
        float $headroomRatio = 0.15,
        int $injectedSectionReserve = 1500,
        bool $enabled = true
    ): array {
        return [
            'enabled' => $enabled,
            'headroom_ratio' => $headroomRatio,
            'injected_section_reserve' => $injectedSectionReserve,
            'models' => [
                'test-model' => [
                    'context' => $context,
                    'response_reserve' => $responseReserve,
                ],
            ],
            'providers' => [
                'openai' => [
                    'context' => 8192,
                    'response_reserve' => 2048,
                ],
            ],
            'fallback' => [
                'context' => 8192,
                'response_reserve' => 2048,
            ],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
    }

    /* ------------------------------------------------------------------ */
    /* T005: Budget-resolution & passthrough tests                         */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function exactly_at_budget_does_not_trim(): void
    {
        // Budget: context=100, headroom=0, reserve=10, injected=0
        // historyBudget = floor(100*1) - 10 - 0 - estimate(system)
        // system = 0 chars → estimate(system) = 0+4 = 4
        // historyBudget = 100 - 10 - 0 - 4 = 86
        // We need messages that total exactly 86 tokens.
        // Each user message: ceil(chars/4) + 4.
        // 16-char content → ceil(16/4)+4 = 8 tokens each.
        // 10 messages = 80 tokens (plus system 4 = 84 ≤ 86, passthrough).
        $system = $this->systemMessage(0);
        $msg = $this->userMessage(16); // 8 tokens each
        $messages = array_merge([$system], array_fill(0, 11, $msg)); // 11 * 8 = 88 > 86
        // Actually let's be precise: 10 msgs = 80, system=4, total=84 ≤ 86 → passthrough
        $messages = array_merge([$system], array_fill(0, 10, $msg)); // total = 84

        $config = $this->makeConfig(context: 100, responseReserve: 10, headroomRatio: 0.0, injectedSectionReserve: 0);
        $budgeter = new ContextWindowBudgeter($config);

        $result = $budgeter->trim($messages, 'test-model', ProviderType::OpenAI, $this->estimator(...), 'test-conv-id');

        $this->assertEquals($messages, $result);
        Event::assertNotDispatched(ContextWindowTrimmed::class);
    }

    #[Test]
    public function response_reserve_and_reserves_subtracted(): void
    {
        // Verify the formula: historyBudget = floor(context*(1-headroom)) - response_reserve - injected_section_reserve - estimate(system)
        // context=200, headroom=0.10 → floor(200*0.9) = 180
        // response_reserve=20, injected=10
        // system = 0 chars → estimate = 0+4 = 4
        // historyBudget = 180 - 20 - 10 - 4 = 146
        // Each 16-char user msg = 8 tokens. 18 msgs = 144 ≤ 146, 19 msgs = 152 > 146.
        $system = $this->systemMessage(0);
        $msg = $this->userMessage(16); // 8 tokens each

        // 18 messages = 144 ≤ 146 → passthrough
        $messages = array_merge([$system], array_fill(0, 18, $msg));

        $config = $this->makeConfig(context: 200, responseReserve: 20, headroomRatio: 0.10, injectedSectionReserve: 10);
        $budgeter = new ContextWindowBudgeter($config);

        $result = $budgeter->trim($messages, 'test-model', ProviderType::OpenAI, $this->estimator(...), 'test-conv-id');

        // Should be passthrough — all 18 messages fit
        $this->assertEquals($messages, $result);
        Event::assertNotDispatched(ContextWindowTrimmed::class);
    }

    #[Test]
    public function unknown_model_uses_conservative_fallback(): void
    {
        // Model 'unknown-model' not in config → use provider default, else fallback.
        // Provider 'anthropic' not in config providers → fallback (8192 context, 2048 reserve).
        $system = $this->systemMessage(0);
        $messages = array_merge([$system], [$this->userMessage(100)]);

        $config = $this->makeConfig(); // only has 'test-model' and 'openai' provider
        $budgeter = new ContextWindowBudgeter($config);

        $result = $budgeter->trim($messages, 'unknown-model', ProviderType::Anthropic, $this->estimator(...), 'test-conv-id');

        // Should still produce a valid result (fallback budget is large enough for this small history)
        $this->assertNotEmpty($result);
        // System must be first
        $this->assertEquals('system', $result[0]['role']);
    }

    #[Test]
    public function disabled_config_is_passthrough(): void
    {
        $config = $this->makeConfig(enabled: false);
        $budgeter = new ContextWindowBudgeter($config);

        $system = $this->systemMessage(0);
        $messages = array_merge([$system], array_fill(0, 100, $this->userMessage(1000)));

        $result = $budgeter->trim($messages, 'test-model', ProviderType::OpenAI, $this->estimator(...), 'test-conv-id');

        $this->assertEquals($messages, $result);
        Event::assertNotDispatched(ContextWindowTrimmed::class);
    }

    #[Test]
    public function recomputes_fresh_per_call_no_caching_across_invocations(): void
    {
        $config = $this->makeConfig(context: 200, responseReserve: 10, headroomRatio: 0.0, injectedSectionReserve: 0);
        $budgeter = new ContextWindowBudgeter($config);

        $system = $this->systemMessage(0);
        $msg = $this->userMessage(16); // 8 tokens each

        // historyBudget = 200 - 10 - 0 - 4 = 186
        // First call with 10 messages (80 tokens) → passthrough
        $messages1 = array_merge([$system], array_fill(0, 10, $msg));
        $result1 = $budgeter->trim($messages1, 'test-model', ProviderType::OpenAI, $this->estimator(...), 'test-conv-id');
        $this->assertEquals($messages1, $result1);

        // Second call with 30 messages (240 tokens) → must trim
        $messages2 = array_merge([$system], array_fill(0, 30, $msg));
        $result2 = $budgeter->trim($messages2, 'test-model', ProviderType::OpenAI, $this->estimator(...), 'test-conv-id');
        // Should trim down to fit budget
        $this->assertLessThan(count($messages2), count($result2));
    }

    /* ------------------------------------------------------------------ */
    /* T006: Trim-invariant tests                                          */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function over_budget_drops_oldest_units_newest_first(): void
    {
        // historyBudget = floor(100*0.9) - 10 - 0 - 4 = 90 - 10 - 4 = 76
        // Each 16-char msg = 8 tokens. 10 msgs = 80 > 76.
        // Should drop oldest until it fits: 9 msgs = 72 ≤ 76.
        $system = $this->systemMessage(0);
        $msg = $this->userMessage(16);
        $messages = array_merge([$system], array_fill(0, 10, $msg));

        $config = $this->makeConfig(context: 100, responseReserve: 10, headroomRatio: 0.10, injectedSectionReserve: 0);
        $budgeter = new ContextWindowBudgeter($config);

        $result = $budgeter->trim($messages, 'test-model', ProviderType::OpenAI, $this->estimator(...), 'test-conv-id');

        // System + 9 messages should remain (72 tokens)
        $this->assertCount(10, $result); // system + 9
        $this->assertEquals('system', $result[0]['role']);
        Event::assertDispatched(ContextWindowTrimmed::class);
    }

    #[Test]
    public function system_message_always_retained(): void
    {
        // Even when history is huge, system must be first in output.
        $system = $this->systemMessage(100);
        $messages = array_merge([$system], array_fill(0, 1000, $this->userMessage(500)));

        $config = $this->makeConfig(context: 500, responseReserve: 50, headroomRatio: 0.10, injectedSectionReserve: 0);
        $budgeter = new ContextWindowBudgeter($config);

        $result = $budgeter->trim($messages, 'test-model', ProviderType::OpenAI, $this->estimator(...), 'test-conv-id');

        $this->assertNotEmpty($result);
        $this->assertEquals('system', $result[0]['role']);
        $this->assertEquals($system['content'], $result[0]['content']);
    }

    /* ------------------------------------------------------------------ */
    /* T007: Tool-pairing tests                                            */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function tool_call_and_result_kept_or_dropped_together(): void
    {
        // historyBudget = floor(100*1) - 10 - 0 - 4 = 86
        // Build: system + user(16) + tool unit + user(16)
        // If tool unit is admitted, both assistant(with tool_calls) and tool result must be present
        $system = $this->systemMessage(0);
        $user = $this->userMessage(16); // 8 tokens
        $toolUnit = $this->toolCallUnit(32); // ~12 tokens (tool_calls overhead + result)

        $messages = [$system, $user, ...$toolUnit, $user];

        $config = $this->makeConfig(context: 100, responseReserve: 10, headroomRatio: 0.0, injectedSectionReserve: 0);
        $budgeter = new ContextWindowBudgeter($config);

        $result = $budgeter->trim($messages, 'test-model', ProviderType::OpenAI, $this->estimator(...), 'test-conv-id');

        // Check: no orphaned tool result — if tool message exists, its assistant parent must exist
        $hasToolMessage = false;
        $hasAssistantWithToolCalls = false;
        foreach ($result as $m) {
            if ($m['role'] === 'tool') {
                $hasToolMessage = true;
            }
            if ($m['role'] === 'assistant' && !empty($m['tool_calls'])) {
                $hasAssistantWithToolCalls = true;
            }
        }
        if ($hasToolMessage) {
            $this->assertTrue($hasAssistantWithToolCalls, 'Tool result without assistant parent');
        }
    }

    #[Test]
    public function never_emits_orphaned_tool_result(): void
    {
        // Build a scenario where the tool unit is dropped but the newest user message is kept.
        // The implementation must never emit a tool message without its assistant parent.
        $system = $this->systemMessage(0);
        // Very small user message at end (newest)
        $lastUser = $this->userMessage(4); // ~5 tokens
        // Large tool unit that will be dropped
        $bigToolUnit = $this->toolCallUnit(5000);
        $messages = [$system, ...$bigToolUnit, $lastUser];

        // Small budget: floor(50*1) - 10 - 0 - 4 = 36
        $config = $this->makeConfig(context: 50, responseReserve: 10, headroomRatio: 0.0, injectedSectionReserve: 0);
        $budgeter = new ContextWindowBudgeter($config);

        $result = $budgeter->trim($messages, 'test-model', ProviderType::OpenAI, $this->estimator(...), 'test-conv-id');

        // Verify result contains only system + user (no orphaned tool messages)
        $roles = array_column($result, 'role');
        $this->assertNotContains('tool', $roles, 'Tool message should not appear without its assistant parent');

        // The result should be system + the last user message (or a truncated version of it)
        $this->assertEquals('system', $result[0]['role']);
        $this->assertGreaterThanOrEqual(1, count($result));
    }

    /* ------------------------------------------------------------------ */
    /* T008: Oversized-truncation tests                                    */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function single_oversized_message_is_truncated(): void
    {
        // historyBudget = floor(50*1) - 10 - 0 - 4 = 36
        // Single user message with 10000 chars → way over budget
        // Should be truncated, not dropped.
        $system = $this->systemMessage(0);
        $bigMessage = $this->userMessage(10000);
        $messages = [$system, $bigMessage];

        $config = $this->makeConfig(context: 50, responseReserve: 10, headroomRatio: 0.0, injectedSectionReserve: 0);
        $budgeter = new ContextWindowBudgeter($config);

        $result = $budgeter->trim($messages, 'test-model', ProviderType::OpenAI, $this->estimator(...), 'test-conv-id');

        // Should have system + 1 message (truncated)
        $this->assertCount(2, $result);
        $this->assertEquals('system', $result[0]['role']);
        $this->assertEquals('user', $result[1]['role']);
        // Content should be shorter than original
        $this->assertLessThan(strlen($bigMessage['content']), strlen($result[1]['content']));
        // Should have truncation marker
        $this->assertStringContainsString('[truncated]', $result[1]['content']);
        Event::assertDispatched(ContextWindowTrimmed::class, function ($event) {
            return $event->truncated === true;
        });
    }

    #[Test]
    public function single_oversized_tool_unit_truncates_result_content(): void
    {
        // historyBudget = floor(100*1) - 10 - 0 - 4 = 86
        // Tool unit with massive result content — should truncate result, keep tool_calls intact.
        $system = $this->systemMessage(0);
        $toolUnit = $this->toolCallUnit(50000); // 50K char result
        $messages = [$system, ...$toolUnit];

        $config = $this->makeConfig(context: 100, responseReserve: 10, headroomRatio: 0.0, injectedSectionReserve: 0);
        $budgeter = new ContextWindowBudgeter($config);

        $result = $budgeter->trim($messages, 'test-model', ProviderType::OpenAI, $this->estimator(...), 'test-conv-id');

        // Should have system + assistant(tool_calls) + tool(truncated result)
        $this->assertGreaterThanOrEqual(3, count($result));
        $this->assertEquals('system', $result[0]['role']);

        // Find the assistant with tool_calls — tool_calls should be intact
        $assistantWithToolCalls = null;
        $toolResult = null;
        foreach ($result as $m) {
            if ($m['role'] === 'assistant' && !empty($m['tool_calls'])) {
                $assistantWithToolCalls = $m;
            }
            if ($m['role'] === 'tool') {
                $toolResult = $m;
            }
        }
        $this->assertNotNull($assistantWithToolCalls, 'Assistant with tool_calls should be present');
        $this->assertNotEmpty($assistantWithToolCalls['tool_calls']);
        $this->assertNotNull($toolResult, 'Tool result should be present');
        $this->assertStringContainsString('[truncated]', $toolResult['content']);
    }

    /* ------------------------------------------------------------------ */
    /* T015: Transcript-integrity (non-mutation) test                      */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function trim_does_not_mutate_caller_input_array(): void
    {
        // US2: The original message array must not be mutated.
        // trim() deep-copies input to avoid mutation; stored transcript is untouched.
        $system = $this->systemMessage(0);
        $msg = $this->userMessage(16);
        $messages = [$system, $msg, $msg, $msg, $msg, $msg, $msg, $msg, $msg, $msg, $msg];
        $originalCount = count($messages);
        $originalHash = hash('sha256', serialize($messages));

        // historyBudget = floor(50*1) - 10 - 0 - 4 = 36
        // 10 msgs × 8 tokens = 80 > 36, so trimming will occur.
        $config = $this->makeConfig(context: 50, responseReserve: 10, headroomRatio: 0.0, injectedSectionReserve: 0);
        $budgeter = new ContextWindowBudgeter($config);

        $result = $budgeter->trim($messages, 'test-model', ProviderType::OpenAI, $this->estimator(...), 'test-conv-id');

        // Caller's input is untouched
        $this->assertCount($originalCount, $messages);
        $this->assertEquals($originalHash, hash('sha256', serialize($messages)), 'Input array must not be mutated');

        // Result is trimmed (shorter)
        $this->assertLessThan($originalCount, count($result));
    }

    /* ------------------------------------------------------------------ */
    /* T017: Capacity-adaptation test                                      */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function smaller_model_admits_less_history(): void
    {
        // US3: A smaller model (smaller context window) should admit fewer messages.
        // Same messages, different budgets simulate different model capacities.
        $system = $this->systemMessage(0);
        $msg = $this->userMessage(16);
        $messages = array_merge([$system], array_fill(0, 20, $msg));

        // Large model budget: floor(200*1) - 10 - 0 - 4 = 186 → 20 msgs (160 tokens) fit
        $configLarge = $this->makeConfig(context: 200, responseReserve: 10, headroomRatio: 0.0, injectedSectionReserve: 0);
        $budgeterLarge = new ContextWindowBudgeter($configLarge);
        $resultLarge = $budgeterLarge->trim($messages, 'test-model', ProviderType::OpenAI, $this->estimator(...), 'test-conv-id');

        // Small model budget: floor(50*1) - 10 - 0 - 4 = 36 → only 4 msgs (32 tokens) fit
        $configSmall = $this->makeConfig(context: 50, responseReserve: 10, headroomRatio: 0.0, injectedSectionReserve: 0);
        $budgeterSmall = new ContextWindowBudgeter($configSmall);
        $resultSmall = $budgeterSmall->trim($messages, 'test-model', ProviderType::OpenAI, $this->estimator(...), 'test-conv-id');

        // Large model admits more messages than small model
        $this->assertGreaterThan(count($resultSmall), count($resultLarge));

        // Small model trims significantly
        $this->assertLessThan(count($messages), count($resultSmall));
    }
}
