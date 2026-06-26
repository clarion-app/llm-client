<?php

namespace ClarionApp\LlmClient\Contracts;

/**
 * Supported LLM provider families.
 *
 * Used for routing requests to the correct provider implementation
 * and applying provider-specific configuration.
 *
 * Backed enum with string values for configuration and serialization.
 *
 * @see LlmProvider for the provider contract.
 */
enum ProviderType: string
{
    /**
     * OpenAI-compatible API providers (OpenAI, Azure OpenAI, local proxies).
     */
    case OpenAI = 'openai';

    /**
     * Anthropic Claude API.
     */
    case Anthropic = 'anthropic';

    /**
     * Local llama.cpp server (e.g., via llama.cpp server or Ollama).
     */
    case LlamaCpp = 'llama.cpp';
}
