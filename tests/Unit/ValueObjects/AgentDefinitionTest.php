<?php

namespace ClarionApp\LlmClient\Tests\Unit\ValueObjects;

use ClarionApp\Backend\ApiManager;
use ClarionApp\LlmClient\ValueObjects\AgentDefinition;
use ClarionApp\LlmClient\ValueObjects\MemoryKind;
use ClarionApp\LlmClient\ValueObjects\ReducibleTool;
use Dedoc\Scramble\Generator;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AgentDefinition (data-model.md §1, contracts §2) is a plain, unvalidated
 * readonly value object — every validation rule lives in
 * AgentDefinitionParser (T021), never in the constructor here. This test
 * hand-constructs instances directly, bypassing the not-yet-existing
 * parser, matching DegradationDecisionTest's own established convention
 * for narrow unit coverage of a readonly value object's own methods
 * (contracts §2: "tests may still construct one directly for narrow unit
 * coverage").
 *
 * isOperationPermitted()/isConfirmationRequired() re-evaluate live against
 * ApiManager's own operation catalog (research.md D8/D9) rather than
 * against a catalog passed as a parameter, so every assertion below seeds
 * that catalog via the same reflection seam ApiCallValidatorTest/
 * McpToolRegistryTest already use for ApiManager::getOperationDetails()
 * (writing ApiManager's static $apiDocsCache directly), plus a fake
 * Dedoc\Scramble\Generator container binding for ApiManager::getOperations()
 * (which never reads that cache — it always asks a fresh
 * DocumentationService) — never a real ApiManager call.
 *
 * Scope note (tasks.md Grounding note 3 / Phase 3's own Scope note): this
 * phase's isOperationPermitted()/isConfirmationRequired() check only the
 * definition's own toolsAllow/toolsDeny/safetyConfirmationRequired — no
 * config('llm-client.api_denylist')/confirm_methods union yet (that is
 * Phase 4/US3's own scope). None of the fixtures below configure a
 * restrictive installation rule, so the missing union cannot affect any
 * assertion here.
 */
class AgentDefinitionTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function memory_enabled_reflects_the_named_kind_when_true(): void
    {
        $definition = $this->makeDefinition(memory: [
            MemoryKind::Scratch->value => true,
            MemoryKind::ShortTerm->value => true,
            MemoryKind::LongTerm->value => true,
            MemoryKind::Episodic->value => false,
            MemoryKind::Declarative->value => false,
        ]);

        $this->assertTrue($definition->memoryEnabled(MemoryKind::LongTerm));
    }

    #[Test]
    public function memory_enabled_reflects_the_named_kind_when_false(): void
    {
        $definition = $this->makeDefinition(memory: [
            MemoryKind::Scratch->value => true,
            MemoryKind::ShortTerm->value => true,
            MemoryKind::LongTerm->value => false,
            MemoryKind::Episodic->value => true,
            MemoryKind::Declarative->value => true,
        ]);

        $this->assertFalse($definition->memoryEnabled(MemoryKind::LongTerm));
    }

    #[Test]
    public function has_capability_reflects_presence_in_the_capabilities_list(): void
    {
        $definition = $this->makeDefinition(capabilities: [
            ReducibleTool::MemoryCreate,
            ReducibleTool::MemorySearch,
        ]);

        $this->assertTrue($definition->hasCapability(ReducibleTool::MemoryCreate));
    }

    #[Test]
    public function has_capability_reflects_absence_from_the_capabilities_list(): void
    {
        $definition = $this->makeDefinition(capabilities: [
            ReducibleTool::MemorySearch,
        ]);

        $this->assertFalse($definition->hasCapability(ReducibleTool::MemoryCreate));
    }

    #[Test]
    public function is_operation_permitted_is_true_when_an_allow_pattern_matches_and_nothing_denies(): void
    {
        $this->seedOperationCatalog([
            'contacts.store' => ['path' => '/api/contacts', 'method' => 'post', 'summary' => 'Store a contact'],
        ]);

        $definition = $this->makeDefinition(toolsAllow: ['contacts.*'], toolsDeny: []);

        $this->assertTrue($definition->isOperationPermitted('contacts.store'));
    }

    #[Test]
    public function is_operation_permitted_is_false_when_a_deny_pattern_also_matches_deny_wins_over_allow(): void
    {
        $this->seedOperationCatalog([
            'contacts.store' => ['path' => '/api/contacts', 'method' => 'post', 'summary' => 'Store a contact'],
        ]);

        $definition = $this->makeDefinition(toolsAllow: ['contacts.*'], toolsDeny: ['contacts.store']);

        $this->assertFalse($definition->isOperationPermitted('contacts.store'));
    }

    #[Test]
    public function is_operation_permitted_is_false_when_no_allow_pattern_matches_at_all(): void
    {
        $this->seedOperationCatalog([
            'contacts.store' => ['path' => '/api/contacts', 'method' => 'post', 'summary' => 'Store a contact'],
            'weather.get_forecast' => ['path' => '/api/weather', 'method' => 'get', 'summary' => 'Get forecast'],
        ]);

        $definition = $this->makeDefinition(toolsAllow: ['contacts.*'], toolsDeny: []);

        $this->assertFalse($definition->isOperationPermitted('weather.get_forecast'));
    }

    #[Test]
    public function is_confirmation_required_is_true_for_a_matching_operation_pattern(): void
    {
        $this->seedOperationCatalog([
            'contacts.destroy' => ['path' => '/api/contacts/{id}', 'method' => 'delete', 'summary' => 'Delete a contact'],
        ]);

        $definition = $this->makeDefinition(safetyConfirmationRequired: ['contacts.destroy']);

        $this->assertTrue($definition->isConfirmationRequired('contacts.destroy'));
    }

    #[Test]
    public function is_confirmation_required_is_true_for_a_bare_verb_matching_the_operations_resolved_method(): void
    {
        $this->seedOperationCatalog([
            'contacts.destroy' => ['path' => '/api/contacts/{id}', 'method' => 'delete', 'summary' => 'Delete a contact'],
        ]);

        $definition = $this->makeDefinition(safetyConfirmationRequired: ['DELETE']);

        $this->assertTrue($definition->isConfirmationRequired('contacts.destroy'));
    }

    #[Test]
    public function is_confirmation_required_is_false_when_no_pattern_or_verb_matches(): void
    {
        $this->seedOperationCatalog([
            'contacts.store' => ['path' => '/api/contacts', 'method' => 'post', 'summary' => 'Store a contact'],
        ]);

        $definition = $this->makeDefinition(safetyConfirmationRequired: ['contacts.destroy']);

        $this->assertFalse($definition->isConfirmationRequired('contacts.store'));
    }

    /**
     * @param array<string, bool>|null $memory
     * @param list<ReducibleTool> $capabilities
     * @param list<string> $toolsAllow
     * @param list<string> $toolsDeny
     * @param list<string> $safetyConfirmationRequired
     * @param list<string> $safetyDenylist
     */
    private function makeDefinition(
        ?array $memory = null,
        array $capabilities = [],
        array $toolsAllow = ['*'],
        array $toolsDeny = [],
        array $safetyConfirmationRequired = [],
        array $safetyDenylist = [],
    ): AgentDefinition {
        $memory ??= array_fill_keys(
            array_map(static fn (MemoryKind $kind): string => $kind->value, MemoryKind::cases()),
            true
        );

        return new AgentDefinition(
            formatVersion: '1.0',
            name: 'test-agent',
            version: null,
            instructions: '',
            model: null,
            memory: $memory,
            capabilities: $capabilities,
            toolsAllow: $toolsAllow,
            toolsDeny: $toolsDeny,
            safetyConfirmationRequired: $safetyConfirmationRequired,
            safetyDenylist: $safetyDenylist,
        );
    }

    /**
     * Seeds both of ApiManager's live-catalog seams with the identical
     * OpenAPI-shaped document: the static $apiDocsCache property
     * getOperationDetails() reads directly (reflection, matching
     * ApiCallValidatorTest/McpToolRegistryTest's own established
     * convention), and a fake Dedoc\Scramble\Generator bound into the
     * container for getOperations() — which never reads that cache, it
     * always resolves a fresh DocumentationService.
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
