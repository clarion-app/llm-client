<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use Tests\TestCase;
use ClarionApp\LlmClient\Services\MessageScorer;
use ClarionApp\LlmClient\ValueObjects\MessageScore;
use Illuminate\Support\Facades\Cache;

use PHPUnit\Framework\Attributes\Test;

class MessageScorerTest extends TestCase
{
    private MessageScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->scorer = new MessageScorer(['score_cache_ttl_minutes' => 5]);
    }

    /* ------------------------------------------------------------------ */
    /* Role-based scoring heuristics                                        */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function system_message_scores_1_0_and_pinned(): void
    {
        $messages = [['role' => 'system', 'content' => 'You are an assistant.']];
        $scores = $this->scorer->computeScores($messages);

        $this->assertCount(1, $scores);
        $this->assertEquals(1.0, $scores[0]->score);
        $this->assertTrue($scores[0]->pinned);
        $this->assertEquals('system_message', $scores[0]->reason);
    }

    #[Test]
    public function user_message_scores_0_9(): void
    {
        $messages = [['role' => 'user', 'content' => 'Please help me with this task.']];
        $scores = $this->scorer->computeScores($messages);

        $this->assertEquals(0.9, $scores[0]->score);
        $this->assertFalse($scores[0]->pinned);
        $this->assertEquals('user_message', $scores[0]->reason);
    }

    #[Test]
    public function assistant_with_tool_calls_scores_0_7(): void
    {
        $messages = [[
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => [
                [
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => ['name' => 'search', 'arguments' => '{}'],
                ],
            ],
        ]];
        $scores = $this->scorer->computeScores($messages);

        $this->assertEquals(0.7, $scores[0]->score);
        $this->assertEquals('assistant_with_tool_calls', $scores[0]->reason);
    }

    #[Test]
    public function plain_assistant_message_scores_0_6(): void
    {
        $messages = [['role' => 'assistant', 'content' => 'The answer is 42.']];
        $scores = $this->scorer->computeScores($messages);

        $this->assertEquals(0.6, $scores[0]->score);
        $this->assertEquals('assistant_statement', $scores[0]->reason);
    }

    #[Test]
    public function tool_result_scores_0_5(): void
    {
        $messages = [[
            'role' => 'tool',
            'tool_call_id' => 'call_1',
            'content' => '{"result": "success"}',
        ]];
        $scores = $this->scorer->computeScores($messages);

        $this->assertEquals(0.5, $scores[0]->score);
        $this->assertEquals('tool_result', $scores[0]->reason);
    }

    /* ------------------------------------------------------------------ */
    /* Superseded tool result detection                                      */
    /* ------------------------------------------------------------------ */

    /**
     * Regression: supersession keyed on the tool *name*. In this harness nearly
     * every call is the `execute_operation` meta-tool, so one recent success
     * marked every earlier operation result — entirely unrelated operations —
     * as superseded (score 0.1) and first in line for eviction. Identity must
     * come from the operationId being invoked.
     */
    #[Test]
    public function different_operations_through_the_same_meta_tool_do_not_supersede_each_other(): void
    {
        $messages = [
            [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => ['name' => 'execute_operation', 'arguments' => '{"operationId":"contacts.index"}'],
                ]],
            ],
            ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => '{"contacts": [1, 2]}'],
            [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_2',
                    'type' => 'function',
                    'function' => ['name' => 'execute_operation', 'arguments' => '{"operationId":"weather.forecast"}'],
                ]],
            ],
            ['role' => 'tool', 'tool_call_id' => 'call_2', 'content' => '{"temp": 21}'],
        ];

        $scores = $this->scorer->computeScores($messages);

        $this->assertEquals(
            'tool_result',
            $scores[1]->reason,
            'A weather lookup does not supersede a contacts lookup just because both went through execute_operation'
        );
        $this->assertEquals(0.5, $scores[1]->score);
    }

    #[Test]
    public function repeating_the_same_operation_supersedes_the_earlier_result(): void
    {
        $messages = [
            [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => ['name' => 'execute_operation', 'arguments' => '{"operationId":"contacts.index"}'],
                ]],
            ],
            ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => '{"contacts": [1]}'],
            [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_2',
                    'type' => 'function',
                    'function' => ['name' => 'execute_operation', 'arguments' => '{"operationId":"contacts.index"}'],
                ]],
            ],
            ['role' => 'tool', 'tool_call_id' => 'call_2', 'content' => '{"contacts": [1, 2]}'],
        ];

        $scores = $this->scorer->computeScores($messages);

        $this->assertEquals('superseded_tool_result', $scores[1]->reason);
        $this->assertEquals(0.1, $scores[1]->score);
    }

    #[Test]
    public function earlier_tool_result_marked_superseded_by_later_result(): void
    {
        $messages = [
            // First tool call
            [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [
                    ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'search', 'arguments' => '{}']],
                ],
            ],
            ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => '{"items": [1, 2]}'],
            // Second call to same tool (supersedes first)
            [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [
                    ['id' => 'call_2', 'type' => 'function', 'function' => ['name' => 'search', 'arguments' => '{}']],
                ],
            ],
            ['role' => 'tool', 'tool_call_id' => 'call_2', 'content' => '{"items": [1, 2, 3]}'],
        ];
        $scores = $this->scorer->computeScores($messages);

        // First tool result should be superseded
        $this->assertEquals(0.1, $scores[1]->score);
        $this->assertEquals('superseded_tool_result', $scores[1]->reason);

        // Second tool result should be normal
        $this->assertEquals(0.5, $scores[3]->score);
        $this->assertEquals('tool_result', $scores[3]->reason);
    }

    /* ------------------------------------------------------------------ */
    /* Resolved error detection                                              */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function error_marked_resolved_when_later_success_exists(): void
    {
        $messages = [
            // First call - error
            [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [
                    ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'fetch', 'arguments' => '{}']],
                ],
            ],
            ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => 'Error: connection refused'],
            // Retry - success
            [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [
                    ['id' => 'call_2', 'type' => 'function', 'function' => ['name' => 'fetch', 'arguments' => '{}']],
                ],
            ],
            ['role' => 'tool', 'tool_call_id' => 'call_2', 'content' => '{"data": "ok"}'],
        ];
        $scores = $this->scorer->computeScores($messages);

        // Error result should be marked as resolved_error
        $this->assertEquals(0.1, $scores[1]->score);
        $this->assertEquals('resolved_error', $scores[1]->reason);

        // Success result should be normal
        $this->assertEquals(0.5, $scores[3]->score);
        $this->assertEquals('tool_result', $scores[3]->reason);
    }

    /* ------------------------------------------------------------------ */
    /* Pleasantry detection                                                  */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function short_greeting_detected_as_pleasantry(): void
    {
        $messages = [['role' => 'user', 'content' => 'Hi there!']];
        $scores = $this->scorer->computeScores($messages);

        $this->assertEquals(0.2, $scores[0]->score);
        $this->assertEquals('pleasantry', $scores[0]->reason);
    }

    #[Test]
    public function thanks_detected_as_pleasantry(): void
    {
        $messages = [['role' => 'user', 'content' => 'Thanks!']];
        $scores = $this->scorer->computeScores($messages);

        $this->assertEquals(0.2, $scores[0]->score);
        $this->assertEquals('pleasantry', $scores[0]->reason);
    }

    #[Test]
    public function long_message_with_greeting_not_pleasantry(): void
    {
        // Over 50 chars — should not be classified as pleasantry
        $messages = [['role' => 'user', 'content' => 'Hi, can you please help me with a very long and detailed request?']];
        $scores = $this->scorer->computeScores($messages);

        $this->assertEquals(0.9, $scores[0]->score);
        $this->assertEquals('user_message', $scores[0]->reason);
    }

    /* ------------------------------------------------------------------ */
    /* Pin keyword detection                                                 */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function remember_keyword_sets_pinned(): void
    {
        $messages = [['role' => 'user', 'content' => 'Remember this IP: 192.168.1.1']];
        $scores = $this->scorer->computeScores($messages);

        $this->assertEquals(1.0, $scores[0]->score);
        $this->assertTrue($scores[0]->pinned);
        $this->assertEquals('user_pinned', $scores[0]->reason);
    }

    #[Test]
    public function keep_in_mind_sets_pinned(): void
    {
        $messages = [['role' => 'user', 'content' => 'Keep this in mind: the server is on port 8080']];
        $scores = $this->scorer->computeScores($messages);

        $this->assertTrue($scores[0]->pinned);
        $this->assertEquals(1.0, $scores[0]->score);
    }

    #[Test]
    public function dont_forget_sets_pinned(): void
    {
        $messages = [['role' => 'user', 'content' => "Don't forget the password is abc123"]];
        $scores = $this->scorer->computeScores($messages);

        $this->assertTrue($scores[0]->pinned);
        $this->assertEquals(1.0, $scores[0]->score);
    }

    #[Test]
    public function important_keyword_sets_pinned(): void
    {
        $messages = [['role' => 'user', 'content' => 'This is important: backup daily']];
        $scores = $this->scorer->computeScores($messages);

        $this->assertTrue($scores[0]->pinned);
        $this->assertEquals(1.0, $scores[0]->score);
    }

    /* ------------------------------------------------------------------ */
    /* Score caching                                                         */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function computeScores_caches_and_retrieves(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'assistant', 'content' => 'Hi there'],
        ];

        $scores1 = $this->scorer->scoreMessages($messages, 'conv-1');
        $this->assertCount(2, $scores1);

        // Second call should hit cache
        $scores2 = $this->scorer->scoreMessages($messages, 'conv-1');
        $this->assertEquals($scores1[0]->score, $scores2[0]->score);
        $this->assertEquals($scores1[1]->score, $scores2[1]->score);
    }

    #[Test]
    public function cache_invalidated_on_different_history(): void
    {
        $messages1 = [['role' => 'user', 'content' => 'Hello']];
        $messages2 = [['role' => 'user', 'content' => 'Please help me with this task']];

        $scores1 = $this->scorer->scoreMessages($messages1, 'conv-1');
        $scores2 = $this->scorer->scoreMessages($messages2, 'conv-1');

        // Different content → different scores (pleasantry vs user_message)
        $this->assertNotEquals($scores1[0]->reason, $scores2[0]->reason);
    }

    /* ------------------------------------------------------------------ */
    /* Edge cases                                                            */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function empty_messages_returns_empty_scores(): void
    {
        $scores = $this->scorer->computeScores([]);
        $this->assertCount(0, $scores);
    }

    #[Test]
    public function scores_are_indexed_correctly(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 'System prompt'],
            ['role' => 'user', 'content' => 'Task'],
            ['role' => 'assistant', 'content' => 'Done'],
        ];
        $scores = $this->scorer->computeScores($messages);

        $this->assertEquals(0, $scores[0]->messageIndex);
        $this->assertEquals(1, $scores[1]->messageIndex);
        $this->assertEquals(2, $scores[2]->messageIndex);
    }
}
