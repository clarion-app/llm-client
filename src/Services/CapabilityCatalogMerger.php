<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\CapabilityOffering;
use ClarionApp\LlmClient\Models\Conversation;

/**
 * Merges a caller's eligible CapabilityOffering rows into the same catalog
 * shape a real operation entry carries (109-agent-as-capability, Phase 3/
 * US1, data-model.md §5, contracts/capability-agent-call.md).
 *
 * entriesFor() is the ONE public entry point (data-model.md §5's own exact
 * signature). formatOffering() is a small, shared static recipe so
 * AgentLoopService::handleSearchOperations()'s own query-filtered offering
 * results (which query CapabilityOffering directly, since entriesFor()
 * takes no query parameter) can never independently drift out of agreement
 * with buildKnownOperationsSection()'s own unfiltered entries about what
 * the entry shape looks like -- mirroring this feature's own Foundational-
 * phase precedent of sharing one adjacency-building helper between
 * wouldCreateCycle()/wouldOfferingCreateCycle() for the identical reason.
 */
class CapabilityCatalogMerger
{
    public function __construct(
        private readonly CapabilityOfferingQuery $capabilityOfferingQuery,
    ) {}

    /**
     * Every currently-active offering eligible to the conversation's own
     * bound (caller) agent, formatted into the same
     * {operationId, summary, method, path, paramSchema} shape a real
     * OperationCache entry carries. Returns [] when the conversation has
     * no bound agent, or that agent has no eligible offerings.
     *
     * @return array<int, array{operationId: string, summary: string, method: string, path: null, paramSchema: array}>
     */
    public function entriesFor(Conversation $conversation): array
    {
        if ($conversation->agent_id === null) {
            return [];
        }

        $offerings = $this->capabilityOfferingQuery->eligibleFor($conversation->agent_id);

        return $offerings
            ->map(fn (CapabilityOffering $offering) => self::formatOffering($offering))
            ->values()
            ->all();
    }

    /**
     * The one shared formatting recipe for a CapabilityOffering catalog
     * entry. `operationId` is the offering's own id (no separate mapping
     * table); `summary` is the configurer-supplied capability_description,
     * never the offered agent's own raw instructions (research.md D2);
     * `method` is the fixed AGENT sentinel, never matched by
     * ApiCallValidator's HTTP-method-specific checks (research.md D1);
     * `path` is always null (never read on this dispatch path);
     * `paramSchema` is the synthesized one-field object accepting a single
     * free-text `input` string (research.md D2).
     *
     * @return array{operationId: string, summary: string, method: string, path: null, paramSchema: array}
     */
    public static function formatOffering(CapabilityOffering $offering): array
    {
        return [
            'operationId' => $offering->id,
            'summary' => $offering->capability_description,
            'method' => 'AGENT',
            'path' => null,
            'paramSchema' => [
                'type' => 'object',
                'properties' => [
                    'input' => [
                        'type' => 'string',
                        'description' => $offering->input_description,
                    ],
                ],
                'required' => ['input'],
            ],
        ];
    }
}
