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
            'summary' => 'Store a new contact',
            'method' => 'POST',
            'path' => '/api/contacts',
            'paramSchema' => json_encode(['body_name' => ['type' => 'string', 'in' => 'body', 'required' => true]]),
        ];
        $mockRow2 = (object) [
            'operationId' => 'contacts.index',
            'summary' => 'List all contacts',
            'method' => 'GET',
            'path' => '/api/contacts',
            'paramSchema' => null,
        ];

        $collectionMock = Mockery::mock();
        $collectionMock->shouldReceive('toArray')->once()->andReturn([$mockRow1, $mockRow2]);

        $queryMock = Mockery::mock();
        $queryMock->shouldReceive('select')->with(
            'operation_id as operationId',
            'summary',
            'method',
            'path',
            'param_schema as paramSchema'
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
}
