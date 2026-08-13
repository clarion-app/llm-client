<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Exceptions\InvalidAgentVersionComparisonException;
use ClarionApp\LlmClient\Models\AgentVersion;
use ClarionApp\LlmClient\ValueObjects\AgentDefinition;
use ClarionApp\LlmClient\ValueObjects\AgentVersionComparison;
use ClarionApp\LlmClient\ValueObjects\AgentVersionFieldDifference;
use ClarionApp\LlmClient\ValueObjects\AgentVersionListDifference;
use ClarionApp\LlmClient\ValueObjects\InvalidAgentVersionComparisonKind;
use ClarionApp\LlmClient\ValueObjects\MemoryKind;
use ClarionApp\LlmClient\ValueObjects\ReducibleTool;

/**
 * Produces a structured field/list diff between two agent versions
 * (090-agent-version-binding, Phase 5/US3, contracts §4, research.md
 * D6-D8). Resolves both sides via its own ownership-scoped lookup — never
 * assumes the caller already validated ownership, though
 * AgentVersionComparisonController's own call path always does its own
 * lookup first, making the defensive \RuntimeException below unreachable
 * in practice (Grounding note 16).
 *
 * Same-id and cross-agent comparisons are refused before either side is
 * ever parsed. Both sides are then parsed via the real, unmodified
 * AgentDefinitionParser::parse() — an unresolvable side's exception
 * propagates uncaught (research.md D8): comparison is not best-effort,
 * unlike a plain version read or a turn-time resolution.
 */
class AgentVersionComparer
{
    /** Scalar fields compared with !== on the parsed AgentDefinition. */
    private const SCALAR_FIELDS = ['formatVersion', 'name', 'version', 'instructions', 'model'];

    /** Set-shaped fields compared as sets (order carries no meaning). */
    private const LIST_FIELDS = ['capabilities', 'toolsAllow', 'toolsDeny', 'safetyConfirmationRequired', 'safetyDenylist'];

    public function __construct(
        private readonly AgentDefinitionParser $parser,
    ) {}

    public function compare(string $callerUserId, string $leftVersionId, string $rightVersionId): AgentVersionComparison
    {
        $left = $this->findOwnedVersion($callerUserId, $leftVersionId);
        $right = $this->findOwnedVersion($callerUserId, $rightVersionId);

        if ($left === null || $right === null) {
            throw new \RuntimeException('Agent version not found.');
        }

        if ($left->id === $right->id) {
            throw new InvalidAgentVersionComparisonException(InvalidAgentVersionComparisonKind::SameVersion, $leftVersionId, $rightVersionId);
        }

        if ($left->agent_id !== $right->agent_id) {
            throw new InvalidAgentVersionComparisonException(InvalidAgentVersionComparisonKind::DifferentAgents, $leftVersionId, $rightVersionId);
        }

        $leftDefinition = $this->parser->parse($left->raw_definition);
        $rightDefinition = $this->parser->parse($right->raw_definition);

        $fieldDifferences = $this->diffFields($leftDefinition, $rightDefinition);
        $listDifferences = $this->diffLists($leftDefinition, $rightDefinition);

        return new AgentVersionComparison(
            leftVersionId: $left->id,
            rightVersionId: $right->id,
            identical: $fieldDifferences === [] && $listDifferences === [],
            fieldDifferences: $fieldDifferences,
            listDifferences: $listDifferences,
        );
    }

    private function findOwnedVersion(string $callerUserId, string $versionId): ?AgentVersion
    {
        return AgentVersion::where('id', $versionId)
            ->whereHas('agent', fn ($q) => $q->where('user_id', $callerUserId))
            ->first();
    }

    /**
     * @return list<AgentVersionFieldDifference>
     */
    private function diffFields(AgentDefinition $left, AgentDefinition $right): array
    {
        $differences = [];

        foreach (self::SCALAR_FIELDS as $field) {
            if ($left->$field !== $right->$field) {
                $differences[] = new AgentVersionFieldDifference($field, $left->$field, $right->$field);
            }
        }

        foreach (MemoryKind::cases() as $kind) {
            $leftEnabled = $left->memoryEnabled($kind);
            $rightEnabled = $right->memoryEnabled($kind);

            if ($leftEnabled !== $rightEnabled) {
                $differences[] = new AgentVersionFieldDifference('memory.' . $kind->value, $leftEnabled, $rightEnabled);
            }
        }

        return $differences;
    }

    /**
     * @return list<AgentVersionListDifference>
     */
    private function diffLists(AgentDefinition $left, AgentDefinition $right): array
    {
        $differences = [];

        foreach (self::LIST_FIELDS as $field) {
            $leftSet = $this->listValues($left->$field);
            $rightSet = $this->listValues($right->$field);

            $added = array_values(array_diff($rightSet, $leftSet));
            $removed = array_values(array_diff($leftSet, $rightSet));

            if ($added !== [] || $removed !== []) {
                $differences[] = new AgentVersionListDifference($field, $added, $removed);
            }
        }

        return $differences;
    }

    /**
     * @param list<ReducibleTool>|list<string> $values
     * @return list<string>
     */
    private function listValues(array $values): array
    {
        return array_map(
            static fn ($value): string => $value instanceof ReducibleTool ? $value->value : (string) $value,
            $values,
        );
    }
}
