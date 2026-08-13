<?php

namespace ClarionApp\LlmClient\Tests\Unit\ValueObjects;

use ClarionApp\LlmClient\ValueObjects\AgentKind;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for AgentKind (089-agent-scaffolding-cli, Phase 5/US2, T025,
 * contracts §6, data-model.md §1, research.md D11).
 *
 * AgentKind (and its structural tools/safety guard) was already built in
 * Phase 2 (T005/T011) — this file formalizes/locks in that already-existing
 * behavior rather than driving new production code, so most/all assertions
 * here are expected to PASS immediately. It is still written as its own
 * dedicated Unit test file per tasks.md's own instruction, rather than
 * folded into AgentDefinitionScaffolderTest.php (which only exercises
 * AgentKind incidentally, via reflection, for its own name-clobber case).
 */
class AgentKindTest extends TestCase
{
    // ---------------------------------------------------------------
    // 1. research()/coding() return their exact documented content
    //    (contracts §6, data-model.md §1).
    // ---------------------------------------------------------------

    #[Test]
    public function research_returns_its_exact_slug_description_and_overrides(): void
    {
        $kind = AgentKind::research();

        $this->assertSame('research', $kind->getSlug());
        $this->assertSame(
            'A starting point for an agent that gathers, synthesizes, and reports information rather than taking action.',
            $kind->getDescription()
        );

        $overrides = $kind->getOverrides();

        $this->assertSame(
            'You are a research agent. Gather information before acting: search broadly, read before concluding, and prefer citing where a fact came from over asserting it from memory. Synthesize what you find into a clear, well-organized answer rather than a raw dump of sources. When evidence is thin or conflicting, say so explicitly rather than guessing.',
            $overrides['instructions']
        );
        $this->assertSame(
            ['memory_read', 'memory_search', 'memory_create', 'propose_declarative_memory'],
            $overrides['capabilities']
        );
        $this->assertSame(
            [
                'scratch' => 'enabled',
                'short_term' => 'enabled',
                'long_term' => 'enabled',
                'episodic' => 'enabled',
                'declarative' => 'enabled',
            ],
            $overrides['memory']
        );
    }

    #[Test]
    public function coding_returns_its_exact_slug_description_and_overrides(): void
    {
        $kind = AgentKind::coding();

        $this->assertSame('coding', $kind->getSlug());
        $this->assertSame(
            'A starting point for an agent that reads, writes, and modifies code or configuration on the user\'s behalf.',
            $kind->getDescription()
        );

        $overrides = $kind->getOverrides();

        $this->assertSame(
            'You are a coding agent. Make the smallest change that satisfies the request rather than a larger rewrite. Explain what you changed and why before considering the task done. Confirm with the user before taking any destructive or hard-to-reverse action.',
            $overrides['instructions']
        );
        $this->assertSame(
            ['memory_read', 'memory_search'],
            $overrides['capabilities']
        );
        $this->assertSame(
            [
                'scratch' => 'enabled',
                'short_term' => 'enabled',
                'long_term' => 'enabled',
                'episodic' => 'enabled',
                'declarative' => 'enabled',
            ],
            $overrides['memory']
        );
    }

    // ---------------------------------------------------------------
    // 2. Belt-and-suspenders: every built-in kind's overrides omit
    //    tools/safety/name (research.md D11).
    // ---------------------------------------------------------------

    #[Test]
    public function every_built_in_kinds_overrides_omit_tools_and_safety_and_name(): void
    {
        foreach ([AgentKind::research(), AgentKind::coding()] as $kind) {
            $overrides = $kind->getOverrides();

            $this->assertFalse(
                array_key_exists('tools', $overrides),
                sprintf('Kind "%s" must not override "tools".', $kind->getSlug())
            );
            $this->assertFalse(
                array_key_exists('safety', $overrides),
                sprintf('Kind "%s" must not override "safety".', $kind->getSlug())
            );
            $this->assertFalse(
                array_key_exists('name', $overrides),
                sprintf('Kind "%s" must not override "name" — name is always merged in last by AgentDefinitionScaffolder::generate().', $kind->getSlug())
            );
        }
    }

    // ---------------------------------------------------------------
    // 3. Structural guarantee: the private constructor itself refuses a
    //    tools/safety override for ANY kind, not only the two enumerated
    //    above (research.md D11, mutation-checklist row 6).
    // ---------------------------------------------------------------

    #[Test]
    public function the_constructor_throws_for_a_malformed_tools_override(): void
    {
        $reflection = new \ReflectionClass(AgentKind::class);
        $constructor = $reflection->getConstructor();
        $constructor->setAccessible(true);

        $malformedKind = $reflection->newInstanceWithoutConstructor();

        $this->expectException(\InvalidArgumentException::class);

        $constructor->invoke($malformedKind, 'malformed-tools', 'A malformed kind used only to prove the tools guard.', [
            'tools' => ['GET'],
        ]);
    }

    #[Test]
    public function the_constructor_throws_for_a_malformed_safety_override(): void
    {
        $reflection = new \ReflectionClass(AgentKind::class);
        $constructor = $reflection->getConstructor();
        $constructor->setAccessible(true);

        $malformedKind = $reflection->newInstanceWithoutConstructor();

        $this->expectException(\InvalidArgumentException::class);

        $constructor->invoke($malformedKind, 'malformed-safety', 'A malformed kind used only to prove the safety guard.', [
            'safety' => ['denylist' => ['DELETE']],
        ]);
    }
}
