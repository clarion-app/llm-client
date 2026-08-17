<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * Registration-time data for one ready-made agent starting point.
 * Immutable, constructed only by AgentStartingPointCatalog::register()
 * (never caller-supplied), so its fields are always
 * installation-authored, never request data.
 */
final readonly class AgentStartingPoint
{
    public function __construct(
        public string $slug,
        public string $description,
        public string $templatePath,
    ) {
    }
}
