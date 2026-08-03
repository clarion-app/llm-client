<?php

namespace Tests\RealDatabase;

use ClarionApp\LlmClient\Services\OperationsSearchService;
use Tests\RealDatabase\Support\OperationIndexFixture;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * T030: Fulltext matching rules on the real engine.
 *
 * Asserts sub-token-floor behaviour, stopword behaviour, and that
 * the type column participates in the search space.
 */
#[Group('real-db')]
class FulltextMatchingRulesTest extends RealDatabaseTestCase
{
    protected array $seedTables = ['operation_search_index'];

    protected OperationsSearchService $searchService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->searchService = new OperationsSearchService();
    }

    /**
     * Sub-token-floor term (2 chars) matches nothing.
     *
     * InnoDB's min token size is 3 (probed in R3). A 2-character term
     * is below the floor and should be ignored by the fulltext engine.
     */
    #[Test]
    public function subTokenFloorTermMatchesNothing(): void
    {
        $this->assertReady();
        OperationIndexFixture::seed();

        // "ab" is a 2-character term that doesn't appear in any entry.
        // Even if it did, it would be below the token floor.
        $results = $this->searchService->search('ab');

        $this->assertEmpty(
            $results,
            "2-character term 'ab' should match nothing (below InnoDB min token size of 3) "
            . '(query: search for "ab" - sub token floor test)'
        );
    }

    /**
     * Stopword behaviour: InnoDB stopwords are enabled by default.
     *
     * We probe with a known stopword ("the") mixed with a real term.
     * The stopword should be ignored, but the real term should still match.
     */
    #[Test]
    public function stopwordIsIgnoredButRealTermMatches(): void
    {
        $this->assertReady();
        OperationIndexFixture::seed();

        // "the" is an InnoDB stopword. "toggle" is not.
        // Searching "the toggle" should match the same as "toggle".
        $withStopword = $this->searchService->search('the toggle');
        $withoutStopword = $this->searchService->search('toggle');

        $withIds = array_map(fn ($r) => $r->operationId, $withStopword);
        $withoutIds = array_map(fn ($r) => $r->operationId, $withoutStopword);

        $this->assertSame(
            $withoutIds,
            $withIds,
            "Stopword 'the' should be ignored — results with and without it should match. "
            . "With stopword: " . json_encode($withIds)
            . ", without: " . json_encode($withoutIds)
            . ' (query: search for "the toggle" vs "toggle" - stopword test)'
        );

        // The real term should still produce results.
        $this->assertNotEmpty(
            $withoutStopword,
            "Term 'toggle' should match at least one entry "
            . '(query: search for "toggle" - real term after stopword test)'
        );
    }

    /**
     * The type column participates in the search space.
     *
     * Querying "prompt" should match the row whose type is "prompt".
     * This verifies that the FULLTEXT index over (type, searchable_text)
     * makes the type column searchable.
     */
    #[Test]
    public function typeColumnParticipatesInSearchSpace(): void
    {
        $this->assertReady();
        OperationIndexFixture::seed();

        // "prompt" appears as the type value for wizlights_scene_evening.
        // It does NOT appear in any searchable_text column.
        $results = $this->searchService->search('prompt');

        $this->assertNotEmpty(
            $results,
            "Query 'prompt' should match at least one entry via the type column "
            . '(query: search for "prompt" - type column participation test)'
        );

        $operationIds = array_map(fn ($r) => $r->operationId, $results);
        $this->assertContains(
            'wizlights_scene_evening',
            $operationIds,
            "Query 'prompt' should match 'wizlights_scene_evening' (type = 'prompt') "
            . 'via the type column. Results: ' . json_encode($operationIds)
            . ' (query: search for "prompt" - type column participation, contains check)'
        );

        // The matched row should have type = 'prompt'.
        $matchedRow = null;
        foreach ($results as $row) {
            if ($row->operationId === 'wizlights_scene_evening') {
                $matchedRow = $row;
                break;
            }
        }
        $this->assertNotNull($matchedRow,
            'wizlights_scene_evening should be in results (query: search for "prompt")');
        $this->assertSame(
            'prompt',
            $matchedRow->type,
            "Matched row should have type = 'prompt' "
            . '(query: search for "prompt" - type value check)'
        );
    }
}
