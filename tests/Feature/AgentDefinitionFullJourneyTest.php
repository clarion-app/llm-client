<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\LlmClient\Exceptions\AgentDefinitionParseException;
use ClarionApp\LlmClient\Models\LanguageModel;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\AgentDefinitionParser;
use ClarionApp\LlmClient\ValueObjects\AgentDefinitionParseErrorKind;
use ClarionApp\LlmClient\ValueObjects\ReducibleTool;
use Dedoc\Scramble\Generator;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AgentDefinitionParser::parse() (contracts/agent-definition-parser.md §1,
 * spec.md US1 Acceptance Scenarios 1-3) — covers maximal fidelity (SC-001),
 * operation-group pattern expansion (US1 AC2/FR-008), confirmation
 * distinct from allow/forbid (US1 AC3), plus two regression scenarios
 * added during this tasks.md's own analysis-pass correction: live
 * re-evaluation after parse() returns (mutation-checklist row 3) and the
 * unknown-key / recognized-key-invalid-value cases (mutation-checklist
 * row 6, and research.md D10's UnknownKey-for-both-cases resolution).
 *
 * Scope note (Phase 3's own Scope note, tasks.md Grounding note 3): this
 * phase's isOperationPermitted()/isConfirmationRequired() check only the
 * definition's own toolsAllow/toolsDeny/safetyConfirmationRequired — no
 * config('llm-client.api_denylist')/confirm_methods union yet (Phase
 * 4/US3's own scope). confirm_methods is explicitly configured to an
 * empty set below so a later Phase 4 installation-side union can never
 * itself produce a `true` this file's own assertions did not intend to
 * exercise.
 */
class AgentDefinitionFullJourneyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Isolate every assertion in this file to the definition's own
        // safety settings — Phase 4/US3's installation-ceiling union is
        // out of this phase's scope (see class docblock).
        $this->app['config']->set('llm-client.confirm_methods', []);
    }

    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function a_maximal_definition_round_trips_every_documented_setting_with_full_fidelity(): void
    {
        $this->seedOperationCatalog([
            'contacts.destroy' => ['path' => '/api/contacts/{id}', 'method' => 'delete', 'summary' => 'Delete a contact'],
            'weather.get_forecast' => ['path' => '/api/weather', 'method' => 'get', 'summary' => 'Get forecast'],
        ]);

        $server = Server::create([
            'name' => 'FullJourneyServer',
            'server_url' => 'http://localhost:11434/v1/chat/completions',
            'token' => 'test-token',
        ]);
        LanguageModel::create([
            'name' => 'seeded-model',
            'server_id' => $server->id,
        ]);

        $raw = <<<YAML
format_version: "1.0"
name: full-agent
version: "1.0.0"
instructions: |
  You are a helpful assistant.
  Always be polite.
model: seeded-model
memory:
  scratch: enabled
  short_term: disabled
  long_term: enabled
  episodic: disabled
  declarative: enabled
capabilities:
  - memory_create
  - memory_search
tools:
  allow:
    - contacts.*
  deny:
    - contacts.destroy
safety:
  confirmation_required:
    - contacts.destroy
  denylist:
    - weather.*
YAML;

        $definition = (new AgentDefinitionParser())->parse($raw);

        $this->assertSame('1.0', $definition->formatVersion);
        $this->assertSame('full-agent', $definition->name);
        $this->assertSame('1.0.0', $definition->version);
        $this->assertSame("You are a helpful assistant.\nAlways be polite.\n", $definition->instructions);
        $this->assertSame('seeded-model', $definition->model);
        $this->assertSame([
            'scratch' => true,
            'short_term' => false,
            'long_term' => true,
            'episodic' => false,
            'declarative' => true,
        ], $definition->memory);
        $this->assertSame([ReducibleTool::MemoryCreate, ReducibleTool::MemorySearch], $definition->capabilities);
        $this->assertSame(['contacts.*'], $definition->toolsAllow);
        $this->assertSame(['contacts.destroy'], $definition->toolsDeny);
        $this->assertSame(['contacts.destroy'], $definition->safetyConfirmationRequired);
        $this->assertSame(['weather.*'], $definition->safetyDenylist);
    }

    #[Test]
    public function an_operation_group_pattern_expands_to_exactly_the_operations_it_covers(): void
    {
        $this->seedOperationCatalog([
            'contacts.store' => ['path' => '/api/contacts', 'method' => 'post', 'summary' => 'Store a contact'],
            'contacts.destroy' => ['path' => '/api/contacts/{id}', 'method' => 'delete', 'summary' => 'Delete a contact'],
            'weather.get_forecast' => ['path' => '/api/weather', 'method' => 'get', 'summary' => 'Get forecast'],
        ]);

        $raw = <<<YAML
name: pattern-agent
tools:
  allow:
    - contacts.*
YAML;

        $definition = (new AgentDefinitionParser())->parse($raw);

        $this->assertTrue($definition->isOperationPermitted('contacts.store'));
        $this->assertTrue($definition->isOperationPermitted('contacts.destroy'));
        $this->assertFalse($definition->isOperationPermitted('weather.get_forecast'));
    }

    #[Test]
    public function confirmation_required_is_independent_of_and_does_not_conflict_with_being_permitted(): void
    {
        $this->seedOperationCatalog([
            'contacts.destroy' => ['path' => '/api/contacts/{id}', 'method' => 'delete', 'summary' => 'Delete a contact'],
        ]);

        $raw = <<<YAML
name: confirm-agent
tools:
  allow:
    - contacts.destroy
safety:
  confirmation_required:
    - contacts.destroy
YAML;

        $definition = (new AgentDefinitionParser())->parse($raw);

        $this->assertTrue($definition->isOperationPermitted('contacts.destroy'));
        $this->assertTrue($definition->isConfirmationRequired('contacts.destroy'));
    }

    #[Test]
    public function an_operation_added_to_the_catalog_after_parse_returns_is_picked_up_with_no_reparse(): void
    {
        $this->seedOperationCatalog([
            'reports.summary' => ['path' => '/api/reports/summary', 'method' => 'get', 'summary' => 'Report summary'],
        ]);

        $raw = <<<YAML
name: live-agent
tools:
  allow:
    - reports.*
YAML;

        $definition = (new AgentDefinitionParser())->parse($raw);

        $this->assertTrue($definition->isOperationPermitted('reports.summary'));
        $this->assertFalse($definition->isOperationPermitted('reports.export'));

        // The catalog changes after parse() has already returned — no
        // re-parse. isOperationPermitted() must re-evaluate live (FR-008,
        // research.md D8) rather than freezing an expansion computed at
        // parse time.
        $this->seedOperationCatalog([
            'reports.summary' => ['path' => '/api/reports/summary', 'method' => 'get', 'summary' => 'Report summary'],
            'reports.export' => ['path' => '/api/reports/export', 'method' => 'get', 'summary' => 'Report export'],
        ]);

        $this->assertTrue($definition->isOperationPermitted('reports.export'));
    }

    #[Test]
    public function a_misspelled_top_level_key_throws_unknown_key_naming_the_offending_key(): void
    {
        // collect() (088-agent-definition-validator) always evaluates every
        // one of the 11 steps, including the operation-catalog-dependent
        // tools/safety steps, regardless of an earlier step's own outcome
        // (FR-001) -- so the catalog is now reached even for a document
        // whose only problem is an earlier, unrelated key, unlike 086's
        // fail-fast parse() which never got this far for this document.
        $this->seedOperationCatalog([]);

        $raw = <<<YAML
name: ok
namee: broken
YAML;

        try {
            (new AgentDefinitionParser())->parse($raw);
            $this->fail('Expected AgentDefinitionParseException for an unrecognized top-level key.');
        } catch (AgentDefinitionParseException $e) {
            $this->assertSame(AgentDefinitionParseErrorKind::UnknownKey, $e->kind);
            $this->assertSame('namee', $e->key);
        }
    }

    #[Test]
    public function a_recognized_key_with_an_out_of_vocabulary_value_also_throws_unknown_key(): void
    {
        // See the identical note in the sibling test just above.
        $this->seedOperationCatalog([]);

        $raw = <<<YAML
name: ok
memory:
  long_term: maybe
YAML;

        try {
            (new AgentDefinitionParser())->parse($raw);
            $this->fail('Expected AgentDefinitionParseException for memory.long_term: maybe.');
        } catch (AgentDefinitionParseException $e) {
            $this->assertSame(AgentDefinitionParseErrorKind::UnknownKey, $e->kind);
            $this->assertSame('memory.long_term', $e->key);
            $this->assertSame('maybe', $e->value);
        }
    }

    /**
     * Seeds both of ApiManager's live-catalog seams with the identical
     * OpenAPI-shaped document: the static $apiDocsCache property
     * getOperationDetails() reads directly (reflection, matching
     * ApiCallValidatorTest/McpToolRegistryTest's own established
     * convention), and a fake Dedoc\Scramble\Generator bound into the
     * container for getOperations() — which never reads that cache, it
     * always resolves a fresh DocumentationService. Safe to call more than
     * once per test to simulate the catalog changing after parse() has
     * already returned.
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
