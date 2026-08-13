<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * One ready-made starting shape (spec's own "Ready-Made Agent Kind" Key
 * Entity, data-model.md §1). Immutable — constructed only via its own
 * static named constructors (research()/coding()); a kind's content is
 * code, not caller-supplied data (research.md D2), so no public
 * general-purpose constructor is exposed.
 *
 * `overrides` may only ever set `instructions`/`capabilities`/`memory`
 * keys — never `tools`/`safety` (research.md D11 — installation-catalog-
 * dependent patterns must never live in a built-in kind), and never a
 * `name` key (name is always merged in last by
 * AgentDefinitionScaffolder::generate()).
 */
final readonly class AgentKind
{
    private function __construct(
        public string $slug,
        public string $description,
        public array $overrides,
    ) {
        // research.md D11 — structural guarantee, not a convention a future
        // kind could silently violate: no AgentKind can ever be built with
        // a tools/safety override, checked here rather than relying solely
        // on a unit test remembering to enumerate every kind.
        if (array_key_exists('tools', $overrides) || array_key_exists('safety', $overrides)) {
            throw new \InvalidArgumentException(
                "Agent kind \"{$slug}\" may not override \"tools\" or \"safety\" — those resolve against the live, per-installation operation catalog (research.md D11)."
            );
        }
    }

    public static function research(): self
    {
        return new self(
            slug: 'research',
            description: 'A starting point for an agent that gathers, synthesizes, and reports information rather than taking action.',
            overrides: [
                'instructions' => 'You are a research agent. Gather information before acting: search broadly, read before concluding, and prefer citing where a fact came from over asserting it from memory. Synthesize what you find into a clear, well-organized answer rather than a raw dump of sources. When evidence is thin or conflicting, say so explicitly rather than guessing.',
                'capabilities' => ['memory_read', 'memory_search', 'memory_create', 'propose_declarative_memory'],
                'memory' => [
                    'scratch' => 'enabled',
                    'short_term' => 'enabled',
                    'long_term' => 'enabled',
                    'episodic' => 'enabled',
                    'declarative' => 'enabled',
                ],
            ],
        );
    }

    public static function coding(): self
    {
        return new self(
            slug: 'coding',
            description: 'A starting point for an agent that reads, writes, and modifies code or configuration on the user\'s behalf.',
            overrides: [
                'instructions' => 'You are a coding agent. Make the smallest change that satisfies the request rather than a larger rewrite. Explain what you changed and why before considering the task done. Confirm with the user before taking any destructive or hard-to-reverse action.',
                'capabilities' => ['memory_read', 'memory_search'],
                'memory' => [
                    'scratch' => 'enabled',
                    'short_term' => 'enabled',
                    'long_term' => 'enabled',
                    'episodic' => 'enabled',
                    'declarative' => 'enabled',
                ],
            ],
        );
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getOverrides(): array
    {
        return $this->overrides;
    }
}
