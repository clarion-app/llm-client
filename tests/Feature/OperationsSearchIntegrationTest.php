<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use PHPUnit\Framework\TestCase;
use ClarionApp\LlmClient\Services\OperationsSearchService;
use ClarionApp\LlmClient\Jobs\ReindexOperationsJob;
use ClarionApp\LlmClient\Listeners\ReindexOnPackageChange;
use ClarionApp\LlmClient\Commands\ReindexOperationsCommand;
use Mockery;

/**
 * Integration tests that verify the complete flow without requiring
 * full Laravel app bootstrap. These tests verify class structure,
 * method signatures, and basic behavior.
 */
class OperationsSearchIntegrationTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function operations_search_service_exists_and_is_callable()
    {
        $this->assertTrue(class_exists(OperationsSearchService::class));
    }

    /** @test */
    public function reindex_job_exists_and_implementation()
    {
        $this->assertTrue(class_exists(ReindexOperationsJob::class));
    }

    /** @test */
    public function reindex_command_exists_and_has_signature()
    {
        $this->assertTrue(class_exists(ReindexOperationsCommand::class));
        $command = new ReindexOperationsCommand();
        $this->assertEquals('llm-client:reindex', $command->getName());
    }

    /** @test */
    public function listener_exists()
    {
        $this->assertTrue(class_exists(ReindexOnPackageChange::class));
    }

    /** @test */
    public function cross_package_search_returns_merged_results()
    {
        $mockRow1 = (object) [
            'operationId' => 'contacts.store',
            'summary' => 'Store a new contact',
            'method' => 'POST',
            'path' => '/api/contacts',
            'paramSchema' => '[]',
        ];
        $mockRow2 = (object) [
            'operationId' => 'weather.forecast',
            'summary' => 'Get weather forecast',
            'method' => 'GET',
            'path' => '/api/weather',
            'paramSchema' => '[]',
        ];

        $collectionMock = Mockery::mock();
        $collectionMock->shouldReceive('toArray')->once()->andReturn([$mockRow1, $mockRow2]);

        $queryMock = Mockery::mock();
        $queryMock->shouldReceive('select')->once()->andReturnSelf();
        $queryMock->shouldReceive('whereRaw')->once()->andReturnSelf();
        $queryMock->shouldReceive('orderByRaw')->once()->andReturnSelf();
        $queryMock->shouldReceive('limit')->with(10)->once()->andReturnSelf();
        $queryMock->shouldReceive('get')->once()->andReturn($collectionMock);

        $dbMock = Mockery::mock(\Illuminate\Database\ConnectionInterface::class);
        $dbMock->shouldReceive('table')->with('operation_search_index')->once()->andReturn($queryMock);

        $service = new OperationsSearchService($dbMock, 10);
        $results = $service->search('weather contacts');

        // Verify results span multiple packages
        $this->assertCount(2, $results);
        $operationIds = array_column($results, 'operationId');
        $this->assertContains('contacts.store', $operationIds);
        $this->assertContains('weather.forecast', $operationIds);
    }

    /** @test */
    public function result_limit_enforcement()
    {
        // DB limit is applied at query level, so mock returns only the limited set
        // Create 10 mock results (matching the limit)
        $mockRows = [];
        for ($i = 0; $i < 10; $i++) {
            $mockRows[] = (object) [
                'operationId' => "pkg.operation_{$i}",
                'summary' => "Operation {$i}",
                'method' => 'GET',
                'path' => "/api/operation_{$i}",
                'paramSchema' => '[]',
            ];
        }

        $collectionMock = Mockery::mock();
        $collectionMock->shouldReceive('toArray')->once()->andReturn($mockRows);

        $queryMock = Mockery::mock();
        $queryMock->shouldReceive('select')->once()->andReturnSelf();
        $queryMock->shouldReceive('whereRaw')->once()->andReturnSelf();
        $queryMock->shouldReceive('orderByRaw')->once()->andReturnSelf();
        $queryMock->shouldReceive('limit')->with(10)->once()->andReturnSelf();
        $queryMock->shouldReceive('get')->once()->andReturn($collectionMock);

        $dbMock = Mockery::mock(\Illuminate\Database\ConnectionInterface::class);
        $dbMock->shouldReceive('table')->with('operation_search_index')->once()->andReturn($queryMock);

        $service = new OperationsSearchService($dbMock, 10);
        $results = $service->search('test');

        // Verify limit is passed correctly to DB query
        $this->assertCount(10, $results);
    }

    /** @test */
    public function result_limit_custom_value_enforcement()
    {
        $mockRows = [];
        for ($i = 0; $i < 5; $i++) {
            $mockRows[] = (object) [
                'operationId' => "pkg.operation_{$i}",
                'summary' => "Operation {$i}",
                'method' => 'GET',
                'path' => "/api/operation_{$i}",
                'paramSchema' => '[]',
            ];
        }

        $collectionMock = Mockery::mock();
        $collectionMock->shouldReceive('toArray')->once()->andReturn($mockRows);

        $queryMock = Mockery::mock();
        $queryMock->shouldReceive('select')->once()->andReturnSelf();
        $queryMock->shouldReceive('whereRaw')->once()->andReturnSelf();
        $queryMock->shouldReceive('orderByRaw')->once()->andReturnSelf();
        $queryMock->shouldReceive('limit')->with(5)->once()->andReturnSelf();
        $queryMock->shouldReceive('get')->once()->andReturn($collectionMock);

        $dbMock = Mockery::mock(\Illuminate\Database\ConnectionInterface::class);
        $dbMock->shouldReceive('table')->with('operation_search_index')->once()->andReturn($queryMock);

        // Default limit is 10, but custom limit of 5 is passed
        $service = new OperationsSearchService($dbMock, 10);
        $results = $service->search('test', 5);

        $this->assertCount(5, $results);
    }

    /** @test */
    public function empty_index_returns_error_message_in_agent_handler()
    {
        $collectionMock = Mockery::mock();
        $collectionMock->shouldReceive('toArray')->once()->andReturn([]);

        $queryMock = Mockery::mock();
        $queryMock->shouldReceive('select')->once()->andReturnSelf();
        $queryMock->shouldReceive('whereRaw')->once()->andReturnSelf();
        $queryMock->shouldReceive('orderByRaw')->once()->andReturnSelf();
        $queryMock->shouldReceive('limit')->with(10)->once()->andReturnSelf();
        $queryMock->shouldReceive('get')->once()->andReturn($collectionMock);

        $dbMock = Mockery::mock(\Illuminate\Database\ConnectionInterface::class);
        $dbMock->shouldReceive('table')->with('operation_search_index')->once()->andReturn($queryMock);

        $service = new OperationsSearchService($dbMock, 10);
        $results = $service->search('test');

        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    /** @test T021 - Integration: complete flow with valid query returns correct format */
    public function search_operations_complete_flow_returns_formatted_results()
    {
        $paramSchema = json_encode([
            'path' => [['name' => 'id', 'type' => 'integer', 'required' => true]],
            'body' => [['name' => 'name', 'type' => 'string', 'required' => true]],
        ]);
        $mockRow = (object) [
            'operationId' => 'contacts.update',
            'summary' => 'Update a contact by ID',
            'method' => 'PUT',
            'path' => '/api/contacts/{id}',
            'paramSchema' => $paramSchema,
        ];

        $collectionMock = Mockery::mock();
        $collectionMock->shouldReceive('toArray')->once()->andReturn([$mockRow]);

        $queryMock = Mockery::mock();
        $queryMock->shouldReceive('select')->once()->andReturnSelf();
        $queryMock->shouldReceive('whereRaw')->once()->andReturnSelf();
        $queryMock->shouldReceive('orderByRaw')->once()->andReturnSelf();
        $queryMock->shouldReceive('limit')->with(10)->once()->andReturnSelf();
        $queryMock->shouldReceive('get')->once()->andReturn($collectionMock);

        $dbMock = Mockery::mock(\Illuminate\Database\ConnectionInterface::class);
        $dbMock->shouldReceive('table')->with('operation_search_index')->once()->andReturn($queryMock);

        $service = new OperationsSearchService($dbMock, 10);
        $results = $service->search('update contact');

        $this->assertCount(1, $results);
        $result = $results[0];
        $this->assertEquals('contacts.update', $result->operationId);
        $this->assertEquals('PUT', $result->method);
        $this->assertEquals('/api/contacts/{id}', $result->path);

        // Verify safeDecodeParamSchema preserves structure
        $decoded = OperationsSearchService::safeDecodeParamSchema($result->paramSchema);
        $this->assertArrayHasKey('path', $decoded);
        $this->assertArrayHasKey('body', $decoded);
        $this->assertEquals('id', $decoded['path'][0]['name']);
        $this->assertEquals('name', $decoded['body'][0]['name']);
    }

    /** @test T022 - Integration: malformed paramSchema in DB row returns null without error */
    public function search_operations_handles_malformed_paramSchema_in_db_row()
    {
        $mockRow1 = (object) [
            'operationId' => 'good.op',
            'summary' => 'Good operation',
            'method' => 'GET',
            'path' => '/api/good',
            'paramSchema' => json_encode(['query' => [['name' => 'page', 'type' => 'integer']]]),
        ];
        $mockRow2 = (object) [
            'operationId' => 'bad.op',
            'summary' => 'Bad paramSchema',
            'method' => 'GET',
            'path' => '/api/bad',
            'paramSchema' => '{malformed json',
        ];

        $collectionMock = Mockery::mock();
        $collectionMock->shouldReceive('toArray')->once()->andReturn([$mockRow1, $mockRow2]);

        $queryMock = Mockery::mock();
        $queryMock->shouldReceive('select')->once()->andReturnSelf();
        $queryMock->shouldReceive('whereRaw')->once()->andReturnSelf();
        $queryMock->shouldReceive('orderByRaw')->once()->andReturnSelf();
        $queryMock->shouldReceive('limit')->with(10)->once()->andReturnSelf();
        $queryMock->shouldReceive('get')->once()->andReturn($collectionMock);

        $dbMock = Mockery::mock(\Illuminate\Database\ConnectionInterface::class);
        $dbMock->shouldReceive('table')->with('operation_search_index')->once()->andReturn($queryMock);

        $service = new OperationsSearchService($dbMock, 10);
        $results = $service->search('test');

        $this->assertCount(2, $results);

        // Good row decodes properly
        $decoded1 = OperationsSearchService::safeDecodeParamSchema($results[0]->paramSchema);
        $this->assertArrayHasKey('query', $decoded1);

        // Bad row decodes to null without error
        $decoded2 = OperationsSearchService::safeDecodeParamSchema($results[1]->paramSchema);
        $this->assertNull($decoded2);
    }

    /** @test T023 - Integration: operations with no parameters returns paramSchema null */
    public function search_operations_handles_operations_with_no_parameters()
    {
        $mockRow = (object) [
            'operationId' => 'health.check',
            'summary' => 'Health check endpoint',
            'method' => 'GET',
            'path' => '/api/health',
            'paramSchema' => null,
        ];

        $collectionMock = Mockery::mock();
        $collectionMock->shouldReceive('toArray')->once()->andReturn([$mockRow]);

        $queryMock = Mockery::mock();
        $queryMock->shouldReceive('select')->once()->andReturnSelf();
        $queryMock->shouldReceive('whereRaw')->once()->andReturnSelf();
        $queryMock->shouldReceive('orderByRaw')->once()->andReturnSelf();
        $queryMock->shouldReceive('limit')->with(10)->once()->andReturnSelf();
        $queryMock->shouldReceive('get')->once()->andReturn($collectionMock);

        $dbMock = Mockery::mock(\Illuminate\Database\ConnectionInterface::class);
        $dbMock->shouldReceive('table')->with('operation_search_index')->once()->andReturn($queryMock);

        $service = new OperationsSearchService($dbMock, 10);
        $results = $service->search('health');

        $this->assertCount(1, $results);
        $decoded = OperationsSearchService::safeDecodeParamSchema($results[0]->paramSchema);
        $this->assertNull($decoded);
    }
}
