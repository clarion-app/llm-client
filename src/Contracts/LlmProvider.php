<?php

namespace ClarionApp\LlmClient\Contracts;

use Generator;
use RuntimeException;

/**
 * Provider-agnostic LLM provider contract.
 *
 * Defines four core operations any LLM provider must support:
 * synchronous chat, streaming chat, embedding generation, and token counting.
 *
 * Callers work with unified provider-agnostic types regardless of whether
 * the backend is OpenAI, Anthropic, llama.cpp, or another provider.
 *
 * @see ProviderType for supported provider families.
 */
interface LlmProvider
{
    /**
     * Synchronous non-streaming completion.
     *
     * @param list<array{
     *     role: 'system'|'user'|'assistant'|'tool',
     *     content: string,
     *     tool_call_id?: string,
     *     tool_calls?: list<array{
     *         id: string,
     *         type: string,
     *         function: array{
     *             name: string,
     *             arguments: string
     *         }
     *     }>
     * }> $messages Conversation messages. Each message has a role, content,
     *              and optionally tool_call_id (for tool results) or tool_calls
     *              (for assistant responses with tool invocations).
     * @param list<array{
     *     type: string,
     *     function: array{
     *         name: string,
     *         description: string,
     *         parameters: array{
     *             type: string,
     *             properties: array<string, array{
     *                 type: string,
     *                 description?: string,
     *                 enum?: list<string>
     *             }>,
     *             required?: list<string>
     *         }
     *     }
     * }> $tools Optional tool definitions with JSON Schema parameter definitions.
     * @param array<string, mixed> $options Optional provider-specific configuration.
     *                                     Recognized keys:
     *                                     - temperature, top_p, max_tokens, model (standard params)
     *                                     - response_format: 'json' to enable JSON mode (provider-specific implementation)
     *
     * @return array{
     *     choices: list<array{
     *         message: array{
     *             role: string,
     *             content: string,
     *             tool_calls?: list<array{
     *                 id: string,
     *                 type: string,
     *                 function: array{
     *                     name: string,
     *                     arguments: string
     *                 }
     *             }>
     *         },
     *         finish_reason?: string
     *     }>,
     *     usage?: array{
     *         prompt_tokens: int,
     *         completion_tokens: int,
     *         total_tokens: int
     *     },
     *     model?: string
     * } Completion result with choices, content, optional tool_calls, and usage metadata.
     *
     * @throws RuntimeException If the provider request fails.
     */
    public function chat(array $messages, array $tools = [], array $options = []): array;

    /**
     * Streaming completion via PHP Generator.
     *
     * Yields partial completion results (delta chunks) consumable with `foreach`.
     *
     * @param list<array{
     *     role: 'system'|'user'|'assistant'|'tool',
     *     content: string,
     *     tool_call_id?: string,
     *     tool_calls?: list<array{
     *         id: string,
     *         type: string,
     *         function: array{
     *             name: string,
     *             arguments: string
     *         }
     *     }>
     * }> $messages Conversation messages (same format as chat()).
     * @param list<array{
     *     type: string,
     *     function: array{
     *         name: string,
     *         description: string,
     *         parameters: array{
     *             type: string,
     *             properties: array<string, array{
     *                 type: string,
     *                 description?: string,
     *                 enum?: list<string>
     *             }>,
     *             required?: list<string>
     *         }
     *     }
     * }> $tools Optional tool definitions (same format as chat()).
     * @param array<string, mixed> $options Optional provider-specific configuration.
     *                                     Recognized keys:
     *                                     - temperature, top_p, max_tokens, model (standard params)
     *                                     - response_format: 'json' to enable JSON mode (provider-specific implementation)
     *
     * @return Generator<int, array{
     *     content?: string,
     *     tool_calls?: list<array{
     *         id: string,
     *         type: string,
     *         function: array{
     *             name: string,
     *             arguments: string
     *         }
     *     }>,
     *     finish_reason?: string
     * }, void, void> Generator yielding streaming delta chunks.
     *                Each chunk may contain partial content, partial tool_calls,
     *                or a finish_reason indicating stream completion.
     *
     * @throws RuntimeException If the streaming connection fails.
     */
    public function stream(array $messages, array $tools = [], array $options = []): Generator;

    /**
     * Generate embeddings for input text(s).
     *
     * @param non-empty-list<string> $inputs Array of input text strings to embed.
     * @param array<string, mixed> $options Optional provider-specific configuration
     *                                     (e.g., model selection).
     *
     * @return array{
     *     embeddings: list<float[]>,
     *     usage?: array{
     *         prompt_tokens: int,
     *         total_tokens: int
     *     }
     * } Embedding result with float arrays (one per input) and optional usage metadata.
     *
     * @throws RuntimeException If the provider does not support embeddings
     *                          or the request fails.
     */
    public function embed(array $inputs, array $options = []): array;

    /**
     * Count approximate tokens in text.
     *
     * This is an estimate — no accuracy guarantee is made.
     * Callers should treat the result as approximate.
     *
     * @param string $text Text to count tokens for.
     * @param string|null $model Optional model name for more accurate counting
     *                           (different models use different tokenizers).
     *
     * @return int Approximate token count.
     *
     * @throws RuntimeException If the provider does not support token counting
     *                          or the tokenizer is unavailable.
     */
    public function countTokens(string $text, ?string $model = null): int;

    /**
     * List available models on this provider's server.
     *
     * Returns a unified model list regardless of provider type.
     * Providers that do not support model listing should throw
     * a descriptive RuntimeException.
     *
     * @return array{
     *     models: list<array{
     *         id: string,
     *         object?: string,
     *         owned_by?: string
     *     }>
     * } Model list with at minimum an `id` for each model.
     *
     * @throws RuntimeException If the provider does not support model listing
     *                          or the request fails.
     */
    public function listModels(): array;
}
