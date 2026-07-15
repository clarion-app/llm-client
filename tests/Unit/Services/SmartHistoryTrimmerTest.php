<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use Tests\TestCase;
use ClarionApp\LlmClient\Services\MessageScorer;
use ClarionApp\LlmClient\Services\SmartHistoryTrimmer;
use ClarionApp\LlmClient\Services\CoherenceValidator;
use ClarionApp\LlmClient\Events\SmartHistoryTrimmed;
use ClarionApp\LlmClient\ValueObjects\TrimAudit;
use Illuminate\Support\Facades\Event;

use PHPUnit\Framework\Attributes\Test;

class SmartHistoryTrimmerTest extends TestCase
{
    /**
     * Deterministic token estimator: 1 token per 4 characters (ceil).
     */
    private function estimator(string $text): int
    {
        return (int) ceil(max(strlen($text), 0) / 4);
    }

    private function makeTrimmer(?CoherenceValidator $coherenceValidator = null, array $config = []): SmartHistoryTrimmer
    {
        $scorer = new MessageScorer();
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
    /* Eviction order tests                                                  */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function drops_lowest_value_units_first(): void
    {
        // Build messages: pleasantry (0.2), tool result (0.5), user message (0.9)
        $messages = [
            $this->userMessage('Hi'),                          // 0.2 pleasantry
            $this->assistantMessage('Hello!'),                  // 0.2 pleasantry
            ...$this->toolCallUnit('search', 'result data'),   // 0.5 tool result
            $this->userMessage('Do something important'),      // 0.9 user message
        ];

        $trimmer = $this->makeTrimmer(null, [
            'enabled' => true,
            'preserved_pairs' => 0, // No preserved pairs — all units eligible for eviction
            'emit_events' => false,
        ]);

        // Budget allows keeping tool unit + user message after dropping pleasantries
        $result = $trimmer->trim($messages, 25, fn ($t) => (int) ceil(strlen($t) / 4), 'conv-1');

        // Pleasantries dropped first, tool unit + user message preserved
        $this->assertCount(3, $result);
    }

    #[Test]
    public function preserves_recent_pairs(): void
    {
        $messages = [];
        // Add 20 user/assistant pairs
        for ($i = 0; $i < 20; $i++) {
            $messages[] = $this->userMessage("Task $i details content here");
            $messages[] = $this->assistantMessage("Response $i with some content");
        }

        $trimmer = $this->makeTrimmer(null, [
            'enabled' => true,
            'preserved_pairs' => 8, // Preserve last 8 pairs (16 messages)
            'emit_events' => false,
        ]);

        // Budget tight enough to force trimming but allows preserved pairs
        $result = $trimmer->trim($messages, 600, fn ($t) => (int) ceil(strlen($t) / 4), 'conv-1');

        // Last 8 pairs should be preserved (16 messages)
        $this->assertGreaterThanOrEqual(16, count($result));
    }

    #[Test]
    public function stops_evicting_when_budget_met(): void
    {
        $messages = [
            $this->userMessage('Hi'),                                       // 0.2
            $this->assistantMessage('OK'),                                  // 0.2
            $this->userMessage('Important task instruction for the system'), // 0.9
            $this->assistantMessage('Working on it now'),                    // 0.6
        ];

        $trimmer = $this->makeTrimmer(null, [
            'enabled' => true,
            'preserved_pairs' => 1, // Preserve last 2 units only
            'emit_events' => false,
        ]);

        // Budget large enough that after dropping one pleasantry we fit
        $result = $trimmer->trim($messages, 30, fn ($t) => (int) ceil(strlen($t) / 4), 'conv-1');

        // Should have dropped at least the first pleasantry
        $this->assertLessThan(4, count($result));
    }

    /* ------------------------------------------------------------------ */
    /* Atomic unit drop tests                                                */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function drops_tool_call_unit_atomically(): void
    {
        $messages = [
            $this->userMessage('Start task'),
            ...$this->toolCallUnit('old_tool', 'very old result data that is superseded'),
            $this->userMessage('Current task'),
            $this->assistantMessage('Current response'),
        ];

        $trimmer = $this->makeTrimmer(null, [
            'enabled' => true,
            'preserved_pairs' => 1, // Preserve last 2 units only (current task + response)
            'emit_events' => false,
        ]);

        $result = $trimmer->trim($messages, 20, fn ($t) => (int) ceil(strlen($t) / 4), 'conv-1');

        // Tool unit (score 0.5) should be dropped before user message (score 0.9)
        // Result should have no orphaned tool messages
        $toolMessages = array_filter($result, fn ($m) => $m['role'] === 'tool');
        $this->assertEmpty($toolMessages, 'Tool unit should be dropped atomically, no orphaned tool messages');
        $this->assertCount(2, $result); // Current task user msg + Current response
    }

    /* ------------------------------------------------------------------ */
    /* Audit event tests                                                     */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function emits_audit_event_on_trim(): void
    {
        Event::fake();

        $messages = [
            $this->userMessage('Hi'),
            $this->assistantMessage('OK'),
            $this->userMessage('Important task'),
            $this->assistantMessage('Working on it'),
        ];

        $trimmer = $this->makeTrimmer(null, [
            'enabled' => true,
            'preserved_pairs' => 1, // Preserve last 2 units only
            'emit_events' => true,
        ]);

        $trimmer->trim($messages, 10, fn ($t) => (int) ceil(strlen($t) / 4), 'conv-1');

        Event::assertDispatched(SmartHistoryTrimmed::class, function ($event) {
            return $event->audit->conversationId === 'conv-1'
                && $event->audit->messagesAfter < $event->audit->messagesBefore;
        });
    }

    #[Test]
    public function no_event_when_no_trimming_needed(): void
    {
        Event::fake();

        $messages = [
            $this->userMessage('Hello'),
            $this->assistantMessage('Hi'),
        ];

        $trimmer = $this->makeTrimmer(null, [
            'enabled' => true,
            'preserved_pairs' => 2,
            'emit_events' => true,
        ]);

        // Large budget — no trimming needed
        $trimmer->trim($messages, 10000, fn ($t) => (int) ceil(strlen($t) / 4), 'conv-1');

        Event::assertNotDispatched(SmartHistoryTrimmed::class);
    }

    /* ------------------------------------------------------------------ */
    /* Pinned content protection                                             */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function preserves_pinned_content(): void
    {
        $messages = [
            $this->userMessage('Hi'),                                       // 0.2 pleasantry
            $this->userMessage('Remember this: the server is on port 8080'), // 1.0 pinned
            $this->assistantMessage('OK'),                                  // 0.2 pleasantry
            $this->userMessage('Current task'),                             // 0.9
        ];

        $trimmer = $this->makeTrimmer(null, [
            'enabled' => true,
            'preserved_pairs' => 1,
            'emit_events' => false,
        ]);

        $result = $trimmer->trim($messages, 20, fn ($t) => (int) ceil(strlen($t) / 4), 'conv-1');

        // Pinned message should be in result
        $foundPinned = false;
        foreach ($result as $msg) {
            if (str_contains($msg['content'] ?? '', 'Remember this')) {
                $foundPinned = true;
                break;
            }
        }
        $this->assertTrue($foundPinned, 'Pinned message was incorrectly trimmed');
    }

    /* ------------------------------------------------------------------ */
    /* Edge cases                                                            */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function passthrough_when_disabled(): void
    {
        $messages = [
            $this->userMessage('Hello'),
            $this->assistantMessage('Hi there'),
        ];

        $trimmer = $this->makeTrimmer(null, ['enabled' => false]);
        $result = $trimmer->trim($messages, 1, fn ($t) => (int) ceil(strlen($t) / 4), 'conv-1');

        $this->assertCount(2, $result);
    }

    #[Test]
    public function passthrough_when_budget_sufficient(): void
    {
        $messages = [
            $this->userMessage('Hello'),
            $this->assistantMessage('Hi'),
        ];

        $trimmer = $this->makeTrimmer(null, ['enabled' => true, 'preserved_pairs' => 10, 'emit_events' => false]);
        $result = $trimmer->trim($messages, 10000, fn ($t) => (int) ceil(strlen($t) / 4), 'conv-1');

        $this->assertCount(2, $result);
    }

    #[Test]
    public function preserves_system_message(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 'You are an assistant.'],
            $this->userMessage('Hi'),
            $this->assistantMessage('OK'),
        ];

