<?php

namespace ClarionApp\LlmClient\Exceptions;

use ClarionApp\LlmClient\ValueObjects\AgentDefinitionParseErrorKind;

/**
 * A structural problem in an agent definition document — malformed YAML,
 * an unsupported format_version, a missing name, an unrecognized (or
 * wrongly-shaped) key anywhere in the document, or instructions exceeding
 * the configured token bound (data-model.md §5, contracts §3).
 *
 * Extends \RuntimeException deliberately, matching
 * RoleAssignmentFailedException's own precedent (research.md D11).
 */
final class AgentDefinitionParseException extends \RuntimeException
{
    public function __construct(
        public readonly AgentDefinitionParseErrorKind $kind,
        public readonly ?string $key = null,
        public readonly mixed $value = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($this->composeMessage($kind, $key, $value), 0, $previous);
    }

    private function composeMessage(AgentDefinitionParseErrorKind $kind, ?string $key, mixed $value): string
    {
        return match ($kind) {
            AgentDefinitionParseErrorKind::MalformedYaml => sprintf(
                'The definition is not valid YAML: %s',
                is_string($value) ? $value : ''
            ),
            AgentDefinitionParseErrorKind::UnrecognizedFormatVersion => sprintf(
                'format_version "%s" is not supported. Supported versions: %s.',
                (string) $value,
                implode(', ', config('llm-client.agent_definitions.supported_format_versions', []))
            ),
            AgentDefinitionParseErrorKind::MissingName => 'A definition must state a non-empty "name".',
            AgentDefinitionParseErrorKind::UnknownKey => sprintf(
                'Unrecognized setting "%s"%s.',
                (string) $key,
                ($hint = $this->didYouMeanHint($key)) !== null ? sprintf(' (did you mean "%s"?)', $hint) : ''
            ),
            AgentDefinitionParseErrorKind::InstructionsTooLong => sprintf(
                'instructions is too long (%s estimated tokens; the limit is %s).',
                is_array($value) ? ($value['estimated'] ?? '?') : (string) $value,
                is_array($value) ? ($value['limit'] ?? '?') : ''
            ),
        };
    }

    /**
     * A nice-to-have "did you mean" suggestion against this format's own
     * known top-level/nested key names — absent when no close match
     * exists. Not load-bearing (contracts §3).
     */
    private function didYouMeanHint(?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }

        // Only the leaf segment of a dotted path is compared (e.g.
        // "memory.long_term" -> "long_term").
        $segments = explode('.', $key);
        $leaf = end($segments);

        $knownKeys = [
            'format_version', 'name', 'version', 'instructions', 'model',
            'memory', 'capabilities', 'tools', 'allow', 'deny', 'safety',
            'confirmation_required', 'denylist',
            'scratch', 'short_term', 'long_term', 'episodic', 'declarative',
        ];

        $closest = null;
        $shortestDistance = null;

        foreach ($knownKeys as $known) {
            $distance = levenshtein($leaf, $known);
            if ($distance <= 2 && ($shortestDistance === null || $distance < $shortestDistance)) {
                $closest = $known;
                $shortestDistance = $distance;
            }
        }

        return $closest;
    }
}
