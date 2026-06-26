<?php

namespace ClarionApp\LlmClient\Providers;

use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Contracts\ProviderType;
use ClarionApp\LlmClient\Models\Server;
use RuntimeException;

/**
 * Registry that maps provider types to factory callables.
 *
 * Factories receive a {@see Server} model and return an {@see LlmProvider} instance.
 * The registry supports a default fallback for legacy Server records
 * that do not have an explicit provider_type set.
 */
class ProviderRegistry
{
    /**
     * Map of provider type values to factory callables.
     *
     * @var array<string, callable(Server): LlmProvider>
     */
    protected array $factories = [];

    /**
     * Default factory for legacy records with no provider_type.
     *
     * @var callable|null
     */
    protected $defaultFactory = null;

    /**
     * Register a factory callable for a provider type.
     *
     * @param string|ProviderType $type Provider type enum or string value
     * @param callable(Server): LlmProvider $factory Factory that produces provider instances
     */
    public function register(string|ProviderType $type, callable $factory): void
    {
        $key = $type instanceof ProviderType ? $type->value : $type;
        $this->factories[$key] = $factory;
    }

    /**
     * Set the default factory for legacy Server records.
     *
     * @param callable(Server): LlmProvider $factory
     */
    public function default(callable $factory): void
    {
        $this->defaultFactory = $factory;
    }

    /**
     * Resolve a provider instance for a Server model.
     *
     * Uses the Server's provider_type attribute to look up the registered factory.
     * Falls back to the default factory if no provider_type is set or no factory
     * is registered for the given type.
     *
     * @param Server $server Server model with provider_type and connection details
     * @return LlmProvider Provider instance for the given server
     * @throws RuntimeException If no provider is registered for the server's type
     */
    public function resolve(Server $server): LlmProvider
    {
        $type = $server->provider_type;

        // Try explicit factory first
        if (isset($this->factories[$type->value])) {
            return call_user_func($this->factories[$type->value], $server);
        }

        // Fall back to default factory
        if ($this->defaultFactory !== null) {
            return call_user_func($this->defaultFactory, $server);
        }

        throw new RuntimeException(
            "No provider registered for type '{$type->value}'. " .
            'Available types: ' . implode(', ', array_keys($this->factories))
        );
    }

    /**
     * Resolve a provider instance for an explicit provider type and Server.
     *
     * Unlike {@see resolve()}, this method accepts an explicit ProviderType
     * (e.g., from a conversation override) rather than deriving it from the Server.
     * The Server is still passed to the factory for connection details.
     *
     * @param ProviderType $type Explicit provider type to resolve
     * @param Server $server Server model with connection details
     * @return LlmProvider Provider instance for the given type and server
     * @throws RuntimeException If no provider is registered for the given type
     */
    public function resolveByType(ProviderType $type, Server $server): LlmProvider
    {
        $key = $type->value;

        if (isset($this->factories[$key])) {
            return call_user_func($this->factories[$key], $server);
        }

        throw new RuntimeException(
            "No provider registered for type '{$key}'. " .
            'Available types: ' . implode(', ', array_keys($this->factories))
        );
    }

    /**
     * Get the list of registered provider types.
     *
     * @return list<string> Registered provider type values
     */
    public function getRegisteredTypes(): array
    {
        return array_keys($this->factories);
    }
}
