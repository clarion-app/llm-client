<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\LlmClient\Services\AgentDefinitionParser;
use ClarionApp\LlmClient\ValueObjects\MemoryKind;
use ClarionApp\LlmClient\ValueObjects\ReducibleTool;
use Dedoc\Scramble\Generator;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AgentDefinitionParser::parse() default resolution for a minimal
 * definition (086-agent-yaml-schema, spec.md US2 Acceptance Scenarios 1-2,
 * quickstart.md steps 1, 5, 11, mutation-checklist row 7).
 *
 * Phase 5's own Scope note: every default exercised here was already
 * implemented in Phase 3 as an unavoidable part of building parse() at all
 * (there is no way to implement "capture every stated value" without
 * simultaneously deciding what every un-stated value resolves to). A fully
 * green result against the existing Phase 3/4 code is the expected,
 * correct outcome for this file — not a sign of a missing test.
 */
class AgentDefinitionMinimalJourneyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Isolate every assertion in this file to parse()'s own default
        // resolution — the installation-ceiling union (Phase 4/US3) plays
        // no part in what a minimal definition's fields resolve to.
        $this->app['config']->set('llm-client.confirm_methods', []);
    }

    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @return array<string, bool>
     */
    private function allMemoryKindsEnabled(): array
    {
        return array_fill_keys(
            array_map(static fn (MemoryKind $kind): string => $kind->value, MemoryKind::cases()),
            true,
        );
    }

    private function assertResolvesToDocumentedDefaults(string $rawYaml, string $expectedName): void
    {
        $definition = (new AgentDefinitionParser())->parse($rawYaml);

        $this->assertSame($expectedName, $definition->name);
        $this->assertSame('1.0', $definition->formatVersion);
        $this->assertNull($definition->version);
        $this->assertSame('', $definition->instructions);
        $this->assertNull($definition->model);
        $this->assertSame($this->allMemoryKindsEnabled(), $definition->memory);
        $this->assertSame(ReducibleTool::cases(), $definition->capabilities);
        $this->assertSame(['*'], $definition->toolsAllow);
        $this->assertSame([], $definition->toolsDeny);
        $this->assertSame([], $definition->safetyConfirmationRequired);
        $this->assertSame([], $definition->safetyDenylist);
    }

    #[Test]
    public function a_bare_definition_naming_only_a_name_resolves_every_other_setting_to_its_documented_default(): void
    {
        $this->seedOperationCatalog([
            'contacts.store' => ['path' => '/api/contacts', 'method' => 'post', 'summary' => 'Store a contact'],
        ]);

        $this->assertResolvesToDocumentedDefaults('name: my-agent', 'my-agent');
    }

    #[Test]
    public function a_second_differently_named_bare_definition_also_resolves_every_setting_to_its_documented_default(): void
    {
        $this->seedOperationCatalog([
            'contacts.store' => ['path' => '/api/contacts', 'method' => 'post', 'summary' => 'Store a contact'],
        ]);

        $this->assertResolvesToDocumentedDefaults('name: bare', 'bare');
    }

    #[Test]
    public function two_independently_authored_minimal_definitions_resolve_every_defaulted_field_identically(): void
    {
        $this->seedOperationCatalog([
            'contacts.store' => ['path' => '/api/contacts', 'method' => 'post', 'summary' => 'Store a contact'],
        ]);

        $parser = new AgentDefinitionParser();
        $first = $parser->parse('name: author-one-agent');
        $second = $parser->parse('name: author-two-agent');

        $this->assertNotSame($first->name, $second->name);

        $this->assertSame($first->formatVersion, $second->formatVersion);
        $this->assertSame($first->version, $second->version);
        $this->assertSame($first->instructions, $second->instructions);
        $this->assertSame($first->model, $second->model);
        $this->assertSame($first->memory, $second->memory);
        $this->assertSame($first->capabilities, $second->capabilities);
        $this->assertSame($first->toolsAllow, $second->toolsAllow);
        $this->assertSame($first->toolsDeny, $second->toolsDeny);
        $this->assertSame($first->safetyConfirmationRequired, $second->safetyConfirmationRequired);
        $this->assertSame($first->safetyDenylist, $second->safetyDenylist);
    }

    #[Test]
    public function an_explicitly_empty_capabilities_list_is_honored_as_zero_capabilities_distinct_from_omission(): void
    {
        $this->seedOperationCatalog([
            'contacts.store' => ['path' => '/api/contacts', 'method' => 'post', 'summary' => 'Store a contact'],
        ]);

        $raw = <<<YAML
name: no-capabilities-agent
capabilities: []
YAML;

        $definition = (new AgentDefinitionParser())->parse($raw);

        // Explicit-empty grants none of the five, unlike the omitted-key
        // case above which grants all five (research.md D7).
        $this->assertSame([], $definition->capabilities);
    }

    #[Test]
    public function the_synthesized_default_tools_allow_is_exempt_from_the_catalog_emptiness_check(): void
    {
        // A live catalog that resolves to zero operations at all — the
        // regression guard for the analysis-pass correction: an omitted
        // tools/tools.allow key must never fail parsing based on the
        // installation's live catalog state (research.md D8/D3/SC-002).
        $this->seedOperationCatalog([]);

        $definition = (new AgentDefinitionParser())->parse('name: my-agent');

        $this->assertSame(['*'], $definition->toolsAllow);
    }

    /**
     * Seeds both of ApiManager's live-catalog seams with the identical
     * OpenAPI-shaped document: the static $apiDocsCache property
     * getOperationDetails() reads directly (reflection, matching
     * AgentDefinitionFullJourneyTest's/AgentDefinitionSafetyCeilingJourneyTest's
     * own established convention), and a fake Dedoc\Scramble\Generator
     * bound into the container for getOperations() — which never reads
     * that cache, it always resolves a fresh DocumentationService.
     *
     * @param array<string, array{path: string, method: string, summary: string}> $operations
     */
    private function seedOperationCatalog(array $operations): void
    {
        $paths = [];
        foreach ($operations as $operationId => $entry) {
            $paths[$entry['path']][$entry['method']] = [
                'operationId' => $operationId,
                'summary' => $entry['summary'],
            ];
        }
        $doc = ['paths' => $paths];

        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, $doc);

        $generator = Mockery::mock(Generator::class);
        $generator->shouldReceive('__invoke')->andReturn($doc);
        $this->app->instance(Generator::class, $generator);
    }

    private function clearOperationCatalog(): void
    {
        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }
}