        $trimmer = $this->makeTrimmer(null, [
            'enabled' => true,
            'preserved_pairs' => 1,
            'emit_events' => false,
        ]);

        $result = $trimmer->trim($messages, 5, fn ($t) => (int) ceil(strlen($t) / 4), 'conv-1');

        $this->assertNotEmpty($result);
        $this->assertEquals('system', $result[0]['role']);
    }

    #[Test]
    public function late_pin_recovery_no_crash(): void
    {
        // When user pins content that was already trimmed, system should not crash
        $messages = [
            $this->userMessage('Hi'),
            $this->assistantMessage('OK'),
            $this->userMessage('Remember this: critical info'),
        ];

        $trimmer = $this->makeTrimmer(null, [
            'enabled' => true,
            'preserved_pairs' => 1,
            'emit_events' => false,
        ]);

        // This should not throw
        $result = $trimmer->trim($messages, 5, fn ($t) => (int) ceil(strlen($t) / 4), 'conv-1');
        $this->assertIsArray($result);
    }

    #[Test]
    public function all_low_value_content_falls_back_to_recent_pairs(): void
    {
        // All messages are pleasantries — recent pairs should still be preserved
        $messages = [
            $this->userMessage('Hi'),
            $this->assistantMessage('OK'),
            $this->userMessage('Thanks'),
            $this->assistantMessage('Sure'),
            $this->userMessage('Yes'),
            $this->assistantMessage('Cool'),
        ];

        $trimmer = $this->makeTrimmer(null, [
            'enabled' => true,
            'preserved_pairs' => 2, // Preserve last 2 pairs (4 messages)
            'emit_events' => false,
        ]);

        $result = $trimmer->trim($messages, 100, fn ($t) => (int) ceil(strlen($t) / 4), 'conv-1');

        // Should preserve at least last 2 pairs (4 messages)
        $this->assertGreaterThanOrEqual(4, count($result));
    }

    #[Test]
    public function mixed_value_message_retained_whole(): void
    {
        // A message with both high and low value parts should be retained as a whole
        // (no mid-message splitting)
        $messages = [
            $this->userMessage('Hi, also the server IP is 192.168.1.1'),
            $this->assistantMessage('Got it'),
        ];

        $trimmer = $this->makeTrimmer(null, [
            'enabled' => true,
            'preserved_pairs' => 1,
            'emit_events' => false,
        ]);

        $result = $trimmer->trim($messages, 100, fn ($t) => (int) ceil(strlen($t) / 4), 'conv-1');

        // Messages should be intact (no partial content)
        foreach ($result as $msg) {
            $content = $msg['content'] ?? '';
            // No truncation markers or partial content
            $this->assertStringNotContainsString('[truncated]', $content);
        }
    }
}
