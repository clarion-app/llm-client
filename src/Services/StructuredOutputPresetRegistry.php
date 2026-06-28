<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Exceptions\PresetNotFoundException;

class StructuredOutputPresetRegistry
{
    /** @var array<string, StructuredOutputPreset> */
    private array $presets = [];

    private SchemaMerger $schemaMerger;

    public function __construct(?SchemaMerger $schemaMerger = null)
    {
        $this->schemaMerger = $schemaMerger ?? new SchemaMerger();
    }

    /**
     * Register a preset in the registry.
     */
    public function register(StructuredOutputPreset $preset): void
    {
        $this->presets[$preset->getName()] = $preset;
    }

    /**
     * Find a preset by name.
     *
     * @throws PresetNotFoundException When the preset is not found
     */
    public function find(string $name): StructuredOutputPreset
    {
        if (!isset($this->presets[$name])) {
            throw new PresetNotFoundException($name, array_keys($this->presets));
        }

        return $this->presets[$name];
    }

    /**
     * Check if a preset exists in the registry.
     */
    public function has(string $name): bool
    {
        return isset($this->presets[$name]);
    }

    /**
     * List all registered presets with metadata.
     *
     * @return array<string, array{name: string, description: string, schema: array}>
     */
    public function list(): array
    {
        $result = [];
        foreach ($this->presets as $name => $preset) {
            $result[$name] = [
                'name' => $preset->getName(),
                'description' => $preset->getDescription(),
                'schema' => $preset->getSchema(),
            ];
        }

        return $result;
    }

    /**
     * Resolve the final schema for a preset, applying params and overrides.
     *
     * @param string $name Preset name
     * @param array|null $params Parameters for parameterized presets
     * @param array|null $overrides Schema overrides to deep-merge
     * @return array Merged JSON Schema
     * @throws PresetNotFoundException When the preset is not found
     */
    public function resolveSchema(string $name, ?array $params = null, ?array $overrides = null): array
    {
        $preset = $this->find($name);
        $schema = $preset->getSchema($params);

        if (!empty($overrides)) {
            $schema = $this->schemaMerger->merge($schema, $overrides);
        }

        return $schema;
    }
}
