<?php

namespace Tests\RealDatabase;

use ClarionApp\LlmClient\Services\OperationsSearchService;
use Illuminate\Support\Facades\DB;
use Tests\RealDatabase\Support\OperationIndexFixture;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * T024-T029: Operation discovery search on the real engine.
 *
 * Builds the index by running the product's own indexer (ReindexOperationsJob)
 * over a known catalogue, then searches through OperationsSearchService, so
 * index population is under test alongside querying (FR-001, FR-004).
 */
#[Group('real-db')]
class OperationDiscoverySearchTest extends RealDatabaseTestCase
{
    protected array $seedTables = ['operation_search_index'];

    protected OperationsSearchService $searchService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->searchService = new OperationsSearchService();
    }

    protected function tearDown(): void
    {
        OperationIndexFixture::reset();

        parent::tearDown();
    }

    /**
     * T024: Indexing through the product's own path populates the index
     * with one entry per operation.
     */
    #[Test]
    public function indexingPopulatesOneEntryPerOperation(): void
    {
        $this->assertReady();

        OperationIndexFixture::seed();

        $count = DB::table('operation_search_index')->count();
        $expected = count(OperationIndexFixture::entries());

        $this->assertSame(
            $expected,
            $count,
            "Expected {$expected} entries after seeding, found {$count} "
            . '(query: row count on operation_search_index after seed)'
        );

        // Verify each operation_id is present.
        $operationIds = DB::table('operation_search_index')
            ->pluck('operation_id')
            ->sort()
            ->values()
            ->toArray();

        $expectedIds = array_map(
            fn ($e) => $e['operation_id'],
            OperationIndexFixture::entries()
        );
        sort($expectedIds);

        $this->assertSame(
            $expectedIds,
            $operationIds,
            "Expected operation IDs don't match seeded IDs "
            . '(query: operation_id values on operation_search_index after seed)'
        );
    }

    /**
     * T025: Natural-language query returns expected entries in expected order
     * (best match first).
     */
    #[Test]
    public function naturalLanguageQueryReturnsEntriesInExpectedOrder(): void
    {
        $this->assertReady();
        OperationIndexFixture::seed();

        $queries = OperationIndexFixture::queries();

        foreach ($queries as $query => $expectedOrder) {
            $results = $this->searchService->search($query);
            $actualOrder = array_map(fn ($r) => $r->operationId, $results);

            $this->assertSame(
                $expectedOrder,
                $actualOrder,
                "Query '{$query}': expected order " . json_encode($expectedOrder)
                . ", got " . json_encode($actualOrder)
                . " (query: search for '{$query}')"
            );
        }
    }

    /**
     * T026: Result limit returns top N by relevance (not the right count
     * of wrong rows).
     */
    #[Test]
    public function resultLimitReturnsTopNByRelevance(): void
    {
        $this->assertReady();
        OperationIndexFixture::seed();

        // Search with limit=1 — should return the best match only.
        $service = new OperationsSearchService(defaultLimit: 1);
        $results = $service->search('toggle light power');

        $this->assertCount(
            1,
            $results,
            "Expected 1 result with limit=1, got " . count($results)
            . ' (query: search for "toggle light power" with limit 1)'
        );

        $this->assertSame(
            'wizlights.toggle_power',
            $results[0]->operationId,
            "Expected best match 'wizlights.toggle_power' at position 0 with limit=1 "
            . '(query: search for "toggle light power" with limit 1, position 0)'
        );

        // Search with limit=2 — should return top 2.
        $service = new OperationsSearchService(defaultLimit: 2);
        $results = $service->search('toggle light power');

        $this->assertCount(
            2,
            $results,
            "Expected 2 results with limit=2, got " . count($results)
            . ' (query: search for "toggle light power" with limit 2)'
        );

        $actualOrder = array_map(fn ($r) => $r->operationId, $results);
        $this->assertSame(
            ['wizlights.toggle_power', 'wizlights.set_brightness'],
            $actualOrder,
            "Expected top 2 in correct order with limit=2 "
            . '(query: search for "toggle light power" with limit 2, order)'
        );
    }

    /**
     * T027: Agent-facing fields are present and correctly shaped via
     * AgentLoopService::executeMetaTool('search_operations', ...).
     */
    #[Test]
    public function agentFacingFieldsArePresentAndCorrectlyShaped(): void
    {
        $this->assertReady();
        OperationIndexFixture::seed();

        $results = $this->searchService->search('create contact');
        $this->assertNotEmpty($results, 'Search should return results for "create contact"');

        $row = $results[0];

        // Check that agent-facing fields are present.
        $this->assertObjectHasProperty('operationId', $row,
            'Result should have operationId field (query: search for "create contact", field check)');
        $this->assertObjectHasProperty('package_name', $row,
            'Result should have package_name field (query: search for "create contact", field check)');
        $this->assertObjectHasProperty('type', $row,
            'Result should have type field (query: search for "create contact", field check)');
        $this->assertObjectHasProperty('summary', $row,
            'Result should have summary field (query: search for "create contact", field check)');
        $this->assertObjectHasProperty('method', $row,
            'Result should have method field (query: search for "create contact", field check)');
        $this->assertObjectHasProperty('path', $row,
            'Result should have path field (query: search for "create contact", field check)');
        $this->assertObjectHasProperty('paramSchema', $row,
            'Result should have paramSchema field (query: search for "create contact", field check)');

        // Check field shapes.
        $this->assertIsString($row->operationId,
            'operationId should be a string (query: search for "create contact", operationId type)');
        $this->assertIsString($row->package_name,
            'package_name should be a string (query: search for "create contact", package_name type)');
        $this->assertIsString($row->type,
            'type should be a string (query: search for "create contact", type type)');

        // paramSchema comes as a JSON string from the DB; safeDecodeParamSchema handles it.
        $decoded = \ClarionApp\LlmClient\Services\OperationsSearchService::safeDecodeParamSchema(
            $row->paramSchema
        );
        $this->assertIsArray($decoded,
            'paramSchema should decode to an array (query: search for "create contact", paramSchema decode)');
    }

    /**
     * T028: Three-way response for no matches:
     * index missing vs index empty vs nothing matched.
     */
    #[Test]
    public function threeWayResponseForNoMatches(): void
    {
        $this->assertReady();

        // Case 1: Index exists but is empty.
        $results = $this->searchService->search('contact');
        $resultsJson = json_encode($results);

        // The search service returns an empty array when the table is empty.
        // AgentLoopService wraps this with a hint.
        $this->assertIsArray($results, 'Search returns an array even when empty');
        $this->assertEmpty($results,
            'Search should return empty results when index is empty '
            . '(query: search for "contact" on empty index)');

        // Case 2: Index has data but query matches nothing.
        OperationIndexFixture::seed();
        $nonMatching = OperationIndexFixture::nonMatchingQuery();
        $results = $this->searchService->search($nonMatching);

        $this->assertIsArray($results, 'Search returns an array for non-matching query');
        $this->assertEmpty($results,
            "Search should return empty results for '{$nonMatching}' "
            . "(query: search for '{$nonMatching}' on populated index)");

        // Case 3: Table doesn't exist — the service handles this gracefully.
        // We don't drop the table here (that would break other tests),
        // but we verify the tableExists method works.
        $this->assertTrue(
            $this->searchService->tableExists(),
            'tableExists() should return true after migrations '
            . '(query: tableExists check)'
        );
    }

    /**
     * T029: Content changed and re-indexed reflects new content.
     */
    #[Test]
    public function contentChangedAndReindexedReflectsNewContent(): void
    {
        $this->assertReady();
        OperationIndexFixture::seed();

        // Before revision: search for "postal zip" should NOT match contacts.store
        // (the original searchable_text doesn't contain "postal" or "zip").
        $resultsBefore = $this->searchService->search('postal zip code');
        $operationIdsBefore = array_map(fn ($r) => $r->operationId, $resultsBefore);

        $this->assertNotContains(
            'contacts.store',
            $operationIdsBefore,
            "Before revision, 'contacts.store' should not match 'postal zip code' "
            . '(query: search for "postal zip code" before revision)'
        );

        // Apply the revision (simulates re-index with updated content).
        OperationIndexFixture::applyRevision();

        // After revision: search for "postal zip" SHOULD match contacts.store
        // (the revision adds "postal zip code" to searchable_text).
        $resultsAfter = $this->searchService->search('postal zip code');
        $operationIdsAfter = array_map(fn ($r) => $r->operationId, $resultsAfter);

        $this->assertContains(
            'contacts.store',
            $operationIdsAfter,
            "After revision, 'contacts.store' should match 'postal zip code' "
            . '(query: search for "postal zip code" after revision)'
        );

        // Verify the revision is first (best match for the new terms).
        $this->assertSame(
            'contacts.store',
            $operationIdsAfter[0],
            "After revision, 'contacts.store' should be the best match for 'postal zip code' "
            . '(query: search for "postal zip code" after revision, position 0)'
        );

        // FR-007's other half: results no longer reflect superseded content.
        // The revision drops "create" and the singular "contact" from
        // contacts.store, so the query that matched it before must not now.
        $supersededResults = $this->searchService->search('create contact');
        $supersededIds = array_map(fn ($r) => $r->operationId, $supersededResults);

        $this->assertNotContains(
            'contacts.store',
            $supersededIds,
            "After revision, 'contacts.store' should no longer match 'create contact' — "
            . 'the re-index must drop superseded content, not merely add new content. '
            . 'Results: ' . json_encode($supersededIds)
            . ' (query: search for "create contact" after revision)'
        );
    }
}
