<?php

namespace ClarionApp\LlmClient\Services;

class StructuredOutputPreset
{
    private string $name;
    private string $description;
    private $schema;
    private string $systemPrompt;

    /**
     * @param string $name Unique preset identifier (e.g., "decision", "summary", "extraction")
     * @param string $description Human-readable description for discovery
     * @param array|callable $schema JSON Schema definition, or callable for parameterized presets
     * @param string $systemPrompt Recommended system prompt text for LLM guidance
     */
    public function __construct(
        string $name,
        string $description,
        $schema,
        string $systemPrompt
    ) {
        $this->name = $name;
        $this->description = $description;
        $this->schema = $schema;
        $this->systemPrompt = $systemPrompt;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * Get the schema, invoking callable if needed with optional params.
     *
     * @param array|null $params Optional parameters for parameterized presets
     * @return array JSON Schema definition
     */
    public function getSchema(?array $params = null): array
    {
        if (is_callable($this->schema)) {
            return call_user_func($this->schema, $params);
        }

        return $this->schema;
    }

    public function getSystemPrompt(): string
    {
        return $this->systemPrompt;
    }
}
