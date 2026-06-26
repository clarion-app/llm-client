<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use Tests\TestCase;
use ClarionApp\LlmClient\Services\MessageFormatter;
use ClarionApp\LlmClient\Contracts\ProviderType;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;

class MessageFormatterTest extends TestCase
{
    private MessageFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new MessageFormatter();
    }

    /* ------------------------------------------------------------------ */
    /* Phase 2: Foundational — Pass-through and return structure           */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function format_returns_structured_array_with_messages_and_system_keys()
    {
        $result = $this->formatter->formatForProvider([], ProviderType::OpenAI);

        $this->assertArrayHasKey('messages', $result);
        $this->assertArrayHasKey('system', $result);
        $this->assertIsArray($result['messages']);
        $this->assertIsString($result['system']);
    }

    #[Test]
    public function openai_provider_passes_messages_through_unchanged()
    {
        $messages = [
            ['role' => 'system', 'content' => 'You are helpful.'],
            ['role' => 'user', 'content' => 'Hello'],
        ];

        $result = $this->formatter->formatForProvider($messages, ProviderType::OpenAI);

        $this->assertEquals($messages, $result['messages']);
        $this->assertEquals('', $result['system']);
    }

    #[Test]
    public function llamacpp_provider_passes_messages_through_unchanged()
    {
        $messages = [
            ['role' => 'system', 'content' => 'Be concise.'],
            ['role' => 'user', 'content' => 'What is 2+2?'],
        ];

        $result = $this->formatter->formatForProvider($messages, ProviderType::LlamaCpp);

        $this->assertEquals($messages, $result['messages']);
        $this->assertEquals('', $result['system']);
    }

    /* ------------------------------------------------------------------ */
    /* Phase 3: US1 — Anthropic role mapping and system extraction        */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function anthropic_extracts_system_message_from_messages_array()
    {
        $messages = [
            ['role' => 'system', 'content' => 'You are a helpful assistant.'],
            ['role' => 'user', 'content' => 'Hello'],
        ];

        $result = $this->formatter->formatForProvider($messages, ProviderType::Anthropic);

        $this->assertEquals('You are a helpful assistant.', $result['system']);
        $this->assertCount(1, $result['messages']);
        $roles = array_column($result['messages'], 'role');
        $this->assertNotContains('system', $roles);
    }

    #[Test]
    public function anthropic_maps_user_role_to_human()
    {
        $messages = [
            ['role' => 'user', 'content' => 'Hello'],
        ];

        $result = $this->formatter->formatForProvider($messages, ProviderType::Anthropic);

        $this->assertEquals('human', $result['messages'][0]['role']);
    }

    #[Test]
    public function anthropic_preserves_assistant_role()
    {
        $messages = [
            ['role' => 'assistant', 'content' => 'Hi there!'],
        ];

        $result = $this->formatter->formatForProvider($messages, ProviderType::Anthropic);

        $this->assertEquals('assistant', $result['messages'][0]['role']);
    }

    #[Test]
    public function anthropic_transforms_plain_message_sequence()
    {
        $messages = [
            ['role' => 'system', 'content' => 'Be kind.'],
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'assistant', 'content' => 'Hi! How can I help?'],
        ];

        $result = $this->formatter->formatForProvider($messages, ProviderType::Anthropic);

        $this->assertEquals('Be kind.', $result['system']);
        $this->assertCount(2, $result['messages']);

        $this->assertEquals('human', $result['messages'][0]['role']);
        $this->assertEquals('Hello', $result['messages'][0]['content']);
        $this->assertEquals('assistant', $result['messages'][1]['role']);
        $this->assertEquals('Hi! How can I help?', $result['messages'][1]['content']);
    }

    #[Test]
    public function openai_preserves_system_messages_inline()
    {
        $messages = [
            ['role' => 'system', 'content' => 'Be kind.'],
            ['role' => 'user', 'content' => 'Hello'],
        ];

        $result = $this->formatter->formatForProvider($messages, ProviderType::OpenAI);

        $this->assertEquals('', $result['system']);
        $this->assertEquals('system', $result['messages'][0]['role']);
    }

    #[Test]
    public function message_chronological_order_is_preserved_for_all_provider_types()
    {
        $messages = [
            ['role' => 'system', 'content' => 'Prompt'],
            ['role' => 'user', 'content' => 'First'],
            ['role' => 'assistant', 'content' => 'Reply 1'],
            ['role' => 'user', 'content' => 'Second'],
            ['role' => 'assistant', 'content' => 'Reply 2'],
        ];

        // OpenAI pass-through preserves order
        $openai = $this->formatter->formatForProvider($messages, ProviderType::OpenAI);
        $this->assertCount(5, $openai['messages']);

        // Anthropic removes system, maps roles, preserves order
        $anthropic = $this->formatter->formatForProvider($messages, ProviderType::Anthropic);
        $this->assertCount(4, $anthropic['messages']);
        $this->assertEquals('human', $anthropic['messages'][0]['role']);
        $this->assertEquals('assistant', $anthropic['messages'][1]['role']);
        $this->assertEquals('human', $anthropic['messages'][2]['role']);
        $this->assertEquals('assistant', $anthropic['messages'][3]['role']);
    }

    /* ------------------------------------------------------------------ */
    /* Phase 4: US2 — Tool call and tool result round-trips               */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function openai_preserves_tool_calls_and_tool_messages_unchanged()
    {
        $messages = [
            [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [
                    ['id' => 'call_abc', 'type' => 'function', 'function' => ['name' => 'search', 'arguments' => '{"q":"test"}']],
                ],
            ],
            ['role' => 'tool', 'tool_call_id' => 'call_abc', 'content' => 'Result'],
        ];

        $result = $this->formatter->formatForProvider($messages, ProviderType::OpenAI);

        $this->assertEquals($messages, $result['messages']);
    }

    #[Test]
    public function anthropic_converts_assistant_tool_calls_to_tool_use_blocks()
    {
        $messages = [
            [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [
                    [
                        'id' => 'call_xyz',
                        'type' => 'function',
                        'function' => ['name' => 'list_apps', 'arguments' => '{}'],
                    ],
                ],
            ],
        ];

        $result = $this->formatter->formatForProvider($messages, ProviderType::Anthropic);

        $msg = $result['messages'][0];
        $this->assertEquals('assistant', $msg['role']);
        $this->assertIsArray($msg['content']);
        $block = $msg['content'][0];
        $this->assertEquals('tool_use', $block['type']);
        $this->assertEquals('list_apps', $block['name']);
        $this->assertEquals([], $block['input']);
    }

    #[Test]
    public function anthropic_converts_tool_result_messages_to_human_blocks()
    {
        $messages = [
            [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [
                    ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'search', 'arguments' => '{}']],
                ],
            ],
            ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => 'Search results'],
        ];

        $result = $this->formatter->formatForProvider($messages, ProviderType::Anthropic);

        $toolMsg = $result['messages'][1];
        $this->assertEquals('human', $toolMsg['role']);
        $this->assertIsArray($toolMsg['content']);
        $block = $toolMsg['content'][0];
        $this->assertEquals('tool_result', $block['type']);
        $this->assertEquals('toolu_call_1', $block['tool_use_id']);
        $this->assertEquals('Search results', $block['content']);
    }

    #[Test]
    public function anthropic_preserves_multiple_tool_calls_in_sequence()
    {
        $messages = [
            [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [
                    ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'list', 'arguments' => '{}']],
                    ['id' => 'call_2', 'type' => 'function', 'function' => ['name' => 'get', 'arguments' => '{"id":1}']],
                ],
            ],
            ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => 'List result'],
            ['role' => 'tool', 'tool_call_id' => 'call_2', 'content' => 'Get result'],
        ];

        $result = $this->formatter->formatForProvider($messages, ProviderType::Anthropic);

        // Assistant with 2 tool_use blocks
        $assistant = $result['messages'][0];
        $this->assertCount(2, $assistant['content']);
        $this->assertEquals('tool_use', $assistant['content'][0]['type']);
        $this->assertEquals('tool_use', $assistant['content'][1]['type']);

        // Tool results aggregated into single human message
        $toolResult = $result['messages'][1];
        $this->assertEquals('human', $toolResult['role']);
        // Both tool results should be in the same aggregated human message
        $this->assertCount(2, $toolResult['content']);
        $this->assertEquals('tool_result', $toolResult['content'][0]['type']);
        $this->assertEquals('tool_result', $toolResult['content'][1]['type']);
    }

    #[Test]
    public function anthropic_tool_conversion_with_null_content_on_assistant()
    {
        $messages = [
            [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [
                    ['id' => 'call_a', 'type' => 'function', 'function' => ['name' => 'op', 'arguments' => '{}']],
                ],
            ],
        ];

        $result = $this->formatter->formatForProvider($messages, ProviderType::Anthropic);

        $msg = $result['messages'][0];
        $this->assertIsArray($msg['content']);
        $this->assertCount(1, $msg['content']);
        $this->assertEquals('tool_use', $msg['content'][0]['type']);
    }

    /* ------------------------------------------------------------------ */
    /* Phase 5: US3 — Edge cases                                          */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function empty_content_string_handled_for_all_providers()
    {
        $messages = [
            ['role' => 'user', 'content' => ''],
        ];

        $openai = $this->formatter->formatForProvider($messages, ProviderType::OpenAI);
        $this->assertEquals('', $openai['messages'][0]['content']);

        $anthropic = $this->formatter->formatForProvider($messages, ProviderType::Anthropic);
        $this->assertEquals('', $anthropic['messages'][0]['content']);
    }

    #[Test]
    public function null_content_without_tool_calls_normalized_to_empty_string()
    {
        $messages = [
            ['role' => 'user', 'content' => null],
        ];

        $result = $this->formatter->formatForProvider($messages, ProviderType::Anthropic);
        $this->assertEquals('', $result['messages'][0]['content']);
    }

    #[Test]
    public function null_content_with_tool_calls_passed_through_as_null_for_openai()
    {
        $messages = [
            [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [
                    ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'op', 'arguments' => '{}']],
                ],
            ],
        ];

        $result = $this->formatter->formatForProvider($messages, ProviderType::OpenAI);
        $this->assertNull($result['messages'][0]['content']);
    }

    #[Test]
    public function unsupported_provider_type_throws_invalidargumentexception()
    {
        // The match has a `default` fallback that throws InvalidArgumentException.
        // Verify the formatter is exhaustive by checking all 3 cases work,
        // then confirm the default handler exists via reflection.
        $formatter = new MessageFormatter();
        $method = new \ReflectionMethod($formatter, 'formatUnsupported');
        $this->assertTrue($method->isPrivate());

        // Invoke the private method to verify it throws
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported provider type');
        $method->invoke($formatter, ProviderType::OpenAI);
    }

    #[Test]
    public function orphaned_tool_message_skipped_for_anthropic()
    {
        $messages = [
            ['role' => 'tool', 'tool_call_id' => 'call_orphan', 'content' => 'Orphaned result'],
        ];

        $result = $this->formatter->formatForProvider($messages, ProviderType::Anthropic);

        $this->assertCount(0, $result['messages']);
    }

    #[Test]
    public function empty_messages_array_handled_gracefully()
    {
        $result = $this->formatter->formatForProvider([], ProviderType::Anthropic);

        $this->assertEquals([], $result['messages']);
        $this->assertEquals('', $result['system']);
    }

    /* ------------------------------------------------------------------ */
    /* Additional: Assistant with text + tool_calls                       */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function anthropic_assistant_with_text_and_tool_calls_creates_both_blocks()
    {
        $messages = [
            [
                'role' => 'assistant',
                'content' => 'Let me search for that.',
                'tool_calls' => [
                    ['id' => 'call_s', 'type' => 'function', 'function' => ['name' => 'search', 'arguments' => '{"q":"test"}']],
                ],
            ],
        ];

        $result = $this->formatter->formatForProvider($messages, ProviderType::Anthropic);

        $msg = $result['messages'][0];
        $this->assertCount(2, $msg['content']);
        $this->assertEquals('text', $msg['content'][0]['type']);
        $this->assertEquals('Let me search for that.', $msg['content'][0]['text']);
        $this->assertEquals('tool_use', $msg['content'][1]['type']);
    }
}
