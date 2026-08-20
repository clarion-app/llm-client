<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\LlmClient\Services\OperationsSearchService;
use Illuminate\Database\ConnectionInterface;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 128-project-command-indexing, Phase 3 (US1), T013.
 *
 * OperationsSearchService::search() itself never transforms a row by type
 * (contracts/operations-search-service.md postcondition 5) -- the label a
 * caller ultimately shows the model is computed downstream, by
 * AgentLoopService::handleSearchOperations(), from the row's existing
 * `type` value. This file proves the precondition that caller-side labeling
 * depends on: a `type = 'project_command'` row survives search() with every
 * field data-model.md §2 says a caller needs to render it as project-sourced
 * with a description -- `type` itself, `summary`, `promptContent`, and an
 * `operationId` that decomposes back into `{coding_project_id}:{name}` --
 * with no further lookup, and a pre-existing `'operation'`/`'prompt'` row's
 * key set stays byte-identical to today, exactly as
 * tests/Unit/OperationsSearchServiceTest.php's own fixtures already assert.
 *
 * Mirrors tests/Unit/Services/OperationsSearchServiceScopingTest.php's own
 * mocked-ConnectionInterface convention (query builder chain expectations
 * for select()/whereRaw()/orderByRaw()/limit()/get()).
 */
class OperationsSearchServiceProjectCommandLabelTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** Every key search() currently selects, per contracts/operations-search-service.md postcondition 5. */
    private const EXPECTED_KEYS = [
        'operationId',
        'package_name',
        'type',
        'summary',
        'method',
        'path',
        'paramSchema',
        'promptContent',
    ];

    /**
     * Builds a query mock with the pre-feature expectations
     * (select/whereRaw/orderByRaw/limit/get) already wired, returning the
     * given rows verbatim -- exactly as
     * tests/Unit/OperationsSearchServiceTest.php's own fixtures do. Used
     * only by this file's unscoped cases, whose query shape is unchanged
     * by Phase 7 (contracts/operations-search-service.md postcondition 1).
     */
    private function queryMockReturning(string $query, int $limit, array $rows): \Mockery\MockInterface
    {
        $collectionMock = Mockery::mock();
        $collectionMock->shouldReceive('toArray')->once()->andReturn($rows);

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
     * 128-project-command-indexing (Phase 7/US5, collateral fix): a scoped
     * search() call no longer issues a single query with a
     * whereNull/orWhere('coding_project_id') predicate -- research.md D6 /
     * contracts/operations-search-service.md postcondition 3 replace it with
     * two independently-capped queries (builtin/global rows, and this
     * workspace's own project_command rows), merged and sorted by relevance
     * in PHP. This file's own subject is row-shape pass-through fidelity,
     * not the two-query split itself (OperationsSearchServiceScopingTest
     * owns that), so the two query mocks below are wired loosely -- only
     * the row each query returns is asserted, via its own $rows -- and
     * built to mirror OperationsSearchServiceScopingTest::twoQueryScopedMocks()
     * exactly, since both files' fixtures must match the same production
     * query shape.
     *
     * @return array{0: \Mockery\MockInterface, 1: \Mockery\MockInterface} [builtinQueryMock, projectQueryMock]
     */
    private function twoQueryMocksReturning(
        string $query,
        int $limit,
        int $projectCommandCap,
        string $codingProjectId,
        array $builtinRows,
        array $projectRows
    ): array {
        $builtinCollectionMock = Mockery::mock();
        $builtinCollectionMock->shouldReceive('toArray')->once()->andReturn($builtinRows);

        $builtinQueryMock = Mockery::mock();
        $builtinQueryMock->shouldReceive('select')->once()->andReturnSelf();
        $builtinQueryMock->shouldReceive('whereRaw')->once()->andReturnSelf();
        $builtinQueryMock->shouldReceive('where')->with('type', '!=', 'project_command')->once()->andReturnSelf();
        $builtinQueryMock->shouldReceive('orderByRaw')->once()->andReturnSelf();
        $builtinQueryMock->shouldReceive('limit')->with($limit)->once()->andReturnSelf();
        $builtinQueryMock->shouldReceive('get')->once()->andReturn($builtinCollectionMock);

        $projectCollectionMock = Mockery::mock();
        $projectCollectionMock->shouldReceive('toArray')->once()->andReturn($projectRows);

        $projectQueryMock = Mockery::mock();
        $projectQueryMock->shouldReceive('select')->once()->andReturnSelf();
        $projectQueryMock->shouldReceive('whereRaw')->once()->andReturnSelf();
        $projectQueryMock->shouldReceive('where')->with('type', 'project_command')->once()->andReturnSelf();
        $projectQueryMock->shouldReceive('where')->with('coding_project_id', $codingProjectId)->once()->andReturnSelf();
        $projectQueryMock->shouldReceive('orderByRaw')->once()->andReturnSelf();
        $projectQueryMock->shouldReceive('limit')->with(min($limit, $projectCommandCap))->once()->andReturnSelf();
        $projectQueryMock->shouldReceive('get')->once()->andReturn($projectCollectionMock);

        return [$builtinQueryMock, $projectQueryMock];
    }

    #[Test]
    public function a_project_command_row_carries_type_summary_and_content_through_untouched(): void
    {
        $projectCommandRow = (object) [
            'operationId' => 'coding-project-abc:deploy',
            'package_name' => null,
            'type' => 'project_command',
            'coding_project_id' => 'coding-project-abc',
            'summary' => 'Deploy the current branch',
            'method' => null,
            'path' => null,
            'paramSchema' => null,
            'promptContent' => 'Run the deploy script for $ARGUMENTS and report the result.',
            'relevanceScore' => 8.0,
        ];

        // 128-project-command-indexing (Phase 7/US5, collateral fix): a
        // scoped call now runs two independently-capped queries instead of
        // one (research.md D6) -- the builtin/global leg returns nothing
        // here, the project leg returns this row.
        [$builtinQueryMock, $projectQueryMock] = $this->twoQueryMocksReturning(
            'deploy the branch',
            limit: 10,
            projectCommandCap: 5,
            codingProjectId: 'coding-project-abc',
            builtinRows: [],
            projectRows: [$projectCommandRow]
        );

        $dbMock = Mockery::mock(ConnectionInterface::class);
        $dbMock->shouldReceive('table')->with('operation_search_index')->twice()
            ->andReturn($builtinQueryMock, $projectQueryMock);

        $service = new OperationsSearchService($dbMock, 10, 5);

        // 128-project-command-indexing (Phase 4/US2 fix): a 'project_command'
        // row can now only ever legitimately survive search() when the call
        // is scoped to that row's own workspace -- an unscoped call filters
        // any such row out (contracts/operations-search-service.md
        // postcondition 1, FR-003). This test's own subject is field
        // pass-through fidelity for a project-command row, not the scoping
        // predicate itself (OperationsSearchServiceScopingTest owns that),
        // so the call is scoped to the row's own coding_project_id here --
        // the only realistic scenario in which this row shape is ever
        // actually returned.
        $results = $service->search('deploy the branch', 'coding-project-abc');

        $this->assertCount(1, $results);
        $row = $results[0];

        // The label ('Project command' vs 'Built-in capability') is computed
        // by the caller from this field -- it must survive intact.
        $this->assertSame('project_command', $row->type);

        // The description/content a caller shows without any further lookup.
        $this->assertSame('Deploy the current branch', $row->summary);
        $this->assertSame('Run the deploy script for $ARGUMENTS and report the result.', $row->promptContent);

        // The underlying command identity decomposes back into workspace +
        // command name from operationId alone (data-model.md §2) -- no
        // lookup against a separate coding_project_id field is required.
        [$decomposedProjectId, $decomposedName] = explode(':', $row->operationId, 2);
        $this->assertSame('coding-project-abc', $decomposedProjectId);
        $this->assertSame('deploy', $decomposedName);

        // A project-command row has no owning package, method, or path --
        // these must arrive as null, not silently dropped or coerced.
        $this->assertNull($row->package_name);
        $this->assertNull($row->method);
        $this->assertNull($row->path);
    }

    #[Test]
    public function an_operation_typed_row_keeps_its_exact_pre_feature_key_set(): void
    {
        $operationRow = (object) [
            'operationId' => 'contacts.store',
            'package_name' => '@clarion-app/contacts',
            'type' => 'operation',
            'summary' => 'Store a new contact',
            'method' => 'POST',
            'path' => '/api/contacts',
            'paramSchema' => json_encode(['body' => ['name' => ['type' => 'string', 'in' => 'body', 'required' => true]]]),
            'promptContent' => null,
        ];

        $queryMock = $this->queryMockReturning('create a contact', 10, [$operationRow]);

        $dbMock = Mockery::mock(ConnectionInterface::class);
        $dbMock->shouldReceive('table')->with('operation_search_index')->once()->andReturn($queryMock);

        $service = new OperationsSearchService($dbMock, 10, 5);
        $results = $service->search('create a contact');

        $this->assertCount(1, $results);
        $row = $results[0];

        $this->assertSame('operation', $row->type);
        $this->assertSame('contacts.store', $row->operationId);
        $this->assertSame('@clarion-app/contacts', $row->package_name);
        $this->assertSame('POST', $row->method);
        $this->assertSame('/api/contacts', $row->path);

        // No new key (e.g. coding_project_id) is ever added to a
        // non-project row's shape -- byte-identical to before this feature.
        $actualKeys = array_keys(get_object_vars($row));
        sort($actualKeys);
        $expectedKeys = self::EXPECTED_KEYS;
        sort($expectedKeys);
        $this->assertSame($expectedKeys, $actualKeys);
    }

    #[Test]
    public function a_prompt_typed_row_keeps_its_exact_pre_feature_key_set(): void
    {
        $promptRow = (object) [
            'operationId' => 'wizlights_scene_evening',
            'package_name' => '@clarion-app/wizlights',
            'type' => 'prompt',
            'summary' => 'Set an evening lighting scene',
            'method' => null,
            'path' => null,
            'paramSchema' => null,
            'promptContent' => 'Dim every light to a warm evening scene.',
        ];

        $queryMock = $this->queryMockReturning('evening scene', 10, [$promptRow]);

        $dbMock = Mockery::mock(ConnectionInterface::class);
        $dbMock->shouldReceive('table')->with('operation_search_index')->once()->andReturn($queryMock);

        $service = new OperationsSearchService($dbMock, 10, 5);
        $results = $service->search('evening scene');

        $this->assertCount(1, $results);
        $row = $results[0];

        $this->assertSame('prompt', $row->type);
        $this->assertSame('Dim every light to a warm evening scene.', $row->promptContent);

        $actualKeys = array_keys(get_object_vars($row));
        sort($actualKeys);
        $expectedKeys = self::EXPECTED_KEYS;
        sort($expectedKeys);
        $this->assertSame($expectedKeys, $actualKeys);
    }

    #[Test]
    public function a_mixed_result_set_preserves_each_rows_own_type_independently(): void
    {
        $projectCommandRow = (object) [
            'operationId' => 'coding-project-xyz:deploy',
            'package_name' => null,
            'type' => 'project_command',
            'coding_project_id' => 'coding-project-xyz',
            'summary' => 'Deploy the current branch (project)',
            'method' => null,
            'path' => null,
            'paramSchema' => null,
            'promptContent' => 'Project deploy instructions.',
            // 128-project-command-indexing (Phase 7/US5, collateral fix):
            // the merged, scored result set is now sorted by relevanceScore
            // descending (research.md D6) rather than concatenated
            // query-by-query, so this row is given a higher score than
            // $builtinRow below to keep this test's own expected ordering
            // (project row first, builtin row second) -- the property under
            // test (each row keeps its own type/operationId independently)
            // is otherwise unchanged.
            'relevanceScore' => 9.0,
        ];
        $builtinRow = (object) [
            'operationId' => 'deploy',
            'package_name' => '@clarion-app/ops',
            'type' => 'operation',
            'summary' => 'Deploy the application to production',
            'method' => 'POST',
            'path' => '/api/deploy',
            'paramSchema' => null,
            'promptContent' => null,
            'relevanceScore' => 5.0,
        ];

        // 128-project-command-indexing (Phase 7/US5, collateral fix): a
        // scoped call now runs two independently-capped queries instead of
        // one -- the builtin/global leg returns $builtinRow, the project leg
        // returns $projectCommandRow.
        [$builtinQueryMock, $projectQueryMock] = $this->twoQueryMocksReturning(
            'deploy',
            limit: 10,
            projectCommandCap: 5,
            codingProjectId: 'coding-project-xyz',
            builtinRows: [$builtinRow],
            projectRows: [$projectCommandRow]
        );

        $dbMock = Mockery::mock(ConnectionInterface::class);
        $dbMock->shouldReceive('table')->with('operation_search_index')->twice()
            ->andReturn($builtinQueryMock, $projectQueryMock);

        $service = new OperationsSearchService($dbMock, 10, 5);

        // 128-project-command-indexing (Phase 4/US2 fix): same reasoning as
        // the single-row case above -- a 'project_command' row only ever
        // legitimately survives a search scoped to its own workspace, so
        // this mixed-set fixture is scoped to the project-command row's own
        // coding_project_id.
        $results = $service->search('deploy', 'coding-project-xyz');

        $this->assertCount(2, $results);
        $this->assertSame('project_command', $results[0]->type);
        $this->assertSame('operation', $results[1]->type);

        // Neither row is dropped, overwritten, or merged despite the
        // colliding short name (research.md D5 -- two distinct rows, no
        // winner/loser computed at the search() layer).
        $this->assertSame('coding-project-xyz:deploy', $results[0]->operationId);
        $this->assertSame('deploy', $results[1]->operationId);
    }
}
