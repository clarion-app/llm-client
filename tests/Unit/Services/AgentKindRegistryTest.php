<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Exceptions\AgentKindNotFoundException;
use ClarionApp\LlmClient\Services\AgentKindRegistry;
use ClarionApp\LlmClient\ValueObjects\AgentKind;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for AgentKindRegistry (089-agent-scaffolding-cli, Phase 5/US2,
 * T026, contracts §5, data-model.md §5), mirroring
 * StructuredOutputPresetRegistryTest.php's own structure.
 *
 * Written before AgentKindRegistry exists — every test in this file is
 * expected to fail with a "class not found"-style error until Phase 5's
 * own Implementation tasks (T031) create it. That is the intended RED
 * state, not a mistake.
 */
class AgentKindRegistryTest extends TestCase
{
    private AgentKindRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new AgentKindRegistry();
    }

    #[Test]
    public function register_adds_kind_to_registry(): void
    {
        $this->registry->register(AgentKind::research());

        $this->assertTrue($this->registry->has('research'));
    }

    #[Test]
    public function has_returns_false_for_unregistered_slug(): void
    {
        $this->assertFalse($this->registry->has('devops'));
    }

    #[Test]
    public function find_returns_kind_by_slug(): void
    {
        $this->registry->register(AgentKind::research());

        $found = $this->registry->find('research');

        $this->assertSame('research', $found->getSlug());
        $this->assertSame(AgentKind::research()->getDescription(), $found->getDescription());
    }

    #[Test]
    public function find_throws_for_unregistered_slug_naming_it_and_listing_available(): void
    {
        $this->registry->register(AgentKind::research());
        $this->registry->register(AgentKind::coding());

        try {
            $this->registry->find('devops');
            $this->fail('Expected AgentKindNotFoundException');
        } catch (AgentKindNotFoundException $e) {
            $this->assertSame('devops', $e->getSlug());
            $this->assertContains('research', $e->getAvailableSlugs());
            $this->assertContains('coding', $e->getAvailableSlugs());
            $this->assertStringContainsString('devops', $e->getMessage());
            $this->assertStringContainsString('research', $e->getMessage());
            $this->assertStringContainsString('coding', $e->getMessage());
        }
    }

    #[Test]
    public function list_returns_slug_and_description_for_every_registered_kind(): void
    {
        $this->registry->register(AgentKind::research());
        $this->registry->register(AgentKind::coding());

        $list = $this->registry->list();

        $this->assertCount(2, $list);
        $this->assertArrayHasKey('research', $list);
        $this->assertArrayHasKey('coding', $list);

        $this->assertSame('research', $list['research']['slug']);
        $this->assertSame(AgentKind::research()->getDescription(), $list['research']['description']);

        $this->assertSame('coding', $list['coding']['slug']);
        $this->assertSame(AgentKind::coding()->getDescription(), $list['coding']['description']);
    }

    #[Test]
    public function list_returns_empty_array_when_nothing_registered(): void
    {
        $this->assertSame([], $this->registry->list());
    }
}
