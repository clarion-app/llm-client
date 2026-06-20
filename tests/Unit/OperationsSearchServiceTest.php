<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ClarionApp\LlmClient\Services\OperationsSearchService;
use Illuminate\Database\ConnectionInterface;
use Mockery;

class OperationsSearchServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function search_with_populated_index_returns_ranked_results()
    {
        $mockRow1 = (object) [
            'operationId' => 'contacts.store',
            'type' => 'operation',
            'summary' => 'Store a new contact',
            'method' => 'POST',
            'path' => '/api/contacts',
            'paramSchema' => json_encode(['body_name' => ['type' => 'string', 'in' => 'body', 'required' => true]]),
            'promptContent' => null,
        ];
        $mockRow2 = (object) [
            'operationId' => 'contacts.index',
            'type' => 'operation',
            'summary' => 'List all contacts',
            'method' => 'GET',
            'path' => '/api/contacts',
            'paramSchema' => null,
            'promptContent' => null,
        ];

        $collectionMock = Mockery::mock();
        $collectionMock->shouldReceive('toArray')->once()->andReturn([$mockRow1, $mockRow2]);

        $queryMock = Mockery::mock();
        $queryMock->shouldReceive('select')->with(
            'operation_id as operationId',
            'package_name',
            'type',
            'summary',
            'method',
            'path',
            'param_schema as paramSchema',
            'prompt_content as promptContent'
        )->once()->andReturnSelf();
        $queryMock->shouldReceive('whereRaw')->with('MATCH(searchable_text) AGAINST(? IN NATURAL LANGUAGE MODE)', ['create a contact'])->once()->andReturnSelf();
        $queryMock->shouldReceive('orderByRaw')->with('MATCH(searchable_text) AGAINST(? IN NATURAL LANGUAGE MODE) DESC', ['create a contact'])->once()->andReturnSelf();
        $queryMock->shouldReceive('limit')->with(10)->once()->andReturnSelf();
        $queryMock->shouldReceive('get')->once()->andReturn($collectionMock);

        $dbMock = Mockery::mock(ConnectionInterface::class);
        $dbMock->shouldReceive('table')->with('operation_search_index')->once()->andReturn($queryMock);

        $service = new OperationsSearchService($dbMock, 10);
        $results = $service->search('create a contact');

        $this->assertCount(2, $results);
        $this->assertEquals('contacts.store', $results[0]->operationId);
        $this->assertEquals('POST', $results[0]->method);
        $this->assertEquals('/api/contacts', $results[0]->path);
        $this->assertEquals('contacts.index', $results[1]->operationId);
    }

    /** @test */
    public function search_with_empty_index_returns_empty_array()
    {
        $collectionMock = Mockery::mock();
        $collectionMock->shouldReceive('toArray')->once()->andReturn([]);

        $queryMock = Mockery::mock();
        $queryMock->shouldReceive('select')->once()->andReturnSelf();
        $queryMock->shouldReceive('whereRaw')->once()->andReturnSelf();
        $queryMock->shouldReceive('orderByRaw')->once()->andReturnSelf();
        $queryMock->shouldReceive('limit')->with(10)->once()->andReturnSelf();
        $queryMock->shouldReceive('get')->once()->andReturn($collectionMock);

        $dbMock = Mockery::mock(ConnectionInterface::class);
        $dbMock->shouldReceive('table')->with('operation_search_index')->once()->andReturn($queryMock);

        $service = new OperationsSearchService($dbMock, 10);
        $results = $service->search('something');

        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    /** @test */
    public function search_with_no_matches_returns_empty_array()
    {
        $collectionMock = Mockery::mock();
        $collectionMock->shouldReceive('toArray')->once()->andReturn([]);

        $queryMock = Mockery::mock();
        $queryMock->shouldReceive('select')->once()->andReturnSelf();
        $queryMock->shouldReceive('whereRaw')->once()->andReturnSelf();
        $queryMock->shouldReceive('orderByRaw')->once()->andReturnSelf();
        $queryMock->shouldReceive('limit')->with(10)->once()->andReturnSelf();
        $queryMock->shouldReceive('get')->once()->andReturn($collectionMock);

        $dbMock = Mockery::mock(ConnectionInterface::class);
        $dbMock->shouldReceive('table')->with('operation_search_index')->once()->andReturn($queryMock);

        $service = new OperationsSearchService($dbMock, 10);
        $results = $service->search('xyz nonmatching query');

        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    /** @test */
    public function search_uses_custom_limit_when_provided()
    {
        $collectionMock = Mockery::mock();
        $collectionMock->shouldReceive('toArray')->once()->andReturn([]);

        $queryMock = Mockery::mock();
        $queryMock->shouldReceive('select')->once()->andReturnSelf();
        $queryMock->shouldReceive('whereRaw')->once()->andReturnSelf();
        $queryMock->shouldReceive('orderByRaw')->once()->andReturnSelf();
        $queryMock->shouldReceive('limit')->with(5)->once()->andReturnSelf();
        $queryMock->shouldReceive('get')->once()->andReturn($collectionMock);

        $dbMock = Mockery::mock(ConnectionInterface::class);
        $dbMock->shouldReceive('table')->with('operation_search_index')->once()->andReturn($queryMock);

        $service = new OperationsSearchService($dbMock, 10);
        $results = $service->search('test', 5);

        $this->assertIsArray($results);
    }

    /** @test */
    public function search_uses_config_default_limit()
    {
        $collectionMock = Mockery::mock();
        $collectionMock->shouldReceive('toArray')->once()->andReturn([]);

        $queryMock = Mockery::mock();
        $queryMock->shouldReceive('select')->once()->andReturnSelf();
        $queryMock->shouldReceive('whereRaw')->once()->andReturnSelf();
        $queryMock->shouldReceive('orderByRaw')->once()->andReturnSelf();
        $queryMock->shouldReceive('limit')->with(20)->once()->andReturnSelf();
        $queryMock->shouldReceive('get')->once()->andReturn($collectionMock);

        $dbMock = Mockery::mock(ConnectionInterface::class);
        $dbMock->shouldReceive('table')->with('operation_search_index')->once()->andReturn($queryMock);

        $service = new OperationsSearchService($dbMock, 20);
        $results = $service->search('test');

        $this->assertIsArray($results);
    }

    /** @test */
    public function table_exists_returns_true_when_table_is_present()
    {
        $schemaBuilderMock = Mockery::mock();
        $schemaBuilderMock->shouldReceive('hasTable')
            ->with('operation_search_index')
            ->once()
            ->andReturn(true);

        $dbMock = Mockery::mock(ConnectionInterface::class);
        $dbMock->shouldReceive('getSchemaBuilder')->once()->andReturn($schemaBuilderMock);

        $service = new OperationsSearchService($dbMock, 10);
        $this->assertTrue($service->tableExists());
    }

    /** @test */
    public function table_exists_returns_false_when_table_is_absent()
    {
        $schemaBuilderMock = Mockery::mock();
        $schemaBuilderMock->shouldReceive('hasTable')
            ->with('operation_search_index')
            ->once()
            ->andReturn(false);

        $dbMock = Mockery::mock(ConnectionInterface::class);
        $dbMock->shouldReceive('getSchemaBuilder')->once()->andReturn($schemaBuilderMock);

        $service = new OperationsSearchService($dbMock, 10);
        $this->assertFalse($service->tableExists());
    }

    /** @test */
    public function safe_decode_param_schema_returns_array_for_valid_json()
    {
        $json = json_encode(['path' => [['name' => 'id', 'type' => 'string']]]);
        $result = OperationsSearchService::safeDecodeParamSchema($json);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('path', $result);
        $this->assertEquals('id', $result['path'][0]['name']);
    }

    /** @test */
    public function safe_decode_param_schema_returns_null_for_malformed_json()
    {
        $result = OperationsSearchService::safeDecodeParamSchema('{invalid json');

        $this->assertNull($result);
    }

    /** @test */
    public function safe_decode_param_schema_returns_null_for_null_input()
    {
        $result = OperationsSearchService::safeDecodeParamSchema(null);

        $this->assertNull($result);
    }

    /** @test */
    public function safe_decode_param_schema_returns_null_for_empty_string()
    {
        $result = OperationsSearchService::safeDecodeParamSchema('');

        $this->assertNull($result);
    }

    /** @test */
    public function safe_decode_param_schema_returns_array_when_input_is_already_array()
    {
        $input = ['body' => [['name' => 'name', 'type' => 'string']]];
        $result = OperationsSearchService::safeDecodeParamSchema($input);

        $this->assertIsArray($result);
        $this->assertEquals($input, $result);
    }
}
