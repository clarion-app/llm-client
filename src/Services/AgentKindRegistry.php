<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Exceptions\AgentKindNotFoundException;
use ClarionApp\LlmClient\ValueObjects\AgentKind;

/**
 * Registry of ready-made AgentKind starting shapes (089-agent-scaffolding-cli,
 * contracts §5, data-model.md §5), mirroring StructuredOutputPresetRegistry
 * field-for-field.
 */
class AgentKindRegistry
{
    /** @var array<string, AgentKind> */
    private array $kinds = [];

    /**
     * Register a kind in the registry.
     */
    public function register(AgentKind $kind): void
    {
        $this->kinds[$kind->getSlug()] = $kind;
    }

    /**
     * Find a kind by slug.
     *
     * @throws AgentKindNotFoundException When the kind is not found
     */
    public function find(string $slug): AgentKind
    {
        if (!isset($this->kinds[$slug])) {
            throw new AgentKindNotFoundException($slug, array_keys($this->kinds));
        }

        return $this->kinds[$slug];
    }

    /**
     * Check if a kind exists in the registry.
     */
    public function has(string $slug): bool
    {
        return isset($this->kinds[$slug]);
    }

    /**
     * List all registered kinds with metadata.
     *
     * @return array<string, array{slug: string, description: string}>
     */
    public function list(): array
    {
        $result = [];
        foreach ($this->kinds as $slug => $kind) {
            $result[$slug] = [
                'slug' => $kind->getSlug(),
                'description' => $kind->getDescription(),
            ];
        }

        return $result;
    }
}
