<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Services\OperationsSearchService;
use Illuminate\Database\ConnectionInterface;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 128-project-command-indexing, Phase 2 (Foundational), T005.
 *
 * OperationsSearchService::search()'s new second parameter,
 * ?string $codingProjectId (contracts/operations-search-service.md), mirrors
 * tests/Unit/OperationsSearchServiceTest.php's own mocked-ConnectionInterface
 * convention exactly: a Mockery::mock() query builder chain is given
 * expectations for select()/whereRaw()/orderByRaw()/limit()/get(), and this
 * file adds the same style of expectation for the new where() call the
 * scoping predicate introduces.
 *
 * Every case here is expected to fail against the current
 * OperationsSearchService::search(string $query, ?int $limit = null): the
 * second parameter does not exist yet, so passing a string as the second
 * positional argument binds it to $limit instead -- a TypeError, not merely
 * a wrong assertion. This is the correct "genuinely red" state.
 */
class OperationsSearchServiceScopingTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Builds a query mock with the pre-feature expectations
     * (select/whereRaw/orderByRaw/limit/get) already wired, exactly as
     * tests/Unit/OperationsSearchServiceTest.php's own fixtures do.
     */
    private function baseQueryMock(string $query, int $limit): \Mockery\MockInterface
    {
        $collectionMock = Mockery::mock();
        $collectionMock->shouldReceive('toArray')->once()->andReturn([]);

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
        $queryMock->shouldReceive('whereRaw')->with('MATCH(type, searchable_text) AGAINST(? IN NATURAL LANGUAGE MODE)', [$query])->once()->andReturnSelf();
        $queryMock->shouldReceive('orderByRaw')->with('MATCH(type, searchable_text) AGAINST(? IN NATURAL LANGUAGE MODE) DESC', [$query])->once()->andReturnSelf();
        $queryMock->shouldReceive('limit')->with($limit)->once()->andReturnSelf();
        $queryMock->shouldReceive('get')->once()->andReturn($collectionMock);

        return $queryMock;
    }

    /**
     * Wires a Mockery::on() expectation for the query builder's where() call
     * that (a) confirms it was handed a Closure and (b) invokes that Closure
     * against its own sub-query mock to assert the exact
     * whereNull('coding_project_id')->orWhere('coding_project_id', $id)
     * shape contracts/operations-search-service.md describes -- the closure-
     * capturing technique the existing file's own whereRaw() bound-parameter
     * assertions use, adapted for a nested closure instead of a flat args
     * array.
     */
    private function expectScopingWhere(\Mockery\MockInterface $queryMock, string $expectedCodingProjectId): void
    {
        $queryMock->shouldReceive('where')->once()->with(Mockery::on(
            function ($closure) use ($expectedCodingProjectId) {
                $this->assertInstanceOf(\Closure::class, $closure, 'the scoping predicate must be passed as a single Closure argument to where()');

                $subQueryMock = Mockery::mock();
                $subQueryMock->shouldReceive('whereNull')->with('coding_project_id')->once()->andReturnSelf();
                $subQueryMock->shouldReceive('orWhere')->with('coding_project_id', $expectedCodingProjectId)->once()->andReturnSelf();

                $closure($subQueryMock);

                return true;
            }
        ))->andReturnSelf();
    }

    #[Test]
    public function search_with_coding_project_id_omitted_builds_the_exact_same_query_as_before(): void
    {
        $queryMock = $this->baseQueryMock('create a contact', 10);
        // No where() expectation is registered at all -- an unexpected call
        // to it on this bare Mockery::mock() double fails the test, which is
        // exactly the "no coding_project_id predicate of any kind" guarantee
        // (contracts/operations-search-service.md postcondition 1).

        $dbMock = Mockery::mock(ConnectionInterface::class);
        $dbMock->shouldReceive('table')->with('operation_search_index')->once()->andReturn($queryMock);

        $service = new OperationsSearchService($dbMock, 10);
        $results = $service->search('create a contact');

        $this->assertIsArray($results);
    }

    #[Test]
    public function search_with_explicit_null_coding_project_id_builds_the_exact_same_query_as_before(): void
    {
        $queryMock = $this->baseQueryMock('create a contact', 10);
        // Same expectation shape as the omitted-argument case above -- an
        // explicit null must behave identically to omitting the parameter.

        $dbMock = Mockery::mock(ConnectionInterface::class);
        $dbMock->shouldReceive('table')->with('operation_search_index')->once()->andReturn($queryMock);

        $service = new OperationsSearchService($dbMock, 10);
        $results = $service->search('create a contact', null);

        $this->assertIsArray($results);
    }

    #[Test]
    public function search_with_a_coding_project_id_adds_the_scoping_where_clause(): void
    {
        $queryMock = $this->baseQueryMock('deploy the branch', 10);
        $this->expectScopingWhere($queryMock, 'project-abc');

        $dbMock = Mockery::mock(ConnectionInterface::class);
        $dbMock->shouldReceive('table')->with('operation_search_index')->once()->andReturn($queryMock);

        $service = new OperationsSearchService($dbMock, 10);
        $results = $service->search('deploy the branch', 'project-abc');

        $this->assertIsArray($results);
    }

    #[Test]
    public function two_scoped_searches_on_the_same_service_instance_produce_independently_correct_bound_parameters(): void
    {
        $queryMockAlpha = $this->baseQueryMock('deploy', 10);
        $this->expectScopingWhere($queryMockAlpha, 'project-alpha');

        $queryMockBeta = $this->baseQueryMock('deploy', 10);
        $this->expectScopingWhere($queryMockBeta, 'project-beta');

        $dbMock = Mockery::mock(ConnectionInterface::class);
        $dbMock->shouldReceive('table')->with('operation_search_index')->twice()->andReturn($queryMockAlpha, $queryMockBeta);

        $service = new OperationsSearchService($dbMock, 10);

        // Back-to-back calls on the same instance -- neither call's bound
        // coding_project_id may leak into, or be overwritten by, the other's
        // (US2's own concurrent-isolation edge case at the query-builder
        // level).
        $resultsAlpha = $service->search('deploy', 'project-alpha');
        $resultsBeta = $service->search('deploy', 'project-beta');

        $this->assertIsArray($resultsAlpha);
        $this->assertIsArray($resultsBeta);
    }
}
