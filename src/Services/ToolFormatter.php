<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Contracts\ProviderType;

/**
 * Provider-aware tool formatter.
 *
 * Transforms canonical OpenAI-format tool definitions into provider-specific
 * schema formats. The formatter flattens the `{type, function}` wrapper for
 * Anthropic providers, while passing through unchanged for OpenAI-compatible
 * providers (OpenAI, LlamaCpp).
 *
 * Input format (OpenAI, produced by AgentLoopService::buildToolsPayload()):
 *   [
 *     [
 *       'type' => 'function',
 *       'function' => [
 *         'name' => 'list_applications',
 *         'description' => 'List all available API applications...',
 *         'parameters' => ['type' => 'object', 'properties' => new \stdClass()],
 *       ],
 *     ],
 *   ]
 *
 * Output format (Anthropic):
 *   [
 *     [
 *       'name' => 'list_applications',
 *       'description' => 'List all available API applications...',
 *       'input_schema' => ['type' => 'object', 'properties' => new \stdClass()],
 *     ],
 *   ]
 */
class ToolFormatter
{
    /**
     * Format tool definitions for a specific LLM provider.
     *
     * @param list<array{
     *     type: 'function',
     *     function: array{
     *         name: string,
     *         description: string,
     *         parameters: array{
     *             type: string,
     *             properties: array|\stdClass,
     *             required?: list<string>
     *         }
     *     }
     * }> $tools OpenAI-format tool definitions.
     * @param ProviderType $type Target provider type.
     *
     * @return list<array{
     *     type?: string,
     *     function?: array{...},
     *     name?: string,
     *     description?: string,
     *     input_schema?: array{...}
     * }> Provider-specific tool definitions.
     *
     * @throws \InvalidArgumentException If provider type is unsupported.
     */
    public function formatForProvider(array $tools, ProviderType $type): array
    {
        return match ($type) {
            ProviderType::OpenAI, ProviderType::LlamaCpp => $tools,
            ProviderType::Anthropic => $this->formatAnthropic($tools),
            default => $this->formatUnsupported($type),
        };
    }

    /**
     * Format tools for Anthropic provider.
     *
     * Flattens from OpenAI `{type, function}` wrapper to Anthropic
     * `{name, description, input_schema}` format.
     */
    private function formatAnthropic(array $tools): array
    {
        $anthropicTools = [];
        foreach ($tools as $tool) {
            $function = $tool['function'] ?? [];
            $anthropicTools[] = [
                'name' => $function['name'] ?? '',
                'description' => $function['description'] ?? '',
                'input_schema' => $function['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass()],
            ];
        }
        return $anthropicTools;
    }

    /**
     * Throw an exception for unsupported provider types.
     */
    private function formatUnsupported(ProviderType $type): never
    {
        throw new \InvalidArgumentException(
            'Unsupported provider type: ' . $type->value . '. ' .
            'Supported types are: openai, anthropic, llama.cpp'
        );
    }
}
