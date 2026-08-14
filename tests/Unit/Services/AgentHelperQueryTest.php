<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentHelperAssignment;
use ClarionApp\LlmClient\Services\AgentDefinitionParser;
use ClarionApp\LlmClient\Services\AgentHelperQuery;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\GitDefinitionFileReader;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for the not-yet-built AgentHelperQuery::isWithinParentBounds()/
 * effectiveOperationIds()/helpersFor() (097-subagent-model, Phase 3/
 * US1+US2, tasks.md T015/T016, data-model.md §4).
 *
 * T015 (US2) covers the two live-computation primitives research.md D3
 * defines: isWithinParentBounds() is a pure subset test, and
 * effectiveOperationIds() is always the narrower, computed intersection —
 * never a pass-through of the helper's own full permitted set, even when
 * the helper happens to be out of bounds.
 *
 * T016 (US1, appended below) covers helpersFor(): owner-only (null
 * uniformly for "doesn't exist"/"not yours," mirroring
 * AgentQuery::findAgent()'s own contract), returning only currently-active
 * assignment rows, each annotated with helper_name/helper_purpose (parsed
 * from the helper's own current AgentDefinition->instructions) and
 * helper_status ('active'/'deactivated' from the helper's own is_active —
 * deliberately not covering a soft-deleted helper here, that is Phase
 * 5/US4's own addition).
 *
 * wouldCreateCycle()/hierarchyFor() are deliberately not covered here
 * (Phase 4/US3, per the Ordering grounding note).
 *
 * Written first, confirmed RED: AgentHelperQuery does not exist yet.
 */
class AgentHelperQueryTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('agent_helper_assignments')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function query(): AgentHelperQuery
    {
        return app(AgentHelperQuery::class);
    }

    private function agentService(): AgentService
    {
        return new AgentService(new AgentDefinitionParser(), new GitDefinitionFileReader());
    }

    private function user(): User
    {
        return User::factory()->create();
    }

    /**
     * Seeds both of ApiManager's live-catalog seams — required before any
     * *valid* AgentDefinitionParser::parse() call (AgentSummaryQueryTest's
     * own established convention).
     */
    private function seedOperationCatalog(array $operations = []): void
    {
        $paths = [];
        foreach ($operations as $operationId => $entry) {
            $paths[$entry['path']][$entry['method']] = [
                'operationId' => $operationId,
                'summary' => $entry['summary'],
            ];
        }
        $doc = ['paths' => $paths];

        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, $doc);

        $generator = Mockery::mock(Generator::class);
        $generator->shouldReceive('__invoke')->andReturn($doc);
        $this->app->instance(Generator::class, $generator);
    }

    private function clearOperationCatalog(): void
    {
        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    /**
     * Three-operation catalog shared by every subset/superset scenario
     * below: contacts.* groups two operations, weather.get_forecast a
     * third, disjoint one (AgentSummaryQueryTest.php's own established
     * shape).
     */
    private function seedThreeOperationCatalog(): void
    {
        $this->seedOperationCatalog([
            'contacts.store' => ['path' => '/api/contacts', 'method' => 'post', 'summary' => 'Store a contact'],
            'contacts.index' => ['path' => '/api/contacts', 'method' => 'get', 'summary' => 'List contacts'],
            'weather.get_forecast' => ['path' => '/api/weather', 'method' => 'get', 'summary' => 'Get forecast'],
        ]);
    }

    /**
     * @param string $toolsAllowPattern a single, already-YAML-ready list
     *   item value for tools.allow — e.g. '"*"' (quoted wildcard) or
     *   'contacts.*' (unquoted group pattern), matching
     *   AgentSummaryQueryTest.php's own fixture YAML shape.
     */
    private function agent(User $owner, string $name, string $toolsAllowPattern): Agent
    {
        $yaml = <<<YAML
name: {$name}
instructions: Assist customers.
tools:
  allow:
    - {$toolsAllowPattern}
YAML;

        return $this->agentService()->create($owner->id, $yaml);
    }

    /**
     * Builds the live [{operationId, method}, ...] catalog once, the exact
     * shape AgentDefinition::permittedOperationIds()/
     * AgentSummaryQuery::buildCatalog() both expect as their $catalog
     * argument — duplicated here rather than imported since it is a small,
     * private assembly step, matching AgentSummaryQuery's own precedent of
     * building it locally rather than sharing a helper class.
     *
     * @return list<array{operationId: string, method: string}>
     */
    private function buildCatalog(): array
    {
        $catalog = [];

        foreach (ApiManager::getOperations() as $operation) {
            $details = (array) ApiManager::getOperationDetails($operation['operationId']);

            if (!isset($details['method'])) {
                continue;
            }

            $catalog[] = [
                'operationId' => $operation['operationId'],
                'method' => $details['method'],
            ];
        }

        return $catalog;
    }

    private function sortedIds(array $ids): array
    {
        $ids = array_values($ids);
        sort($ids);

        return $ids;
    }

    private function assign(Agent $parent, Agent $helper, User $owner): AgentHelperAssignment
    {
        return AgentHelperAssignment::create([
            'parent_agent_id' => $parent->id,
            'helper_agent_id' => $helper->id,
            'owner_user_id' => $owner->id,
        ]);
    }

    // ---------------------------------------------------------------
    // isWithinParentBounds() / effectiveOperationIds() — T015 (US2)
    // ---------------------------------------------------------------

    #[Test]
    public function a_helper_whose_operations_are_a_strict_subset_of_the_parents_is_within_bounds_and_effective_ids_are_exactly_the_helpers_own_set(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $parent = $this->agent($owner, 'bounds-parent-wide', '"*"');
        $helper = $this->agent($owner, 'bounds-helper-narrow', 'contacts.*');
        $catalog = $this->buildCatalog();

        $this->assertTrue($this->query()->isWithinParentBounds($helper, $parent, $catalog));
        $this->assertSame(
            $this->sortedIds(['contacts.store', 'contacts.index']),
            $this->sortedIds($this->query()->effectiveOperationIds($helper, $parent, $catalog)),
        );
    }

    #[Test]
    public function a_helper_whose_operations_exactly_match_the_parents_is_within_bounds_and_effective_ids_are_the_full_shared_set(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $parent = $this->agent($owner, 'bounds-parent-match', 'contacts.*');
        $helper = $this->agent($owner, 'bounds-helper-match', 'contacts.*');
        $catalog = $this->buildCatalog();

        $this->assertTrue($this->query()->isWithinParentBounds($helper, $parent, $catalog));
        $this->assertSame(
            $this->sortedIds(['contacts.store', 'contacts.index']),
            $this->sortedIds($this->query()->effectiveOperationIds($helper, $parent, $catalog)),
            'a byte-identical operation set is not itself a violation, and the full shared set is the effective one',
        );
    }

    #[Test]
    public function a_helper_with_one_operation_the_parent_lacks_is_not_within_bounds_and_effective_ids_are_only_the_intersection(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $parent = $this->agent($owner, 'bounds-parent-narrow', 'contacts.*');
        $helper = $this->agent($owner, 'bounds-helper-wide', '"*"');
        $catalog = $this->buildCatalog();

        $this->assertFalse($this->query()->isWithinParentBounds($helper, $parent, $catalog));
        $this->assertSame(
            $this->sortedIds(['contacts.store', 'contacts.index']),
            $this->sortedIds($this->query()->effectiveOperationIds($helper, $parent, $catalog)),
            'effectiveOperationIds() must return only the intersection with the parent, never the helper\'s own full 3-operation set',
        );
    }

    // ---------------------------------------------------------------
    // helpersFor() — T016 (US1)
    //
    // Written first, confirmed RED: AgentHelperQuery does not exist yet
    // (same reason as every T015 case above).
    // ---------------------------------------------------------------

    #[Test]
    public function helpers_for_returns_null_when_the_caller_does_not_own_the_parent(): void
    {
        $owner = $this->user();
        $stranger = $this->user();
        $this->seedThreeOperationCatalog();
        $parent = $this->agent($owner, 'parent-not-owned', '"*"');

        $result = $this->query()->helpersFor($stranger->id, $parent->id);

        $this->assertNull($result, 'a non-owner caller must get the same uniform null findAgent() itself returns');
    }

    #[Test]
    public function helpers_for_returns_null_for_a_genuinely_nonexistent_parent(): void
    {
        $owner = $this->user();

        $result = $this->query()->helpersFor($owner->id, (string) Str::uuid());

        $this->assertNull($result);
    }

    #[Test]
    public function helpers_for_returns_only_currently_active_assignment_rows_each_carrying_name_purpose_and_status(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $parent = $this->agent($owner, 'parent-with-helpers', '"*"');
        $activeHelper = $this->agent($owner, 'active-helper', 'contacts.*');
        $deactivatedHelper = $this->agent($owner, 'deactivated-helper', 'contacts.*');
        $removedHelper = $this->agent($owner, 'removed-helper', 'contacts.*');

        $deactivatedHelper->is_active = false;
        $deactivatedHelper->save();

        $this->assign($parent, $activeHelper, $owner);
        $this->assign($parent, $deactivatedHelper, $owner);
        $removedAssignment = $this->assign($parent, $removedHelper, $owner);
        $removedAssignment->delete();
        $this->assertNotNull($removedAssignment->fresh()->deleted_at, 'fixture sanity: the assignment must actually be soft-deleted');

        $result = $this->query()->helpersFor($owner->id, $parent->id);

        $this->assertNotNull($result);
        $this->assertCount(2, $result, 'only the two active assignment rows must be returned, never the removed one');

        $byHelperId = $result->keyBy('helper_agent_id');

        $this->assertFalse($byHelperId->has($removedHelper->id), 'a removed assignment must not appear at all');

        $active = $byHelperId->get($activeHelper->id);
        $this->assertNotNull($active);
        $this->assertSame('active-helper', $active->helper_name);
        $this->assertSame('Assist customers.', trim($active->helper_purpose));
        $this->assertSame('active', $active->helper_status);

        $deactivated = $byHelperId->get($deactivatedHelper->id);
        $this->assertNotNull($deactivated);
        $this->assertSame('deactivated-helper', $deactivated->helper_name);
        $this->assertSame('Assist customers.', trim($deactivated->helper_purpose));
        $this->assertSame('deactivated', $deactivated->helper_status);
    }

    #[Test]
    public function helpers_for_computes_within_bounds_and_effective_operation_count_correctly_per_row_from_one_call(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $parent = $this->agent($owner, 'parent-multi-row', 'contacts.*');

        $subsetHelper = $this->agent($owner, 'subset-helper', 'contacts.*');
        $this->assign($parent, $subsetHelper, $owner);

        // Seeded directly at the model layer (AgentHelperService::assign()
        // does not exist yet, and would refuse this pair anyway) — proving
        // helpersFor()'s own within_bounds/effective_operation_count
        // annotation is computed live per row from one shared catalog
        // resolution, never copied from one row to the next.
        $exceedingHelper = $this->agent($owner, 'exceeding-helper', '"*"');
        $this->assign($parent, $exceedingHelper, $owner);

        $result = $this->query()->helpersFor($owner->id, $parent->id);
        $byHelperId = $result->keyBy('helper_agent_id');

        $subsetRow = $byHelperId->get($subsetHelper->id);
        $this->assertTrue($subsetRow->within_bounds);
        $this->assertSame(2, $subsetRow->effective_operation_count);

        $exceedingRow = $byHelperId->get($exceedingHelper->id);
        $this->assertFalse($exceedingRow->within_bounds);
        $this->assertSame(
            2,
            $exceedingRow->effective_operation_count,
            'the intersection with the parent (2), never the helper\'s own full 3-operation set',
        );
    }
}
