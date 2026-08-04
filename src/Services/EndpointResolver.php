<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Contracts\ProviderType;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\ValueObjects\Operation;
use ClarionApp\LlmClient\ValueObjects\ServerAddress;
use RuntimeException;

/**
 * Resolves API endpoints and headers for a given Server + Operation.
 *
 * Centralizes all URL derivation so no other code in the package constructs
 * endpoint paths, reads server_url directly for URL building, or hardcodes
 * provider-specific header shapes.
 *
 * All methods accept extracted values (ServerAddress, ProviderType, token)
 * rather than a Server model — the caller is responsible for reading the
 * model properties. This keeps the resolver decoupled from Eloquent and
 * trivially testable.
 */
class EndpointResolver
{
    /**
     * URL path suffix per provider family + operation.
     * Keys are string values of ProviderType and Operation enums.
     */
    private const PATH_TABLE = [
        'openai' => [
            'chat'             => '/v1/chat/completions',
            'chat_stream'      => '/v1/chat/completions',
            'title_generation' => '/v1/chat/completions',
            'embeddings'       => '/v1/embeddings',
            'models'           => '/v1/models',
        ],
        'llama.cpp' => [
            'chat'             => '/v1/chat/completions',
            'chat_stream'      => '/v1/chat/completions',
            'title_generation' => '/v1/chat/completions',
            'embeddings'       => '/v1/embeddings',
            'models'           => '/v1/models',
        ],
        'anthropic' => [
            'chat'             => '/v1/messages',
            'chat_stream'      => '/v1/messages',
            'title_generation' => '/v1/messages',
            'embeddings'       => null, // unsupported
            'models'           => '/v1/models',
        ],
    ];

    /**
     * Derive the full URL for a given Server and Operation.
     *
     * @throws RuntimeException If the provider family does not support the operation.
     */
    public function urlFor(Server $server, Operation $operation): string
    {
        return $this->urlForValues(
            $server->provider_type,
            $server->server_url,
            $operation,
        );
    }

    /**
     * Derive the full URL from extracted values (model-independent).
     *
     * @throws RuntimeException If the provider family does not support the operation.
     */
    public function urlForValues(ProviderType $family, string $serverUrl, Operation $operation): string
    {
        $familyKey = $family->value;
        $opKey = $operation->value;
        $suffix = self::PATH_TABLE[$familyKey][$opKey] ?? null;

        if ($suffix === null) {
            throw new RuntimeException(
                sprintf(
                    'Provider family "%s" does not support operation "%s".',
                    $familyKey,
                    $operation->name
                )
            );
        }

        $address = ServerAddress::fromStored($serverUrl);
        return $address->base() . $suffix;
    }

    /**
     * Derive the headers array for a given Server and Operation.
     *
     * @return array<string, string>
     */
    public function headersFor(Server $server, Operation $operation): array
    {
        return $this->headersForValues(
            $server->provider_type,
            $server->token,
            $operation,
        );
    }

    /**
     * Derive the headers array from extracted values (model-independent).
     *
     * @return array<string, string>
     */
    public function headersForValues(ProviderType $family, ?string $token, Operation $operation): array
    {
        $headers = [
            'Content-Type' => 'application/json',
        ];

        // Accept header — SSE for streaming, JSON otherwise.
        if ($operation === Operation::ChatStream) {
            $headers['Accept'] = 'text/event-stream';
        } else {
            $headers['Accept'] = 'application/json';
        }

        // Authentication headers — family-specific.
        if ($family === ProviderType::Anthropic) {
            $headers['x-api-key'] = (string) $token;
            $headers['anthropic-version'] = $this->getAnthropicApiVersion();
        } else {
            // OpenAI / LlamaCpp — Authorization only when token is non-null and non-empty.
            if ($token !== null && $token !== '') {
                $headers['Authorization'] = 'Bearer ' . $token;
            }
        }

        return $headers;
    }

    /**
     * Check if a provider family supports a given operation.
     */
    public function supports(Server $server, Operation $operation): bool
    {
        return $this->supportsValues($server->provider_type, $operation);
    }

    /**
     * Check if a provider family supports a given operation (model-independent).
     */
    public function supportsValues(ProviderType $family, Operation $operation): bool
    {
        $familyKey = $family->value;
        $opKey = $operation->value;
        $suffix = self::PATH_TABLE[$familyKey][$opKey] ?? null;
        return $suffix !== null;
    }

    /**
     * Get the Anthropic API version from config.
     */
    private function getAnthropicApiVersion(): string
    {
        try {
            return (string) config('llm-client.providers.anthropic.api_version', '2023-06-01');
        } catch (\Throwable) {
            return '2023-06-01';
        }
    }
}
