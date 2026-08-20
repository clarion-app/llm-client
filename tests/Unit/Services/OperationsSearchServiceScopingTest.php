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

    // 128-project-command-indexing (Phase 7/US5, collateral fix): the
    // whereNull('coding_project_id')->orWhere('coding_project_id', $id)
    // single-query scoping shape this file originally asserted via a
    // (now-removed) expectScopingWhere() helper is superseded by the
    // two-query design below (research.md D6, contracts/
    // operations-search-service.md postcondition 3) -- every scoped-search
    // test in this file now uses twoQueryScopedMocks() instead.

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

        $service = new OperationsSearchService($dbMock, 10, 5);
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

        $service = new OperationsSearchService($dbMock, 10, 5);
        $results = $service->search('create a contact', null);

        $this->assertIsArray($results);
    }

    /**
     * 128-project-command-indexing (Phase 7/US5, collateral fix): this test
     * predates T040 and originally asserted the single-query
     * whereNull('coding_project_id')->orWhere('coding_project_id', $id)
     * scoping shape. research.md D6 / contracts/operations-search-service.md
     * postcondition 3 replace that shape entirely for a scoped call -- it
     * now always runs the two independently-capped queries T040 introduces
     * below, never the old single-query closure predicate -- so this
     * fixture is updated to the two-query shape (via the same
     * twoQueryScopedMocks() helper T040's own tests use) rather than the
     * superseded one. The property under test (a coding_project_id scopes
     * the search) is unchanged; only the query shape that proves it is.
     */
    #[Test]
    public function search_with_a_coding_project_id_adds_the_scoping_where_clause(): void
    {
        [$builtinQueryMock, $projectQueryMock] = $this->twoQueryScopedMocks(
            'deploy the branch',
            builtinLimit: 10,
            projectLimit: 5,
            codingProjectId: 'project-abc',
            builtinRows: [],
            projectRows: []
        );

        $dbMock = Mockery::mock(ConnectionInterface::class);
        $dbMock->shouldReceive('table')->with('operation_search_index')->twice()
            ->andReturn($builtinQueryMock, $projectQueryMock);

        $service = new OperationsSearchService($dbMock, 10, 5);
        $results = $service->search('deploy the branch', 'project-abc');

        $this->assertIsArray($results);
    }

    /**
     * 128-project-command-indexing (Phase 7/US5, collateral fix): same
     * superseded-shape reasoning as the test above -- updated to the
     * two-query design, each scoped call now consuming two table() calls
     * (builtin then project) instead of one.
     */
    #[Test]
    public function two_scoped_searches_on_the_same_service_instance_produce_independently_correct_bound_parameters(): void
    {
        [$builtinQueryMockAlpha, $projectQueryMockAlpha] = $this->twoQueryScopedMocks(
            'deploy',
            builtinLimit: 10,
            projectLimit: 5,
            codingProjectId: 'project-alpha',
            builtinRows: [],
            projectRows: []
        );

        [$builtinQueryMockBeta, $projectQueryMockBeta] = $this->twoQueryScopedMocks(
            'deploy',
            builtinLimit: 10,
            projectLimit: 5,
            codingProjectId: 'project-beta',
            builtinRows: [],
            projectRows: []
        );

        $dbMock = Mockery::mock(ConnectionInterface::class);
        $dbMock->shouldReceive('table')->with('operation_search_index')->times(4)->andReturn(
            $builtinQueryMockAlpha,
            $projectQueryMockAlpha,
            $builtinQueryMockBeta,
            $projectQueryMockBeta
        );

        $service = new OperationsSearchService($dbMock, 10, 5);

        // Back-to-back calls on the same instance -- neither call's bound
        // coding_project_id may leak into, or be overwritten by, the other's
        // (US2's own concurrent-isolation edge case at the query-builder
        // level).
        $resultsAlpha = $service->search('deploy', 'project-alpha');
        $resultsBeta = $service->search('deploy', 'project-beta');

        $this->assertIsArray($resultsAlpha);
        $this->assertIsArray($resultsBeta);
    }

    /**
     * 128-project-command-indexing, Phase 4 (US2), T021.
     *
     * Extends the Foundational coverage above with the one shape T021 asks
     * for that was not already present: an unscoped search performed
     * immediately after a scoped search on the same service instance must
     * still add no coding_project_id predicate of any kind -- proving the
     * unscoped branch carries no leftover state from the immediately
     * preceding scoped call (the other T021 bullet, two independently-bound
     * scoped calls back-to-back, is already covered above by
     * two_scoped_searches_on_the_same_service_instance_produce_independently_correct_bound_parameters(),
     * which predates this phase and was found already green).
     *
     * 128-project-command-indexing (Phase 7/US5, collateral fix): the scoped
     * leg of this fixture is updated to the two-query shape T040/research.md
     * D6 introduce (superseding the single-query whereNull/orWhere closure
     * this test originally asserted) -- the unscoped leg is untouched, since
     * postcondition 1 requires it stay byte-for-byte identical to before
     * this feature.
     */
    #[Test]
    public function an_unscoped_search_still_adds_no_where_clause_when_it_immediately_follows_a_scoped_search_on_the_same_instance(): void
    {
        [$builtinQueryMockScoped, $projectQueryMockScoped] = $this->twoQueryScopedMocks(
            'deploy',
            builtinLimit: 10,
            projectLimit: 5,
            codingProjectId: 'project-alpha',
            builtinRows: [],
            projectRows: []
        );

        $queryMockUnscoped = $this->baseQueryMock('deploy', 10);
        // No where() expectation is registered at all -- an unexpected call
        // to it on this bare Mockery::mock() double fails the test, exactly
        // as the bare-omitted-argument case above does.

        $dbMock = Mockery::mock(ConnectionInterface::class);
        $dbMock->shouldReceive('table')->with('operation_search_index')->times(3)->andReturn(
            $builtinQueryMockScoped,
            $projectQueryMockScoped,
            $queryMockUnscoped
        );

        $service = new OperationsSearchService($dbMock, 10, 5);

        $scopedResults = $service->search('deploy', 'project-alpha');
        $unscopedResults = $service->search('deploy');

        $this->assertIsArray($scopedResults);
        $this->assertIsArray($unscopedResults);
    }

    /**
     * 128-project-command-indexing, Phase 7 (US5), T040.
     *
     * contracts/operations-search-service.md postcondition 3 / research.md
     * D6: a scoped search no longer runs a single combined query. It runs
     * TWO separately bounded queries -- one for `type != 'project_command'`
     * rows capped at the overall $limit, one for
     * `type = 'project_command' AND coding_project_id = ?` rows capped at
     * `min($limit, project_command_result_cap)` -- then merges both result
     * sets in PHP, sorts by relevance score descending, and truncates to
     * the overall $limit.
     *
     * These tests build the service with an explicit third constructor
     * argument for the project-command cap (mirroring how every existing
     * test in this file and in tests/Unit/OperationsSearchServiceTest.php
     * already passes $defaultLimit explicitly rather than exercising the
     * config()-reading fallback, since this file's tests extend plain
     * PHPUnit\Framework\TestCase with no Laravel container/config bound).
     * research.md D6 states the cap is read the same way $defaultLimit
     * already is -- in the constructor -- so
     * OperationsSearchService::__construct() is expected to gain a fourth
     * (sic: third-after-$db) parameter, ?int $projectCommandCap = null,
     * falling back to
     * config('llm-client.operations_search.project_command_result_cap', 5)
     * exactly as $defaultLimit falls back to
     * config('llm-client.operations_search.default_limit', 10).
     *
     * Every case here is expected to fail against the current
     * OperationsSearchService::search(): it builds exactly one query
     * regardless of $codingProjectId, so a mock expecting table() to be
     * called twice is never satisfied, and/or the single query's own
     * where() call (the old whereNull/orWhere closure predicate) does not
     * match either of the new type-filtering where() expectations below --
     * either way, genuinely red, not by construction of an impossible
     * assertion.
     */

    /**
     * Builds one canned row (as a plain array, to be cast to (object) by
     * the caller) carrying every key contracts/operations-search-service.md
     * postcondition 5 requires, plus a relevanceScore this test file uses
     * purely as its own bookkeeping signal for the expected merge order --
     * not a claim about the returned row shape itself (that guarantee
     * belongs to OperationsSearchServiceProjectCommandLabelTest).
     */
    private function scoredRow(string $operationId, string $type, float $relevanceScore): array
    {
        $isProjectCommand = $type === 'project_command';

        return [
            'operationId' => $operationId,
            'package_name' => $isProjectCommand ? null : '@clarion-app/ops',
            'type' => $type,
            'summary' => ucfirst(str_replace('-', ' ', $operationId)),
            'method' => $isProjectCommand ? null : 'POST',
            'path' => $isProjectCommand ? null : '/api/'.$operationId,
            'paramSchema' => null,
            'promptContent' => $isProjectCommand ? 'Do the '.$operationId.' thing.' : null,
            'relevanceScore' => $relevanceScore,
        ];
    }

    /**
     * Wires the two independent query-mock chains postcondition 3 requires:
     * a `type != 'project_command'` query capped at $builtinLimit, and a
     * `type = 'project_command' AND coding_project_id = ?` query capped at
     * $projectLimit. select()/whereRaw()/orderByRaw() are asserted loosely
     * (call count only, no argument constraint) since this test's subject
     * is the two-query split and the cap values, not the exact select
     * column list (already owned by
     * OperationsSearchServiceProjectCommandLabelTest) -- limit() and the
     * type/coding_project_id where() predicates are asserted exactly, since
     * those are precisely what postcondition 3 guarantees.
     *
     * @return array{0: \Mockery\MockInterface, 1: \Mockery\MockInterface} [builtinQueryMock, projectQueryMock]
     */
    private function twoQueryScopedMocks(
        string $query,
        int $builtinLimit,
        int $projectLimit,
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
        $builtinQueryMock->shouldReceive('limit')->with($builtinLimit)->once()->andReturnSelf();
        $builtinQueryMock->shouldReceive('get')->once()->andReturn($builtinCollectionMock);

        $projectCollectionMock = Mockery::mock();
        $projectCollectionMock->shouldReceive('toArray')->once()->andReturn($projectRows);

        $projectQueryMock = Mockery::mock();
        $projectQueryMock->shouldReceive('select')->once()->andReturnSelf();
        $projectQueryMock->shouldReceive('whereRaw')->once()->andReturnSelf();
        $projectQueryMock->shouldReceive('where')->with('type', 'project_command')->once()->andReturnSelf();
        $projectQueryMock->shouldReceive('where')->with('coding_project_id', $codingProjectId)->once()->andReturnSelf();
        $projectQueryMock->shouldReceive('orderByRaw')->once()->andReturnSelf();
        $projectQueryMock->shouldReceive('limit')->with($projectLimit)->once()->andReturnSelf();
        $projectQueryMock->shouldReceive('get')->once()->andReturn($projectCollectionMock);

        return [$builtinQueryMock, $projectQueryMock];
    }

    #[Test]
    public function search_with_coding_project_id_and_limit_10_caps_the_project_query_at_the_configured_default_of_5(): void
    {
        [$builtinQueryMock, $projectQueryMock] = $this->twoQueryScopedMocks(
            'widget',
            builtinLimit: 10,
            projectLimit: 5,
            codingProjectId: 'project-abc',
            builtinRows: [],
            projectRows: []
        );

        $dbMock = Mockery::mock(ConnectionInterface::class);
        $dbMock->shouldReceive('table')->with('operation_search_index')->twice()
            ->andReturn($builtinQueryMock, $projectQueryMock);

        // projectCommandCap explicitly 5, matching the config default this
        // scenario stands in for.
        $service = new OperationsSearchService($dbMock, 10, 5);
        $results = $service->search('widget', 'project-abc', 10);

        $this->assertIsArray($results);
    }

    #[Test]
    public function search_with_coding_project_id_uses_a_custom_configured_cap_when_it_is_smaller_than_the_limit(): void
    {
        [$builtinQueryMock, $projectQueryMock] = $this->twoQueryScopedMocks(
            'widget',
            builtinLimit: 10,
            projectLimit: 2,
            codingProjectId: 'project-abc',
            builtinRows: [],
            projectRows: []
        );

        $dbMock = Mockery::mock(ConnectionInterface::class);
        $dbMock->shouldReceive('table')->with('operation_search_index')->twice()
            ->andReturn($builtinQueryMock, $projectQueryMock);

        // A cap tighter than the config default of 5 must be honored, not
        // silently ignored in favor of the hardcoded default -- proving the
        // implementation genuinely computes min($limit, $cap) rather than
        // always using 5.
        $service = new OperationsSearchService($dbMock, 10, 2);
        $results = $service->search('widget', 'project-abc', 10);

        $this->assertIsArray($results);
    }

    #[Test]
    public function search_with_coding_project_id_caps_the_project_query_at_the_overall_limit_when_the_limit_is_smaller_than_the_cap(): void
    {
        [$builtinQueryMock, $projectQueryMock] = $this->twoQueryScopedMocks(
            'widget',
            builtinLimit: 3,
            projectLimit: 3,
            codingProjectId: 'project-abc',
            builtinRows: [],
            projectRows: []
        );

        $dbMock = Mockery::mock(ConnectionInterface::class);
        $dbMock->shouldReceive('table')->with('operation_search_index')->twice()
            ->andReturn($builtinQueryMock, $projectQueryMock);

        // The configured cap (default 5) is larger than the overall $limit
        // (3) here -- the project query must still be bounded by the
        // smaller of the two (min($limit, $cap) = 3), never by the cap
        // alone, or a scoped search could return only project_command rows
        // (research.md D6's own rejected-alternative reasoning).
        $service = new OperationsSearchService($dbMock, 10, 5);
        $results = $service->search('widget', 'project-abc', 3);

        $this->assertIsArray($results);
    }

    #[Test]
    public function search_with_coding_project_id_merges_both_result_sets_by_relevance_score_descending_before_truncating_to_the_overall_limit(): void
    {
        // Each list is already in its own per-query relevance order (as
        // orderByRaw(...DESC) would produce) -- the point under test is
        // that the SERVICE merges these two already-sorted lists by score
        // across queries, rather than simply concatenating query A's rows
        // before query B's. A naive concatenation would yield
        // [op-a, op-b, op-c, proj-x, proj-y, proj-z]; a correct
        // score-descending merge yields
        // [op-a(9.0), proj-x(8.0), proj-y(7.0), op-b(6.0), op-c(2.0), proj-z(1.0)].
        // Truncating that merged order to the overall $limit of 4 must keep
        // the top 4 by score -- op-a, proj-x, proj-y, op-b -- dropping
        // op-c and proj-z even though each was within its own per-query
        // limit.
        $builtinRows = [
            (object) $this->scoredRow('op-a', 'operation', 9.0),
            (object) $this->scoredRow('op-b', 'operation', 6.0),
            (object) $this->scoredRow('op-c', 'operation', 2.0),
        ];
        $projectRows = [
            (object) $this->scoredRow('proj-x', 'project_command', 8.0),
            (object) $this->scoredRow('proj-y', 'project_command', 7.0),
            (object) $this->scoredRow('proj-z', 'project_command', 1.0),
        ];

        [$builtinQueryMock, $projectQueryMock] = $this->twoQueryScopedMocks(
            'widget',
            builtinLimit: 4,
            projectLimit: 4,
            codingProjectId: 'project-abc',
            builtinRows: $builtinRows,
            projectRows: $projectRows
        );

        $dbMock = Mockery::mock(ConnectionInterface::class);
        $dbMock->shouldReceive('table')->with('operation_search_index')->twice()
            ->andReturn($builtinQueryMock, $projectQueryMock);

        $service = new OperationsSearchService($dbMock, 10, 5);
        $results = $service->search('widget', 'project-abc', 4);

        $this->assertCount(
            4,
            $results,
            'the merged set must be truncated to the overall $limit (4), not left at the combined raw count of 6'
        );
        $this->assertSame(
            ['op-a', 'proj-x', 'proj-y', 'op-b'],
            array_map(fn ($row) => $row->operationId, $results),
            'results from both queries must be merged and sorted by relevance score descending, not concatenated '
            .'query-by-query'
        );
    }
}
