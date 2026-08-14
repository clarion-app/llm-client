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
 * T036 (US3, appended below) covers the not-yet-built wouldCreateCycle():
 * a fresh, disjoint pair is null (no cycle); a direct 2-cycle and a 3-hop
 * transitive chain both return a non-null cycle path (the 3-hop case
 * specifically proving the DFS walks beyond the direct case, naming all
 * three agents in chain order); and a defensive case seeding a
 * pre-existing cycle directly at the model layer (bypassing
 * AgentHelperService::assign()'s own validation, which does not exist
 * yet) proves the traversal's visited-set terminates rather than looping
 * forever, even confronted with fixture data that should never occur
 * through the real API.
 *
 * T037 (US3, appended below) covers the not-yet-built depth-computation
 * primitive (research.md D5) via config('llm-client.helpers.max_depth'):
 * an assignment landing within the configured bound and one landing
 * beyond it both compute the correct depth (the caller -- assign(),
 * tested at the HTTP level in T039 -- is the one that turns "exceeds" into
 * a refusal; this file only proves the number itself is right).
 *
 * hierarchyFor() is deliberately not covered here (Phase 4/US3's own HTTP
 * scenario, T040, in AgentHelperAssignmentJourneyTest.php instead).
 *
 * Written first, confirmed RED: AgentHelperQuery does not exist yet
 * (T015/T016), and wouldCreateCycle()/computeDepth() do not exist yet
 * either (T036/T037).
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
     * Builds an agent permitted exactly the given list of operation ids
     * (100-subagent-tool-restrictions) -- unlike agent()'s own single-
     * pattern shape, tools.allow lists every id in $operationIds verbatim
     * (no wildcard/group pattern), so a candidate's own permitted set can
     * be constructed precisely rather than approximated via a group.
     *
     * @param list<string> $operationIds
     */
    private function agentPermitting(User $owner, string $name, array $operationIds): Agent
    {
        $allowLines = implode("\n", array_map(fn (string $id) => "    - {$id}", $operationIds));

        $yaml = <<<YAML
name: {$name}
instructions: Assist customers.
tools:
  allow:
{$allowLines}
YAML;

        return $this->agentService()->create($owner->id, $yaml);
    }

    /**
     * Two disjoint, single-operation groups -- X and Y in the quickstart
     * scenarios' own shorthand (quickstart.md scenarios 2/3/5) -- used by
     * every structuralEffectiveBound()/multi-level test below, in place of
     * the three-operation contacts group / weather.get_forecast catalog
     * (whose grouping would blur the "X only" vs "X and Y" distinction
     * those scenarios turn on).
     */
    private function seedXyOperationCatalog(): void
    {
        $this->seedOperationCatalog([
            'x.operation' => ['path' => '/api/x', 'method' => 'get', 'summary' => 'X operation'],
            'y.operation' => ['path' => '/api/y', 'method' => 'get', 'summary' => 'Y operation'],
        ]);
    }

    /**
     * Three disjoint, single-operation groups -- used only by the
     * two-active-parents structuralEffectiveBound() case, where a third,
     * partially-overlapping operation is needed to prove the result is a
     * genuine intersection of both parents' own bounds, not merely
     * whichever parent happens to be checked first.
     */
    private function seedXyzOperationCatalog(): void
    {
        $this->seedOperationCatalog([
            'x.operation' => ['path' => '/api/x', 'method' => 'get', 'summary' => 'X operation'],
            'y.operation' => ['path' => '/api/y', 'method' => 'get', 'summary' => 'Y operation'],
            'z.operation' => ['path' => '/api/z', 'method' => 'get', 'summary' => 'Z operation'],
        ]);
    }

    /**
     * Ten distinct, single-operation groups -- used only by the User Story
     * 3 (Phase 5, T025) composition cases below, where a parent permitting
     * a realistically large catalog (10 operations) is narrowed by a
     * helper whose own tools.allow lists only the couple of tools its job
     * needs, without ever referencing the parent's set at all
     * (FR-007/FR-008, spec.md's own "narrow a helper to just what its job
     * needs" framing).
     */
    private function seedTenOperationCatalog(): void
    {
        $operations = [];
        for ($i = 1; $i <= 10; $i++) {
            $operations["op{$i}.operation"] = ['path' => "/api/op{$i}", 'method' => 'get', 'summary' => "Op {$i}"];
        }
        $this->seedOperationCatalog($operations);
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
    // structuralEffectiveBound() — T007 (100-subagent-tool-restrictions,
    // Phase 3/US1, tasks.md T007, data-model.md §2, research.md D3).
    //
    // Not-yet-built: AgentHelperQuery has no structuralEffectiveBound()
    // method at all yet. Written first, confirmed RED: every case below
    // currently errors with "Call to undefined method
    // AgentHelperQuery::structuralEffectiveBound()".
    // ---------------------------------------------------------------

    #[Test]
    public function structural_effective_bound_of_an_agent_with_zero_active_parents_returns_its_own_permitted_operations_unchanged(): void
    {
        $owner = $this->user();
        $this->seedXyOperationCatalog();
        $agent = $this->agentPermitting($owner, 'sb-root-case', ['x.operation', 'y.operation']);
        $catalog = $this->buildCatalog();

        $this->assertSame(
            $this->sortedIds($this->query()->permittedOperationIds($agent, $catalog)),
            $this->sortedIds($this->query()->structuralEffectiveBound($agent, $catalog)),
            'a root (never anyone\'s active helper) must get exactly its own permitted set back, unchanged -- the existing one-level behavior, preserved for the common case',
        );
    }

    #[Test]
    public function structural_effective_bound_with_a_single_active_parent_narrows_to_the_intersection_with_the_parent(): void
    {
        $owner = $this->user();
        $this->seedXyOperationCatalog();
        $parent = $this->agentPermitting($owner, 'sb-single-parent', ['x.operation']);
        $helper = $this->agentPermitting($owner, 'sb-single-helper', ['x.operation', 'y.operation']);
        $this->assign($parent, $helper, $owner);
        $catalog = $this->buildCatalog();

        $this->assertSame(
            ['x.operation'],
            $this->query()->structuralEffectiveBound($helper, $catalog),
            'a single active parent must narrow the helper\'s own {X, Y} down to the parent\'s own {X}',
        );
    }

    #[Test]
    public function structural_effective_bound_narrows_through_both_levels_of_a_three_level_chain(): void
    {
        $owner = $this->user();
        $this->seedXyOperationCatalog();
        $agentA = $this->agentPermitting($owner, 'sb-chain-a', ['x.operation']);
        $agentB = $this->agentPermitting($owner, 'sb-chain-b', ['x.operation', 'y.operation']);
        $this->assign($agentA, $agentB, $owner);
        $catalog = $this->buildCatalog();

        $this->assertSame(
            ['x.operation'],
            $this->query()->structuralEffectiveBound($agentB, $catalog),
            'B\'s own recursive bound must be A\'s {X}, not B\'s own raw {X, Y} -- the check must walk past the immediate parent',
        );
    }

    #[Test]
    public function structural_effective_bound_with_two_active_parents_is_the_intersection_of_both_parents_own_recursive_bounds(): void
    {
        $owner = $this->user();
        $this->seedXyzOperationCatalog();
        $parentOne = $this->agentPermitting($owner, 'sb-two-parents-one', ['x.operation', 'y.operation']);
        $parentTwo = $this->agentPermitting($owner, 'sb-two-parents-two', ['y.operation', 'z.operation']);
        $helper = $this->agentPermitting($owner, 'sb-two-parents-helper', ['x.operation', 'y.operation', 'z.operation']);
        $this->assign($parentOne, $helper, $owner);
        $this->assign($parentTwo, $helper, $owner);
        $catalog = $this->buildCatalog();

        $this->assertSame(
            ['y.operation'],
            $this->query()->structuralEffectiveBound($helper, $catalog),
            'with two active parents ({X, Y} and {Y, Z}), the recursive bound must be the intersection of both parents\' own bounds (Y alone), never just one parent\'s',
        );
    }

    #[Test]
    public function structural_effective_bound_of_a_pre_existing_cycle_degrades_to_an_empty_set_rather_than_recursing_forever(): void
    {
        $owner = $this->user();
        $this->seedXyOperationCatalog();
        $agentA = $this->agentPermitting($owner, 'sb-cycle-a', ['x.operation', 'y.operation']);
        $agentB = $this->agentPermitting($owner, 'sb-cycle-b', ['x.operation', 'y.operation']);

        // A pre-existing 2-cycle, seeded directly at the model layer,
        // bypassing AgentHelperService::assign()'s own cycle guard entirely
        // (which would refuse this pair) -- the real API must never allow
        // this, but the traversal itself must still defend against it via
        // a visited-set, mirroring depthOf()'s own by-value posture rather
        // than dfsForTarget()'s shared one (Grounding note item 1).
        $this->assign($agentA, $agentB, $owner);
        $this->assign($agentB, $agentA, $owner);
        $catalog = $this->buildCatalog();

        $this->assertSame(
            [],
            $this->query()->structuralEffectiveBound($agentA, $catalog),
            'a pre-existing cycle must degrade to an empty bound, not recurse forever or error (mutation-testing checklist row 2)',
        );
    }

    // ---------------------------------------------------------------
    // isWithinParentBounds()/effectiveOperationIds() upgraded to compare
    // against structuralEffectiveBound() — T008 (100-subagent-tool-
    // restrictions, Phase 3/US1, tasks.md T008, contracts §2,
    // quickstart.md scenario 2, mutation-testing checklist row 1).
    //
    // Both methods already exist (T015/097-subagent-model) but still
    // compare against permittedOperationIds($parent, ...) directly -- a
    // one-level check. The cases below currently fail against that
    // shallow comparison (a genuine assertion failure, not a fatal error
    // -- both methods do exist and do return a value, just the wrong one)
    // and are expected to pass once T011 upgrades both internals to
    // compare against structuralEffectiveBound($parent, ...) instead.
    // ---------------------------------------------------------------

    #[Test]
    public function is_within_parent_bounds_and_effective_operation_ids_now_compare_against_the_parents_recursive_bound_not_its_raw_permitted_set(): void
    {
        $owner = $this->user();
        $this->seedXyOperationCatalog();
        $agentA = $this->agentPermitting($owner, 't008-chain-a', ['x.operation']);
        $agentB = $this->agentPermitting($owner, 't008-chain-b', ['x.operation', 'y.operation']);
        $agentC = $this->agentPermitting($owner, 't008-chain-c', ['x.operation', 'y.operation']);
        $this->assign($agentA, $agentB, $owner);
        $catalog = $this->buildCatalog();

        // C ⊆ B's own raw permitted set ({X, Y} == {X, Y}) -- a one-level
        // check would wrongly call this within bounds. B's own *effective*
        // bound (B ∩ A) is only {X}, since B is itself A's helper -- the
        // check must now walk past B to reach that (quickstart scenario 2).
        $this->assertFalse(
            $this->query()->isWithinParentBounds($agentC, $agentB, $catalog),
            'C exceeds B\'s recursive bound ({X}, narrowed by A) even though C is a byte-identical subset of B\'s own raw {X, Y}',
        );
        $this->assertSame(
            ['x.operation'],
            $this->query()->effectiveOperationIds($agentC, $agentB, $catalog),
            'effectiveOperationIds() must reflect the narrower recursive bound, never B\'s raw {X, Y}',
        );
    }

    // ---------------------------------------------------------------
    // FR-007/FR-008 composition with the recursive structural bound —
    // T025 (100-subagent-tool-restrictions, Phase 5/US3, tasks.md T025,
    // spec.md's "narrow a helper to just what its job needs" framing).
    //
    // US3 adds no new production code (Ordering grounding note): a
    // helper's own tools.allow/tools.deny YAML already lists only its own
    // intended tools, never enumerating or negating the parent's set, and
    // that has always been true. What these two cases prove is that this
    // still holds now that T011's structuralEffectiveBound() walks the
    // full ancestor chain rather than comparing only against the
    // immediate parent's raw permitted set -- narrowing composes
    // correctly with multi-level bounding, not just the single-level
    // case T015/097-subagent-model originally covered.
    // ---------------------------------------------------------------

    #[Test]
    public function a_helper_narrowed_to_two_of_the_parents_ten_operations_is_within_bounds_and_effective_ids_are_exactly_its_own_two(): void
    {
        $owner = $this->user();
        $this->seedTenOperationCatalog();
        $allTen = array_map(fn (int $i) => "op{$i}.operation", range(1, 10));
        $parent = $this->agentPermitting($owner, 't025-parent', $allTen);
        $helper = $this->agentPermitting($owner, 't025-helper', ['op1.operation', 'op2.operation']);
        $this->assign($parent, $helper, $owner);
        $catalog = $this->buildCatalog();

        $this->assertTrue(
            $this->query()->isWithinParentBounds($helper, $parent, $catalog),
            'a helper whose own config lists only 2 of the parent\'s 10 operations -- never referencing the parent\'s set at all -- is always within a broader parent\'s bound, at any recursion depth',
        );
        $this->assertSame(
            ['op1.operation', 'op2.operation'],
            $this->sortedIds($this->query()->effectiveOperationIds($helper, $parent, $catalog)),
            'effectiveOperationIds() must return exactly the helper\'s own narrower 2, never the parent\'s full 10',
        );
    }

    #[Test]
    public function a_helper_narrowed_through_a_two_level_parent_chain_composes_correctly_through_the_recursive_bound(): void
    {
        $owner = $this->user();
        $this->seedTenOperationCatalog();
        $allTen = array_map(fn (int $i) => "op{$i}.operation", range(1, 10));
        $rootA = $this->agentPermitting($owner, 't025-root-a', $allTen);
        $middleB = $this->agentPermitting($owner, 't025-middle-b', ['op1.operation', 'op2.operation', 'op3.operation', 'op4.operation']);
        $this->assign($rootA, $middleB, $owner);
        $candidateC = $this->agentPermitting($owner, 't025-candidate-c', ['op1.operation', 'op2.operation']);
        $this->assign($middleB, $candidateC, $owner);
        $catalog = $this->buildCatalog();

        $this->assertTrue(
            $this->query()->isWithinParentBounds($candidateC, $middleB, $catalog),
            'C\'s own 2 must be within B\'s recursive bound (B\'s own 4, itself already within root A\'s 10)',
        );
        $this->assertSame(
            ['op1.operation', 'op2.operation'],
            $this->sortedIds($this->query()->effectiveOperationIds($candidateC, $middleB, $catalog)),
            'effectiveOperationIds() must reflect C\'s own narrower 2 even when B\'s own bound is itself the product of a recursive walk through A -- proving composition through the recursive bound, not merely a flat one-level check against B',
        );
        $this->assertSame(
            ['op1.operation', 'op2.operation'],
            $this->sortedIds($this->query()->structuralEffectiveBound($candidateC, $catalog)),
            'C\'s own full recursive bound, walked through both B and A, must still be exactly C\'s own 2 -- composing correctly at 2 levels, not just 1',
        );
    }

    #[Test]
    public function helpers_for_shows_within_bounds_false_when_the_rows_own_parent_is_itself_narrowed_by_its_own_parent(): void
    {
        $owner = $this->user();
        $this->seedXyOperationCatalog();
        $agentA = $this->agentPermitting($owner, 't008-helpersfor-a', ['x.operation']);
        $agentB = $this->agentPermitting($owner, 't008-helpersfor-b', ['x.operation', 'y.operation']);
        $agentC = $this->agentPermitting($owner, 't008-helpersfor-c', ['x.operation', 'y.operation']);
        $this->assign($agentA, $agentB, $owner); // B is A's helper -- narrows B's own effective bound
        $this->assign($agentB, $agentC, $owner); // C is B's helper -- C's own direct relationship to B never changes

        $result = $this->query()->helpersFor($owner->id, $agentB->id);
        $row = $result->firstWhere('helper_agent_id', $agentC->id);

        $this->assertNotNull($row);
        $this->assertFalse(
            $row->within_bounds,
            'C is within B\'s own raw {X, Y}, but B\'s recursive bound (narrowed by A to {X}) must now govern this row, even though C\'s own relationship to B never changed',
        );
        $this->assertSame(
            1,
            $row->effective_operation_count,
            'the effective count must be against B\'s recursive bound ({X}), not B\'s raw 2-operation set',
        );
    }

    #[Test]
    public function hierarchy_for_shows_within_bounds_false_for_a_grandchild_whose_immediate_parent_is_itself_narrowed(): void
    {
        $owner = $this->user();
        $this->seedXyOperationCatalog();
        $agentA = $this->agentPermitting($owner, 't008-hierarchy-a', ['x.operation']);
        $agentB = $this->agentPermitting($owner, 't008-hierarchy-b', ['x.operation', 'y.operation']);
        $agentC = $this->agentPermitting($owner, 't008-hierarchy-c', ['x.operation', 'y.operation']);
        $this->assign($agentA, $agentB, $owner);
        $this->assign($agentB, $agentC, $owner);

        $result = $this->query()->hierarchyFor($owner->id, $agentA->id);
        $cEntry = collect($result['data'])->firstWhere('agent_id', $agentC->id);

        $this->assertNotNull($cEntry, 'C must still be reachable in the hierarchy beneath A');
        $this->assertFalse(
            $cEntry['within_bounds'],
            'C\'s direct relationship to B never changed, but B\'s own recursive bound (narrowed by A to {X}) must now govern this entry',
        );
        $this->assertSame(1, $cEntry['effective_operation_count']);
    }

    // ---------------------------------------------------------------
    // permittedOperationIds() fail-closed degrade — T004 (100-subagent-
    // tool-restrictions, Phase 2/Foundational, mutation-testing
    // checklist row 10).
    //
    // Every structural (US1) and runtime (US2) walk this feature adds
    // narrows by intersecting with permittedOperationIds() at some agent
    // in a chain. That composition is only sound if a resolution failure
    // degrades to the *empty* set (a no-op intersection input, i.e.
    // "permits nothing") rather than the full catalog or null — either of
    // which would make a broken agent's failure silently widen, not
    // narrow, everything computed from it. This method and its
    // try/catch are unmodified by this feature (Grounding note item 1);
    // this test proves the property already holds and guards it going
    // forward.
    // ---------------------------------------------------------------

    #[Test]
    public function permitted_operation_ids_degrades_to_an_empty_set_never_the_full_catalog_when_the_current_versions_raw_definition_no_longer_resolves(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();

        $server = \ClarionApp\LlmClient\Models\Server::forceCreate(['id' => (string) Str::uuid(), 'name' => 'Primary']);
        $model = \ClarionApp\LlmClient\Models\LanguageModel::create(['id' => (string) Str::uuid(), 'name' => 'retiring-model', 'server_id' => $server->id]);

        $agent = $this->agentService()->create(
            $owner->id,
            "name: unresolvable-agent\nmodel: retiring-model\ntools:\n  allow:\n    - \"*\"",
        );

        // The agent's named model no longer exists on this installation —
        // raw_definition can no longer resolve (AgentDefinitionParser::
        // parse() throws AgentDefinitionResolutionException), mirroring
        // ConversationAgentDefinitionResolverTest's own established
        // pattern for forcing this exact failure mode.
        $model->delete();

        $catalog = $this->buildCatalog();

        $this->assertSame(
            [],
            $this->query()->permittedOperationIds($agent->fresh(), $catalog),
            'a resolution failure must degrade to an empty set — never the full catalog and never null — so no downstream narrowing walk can ever fail open',
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

    // ---------------------------------------------------------------
    // wouldCreateCycle() — T036 (US3)
    //
    // Every assignment below is seeded directly at the model layer
    // (AgentHelperService::assign() does not yet exist / does not yet
    // enforce this rule) so each fixture graph can be built exactly as
    // described regardless of what a future assign() would itself refuse.
    //
    // Written first, confirmed RED: wouldCreateCycle() doesn't exist yet.
    // ---------------------------------------------------------------

    #[Test]
    public function a_fresh_disjoint_pair_returns_null_no_cycle(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $agentA = $this->agent($owner, 'cycle-fresh-a', '"*"');
        $agentB = $this->agent($owner, 'cycle-fresh-b', '"*"');

        $result = $this->query()->wouldCreateCycle($agentA->id, $agentB->id);

        $this->assertNull($result, 'two agents with no existing relationship at all must never be flagged as a cycle');
    }

    #[Test]
    public function a_direct_two_cycle_returns_a_non_null_path_naming_both(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $agentA = $this->agent($owner, 'cycle-direct-a', '"*"');
        $agentB = $this->agent($owner, 'cycle-direct-b', '"*"');

        // A is already a helper of B (existing edge: parent=B, helper=A).
        $this->assign($agentB, $agentA, $owner);

        // Checking whether B could now be assigned as A's own helper
        // (parent=A, helper=B) -- this would close the loop A -> B -> A.
        $result = $this->query()->wouldCreateCycle($agentA->id, $agentB->id);

        $this->assertNotNull($result, 'assigning B as A\'s helper would close a direct 2-cycle with the existing A-helper-of-B edge');
        $this->assertEqualsCanonicalizing(
            [$agentA->id, $agentB->id],
            $result,
            'the cycle path must name both agents involved',
        );
    }

    #[Test]
    public function a_three_hop_chain_returns_a_non_null_path_naming_all_three_in_order(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $agentA = $this->agent($owner, 'cycle-chain-a', '"*"');
        $agentB = $this->agent($owner, 'cycle-chain-b', '"*"');
        $agentC = $this->agent($owner, 'cycle-chain-c', '"*"');

        // A -> B -> C: A is parent of B, B is parent of C.
        $this->assign($agentA, $agentB, $owner);
        $this->assign($agentB, $agentC, $owner);

        // Checking whether A could now be assigned as C's own helper
        // (parent=C, helper=A) -- this would close the loop
        // A -> B -> C -> A, a genuinely transitive cycle the direct-case
        // check above cannot exercise.
        $result = $this->query()->wouldCreateCycle($agentC->id, $agentA->id);

        $this->assertNotNull($result, 'assigning A as C\'s helper would close a transitive, 3-hop cycle');
        $this->assertSame(
            [$agentA->id, $agentB->id, $agentC->id],
            $result,
            'the cycle path must name all three agents in chain order (A, B, C), not merely contain them',
        );
    }

    #[Test]
    public function a_pre_existing_cycle_in_the_fixture_data_does_not_cause_infinite_recursion(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $agentA = $this->agent($owner, 'cycle-guard-a', '"*"');
        $agentB = $this->agent($owner, 'cycle-guard-b', '"*"');
        $agentC = $this->agent($owner, 'cycle-guard-c', '"*"');

        // Seed a pre-existing 2-cycle directly at the model layer,
        // bypassing AgentHelperService::assign()'s own validation entirely
        // -- something the real API must never allow once T044 wires the
        // cycle check in, but the traversal itself must defend against
        // regardless, via a visited-set, rather than looping forever or
        // overflowing the stack if such data somehow existed anyway.
        $this->assign($agentA, $agentB, $owner);
        $this->assign($agentB, $agentA, $owner);

        // C is entirely unrelated to the A<->B cycle -- checking whether A
        // could be assigned as C's own helper must terminate and correctly
        // report no cycle, since C never appears in A's reachable
        // descendant set no matter how many times the traversal revisits
        // A/B.
        $result = $this->query()->wouldCreateCycle($agentC->id, $agentA->id);

        $this->assertNull($result, 'C is unrelated to the pre-existing A<->B cycle; the traversal must still terminate and find no cycle');
    }

    // ---------------------------------------------------------------
    // Depth-computation primitive — T037 (US3)
    //
    // Design note: no method name for this primitive is fixed by
    // data-model.md/research.md D5 (both describe only its behavior, not
    // its signature) -- computeDepth(parentAgentId, helperAgentId): int is
    // chosen here as the smallest primitive that answers "how deep would
    // the candidate helper land if assigned under this parent," mirroring
    // wouldCreateCycle()'s own (parentAgentId, helperAgentId) argument
    // order. Whether a value exceeds config('llm-client.helpers.max_depth')
    // is a comparison the caller makes (AgentHelperService::assign(), at
    // the HTTP level in T039) -- this primitive only computes the number.
    //
    // Written first, confirmed RED: computeDepth() doesn't exist yet.
    // ---------------------------------------------------------------

    #[Test]
    public function an_assignment_landing_within_the_configured_max_depth_computes_the_correct_depth(): void
    {
        config(['llm-client.helpers.max_depth' => 2]);

        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $agentA = $this->agent($owner, 'depth-within-a', '"*"'); // root
        $agentB = $this->agent($owner, 'depth-within-b', '"*"'); // depth 1
        $agentD = $this->agent($owner, 'depth-within-d', '"*"'); // candidate

        $this->assign($agentA, $agentB, $owner);

        // D assigned under B (itself at depth 1) would land at depth 2.
        $depth = $this->query()->computeDepth($agentB->id, $agentD->id);

        $this->assertSame(2, $depth, 'D assigned under B lands at depth 2');
        $this->assertLessThanOrEqual(
            config('llm-client.helpers.max_depth'),
            $depth,
            'depth 2 is within the configured max_depth of 2 -- must not be flagged as exceeding it',
        );
    }

    #[Test]
    public function an_assignment_landing_beyond_the_configured_max_depth_computes_the_correct_depth(): void
    {
        config(['llm-client.helpers.max_depth' => 2]);

        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $agentA = $this->agent($owner, 'depth-beyond-a', '"*"'); // root
        $agentB = $this->agent($owner, 'depth-beyond-b', '"*"'); // depth 1
        $agentC = $this->agent($owner, 'depth-beyond-c', '"*"'); // depth 2
        $agentD = $this->agent($owner, 'depth-beyond-d', '"*"'); // candidate

        $this->assign($agentA, $agentB, $owner);
        $this->assign($agentB, $agentC, $owner);

        // D assigned under C (itself at depth 2) would land at depth 3,
        // beyond the configured max_depth of 2.
        $depth = $this->query()->computeDepth($agentC->id, $agentD->id);

        $this->assertSame(3, $depth, 'D assigned under C lands at depth 3');
        $this->assertGreaterThan(
            config('llm-client.helpers.max_depth'),
            $depth,
            'depth 3 exceeds the configured max_depth of 2 -- must be flagged as exceeding it',
        );
    }

    // ---------------------------------------------------------------
    // helpersFor() -- trash-inclusive resolution -- T052 (US4)
    //
    // Phase 3's helpersFor() (AgentHelperQuery.php's own docblock) resolves
    // each row's helper via the plain, non-trash-inclusive `helper()`
    // relation -- a known, disclosed, temporary limitation this phase
    // (T056) fixes. The *deactivated* case below is included for
    // completeness under this heading, but is NOT expected to newly fail:
    // a deactivated agent (is_active = false) is never soft-deleted, so
    // the plain `helper()` relation already resolves it fine, and T016
    // already proved helper_status: 'deactivated' renders correctly for
    // it. The *soft-deleted* ("gone") case is the genuinely new one: a
    // trashed related model is excluded by Eloquent's own SoftDeletes
    // global scope on a plain (non-withTrashed()) relation query, so the
    // row is currently OMITTED from the result entirely -- not merely
    // mislabeled -- until helpersFor() is fixed to resolve via a
    // trash-inclusive lookup.
    // ---------------------------------------------------------------

    #[Test]
    public function helpers_for_still_includes_a_deactivated_helpers_row_marked_deactivated(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $parent = $this->agent($owner, 'retire-parent-deactivated', '"*"');
        $helper = $this->agent($owner, 'retire-helper-deactivated', 'contacts.*');

        $helper->is_active = false;
        $helper->save();

        $this->assign($parent, $helper, $owner);

        $result = $this->query()->helpersFor($owner->id, $parent->id);
        $row = $result->firstWhere('helper_agent_id', $helper->id);

        $this->assertNotNull($row, 'a deactivated helper\'s row must still appear');
        $this->assertSame('deactivated', $row->helper_status);
    }

    #[Test]
    public function helpers_for_still_includes_a_soft_deleted_helpers_row_marked_gone(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $parent = $this->agent($owner, 'retire-parent-gone', '"*"');
        $helper = $this->agent($owner, 'retire-helper-gone', 'contacts.*');

        $this->assign($parent, $helper, $owner);

        $helper->delete();
        $this->assertNotNull(Agent::withTrashed()->find($helper->id)->deleted_at, 'fixture sanity: the helper agent must actually be soft-deleted');

        $result = $this->query()->helpersFor($owner->id, $parent->id);
        $row = $result->firstWhere('helper_agent_id', $helper->id);

        $this->assertNotNull(
            $row,
            'a soft-deleted helper\'s row must still appear -- against Phase 3\'s plain, non-trash-inclusive helper() '
            .'lookup this currently fails because the row is OMITTED entirely (Eloquent\'s SoftDeletes global scope '
            .'excludes the trashed related model from a plain relation query), not merely mislabeled',
        );
        $this->assertSame('gone', $row->helper_status ?? null);
    }

    /**
     * Phase 6 (Polish, tasks.md T064) mutation-checklist row 5 coverage gap:
     * mutating helpersFor() to resolve the helper via a *plain* (non-
     * trash-inclusive) Agent::find() instead of Agent::withTrashed()->find()
     * stayed unexpectedly GREEN against the test immediately above, because
     * annotateRow()'s own null-branch already renders 'gone' for a null
     * $helper just as it does for a resolved-but-trashed one -- Eloquent's
     * SoftDeletes global scope makes a plain find() return null for a
     * trashed row, which annotateRow() already handles gracefully rather
     * than omitting or crashing. That null-handling is not itself the
     * trash-inclusive lookup's actual job, though: the genuine difference
     * a trash-inclusive lookup provides is that $helper is a real (if
     * trashed) Agent instance, so its own `name` is still readable --
     * a plain find() loses that to null instead. This test asserts the
     * gone row's helper_name is still the agent's own name, not null,
     * closing the gap the row-5 mutation would otherwise pass right
     * through.
     */
    #[Test]
    public function helpers_for_preserves_a_soft_deleted_helpers_own_name_via_the_trash_inclusive_lookup(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $parent = $this->agent($owner, 'retire-parent-gone-name', '"*"');
        $helper = $this->agent($owner, 'retire-helper-gone-name', 'contacts.*');

        $this->assign($parent, $helper, $owner);

        $helper->delete();

        $result = $this->query()->helpersFor($owner->id, $parent->id);
        $row = $result->firstWhere('helper_agent_id', $helper->id);

        $this->assertNotNull($row);
        $this->assertSame('gone', $row->helper_status);
        $this->assertSame(
            'retire-helper-gone-name',
            $row->helper_name,
            'a trash-inclusive lookup must still resolve the gone helper\'s own name -- a plain, non-trash-inclusive '
            .'lookup would lose it to null instead, even though annotateRow()\'s own defensive null-handling means '
            .'the row itself would still appear correctly marked "gone" either way',
        );
    }
}
