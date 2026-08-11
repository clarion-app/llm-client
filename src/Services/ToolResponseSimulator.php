<?php

namespace ClarionApp\LlmClient\Services;

/**
 * research.md D4: generates a plausible, schema-shaped synthetic tool
 * response for an eval-run case, so the agent can keep reasoning from a
 * believable result without any real network call ever being made.
 *
 * Pure function of its input — no network call, no queue dispatch, no
 * side effect of any kind.
 */
class ToolResponseSimulator
{
    /**
     * @param  array  $inputSchema  the MCP-shaped inputSchema (McpToolRegistry::buildInputSchema()'s output)
     * @param  array  $submittedArguments  the caller's own submitted body/query values, keyed by
     *   property name, used to echo back a plausible value for a string property when available
     * @return array a plain, JSON-encodable PHP array, always including 'success' => true at the top level
     */
    public function simulate(array $inputSchema, array $submittedArguments = []): array
    {
        // McpToolRegistry::buildInputSchema() emits `properties` as a
        // stdClass (not an array) whenever a real operation declares no
        // parameters/request body, so it JSON-encodes to `{}` for an MCP
        // client — array-indexing into that here would throw, so treat
        // anything that isn't itself an array as "nothing declared".
        $topLevelProperties = $inputSchema['properties'] ?? null;
        $body = is_array($topLevelProperties) ? ($topLevelProperties['body'] ?? null) : null;
        $query = is_array($topLevelProperties) ? ($topLevelProperties['query'] ?? null) : null;

        $properties = (is_array($body) ? ($body['properties'] ?? null) : null)
            ?? (is_array($query) ? ($query['properties'] ?? null) : null);

        if (!is_array($properties) || empty($properties)) {
            return ['success' => true];
        }

        $result = ['success' => true];

        foreach ($properties as $name => $propertySchema) {
            $result[$name] = $this->placeholderFor($name, (array) $propertySchema, $submittedArguments);
        }

        return $result;
    }

    private function placeholderFor(string $name, array $propertySchema, array $submittedArguments): mixed
    {
        $type = $propertySchema['type'] ?? null;

        return match ($type) {
            'string' => $submittedArguments[$name] ?? "simulated-{$name}",
            'integer' => 1,
            'number' => 1.0,
            'boolean' => true,
            'array' => [],
            default => new \stdClass(),
        };
    }
}
