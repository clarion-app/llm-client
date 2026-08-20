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
     * tests/Unit/OperationsSearchServiceTest.php's own fixtures do.
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
        ];

        $queryMock = $this->queryMockReturning('deploy the branch', 10, [$projectCommandRow]);

        $dbMock = Mockery::mock(ConnectionInterface::class);
        $dbMock->shouldReceive('table')->with('operation_search_index')->once()->andReturn($queryMock);

        $service = new OperationsSearchService($dbMock, 10);
        $results = $service->search('deploy the branch');

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

        $service = new OperationsSearchService($dbMock, 10);
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

        $service = new OperationsSearchService($dbMock, 10);
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
        ];

        $queryMock = $this->queryMockReturning('deploy', 10, [$projectCommandRow, $builtinRow]);

        $dbMock = Mockery::mock(ConnectionInterface::class);
        $dbMock->shouldReceive('table')->with('operation_search_index')->once()->andReturn($queryMock);

        $service = new OperationsSearchService($dbMock, 10);
        $results = $service->search('deploy');

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
