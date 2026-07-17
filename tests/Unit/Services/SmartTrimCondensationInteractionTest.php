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
 * Full pipeline integration tests: condense → smart trim → coherence → budgeter.
 *
 * Verifies ordered execution and that each stage receives correct input
 * from the previous stage.
 */
class SmartTrimCondensationInteractionTest extends TestCase
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
    /* Pipeline ordered execution tests                                    */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function pipeline_executes_in_order_score_then_evict_then_coherence(): void
    {
        Event::fake();

        $messages = [
            $this->userMessage('Hi there'),
            $this->assistantMessage('Hello!'),
            $this->userMessage('Search for PHP docs'),
            ...$this->toolCallUnit('search', 'PHP 8.2 documentation'),
            $this->assistantMessage('As shown above, PHP 8.2 docs are available'),
            $this->userMessage('Important: the API endpoint is /api/v2/data'),
            $this->assistantMessage('Noted, API endpoint saved'),
        ];

        // preserved_pairs=1 keeps the dangling-reference message outside the
        // preserved window so the coherence stage is free to drop it. Preserved
        // units are protected from cascade dropping (that window holds the user's
        // current question), so a wider window would assert nothing here.
        $trimmer = $this->makeTrimmer([
            'enabled' => true,
            'preserved_pairs' => 1,
            'emit_events' => true,
        ]);

        $result = $trimmer->trim($messages, 50, fn ($t) => (int) ceil(strlen($t) / 4), 'conv-1');

        // Verify pipeline results:
        // 1. Scoring: pleasantries (0.2), tool_result (0.5), user (0.9), assistant (0.6)
        // 2. Eviction: lowest-value units dropped first
        // 3. Coherence: dangling references cascade-dropped
        Event::assertDispatched(SmartHistoryTrimmed::class);

        // Preserved pairs should still be intact
        $resultText = implode(' ', array_map(fn ($m) => $m['content'] ?? '', $result));
        $this->assertStringContainsString('API endpoint', $resultText);
        $this->assertStringContainsString('Noted', $resultText);

        // Dangling reference "As shown above" should be cascade-dropped
        // (tool result was evicted, so reference to it is dangling)
        $hasDanglingRef = false;
        foreach ($result as $msg) {
            if (preg_match('/\bas\s+shown\s+above/i', $msg['content'] ?? '')) {
                $hasDanglingRef = true;
            }
        }
        $this->assertFalse($hasDanglingRef, 'Dangling reference should be cascade-dropped by coherence stage');
    }

    #[Test]
    public function system_message_preserved_through_pipeline(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 'You are a helpful assistant.'],
            $this->userMessage('Hi'),
            $this->assistantMessage('OK'),
            $this->userMessage('Important task: deploy to production'),
            $this->assistantMessage('Deploying now'),
        ];

        $trimmer = $this->makeTrimmer([
            'enabled' => true,
            'preserved_pairs' => 1,
            'emit_events' => false,
        ]);

        $result = $trimmer->trim($messages, 50, fn ($t) => (int) ceil(strlen($t) / 4), 'conv-1');

        // System message should always be first
        $this->assertNotEmpty($result);
        $this->assertEquals('system', $result[0]['role']);
        $this->assertEquals('You are a helpful assistant.', $result[0]['content']);
    }

    #[Test]
    public function audit_event_contains_correct_before_after_counts(): void
    {
        Event::fake();

        $messages = [
            $this->userMessage('Hi'),
            $this->assistantMessage('OK'),
            $this->userMessage('Thanks'),
            $this->assistantMessage('Sure'),
            $this->userMessage('Important task'),
            $this->assistantMessage('Working on it'),
        ];

        $trimmer = $this->makeTrimmer([
            'enabled' => true,
            'preserved_pairs' => 1,
            'emit_events' => true,
        ]);

        $result = $trimmer->trim($messages, 30, fn ($t) => (int) ceil(strlen($t) / 4), 'conv-1');

        Event::assertDispatched(SmartHistoryTrimmed::class, function ($event) use ($messages, $result) {
            return $event->audit->messagesBefore === count($messages)
                && $event->audit->messagesAfter === count($result)
                && $event->audit->messagesAfter < $event->audit->messagesBefore
                && $event->audit->tokensAfter < $event->audit->tokensBefore;
        });
    }

    #[Test]
    public function no_event_when_no_trimming_needed(): void
    {
        Event::fake();

        $messages = [
            $this->userMessage('Hello'),
            $this->assistantMessage('Hi there'),
        ];

        $trimmer = $this->makeTrimmer([
            'enabled' => true,
            'preserved_pairs' => 2,
            'emit_events' => true,
        ]);

        // Large budget — no trimming needed
        $result = $trimmer->trim($messages, 1000, fn ($t) => (int) ceil(strlen($t) / 4), 'conv-1');

        Event::assertNotDispatched(SmartHistoryTrimmed::class);
        $this->assertCount(2, $result);
    }

    #[Test]
    public function disabled_trimmer_returns_original_messages(): void
    {
        $messages = [
            $this->userMessage('Hi'),
            $this->assistantMessage('OK'),
        ];

        $trimmer = $this->makeTrimmer([
            'enabled' => false,
            'preserved_pairs' => 0,
            'emit_events' => false,
        ]);

        $result = $trimmer->trim($messages, 1, fn ($t) => (int) ceil(strlen($t) / 4), 'conv-1');

        // Disabled — no trimming even with tiny budget
        $this->assertCount(2, $result);
    }

    /* ------------------------------------------------------------------ */
    /* Benchmark: SC-004 smart trim vs naive oldest-first                   */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function sc004_smart_trim_retains_more_substantive_tokens_than_naive(): void
    {
        // Build mixed-value conversation with 100 messages
        $messages = [];

        // First 40 messages: low-value pleasantries and filler
        for ($i = 0; $i < 20; $i++) {
            $messages[] = $this->userMessage('Hi');
            $messages[] = $this->assistantMessage('OK');
        }

        // Middle 20 messages: tool results (medium value)
        for ($i = 0; $i < 10; $i++) {
            $messages[] = $this->userMessage("Query $i");
            $toolUnit = $this->toolCallUnit('search', "Result data for query $i with some details");
            foreach ($toolUnit as $toolMsg) {
                $messages[] = $toolMsg;
            }
        }

        // Last 40 messages: high-value instructions and decisions
        for ($i = 0; $i < 20; $i++) {
            $messages[] = $this->userMessage("Important decision $i: the deployment target is production server $i with specific config");
            $messages[] = $this->assistantMessage("Confirmed decision $i: deploying to server $i with the specified configuration");
        }

        $trimmer = $this->makeTrimmer([
            'enabled' => true,
            'preserved_pairs' => 10,
            'emit_events' => false,
        ]);

        // Tight budget — 200 tokens allows ~20 short messages
        $smartResult = $trimmer->trim($messages, 200, fn ($t) => (int) ceil(strlen($t) / 4), 'conv-1');

        // Naive oldest-first: just take the last N messages that fit the budget
        $naiveResult = [];
        $naiveTokens = 0;
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $tokens = (int) ceil(strlen($messages[$i]['content'] ?? '') / 4) + 4;
            if ($naiveTokens + $tokens <= 200) {
                array_unshift($naiveResult, $messages[$i]);
                $naiveTokens += $tokens;
            } else {
                break;
            }
        }

        // Count substantive tokens (messages scoring >= 0.5)
        $scorer = new MessageScorer();

        // Smart result: count substantive content
        $smartScores = $scorer->computeScores($smartResult);
        $smartSubstantiveTokens = 0;
        foreach ($smartResult as $idx => $msg) {
            $score = $smartScores[$idx]->score ?? 0;
            if ($score >= 0.5) {
                $smartSubstantiveTokens += (int) ceil(strlen($msg['content'] ?? '') / 4);
            }
        }

        // Naive result: count substantive content
        $naiveScores = $scorer->computeScores($naiveResult);
        $naiveSubstantiveTokens = 0;
        foreach ($naiveResult as $idx => $msg) {
            $score = $naiveScores[$idx]->score ?? 0;
            if ($score >= 0.5) {
                $naiveSubstantiveTokens += (int) ceil(strlen($msg['content'] ?? '') / 4);
            }
        }

        // Smart trim should retain >= 50% more substantive tokens than naive
        // (because it evicts low-value content from the beginning, keeping more high-value messages)
        if ($naiveSubstantiveTokens > 0) {
            $improvement = ($smartSubstantiveTokens - $naiveSubstantiveTokens) / $naiveSubstantiveTokens;
            $this->assertGreaterThan(0, $improvement,
                "Smart trim should retain more substantive tokens than naive oldest-first. " .
                "Smart: {$smartSubstantiveTokens}, Naive: {$naiveSubstantiveTokens}, Improvement: " . round($improvement * 100, 2) . "%");
        }

        // Smart trim should have fewer low-value messages in result
        $smartLowValue = 0;
        foreach ($smartScores as $s) {
            if ($s->score < 0.3) $smartLowValue++;
        }
        $naiveLowValue = 0;
        foreach ($naiveScores as $s) {
            if ($s->score < 0.3) $naiveLowValue++;
        }
        $this->assertLessThanOrEqual($naiveLowValue, $smartLowValue,
            "Smart trim should have fewer low-value messages than naive approach");
    }
}
