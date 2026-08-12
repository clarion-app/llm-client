<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * A named pattern that resolves, at evaluation time, to a set of individual
 * operations belonging to the installation's currently available
 * operations (spec's own "Operation Group" Key Entity, data-model.md §2,
 * research.md D8).
 *
 * The single "what does this pattern match" implementation shared by the
 * parse-time emptiness check (AgentDefinitionParser) and AgentDefinition's
 * own isOperationPermitted()/isConfirmationRequired() at call time — never
 * two implementations of the same question.
 */
final readonly class OperationGroupPattern
{
    private const HTTP_VERBS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    public string $raw;

    public OperationGroupPatternKind $kind;

    public function __construct(string $raw)
    {
        $this->raw = $raw;
        $this->kind = in_array($raw, self::HTTP_VERBS, true)
            ? OperationGroupPatternKind::HttpVerb
            : OperationGroupPatternKind::Glob;
    }

    public function matches(string $operationId, string $method): bool
    {
        return match ($this->kind) {
            OperationGroupPatternKind::Glob => fnmatch($this->raw, $operationId),
            OperationGroupPatternKind::HttpVerb => strtoupper($method) === $this->raw,
        };
    }

    /**
     * Given a list of raw pattern strings and the live
     * [{operationId, method}, ...] catalog, returns the set of matching
     * operationIds — the union across every pattern, no duplicates.
     *
     * @param list<string> $patterns
     * @param list<array{operationId: string, method: string}> $catalog
     * @return list<string>
     */
    public static function resolve(array $patterns, array $catalog): array
    {
        if (empty($patterns) || empty($catalog)) {
            return [];
        }

        $operationGroupPatterns = array_map(
            static fn (string $raw): self => new self($raw),
            $patterns
        );

        $matched = [];

        foreach ($catalog as $entry) {
            foreach ($operationGroupPatterns as $pattern) {
                if ($pattern->matches($entry['operationId'], $entry['method'])) {
                    $matched[$entry['operationId']] = true;
                    break;
                }
            }
        }

        return array_keys($matched);
    }
}
