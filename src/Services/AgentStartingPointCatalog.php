<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Exceptions\AgentStartingPointNotFoundException;
use ClarionApp\LlmClient\ValueObjects\AgentStartingPoint;
use ClarionApp\LlmClient\ValueObjects\AgentStartingPointSummary;

/**
 * Registry + live-check orchestration for ready-made agent starting
 * points, mirroring AgentKindRegistry's register()/find()/has()/list()
 * shape. A separate class in a separate namespace slice from
 * AgentKindRegistry -- unlike a CLI-scaffolded AgentKind, a starting
 * point is allowed to reference installation-specific operations, so an
 * unmet one can be detected and reported rather than being structurally
 * disallowed.
 */
final class AgentStartingPointCatalog
{
    /** @var array<string, AgentStartingPoint> */
    private array $startingPoints = [];

    public function __construct(
        private readonly AgentDefinitionValidator $validator,
    ) {
    }

    /**
     * Register a starting point in the catalog.
     */
    public function register(AgentStartingPoint $startingPoint): void
    {
        $this->startingPoints[$startingPoint->slug] = $startingPoint;
    }

    /**
     * Find a starting point by slug.
     *
     * @throws AgentStartingPointNotFoundException When the slug is not registered
     */
    public function find(string $slug): AgentStartingPoint
    {
        if (!isset($this->startingPoints[$slug])) {
            throw new AgentStartingPointNotFoundException($slug, array_keys($this->startingPoints));
        }

        return $this->startingPoints[$slug];
    }

    /**
     * Check if a starting point exists in the catalog.
     */
    public function has(string $slug): bool
    {
        return isset($this->startingPoints[$slug]);
    }

    /**
     * The raw YAML content of a registered starting point's template,
     * read fresh from disk on every call -- never cached across calls or
     * requests, so an edited template file is reflected immediately (the
     * same freshness every *AgentProvisioner already gives its own
     * ensureForUser() miss).
     *
     * @throws AgentStartingPointNotFoundException When the slug is not registered
     */
    public function rawYamlFor(string $slug): string
    {
        return (string) file_get_contents($this->find($slug)->templatePath);
    }

    /**
     * List every registered starting point, each with a live-checked
     * requirements-satisfied flag computed against current installation
     * state. Returned in registration order.
     *
     * @return list<AgentStartingPointSummary>
     */
    public function list(): array
    {
        $summaries = [];
        foreach ($this->startingPoints as $slug => $startingPoint) {
            $result = $this->validator->check($this->rawYamlFor($slug));

            $summaries[] = new AgentStartingPointSummary(
                slug: $startingPoint->slug,
                description: $startingPoint->description,
                requirementsSatisfied: $result->valid,
                problems: $result->problems,
            );
        }

        return $summaries;
    }
}
