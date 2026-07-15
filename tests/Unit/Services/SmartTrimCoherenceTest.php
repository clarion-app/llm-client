<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use Tests\TestCase;
use ClarionApp\LlmClient\Services\MessageScorer;
use ClarionApp\LlmClient\Services\SmartHistoryTrimmer;
use ClarionApp\LlmClient\Services\CoherenceValidator;
use ClarionApp\LlmClient\Events\SmartHistoryTrimmed;
use Illuminate\Support\Facades\Event;

use PHPUnit\Framework\Attributes\Test;

/**
 * End-to-end coherence tests for smart trimming.
 *
 * Validates that trimmed conversation history contains zero dangling references
 * and that the most recent exchanges are preserved intact.
 */
class SmartTrimCoherenceTest extends TestCase
{
    private function makeTrimmer(array $config = []): SmartHistoryTrimmer
    {
        $scorer = new MessageScorer();
        $coherenceValidator = new CoherenceValidator();
        return new SmartHistoryTrimmer($scorer, $coherenceValidator, $config);
    }

    private function userMessage(string $content): array
    {
        return ['role' => 'user', 'content' => $content];
    }

    private function assistantMessage(string $content): array
    {
        return ['role' => 'assistant', 'content' => $content];
    }

    private function toolCallUnit(string $toolName, string $resultContent, string $callId = 'call_1'): array
    {
        return [
            [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [
                    [
                        'id' => $callId,
                        'type' => 'function',
                        'function' => ['name' => $toolName, 'arguments' => '{}'],
                    ],
                ],
            ],
            ['role' => 'tool', 'tool_call_id' => $callId, 'content' => $resultContent],
        ];
    }

    /* ------------------------------------------------------------------ */
    /* End-to-end coherence tests                                            */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function trimmed_history_has_zero_dangling_references(): void
    {
        Event::fake();

        $messages = [
            $this->userMessage('Search for PHP version info'),
            ...$this->toolCallUnit('search', 'PHP 8.2 released December 2022'),
            $this->assistantMessage('As shown above, PHP 8.2 was released in Dec 2022'),
            $this->userMessage('Important: remember the deadline is March 2024'),
            $this->assistantMessage('Noted, deadline is March 2024'),
        ];

        $trimmer = $this->makeTrimmer([
            'enabled' => true,
            'preserved_pairs' => 1,
            'emit_events' => true,
        ]);

        // Tight budget to force trimming of tool result + reference
        $result = $trimmer->trim($messages, 30, fn ($t) => (int) ceil(strlen($t) / 4), 'conv-1');

        // No message in result should reference evicted content
        // The assistant message "As shown above..." should be cascade-dropped
        $hasAsShownAbove = false;
        foreach ($result as $msg) {
            if (preg_match('/\bas\s+shown\s+above/i', $msg['content'] ?? '')) {
                $hasAsShownAbove = true;
            }
        }
        $this->assertFalse($hasAsShownAbove, 'Dangling reference "as shown above" should be cascade-dropped');
    }

    #[Test]
    public function recent_exchanges_preserved_intact(): void
    {
        $messages = [
            $this->userMessage('Hi'),
            $this->assistantMessage('OK'),
            $this->userMessage('Do the search'),
            ...$this->toolCallUnit('search', 'old data'),
            $this->userMessage('Important task: deploy to production by Friday'),
            $this->assistantMessage('Understood, I will deploy by Friday'),
        ];

        $trimmer = $this->makeTrimmer([
            'enabled' => true,
            'preserved_pairs' => 2,
            'emit_events' => false,
        ]);

        $result = $trimmer->trim($messages, 50, fn ($t) => (int) ceil(strlen($t) / 4), 'conv-1');

        // Last 2 pairs should be preserved (important task + response)
        $resultText = implode(' ', array_map(fn ($m) => $m['content'] ?? '', $result));
        $this->assertStringContainsString('Important task', $resultText);
        $this->assertStringContainsString('deploy by Friday', $resultText);
    }

    #[Test]
    public function coherence_validator_skipped_when_no_coherence_service(): void
    {
        // Trimmer without coherence validator should still work
        $scorer = new MessageScorer();
        $trimmer = new SmartHistoryTrimmer($scorer, null, [
            'enabled' => true,
            'preserved_pairs' => 1,
            'emit_events' => false,
        ]);

        $messages = [
            $this->userMessage('Hi'),
            $this->assistantMessage('OK'),
            $this->userMessage('Important task'),
            $this->assistantMessage('Working on it'),
        ];

        $result = $trimmer->trim($messages, 20, fn ($t) => (int) ceil(strlen($t) / 4), 'conv-1');

        $this->assertIsArray($result);
        // Should not throw even without coherence validator
    }

    #[Test]
    public function pinned_content_survives_trimming_and_coherence(): void
    {
        $messages = [
            $this->userMessage('Search for something'),
            ...$this->toolCallUnit('search', 'data results'),
            $this->userMessage('Remember: the API key is abc123'),
            $this->assistantMessage('Got it, API key remembered'),
        ];

        $trimmer = $this->makeTrimmer([
            'enabled' => true,
            'preserved_pairs' => 0,
            'emit_events' => false,
        ]);

        // Very tight budget — should still preserve pinned content
        $result = $trimmer->trim($messages, 25, fn ($t) => (int) ceil(strlen($t) / 4), 'conv-1');

        $resultText = implode(' ', array_map(fn ($m) => $m['content'] ?? '', $result));
        $this->assertStringContainsString('API key', $resultText);
    }

    #[Test]
    public function tool_calls_and_results_dropped_atomically(): void
    {
        $messages = [
            $this->userMessage('Run the search'),
            ...$this->toolCallUnit('search', 'detailed results here'),
            $this->userMessage('Now do something else'),
            $this->assistantMessage('Done with the other task'),
        ];

        $trimmer = $this->makeTrimmer([
            'enabled' => true,
            'preserved_pairs' => 1,
            'emit_events' => false,
        ]);

        $result = $trimmer->trim($messages, 30, fn ($t) => (int) ceil(strlen($t) / 4), 'conv-1');

        // No orphaned tool messages
        $toolMessages = array_filter($result, fn ($m) => $m['role'] === 'tool');
        $this->assertEmpty($toolMessages);
    }
}
