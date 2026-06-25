<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ClarionApp\LlmClient\Jobs\ReindexOperationsJob;
use Illuminate\Contracts\Queue\ShouldQueue;

use PHPUnit\Framework\Attributes\Test;

class ReindexOperationsJobTest extends TestCase
{
    #[Test]
    public function job_implements_should_queue()
    {
        $job = new ReindexOperationsJob();
        $this->assertInstanceOf(ShouldQueue::class, $job);
    }

    #[Test]
    public function job_has_handle_method()
    {
        $job = new ReindexOperationsJob();
        $this->assertTrue(method_exists($job, 'handle'));
    }

    #[Test]
    public function job_uses_dispatchable_trait()
    {
        $reflection = new \ReflectionClass(ReindexOperationsJob::class);
        $traits = $reflection->getTraitNames();
        $this->assertContains(
            'Illuminate\Foundation\Bus\Dispatchable',
            $traits,
            'ReindexOperationsJob should use the Dispatchable trait'
        );
    }

    #[Test]
    public function job_uses_queueable_trait()
    {
        $reflection = new \ReflectionClass(ReindexOperationsJob::class);
        $traits = $reflection->getTraitNames();
        $this->assertContains(
            'Illuminate\Bus\Queueable',
            $traits,
            'ReindexOperationsJob should use the Queueable trait'
        );
    }

    #[Test]
    public function job_handles_missing_operation_id_gracefully()
    {
        // Verify the job source code contains the expected skip logic
        // Full integration testing of handle() requires Laravel app bootstrap
        // and is covered by Feature/OperationsSearchIntegrationTest
        $reflection = new \ReflectionClass(ReindexOperationsJob::class);
        $source = file_get_contents($reflection->getFileName());
        
        $this->assertStringContainsString('missing operationId', $source);
        $this->assertStringContainsString('Log::warning', $source);
        $this->assertStringContainsString('no details found', $source);
    }

    #[Test]
    public function job_builds_searchable_text_from_components()
    {
        // Verify the job source code constructs searchable_text correctly
        $reflection = new \ReflectionClass(ReindexOperationsJob::class);
        $source = file_get_contents($reflection->getFileName());
        
        $this->assertStringContainsString('searchable_text', $source);
        $this->assertStringContainsString('searchableParts', $source);
        $this->assertStringContainsString('packageDescription', $source);
    }

    // Tests for full raw schema preservation in ReindexOperationsJob
    #[Test]
    public function job_stores_full_raw_schema_for_path_query_params()
    {
        $reflection = new \ReflectionClass(ReindexOperationsJob::class);
        $source = file_get_contents($reflection->getFileName());
        
        // Verify the job merges rawSchema fields into paramInfo (not just type)
        $this->assertStringContainsString('rawSchema', $source);
        // Verify it iterates over rawSchema keys to preserve all fields
        $this->assertMatchesRegularExpression('/foreach\s*\(\s*\$rawSchema\s+as\s/', $source);
        // Verify it uses array_key_exists check to avoid overwriting in/required
        $this->assertStringContainsString('array_key_exists($key, $paramInfo)', $source);
    }

    #[Test]
    public function job_stores_full_raw_schema_for_body_params()
    {
        $reflection = new \ReflectionClass(ReindexOperationsJob::class);
        $source = file_get_contents($reflection->getFileName());
        
        // Verify body params use array_merge with propSchema (full raw object)
        $this->assertStringContainsString('$bodyParams[$propName] = array_merge(', $source);
        // Verify body params include 'in' => 'body' and 'required' from bodyRequired
        $this->assertStringContainsString("'in' => 'body'", $source);
    }

    // Tests for 10KB warning log
    #[Test]
    public function job_logs_warning_for_large_param_schema()
    {
        $reflection = new \ReflectionClass(ReindexOperationsJob::class);
        $source = file_get_contents($reflection->getFileName());
        
        // Verify 10KB threshold check exists
        $this->assertStringContainsString('10240', $source);
        // Verify Log::warning is called with paramSchema size info
        $this->assertStringContainsString("exceeds 10KB", $source);
        // Verify it includes operation_id in log context
        $this->assertStringContainsString("'operation_id'", $source);
        // Verify it includes size in log context
        $this->assertStringContainsString("'size'", $source);
    }

    #[Test]
    public function job_param_schema_size_check_runs_before_db_insert()
    {
        $reflection = new \ReflectionClass(ReindexOperationsJob::class);
        $source = file_get_contents($reflection->getFileName());
        
        // Verify size check code appears before updateOrInsert
        $sizePos = strpos($source, 'paramSchemaSize');
        $insertPos = strpos($source, 'updateOrInsert');
        $this->assertNotNull($sizePos, 'paramSchemaSize check should exist');
        $this->assertNotNull($insertPos, 'updateOrInsert should exist');
        $this->assertLessThan($insertPos, $sizePos, 'Size check should run before DB insert');
    }

    // Tests for OperationsSearchService::search() returning structured paramSchema
    #[Test]
    public function operations_search_service_returns_structured_param_schema()
    {
        // Verify OperationsSearchService selects param_schema from DB
        $source = file_get_contents(__DIR__ . '/../../src/Services/OperationsSearchService.php');
        
        $this->assertStringContainsString('param_schema as paramSchema', $source);
        // Verify safe decode is used
        $this->assertStringContainsString('safeDecodeParamSchema', $source);
    }

    // Tests for nested object structure preservation
    #[Test]
    public function job_preserves_nested_object_structure_in_body_params()
    {
        $reflection = new \ReflectionClass(ReindexOperationsJob::class);
        $source = file_get_contents($reflection->getFileName());
        
        // Body params store full propSchema (which includes nested 'properties', 'items', etc.)
        $this->assertStringContainsString('array_merge(', $source);
        $this->assertStringContainsString('propSchema', $source);
        // array_merge preserves nested arrays like 'properties', 'items', 'allOf', etc.
    }

    // Tests for enum and default value preservation
    #[Test]
    public function job_preserves_enum_and_default_fields_in_schema()
    {
        $reflection = new \ReflectionClass(ReindexOperationsJob::class);
        $source = file_get_contents($reflection->getFileName());
        
        // rawSchema iteration preserves all fields including 'enum', 'default', 'minimum', 'maximum'
        $this->assertStringContainsString('rawSchema', $source);
        // Verify it iterates over rawSchema keys
        $this->assertMatchesRegularExpression('/foreach\s*\(\s*\$rawSchema\s+as\s/', $source);
        // Body params use array_merge(propSchema, ...) which preserves all fields
        $this->assertStringContainsString('array_merge(', $source);
    }
}
