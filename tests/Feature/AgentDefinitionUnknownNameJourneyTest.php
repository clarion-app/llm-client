<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\LlmClient\Exceptions\AgentDefinitionResolutionException;
use ClarionApp\LlmClient\Models\LanguageModel;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\AgentDefinitionParser;
use ClarionApp\LlmClient\ValueObjects\AgentDefinitionResolutionErrorKind;
use Dedoc\Scramble\Generator;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AgentDefinitionParser::parse()'s resolution-error paths (086-agent-yaml-schema,
 * spec.md US4 Acceptance Scenarios 1-3, quickstart.md step 6,
 * mutation-checklist row 4, contracts §3/§4) — naming an unrecognized
 * capability, an unavailable model, or an operation-group pattern that
 * resolves to zero operations each produces its own specifically-kinded
 * AgentDefinitionResolutionException naming the offending value, and fixing
 * only that one item makes the same document parse successfully.
 *
 * Scope note (Phase 6's own Scope note, tasks.md): these resolution-error
 * paths were built once, in Phase 3, as an unavoidable part of parse()'s
 * own fidelity guarantee — a fully green result here is the expected,
 * correct outcome, not a sign something is missing.
 */
class AgentDefinitionUnknownNameJourneyTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function an_unrecognized_capability_throws_unknown_capability_naming_it(): void
    {
        $raw = <<<YAML
name: broken-agent
capabilities: [web_browsing]
YAML;

        try {
            (new AgentDefinitionParser())->parse($raw);
            $this->fail('Expected AgentDefinitionResolutionException for an unrecognized capability.');
        } catch (AgentDefinitionResolutionException $e) {
            $this->assertSame(AgentDefinitionResolutionErrorKind::UnknownCapability, $e->kind);
            $this->assertSame('web_browsing', $e->value);
        }
    }

    #[Test]
    public function removing_the_unrecognized_capability_makes_the_same_document_parse_successfully(): void
    {
        $this->seedOperationCatalog([]);

        $raw = <<<YAML
name: broken-agent
capabilities: []
YAML;

        $definition = (new AgentDefinitionParser())->parse($raw);

        $this->assertSame('broken-agent', $definition->name);
        $this->assertSame([], $definition->capabilities);
    }

    #[Test]
    public function an_unavailable_model_throws_unknown_model_naming_it_and_constructs_nothing(): void
    {
        $this->seedOperationCatalog([]);

        $raw = <<<YAML
name: broken-agent
model: nonexistent-model
YAML;

        try {
            (new AgentDefinitionParser())->parse($raw);
            $this->fail('Expected AgentDefinitionResolutionException for an unavailable model.');
        } catch (AgentDefinitionResolutionException $e) {
            $this->assertSame(AgentDefinitionResolutionErrorKind::UnknownModel, $e->kind);
            $this->assertSame('nonexistent-model', $e->value);
        }
    }

    #[Test]
    public function removing_the_unavailable_model_line_makes_the_same_document_parse_successfully(): void
    {
        $this->seedOperationCatalog([]);

        $raw = <<<YAML
name: broken-agent
YAML;

        $definition = (new AgentDefinitionParser())->parse($raw);

        $this->assertSame('broken-agent', $definition->name);
        $this->assertNull($definition->model);
    }

    #[Test]
    public function fixing_the_model_line_to_a_real_model_makes_the_same_document_parse_successfully(): void
    {
        $this->seedOperationCatalog([]);

        $server = Server::create([
            'name' => 'UnknownNameJourneyServer',
            'server_url' => 'http://localhost:11434/v1/chat/completions',
            'token' => 'test-token',
        ]);
        LanguageModel::create([
            'name' => 'seeded-model',
            'server_id' => $server->id,
        ]);

        $raw = <<<YAML
name: broken-agent
model: seeded-model
YAML;

        $definition = (new AgentDefinitionParser())->parse($raw);

        $this->assertSame('broken-agent', $definition->name);
        $this->assertSame('seeded-model', $definition->model);
    }

    #[Test]
    public function an_operation_pattern_matching_zero_operations_throws_empty_operation_pattern_naming_it(): void
    {
        $this->seedOperationCatalog([
            'contacts.store' => ['path' => '/api/contacts', 'method' => 'post', 'summary' => 'Store a contact'],
        ]);

        $raw = <<<YAML
name: broken-agent
tools:
  allow:
    - contakts.*
YAML;

        try {
            (new AgentDefinitionParser())->parse($raw);
            $this->fail('Expected AgentDefinitionResolutionException for an empty-resolving operation pattern.');
        } catch (AgentDefinitionResolutionException $e) {
            $this->assertSame(AgentDefinitionResolutionErrorKind::EmptyOperationPattern, $e->kind);
            $this->assertSame('contakts.*', $e->value);
        }
    }

    #[Test]
    public function correcting_the_typo_in_the_operation_pattern_makes_the_same_document_parse_successfully(): void
    {
        $this->seedOperationCatalog([
            'contacts.store' => ['path' => '/api/contacts', 'method' => 'post', 'summary' => 'Store a contact'],
        ]);

        $raw = <<<YAML
name: broken-agent
tools:
  allow:
    - contacts.*
YAML;

        $definition = (new AgentDefinitionParser())->parse($raw);

        $this->assertSame('broken-agent', $definition->name);
        $this->assertSame(['contacts.*'], $definition->toolsAllow);
    }

    /**
     * Mutation-checklist row 4 — an explicit, independent regression
     * assertion that a tools.allow pattern matching nothing must throw,
     * so a future change that silently starts accepting empty patterns is
     * caught even if the AC3 scenario above is later altered.
     */
    #[Test]
    public function a_tools_allow_pattern_matching_nothing_is_never_silently_accepted(): void
    {
        $this->seedOperationCatalog([
            'weather.get_forecast' => ['path' => '/api/weather', 'method' => 'get', 'summary' => 'Get forecast'],
        ]);

        $raw = <<<YAML
name: broken-agent
tools:
  allow:
    - reports.*
YAML;

        try {
            (new AgentDefinitionParser())->parse($raw);
            $this->fail('An empty-resolving tools.allow pattern must throw, never be silently accepted as a no-op grant.');
        } catch (AgentDefinitionResolutionException $e) {
            $this->assertSame(AgentDefinitionResolutionErrorKind::EmptyOperationPattern, $e->kind);
            $this->assertSame('reports.*', $e->value);
        }
    }

    /**
     * Seeds both of ApiManager's live-catalog seams with the identical
     * OpenAPI-shaped document: the static $apiDocsCache property
     * getOperationDetails() reads directly (reflection, matching
     * AgentDefinitionFullJourneyTest's/AgentDefinitionMinimalJourneyTest's
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
