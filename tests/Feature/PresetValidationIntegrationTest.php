<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use PHPUnit\Framework\TestCase;
use ClarionApp\LlmClient\Services\StructuredOutputPresetRegistry;
use ClarionApp\LlmClient\Services\SchemaValidator;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\Services\MessageFormatter;
use ClarionApp\LlmClient\Services\ToolFormatter;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Presets\DecisionPreset;
use ClarionApp\LlmClient\Presets\SummaryPreset;
use ClarionApp\LlmClient\Presets\ExtractionPreset;
use ClarionApp\LlmClient\Exceptions\SchemaValidationError;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration tests that verify the complete preset flow:
 * preset registration → schema resolution → validation → result.
 */
class PresetValidationIntegrationTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * T025: Test preset resolution through full flow
     * (preset → schema resolution → validation → result)
     */
    #[Test]
    public function preset_resolution_through_full_flow_validates_response()
    {
        // Arrange: Register decision preset
        $registry = new StructuredOutputPresetRegistry();
        $registry->register(new DecisionPreset());

        $schema = $registry->resolveSchema('decision');

        // Act: Validate a valid decision response
        $validator = new SchemaValidator();
        $result = $validator->validate(
            json_encode(['decision' => true, 'reasoning' => 'The evidence supports this.']),
            $schema
        );

        // Assert
        $this->assertTrue($result['decision']);
        $this->assertStringContainsString('evidence', $result['reasoning']);
    }

    /**
     * T025: Test validation failure returns SchemaValidationError (FR-006)
     */
    #[Test]
    public function preset_validation_failure_returns_schema_validation_error()
    {
        // Arrange
        $registry = new StructuredOutputPresetRegistry();
        $registry->register(new DecisionPreset());
        $schema = $registry->resolveSchema('decision');

        // Act & Assert
        $validator = new SchemaValidator();
        $this->expectException(SchemaValidationError::class);

        $validator->validate(
            json_encode(['wrong_field' => 'value']),
            $schema
        );
    }

    /**
     * T025: Test validation error includes raw content and violations (FR-006)
     */
    #[Test]
    public function preset_validation_error_includes_raw_content_and_violations()
    {
        $registry = new StructuredOutputPresetRegistry();
        $registry->register(new DecisionPreset());
        $schema = $registry->resolveSchema('decision');
        $validator = new SchemaValidator();

        $rawContent = json_encode(['decision' => 'not_a_boolean']);

        try {
            $validator->validate($rawContent, $schema);
            $this->fail('Expected SchemaValidationError');
        } catch (SchemaValidationError $e) {
            $this->assertEquals($rawContent, $e->getRawContent());
            $this->assertNotEmpty($e->getViolations());
        }
    }

    /**
     * T025: Test parameterized extraction preset with runtime fields
     */
    #[Test]
    public function parameterized_extraction_preset_with_runtime_fields()
    {
        $registry = new StructuredOutputPresetRegistry();
        $registry->register(new ExtractionPreset());

        $fields = ['name' => 'string', 'email' => 'string', 'age' => 'integer'];
        $schema = $registry->resolveSchema('extraction', ['fields' => $fields]);

        $validator = new SchemaValidator();
        $result = $validator->validate(
            json_encode([
                'name' => 'John Doe',
                'email' => 'john@example.com',
                'age' => 30,
            ]),
            $schema
        );

        $this->assertEquals('John Doe', $result['name']);
        $this->assertEquals('john@example.com', $result['email']);
        $this->assertEquals(30, $result['age']);
    }

    /**
     * T025: Test all three built-in presets validate correctly end-to-end
     */
    #[Test]
    public function all_built_in_presets_validate_end_to_end()
    {
        $registry = new StructuredOutputPresetRegistry();
        $registry->register(new DecisionPreset());
        $registry->register(new SummaryPreset());
        $registry->register(new ExtractionPreset());
        $validator = new SchemaValidator();

        // Decision preset
        $decisionSchema = $registry->resolveSchema('decision');
        $decisionResult = $validator->validate(
            json_encode(['decision' => false, 'reasoning' => 'Insufficient data.']),
            $decisionSchema
        );
        $this->assertFalse($decisionResult['decision']);

        // Summary preset
        $summarySchema = $registry->resolveSchema('summary');
        $summaryResult = $validator->validate(
            json_encode([
                'summary' => 'The meeting was productive.',
                'key_points' => ['Action items assigned', 'Timeline confirmed'],
            ]),
            $summarySchema
        );
        $this->assertCount(2, $summaryResult['key_points']);

        // Extraction preset
        $extractionSchema = $registry->resolveSchema(
            'extraction',
            ['fields' => ['title' => 'string']]
        );
        $extractionResult = $validator->validate(
            json_encode(['title' => 'Quarterly Report']),
            $extractionSchema
        );
        $this->assertEquals('Quarterly Report', $extractionResult['title']);
    }

    /**
     * T021-T022: Test schema_overrides through resolveSchema
     */
    #[Test]
    public function preset_with_schema_overrides_extends_validation()
    {
        $registry = new StructuredOutputPresetRegistry();
        $registry->register(new DecisionPreset());

        $overrides = [
            'properties' => [
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
            ],
            'required' => ['confidence'],
        ];
        $schema = $registry->resolveSchema('decision', null, $overrides);

        $validator = new SchemaValidator();
        $result = $validator->validate(
            json_encode([
                'decision' => true,
                'reasoning' => 'Based on analysis.',
                'confidence' => 0.95,
            ]),
            $schema
        );

        $this->assertTrue($result['decision']);
        $this->assertEquals(0.95, $result['confidence']);
    }

    /**
     * T020: Test null sentinel removes properties from schema
     */
    #[Test]
    public function preset_with_null_sentinel_removal_validates_reduced_schema()
    {
        $registry = new StructuredOutputPresetRegistry();
        $registry->register(new DecisionPreset());

        $overrides = [
            'properties' => [
                'reasoning' => null,
            ],
            'required' => ['decision'],
        ];
        $schema = $registry->resolveSchema('decision', null, $overrides);

        $this->assertArrayNotHasKey('reasoning', $schema['properties'] ?? []);

        $validator = new SchemaValidator();
        $result = $validator->validate(
            json_encode(['decision' => true]),
            $schema
        );

        $this->assertTrue($result['decision']);
    }

    /**
     * T019: Test AgentLoopService accepts preset registry (structural test)
     */
    #[Test]
    public function agent_loop_service_accepts_preset_registry()
    {
        $toolRegistry = Mockery::mock(McpToolRegistry::class);
        $toolExecutor = Mockery::mock(McpToolExecutor::class);
        $operationCache = Mockery::mock(OperationCache::class);
        $providerRegistry = new ProviderRegistry();
        $messageFormatter = new MessageFormatter();
        $toolFormatter = new ToolFormatter();
        $presetRegistry = new StructuredOutputPresetRegistry();

        $service = new AgentLoopService(
            $toolRegistry,
            $toolExecutor,
            $operationCache,
            $providerRegistry,
            $messageFormatter,
            $toolFormatter,
            null,
            $presetRegistry
        );

        $this->assertInstanceOf(AgentLoopService::class, $service);
    }

    /**
     * T023-T024: Test discovery via registry list() and find()
     */
    #[Test]
    public function discovery_returns_all_registered_presets_with_metadata()
    {
        $registry = new StructuredOutputPresetRegistry();
        $registry->register(new DecisionPreset());
        $registry->register(new SummaryPreset());
        $registry->register(new ExtractionPreset());

        $list = $registry->list();

        $this->assertCount(3, $list);
        $this->assertArrayHasKey('decision', $list);
        $this->assertArrayHasKey('summary', $list);
        $this->assertArrayHasKey('extraction', $list);

        foreach ($list as $name => $entry) {
            $this->assertEquals($name, $entry['name']);
            $this->assertArrayHasKey('description', $entry);
            $this->assertArrayHasKey('schema', $entry);
        }
    }

    /**
     * T029: Verify AgentLoopService works without preset registry (optional dependency)
     */
    #[Test]
    public function agent_loop_service_works_without_preset_registry()
    {
        $toolRegistry = Mockery::mock(McpToolRegistry::class);
        $toolExecutor = Mockery::mock(McpToolExecutor::class);
        $operationCache = Mockery::mock(OperationCache::class);
        $providerRegistry = new ProviderRegistry();
        $messageFormatter = new MessageFormatter();
        $toolFormatter = new ToolFormatter();

        $service = new AgentLoopService(
            $toolRegistry,
            $toolExecutor,
            $operationCache,
            $providerRegistry,
            $messageFormatter,
            $toolFormatter
        );

        $this->assertInstanceOf(AgentLoopService::class, $service);
    }
}
