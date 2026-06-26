<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Contracts\ProviderType;
use Illuminate\Support\Facades\Log;

/**
 * Provider-aware message formatter.
 *
 * Transforms pre-expanded OpenAI-format message arrays into provider-specific
 * formats. The formatter extracts system messages for Anthropic, maps roles
 * (user → human), and converts tool call/result sequences into provider-specific
 * content blocks.
 *
 * Input format (OpenAI, produced by AgentLoopService::buildMessagesPayload()):
 *   [
 *     ['role' => 'system', 'content' => 'System prompt'],
 *     ['role' => 'user', 'content' => 'User message'],
 *     ['role' => 'assistant', 'content' => null, 'tool_calls' => [...]],
 *     ['role' => 'tool', 'tool_call_id' => 'call_abc', 'content' => 'Result'],
 *   ]
 *
 * Output format:
 *   ['messages' => [...], 'system' => '']
 */
class MessageFormatter
{
    /**
     * Format message array for a specific LLM provider.
     *
     * @param list<array{
     *     role: 'system'|'user'|'assistant'|'tool',
     *     content: string|null,
     *     tool_call_id?: string,
     *     tool_calls?: list<array{
     *         id: string,
     *         type: string,
     *         function: array{
     *             name: string,
     *             arguments: string
     *         }
     *     }>
     * }> $messages Pre-expanded OpenAI-format message array.
     * @param ProviderType $type Target provider type.
     *
     * @return array{
     *     messages: list<array{
     *         role: string,
     *         content: string|array|null,
     *         tool_calls?: list<array{...}>,
     *         tool_call_id?: string
     *     }>,
     *     system: string
     * }
     *
     * @throws \InvalidArgumentException If provider type is unsupported.
     */
    public function formatForProvider(array $messages, ProviderType $type): array
    {
        return match ($type) {
            ProviderType::OpenAI, ProviderType::LlamaCpp => $this->formatPassThrough($messages),
            ProviderType::Anthropic => $this->formatAnthropic($messages),
            default => $this->formatUnsupported($type),
        };
    }

    /**
     * Pass-through formatter for OpenAI and LlamaCpp providers.
     * Messages are returned unchanged with system messages kept inline.
     */
    private function formatPassThrough(array $messages): array
    {
        return [
            'messages' => $messages,
            'system' => '',
        ];
    }

    /**
     * Format messages for the Anthropic API.
     *
     * - Extracts system messages to the `system` key.
     * - Maps `user` → `human`, preserves `assistant`.
     * - Converts tool_calls to `tool_use` content blocks.
     * - Converts tool role messages to `tool_result` content blocks.
     * - Aggregates consecutive tool results into a single human message.
     */
    private function formatAnthropic(array $messages): array
    {
        // Step 1: Extract system messages
        $system = '';
        $nonSystemMessages = [];
        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $system = $msg['content'] ?? '';
            } else {
                $nonSystemMessages[] = $msg;
            }
        }

        // Step 2: Transform messages with role mapping and tool conversion
        $resultMessages = $this->transformAnthropicMessages($nonSystemMessages);

        return [
            'messages' => $resultMessages,
            'system' => $system,
        ];
    }

    /**
     * Throw for unsupported provider types.
     *
     * This provides a future-proof fallback if new ProviderType cases are added
     * without corresponding formatter support.
     */
    private function formatUnsupported(ProviderType $type): array
    {
        throw new \InvalidArgumentException(
            sprintf('Unsupported provider type: %s', $type->value)
        );
    }

    /**
     * Transform non-system messages for Anthropic format.
     *
     * Handles role mapping, tool call conversion, tool result aggregation,
     * and edge cases (null content, orphaned tool messages).
     *
     * @param array $messages Messages with system messages already removed
     * @return array Transformed message array
     */
    private function transformAnthropicMessages(array $messages): array
    {
        $result = [];
        // Track whether we're in a "tool result sequence" — i.e., the previous
        // processed message was an assistant with tool_calls, so subsequent
        // tool role messages should be treated as valid (not orphaned).
        $inToolResultSequence = false;

        foreach ($messages as $msg) {
            $role = $msg['role'] ?? '';
            $content = $msg['content'] ?? null;
            $toolCalls = $msg['tool_calls'] ?? null;
            $toolCallId = $msg['tool_call_id'] ?? null;

            match ($role) {
                'user' => $result[] = [
                    'role' => 'human',
                    'content' => $content ?? '',
                ],
                'assistant' => $result[] = $this->formatAssistantMessage($content, $toolCalls),
                'tool' => $this->handleToolMessage($result, $msg, $inToolResultSequence),
                default => null,
            };

            // Enter tool sequence on assistant with tool_calls, exit on non-tool messages
            if ($role === 'assistant' && !empty($toolCalls)) {
                $inToolResultSequence = true;
            } elseif ($role !== 'tool') {
                $inToolResultSequence = false;
            }
        }

        return $result;
    }

    /**
     * Format an assistant message for Anthropic.
     *
     * Converts tool_calls to tool_use content blocks and text to text blocks.
     */
    private function formatAssistantMessage(?string $content, ?array $toolCalls): array
    {
        if (!empty($toolCalls)) {
            // Build content blocks from text + tool_use
            $contentBlocks = [];

            if ($content !== null && $content !== '') {
                $contentBlocks[] = ['type' => 'text', 'text' => $content];
            }

            foreach ($toolCalls as $call) {
                $function = $call['function'] ?? [];
                $input = json_decode($function['arguments'] ?? '{}', true) ?: [];

                $contentBlocks[] = [
                    'type' => 'tool_use',
                    'id' => 'toolu_' . ($call['id'] ?? ''),
                    'name' => $function['name'] ?? '',
                    'input' => $input,
                ];
            }

            return [
                'role' => 'assistant',
                'content' => $contentBlocks,
            ];
        }

        // Plain text assistant message
        return [
            'role' => 'assistant',
            'content' => $content ?? '',
        ];
    }

    /**
     * Handle a tool role message.
     *
     * Converts to a human message with tool_result content blocks.
     * Aggregates consecutive tool results into a single human message
     * when the previous message was already a tool_result aggregation.
     *
     * @param array $result The result messages array (passed by reference)
     */
    private function handleToolMessage(array &$result, array $msg, bool $previousWasAssistantToolCall): void
    {
        // Skip orphaned tool messages (no preceding assistant tool_calls)
        if (!$previousWasAssistantToolCall) {
            Log::warning('MessageFormatter: skipping orphaned tool message without preceding assistant tool_calls', [
                'tool_call_id' => $msg['tool_call_id'] ?? null,
            ]);
            return;
        }

        $toolResultBlock = [
            'type' => 'tool_result',
            'tool_use_id' => 'toolu_' . ($msg['tool_call_id'] ?? ''),
            'content' => $msg['content'] ?? '',
        ];

        // Check if the last result message is already a human with tool_result blocks
        // → aggregate into that same message
        $lastIndex = count($result) - 1;
        if ($lastIndex >= 0
            && $result[$lastIndex]['role'] === 'human'
            && is_array($result[$lastIndex]['content'])
            && !empty($result[$lastIndex]['content'][0]['type'] ?? '')
            && $result[$lastIndex]['content'][0]['type'] === 'tool_result') {
            $result[$lastIndex]['content'][] = $toolResultBlock;
        } else {
            $result[] = [
                'role' => 'human',
                'content' => [$toolResultBlock],
            ];
        }
    }
}
