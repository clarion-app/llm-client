<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Services\AgentService;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 097-subagent-model, Phase 3 (US1+US2), tasks.md T017/T018 — the full HTTP
 * journey for `POST /agents/{id}/helpers` and `GET /agents/{id}/helpers`
 * (contracts/subagent-model-api.md §1/§2).
 *
 * T017 (US1) covers assignment/listing itself: a single assign succeeds and
 * is reflected in the response and in the parent's own list; a second
 * helper is independently listed; an agent never assigned to anything is
 * invisible both in another parent's list and in its own; a single helper
 * can serve multiple independent parents at once (research.md D1's own
 * defining property, the reason a many-to-many join table was chosen over
 * a single-parent FK); and every §1 error case (404 for an unowned/
 * nonexistent parent, an identically-shaped 404 for an unowned/nonexistent
 * helper, 422 self_assignment).
 *
 * T018 (US2, appended below, sequenced after T017 per tasks.md's own
 * "not [P]" instruction) covers the subset-of-parent constraint at the
 * HTTP level: a refusal names the exact excess and writes no row; a
 * parent's own later narrowing (via the ordinary, unmodified
 * PUT /agents/{id} — zero new code path) is reflected live in the very
 * next GET, with no further action; a byte-identical operation set is not
 * itself a violation.
 *
 * T038 (US3, appended below) covers cycle prevention at the HTTP level
 * (contracts §1, FR-006/SC-003): a direct 2-cycle (AC1) and a transitive
 * 3-hop chain (AC2) are both refused `422 cycle_detected`, naming the
 * agents involved, with no row written. T039 covers the depth-limit
 * refusal (`422 depth_limit_exceeded`, quickstart scenario 12). T040
 * covers `GET /agents/{id}/helpers/hierarchy` (contracts §3, FR-007,
 * quickstart scenario 11) — the full descendant chain, not only immediate
 * helpers.
 *
 * Written first, confirmed RED: no `agents/{id}/helpers` route exists yet,
 * so every T017/T018 call above 404s via Laravel's own route-not-found
 * handling rather than AgentHelperController. For T038/T039, the route
 * exists (Phase 3) but AgentHelperService::assign() does not yet perform
 * either check, so the cycle-/depth-forming assignment below succeeds
 * (`201`) instead of being refused — confirm this is genuinely the
 * "check not implemented yet" reason, not a fixture mistake, before
 * moving on. For T040, `GET .../helpers/hierarchy` has no route at all
 * yet, so it 404s the same way T017/T018's original calls did.
 */
class AgentHelperAssignmentJourneyTest extends TestCase
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
    // Operation catalog seam — required before any *valid*
    // AgentDefinitionParser::parse() call (AgentShareGrantJourneyTest's
    // own established convention).
    // ---------------------------------------------------------------

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

    // ---------------------------------------------------------------
    // Fixture helpers
    // ---------------------------------------------------------------

    private function user(): User
    {
        return User::factory()->create();
    }

    private function agentsUrl(): string
    {
        return '/api/clarion-app/llm-client/agents';
    }

    private function agentUrl(string $id): string
    {
        return $this->agentsUrl().'/'.$id;
    }

    private function helpersUrl(string $agentId): string
    {
        return $this->agentUrl($agentId).'/helpers';
    }

    /**
     * @param string $toolsAllowPattern a single, already-YAML-ready list
     *   item value for tools.allow — e.g. '"*"' (quoted wildcard) or
     *   'contacts.*' (unquoted group pattern), matching
     *   AgentSummaryQueryTest.php's own fixture YAML shape.
     */
    private function makeAgent(User $owner, string $name, string $toolsAllowPattern): Agent
    {
        $yaml = <<<YAML
name: {$name}
instructions: Assist customers.
tools:
  allow:
    - {$toolsAllowPattern}
YAML;

        return app(AgentService::class)->create($owner->id, $yaml);
    }

    // ---------------------------------------------------------------
    // T017 — US1
    // ---------------------------------------------------------------

    #[Test]
    public function ac1_owner_assigns_a_helper_within_the_parents_own_bounds(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $parent = $this->makeAgent($owner, 'parent-ac1', '"*"');
        $helper = $this->makeAgent($owner, 'helper-ac1', 'contacts.*');

        $response = $this->actingAs($owner, 'api')->postJson($this->helpersUrl($parent->id), [
            'helper_agent_id' => $helper->id,
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'parent_agent_id' => $parent->id,
            'helper_agent_id' => $helper->id,
            'helper_status' => 'active',
            'within_bounds' => true,
            'helper_name' => 'helper-ac1',
        ]);
        $this->assertSame('Assist customers.', trim((string) $response->json('helper_purpose')));
    }

    #[Test]
    public function ac2_a_second_helper_assigned_to_the_same_parent_is_both_listed_and_distinguishable(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $parent = $this->makeAgent($owner, 'parent-ac2', '"*"');
        $helperOne = $this->makeAgent($owner, 'helper-ac2-one', 'contacts.*');
        $helperTwo = $this->makeAgent($owner, 'helper-ac2-two', 'weather.get_forecast');

        $this->actingAs($owner, 'api')->postJson($this->helpersUrl($parent->id), ['helper_agent_id' => $helperOne->id])->assertStatus(201);
        $this->actingAs($owner, 'api')->postJson($this->helpersUrl($parent->id), ['helper_agent_id' => $helperTwo->id])->assertStatus(201);

        $response = $this->actingAs($owner, 'api')->getJson($this->helpersUrl($parent->id));
        $response->assertStatus(200);

        $data = collect($response->json('data'));
        $this->assertCount(2, $data);
        $this->assertContains($helperOne->id, $data->pluck('helper_agent_id')->all());
        $this->assertContains($helperTwo->id, $data->pluck('helper_agent_id')->all());
        $this->assertContains('helper-ac2-one', $data->pluck('helper_name')->all());
        $this->assertContains('helper-ac2-two', $data->pluck('helper_name')->all());
        $this->assertNotSame(
            $data->firstWhere('helper_agent_id', $helperOne->id)['id'],
            $data->firstWhere('helper_agent_id', $helperTwo->id)['id'],
            'the two rows must have distinct assignment ids',
        );
    }

    #[Test]
    public function ac3_an_agent_never_assigned_to_anything_does_not_appear_in_any_parents_list_and_its_own_list_is_empty(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $parent = $this->makeAgent($owner, 'parent-ac3', '"*"');
        $helper = $this->makeAgent($owner, 'helper-ac3', 'contacts.*');
        $agentX = $this->makeAgent($owner, 'agent-x-unassigned', 'contacts.*');

        $this->actingAs($owner, 'api')->postJson($this->helpersUrl($parent->id), ['helper_agent_id' => $helper->id])->assertStatus(201);

        $parentList = $this->actingAs($owner, 'api')->getJson($this->helpersUrl($parent->id));
        $parentList->assertStatus(200);
        $this->assertNotContains(
            $agentX->id,
            collect($parentList->json('data'))->pluck('helper_agent_id')->all(),
            'X was never assigned to P and must not appear',
        );

        $xList = $this->actingAs($owner, 'api')->getJson($this->helpersUrl($agentX->id));
        $xList->assertStatus(200);
        $this->assertSame([], $xList->json('data'), "X's own helper list must be empty");
    }

    #[Test]
    public function a_single_helper_can_serve_multiple_independent_parents_simultaneously(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $parentOne = $this->makeAgent($owner, 'multi-parent-one', '"*"');
        $parentTwo = $this->makeAgent($owner, 'multi-parent-two', '"*"');
        $helper = $this->makeAgent($owner, 'multi-parent-helper', 'contacts.*');

        $this->actingAs($owner, 'api')->postJson($this->helpersUrl($parentOne->id), ['helper_agent_id' => $helper->id])->assertStatus(201);

        $secondAssignResponse = $this->actingAs($owner, 'api')->postJson($this->helpersUrl($parentTwo->id), ['helper_agent_id' => $helper->id]);
        $secondAssignResponse->assertStatus(201);

        $listOne = $this->actingAs($owner, 'api')->getJson($this->helpersUrl($parentOne->id));
        $listOne->assertStatus(200);
        $this->assertContains($helper->id, collect($listOne->json('data'))->pluck('helper_agent_id')->all());

        $listTwo = $this->actingAs($owner, 'api')->getJson($this->helpersUrl($parentTwo->id));
        $listTwo->assertStatus(200);
        $this->assertContains($helper->id, collect($listTwo->json('data'))->pluck('helper_agent_id')->all());
    }

    #[Test]
    public function assign_404s_when_the_caller_does_not_own_the_parent(): void
    {
        $owner = $this->user();
        $stranger = $this->user();
        $this->seedThreeOperationCatalog();
        $parent = $this->makeAgent($owner, 'parent-not-mine', '"*"');
        $helper = $this->makeAgent($stranger, 'strangers-own-helper', 'contacts.*');

        $response = $this->actingAs($stranger, 'api')->postJson($this->helpersUrl($parent->id), [
            'helper_agent_id' => $helper->id,
        ]);

        $response->assertStatus(404);
        $response->assertJson(['error' => 'Agent not found', 'code' => 'agent_not_found']);
    }

    #[Test]
    public function assign_404s_when_the_parent_does_not_exist(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $helper = $this->makeAgent($owner, 'orphan-helper', 'contacts.*');

        $response = $this->actingAs($owner, 'api')->postJson($this->helpersUrl((string) Str::uuid()), [
            'helper_agent_id' => $helper->id,
        ]);

        $response->assertStatus(404);
        $response->assertJson(['error' => 'Agent not found', 'code' => 'agent_not_found']);
    }

    #[Test]
    public function assign_404s_when_the_helper_agent_id_does_not_exist(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $parent = $this->makeAgent($owner, 'parent-unknown-helper', '"*"');

        $response = $this->actingAs($owner, 'api')->postJson($this->helpersUrl($parent->id), [
            'helper_agent_id' => (string) Str::uuid(),
        ]);

        $response->assertStatus(404);
        $response->assertJson(['error' => 'Agent not found', 'code' => 'agent_not_found']);
    }

    #[Test]
    public function assign_404s_when_the_helper_agent_id_is_not_owned_by_the_caller(): void
    {
        $owner = $this->user();
        $stranger = $this->user();
        $this->seedThreeOperationCatalog();
        $parent = $this->makeAgent($owner, 'parent-strangers-helper', '"*"');
        $strangersAgent = $this->makeAgent($stranger, 'strangers-own-agent', 'contacts.*');

        $response = $this->actingAs($owner, 'api')->postJson($this->helpersUrl($parent->id), [
            'helper_agent_id' => $strangersAgent->id,
        ]);

        $response->assertStatus(404);
        $response->assertJson(['error' => 'Agent not found', 'code' => 'agent_not_found']);
    }

    #[Test]
    public function assign_422s_for_self_assignment(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $agent = $this->makeAgent($owner, 'self-assign-http-agent', '"*"');

        $response = $this->actingAs($owner, 'api')->postJson($this->helpersUrl($agent->id), [
            'helper_agent_id' => $agent->id,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'self_assignment']);
    }

    // ---------------------------------------------------------------
    // T018 — US2
    // ---------------------------------------------------------------

    #[Test]
    public function us2_ac1_assign_422s_with_exceeds_parent_permissions_naming_the_exact_excess_and_writes_no_row(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $parent = $this->makeAgent($owner, 'us2-narrow-parent', 'contacts.*');
        $helper = $this->makeAgent($owner, 'us2-wide-helper', '"*"');

        $response = $this->actingAs($owner, 'api')->postJson($this->helpersUrl($parent->id), [
            'helper_agent_id' => $helper->id,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'exceeds_parent_permissions']);
        $this->assertSame(['weather.get_forecast'], array_values($response->json('excess_operation_ids')));

        $list = $this->actingAs($owner, 'api')->getJson($this->helpersUrl($parent->id));
        $list->assertStatus(200);
        $this->assertSame([], $list->json('data'), 'a rejected assignment must not partially write');
    }

    #[Test]
    public function us2_ac2_narrowing_the_parents_own_operations_after_assignment_is_reflected_live_with_no_further_action(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $parent = $this->makeAgent($owner, 'us2-live-parent', '"*"');
        $helper = $this->makeAgent($owner, 'us2-live-helper', '"*"');

        $this->actingAs($owner, 'api')->postJson($this->helpersUrl($parent->id), ['helper_agent_id' => $helper->id])->assertStatus(201);

        $before = $this->actingAs($owner, 'api')->getJson($this->helpersUrl($parent->id));
        $before->assertStatus(200);
        $beforeRow = collect($before->json('data'))->firstWhere('helper_agent_id', $helper->id);
        $this->assertSame(3, $beforeRow['effective_operation_count']);

        // Narrow P's own tools.allow to exclude weather.get_forecast, via
        // the ordinary, unmodified PUT /agents/{id} -- zero new code path.
        $this->actingAs($owner, 'api')->putJson($this->agentUrl($parent->id), [
            'definition' => "name: us2-live-parent\ninstructions: Assist customers.\ntools:\n  allow:\n    - contacts.*\n",
        ])->assertStatus(200);

        $after = $this->actingAs($owner, 'api')->getJson($this->helpersUrl($parent->id));
        $after->assertStatus(200);
        $afterRow = collect($after->json('data'))->firstWhere('helper_agent_id', $helper->id);
        $this->assertSame(
            2,
            $afterRow['effective_operation_count'],
            'the narrower parent bound must be reflected immediately, with no further action',
        );
    }

    #[Test]
    public function us2_ac3_a_byte_identical_operation_set_is_not_itself_a_violation(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $parent = $this->makeAgent($owner, 'us2-identical-parent', 'contacts.*');
        $helper = $this->makeAgent($owner, 'us2-identical-helper', 'contacts.*');

        $response = $this->actingAs($owner, 'api')->postJson($this->helpersUrl($parent->id), [
            'helper_agent_id' => $helper->id,
        ]);

        $response->assertStatus(201);
        $response->assertJson(['within_bounds' => true]);
    }

    // ---------------------------------------------------------------
    // T038 — US3 (cycle prevention, contracts §1, FR-006/SC-003)
    //
    // Every agent below uses the identical, wide '"*"' operations pattern
    // so the subset-of-parent check (which runs before the cycle check in
    // AgentHelperService::assign()'s own validation order) never
    // interferes with proving the cycle refusal specifically.
    // ---------------------------------------------------------------

    #[Test]
    public function us3_ac1_a_direct_two_cycle_is_refused_naming_both_agents_and_writes_no_row(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $agentA = $this->makeAgent($owner, 'cycle-http-a', '"*"');
        $agentB = $this->makeAgent($owner, 'cycle-http-b', '"*"');

        // A has helper B, seeded via a passing assignment.
        $this->actingAs($owner, 'api')->postJson($this->helpersUrl($agentA->id), [
            'helper_agent_id' => $agentB->id,
        ])->assertStatus(201);

        // Attempting to make A a helper of B (B's own POST naming A) would
        // close the loop A -> B -> A.
        $response = $this->actingAs($owner, 'api')->postJson($this->helpersUrl($agentB->id), [
            'helper_agent_id' => $agentA->id,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'cycle_detected']);
        $this->assertEqualsCanonicalizing(
            [$agentA->id, $agentB->id],
            $response->json('cycle_path'),
            'the cycle path must name both A and B',
        );

        $list = $this->actingAs($owner, 'api')->getJson($this->helpersUrl($agentB->id));
        $list->assertStatus(200);
        $this->assertSame([], $list->json('data'), 'the refused cycle-forming assignment must not have written any row');
    }

    #[Test]
    public function us3_ac2_a_three_hop_chain_cycle_is_refused_naming_all_three_agents_in_order_and_writes_no_row(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $agentA = $this->makeAgent($owner, 'cycle-http-chain-a', '"*"');
        $agentB = $this->makeAgent($owner, 'cycle-http-chain-b', '"*"');
        $agentC = $this->makeAgent($owner, 'cycle-http-chain-c', '"*"');

        // A -> B -> C, both hops seeded via passing assignments (B already
        // has its own helper C, extending AC1's setup).
        $this->actingAs($owner, 'api')->postJson($this->helpersUrl($agentA->id), [
            'helper_agent_id' => $agentB->id,
        ])->assertStatus(201);
        $this->actingAs($owner, 'api')->postJson($this->helpersUrl($agentB->id), [
            'helper_agent_id' => $agentC->id,
        ])->assertStatus(201);

        // Attempting to make A a helper of C would close the loop
        // A -> B -> C -> A.
        $response = $this->actingAs($owner, 'api')->postJson($this->helpersUrl($agentC->id), [
            'helper_agent_id' => $agentA->id,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'cycle_detected']);
        $this->assertSame(
            [$agentA->id, $agentB->id, $agentC->id],
            $response->json('cycle_path'),
            'the cycle path must name all three agents, in chain order (A, B, C)',
        );

        $list = $this->actingAs($owner, 'api')->getJson($this->helpersUrl($agentC->id));
        $list->assertStatus(200);
        $this->assertSame([], $list->json('data'), 'the refused cycle-forming assignment must not have written any row');
    }

    // ---------------------------------------------------------------
    // T039 — US3 (depth limit, research.md D5, quickstart scenario 12)
    // ---------------------------------------------------------------

    #[Test]
    public function us3_an_assignment_beyond_the_configured_max_depth_is_refused_naming_the_computed_depth_and_the_configured_max(): void
    {
        config(['llm-client.helpers.max_depth' => 2]);

        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $agentA = $this->makeAgent($owner, 'depth-http-a', '"*"');
        $agentB = $this->makeAgent($owner, 'depth-http-b', '"*"');
        $agentC = $this->makeAgent($owner, 'depth-http-c', '"*"');
        $agentD = $this->makeAgent($owner, 'depth-http-d', '"*"');

        // A -> B -> C (depths 1, 2), both within the configured bound.
        $this->actingAs($owner, 'api')->postJson($this->helpersUrl($agentA->id), [
            'helper_agent_id' => $agentB->id,
        ])->assertStatus(201);
        $this->actingAs($owner, 'api')->postJson($this->helpersUrl($agentB->id), [
            'helper_agent_id' => $agentC->id,
        ])->assertStatus(201);

        // Assigning D as C's own helper would land D at depth 3, beyond
        // the configured max_depth of 2.
        $response = $this->actingAs($owner, 'api')->postJson($this->helpersUrl($agentC->id), [
            'helper_agent_id' => $agentD->id,
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'error' => 'depth_limit_exceeded',
            'computed_depth' => 3,
            'max_depth' => 2,
        ]);
    }

    // ---------------------------------------------------------------
    // T040 — FR-007 (hierarchy endpoint, quickstart scenario 11)
    // ---------------------------------------------------------------

    #[Test]
    public function fr007_hierarchy_endpoint_returns_the_full_descendant_chain_not_only_immediate_helpers(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $agentA = $this->makeAgent($owner, 'hierarchy-http-a', '"*"');
        $agentB = $this->makeAgent($owner, 'hierarchy-http-b', '"*"');
        $agentC = $this->makeAgent($owner, 'hierarchy-http-c', '"*"');

        // A -> B -> C, both hops seeded via passing assignments (the same
        // three-level chain T038's AC2 builds, without the cycle attempt
        // itself).
        $this->actingAs($owner, 'api')->postJson($this->helpersUrl($agentA->id), [
            'helper_agent_id' => $agentB->id,
        ])->assertStatus(201);
        $this->actingAs($owner, 'api')->postJson($this->helpersUrl($agentB->id), [
            'helper_agent_id' => $agentC->id,
        ])->assertStatus(201);

        $response = $this->actingAs($owner, 'api')->getJson($this->helpersUrl($agentA->id).'/hierarchy');

        $response->assertStatus(200);
        $response->assertJson(['truncated' => false]);

        $data = collect($response->json('data'));
        $this->assertCount(2, $data, 'both B (depth 1) and C (depth 2) must be reachable from the top, not only one hop at a time');

        $bEntry = $data->firstWhere('agent_id', $agentB->id);
        $this->assertNotNull($bEntry, 'B must appear in the hierarchy beneath A');
        $this->assertSame(1, $bEntry['depth']);

        $cEntry = $data->firstWhere('agent_id', $agentC->id);
        $this->assertNotNull($cEntry, 'C must appear in the hierarchy beneath A, not only as a helper of B one hop away');
        $this->assertSame(2, $cEntry['depth']);
        $this->assertSame([$agentA->id, $agentB->id, $agentC->id], $cEntry['path']);
    }

    /**
     * Reconciliation gap-closure test (post-Phase-6): AgentHelperQuery::
     * walkHierarchy() resolved each row's helper via the plain
     * AgentHelperAssignment::helper() relation, unlike helpersFor()'s own
     * Phase 5 trash-inclusive fix -- so a soft-deleted node mid-chain was
     * silently skipped, and because the traversal never recursed past a
     * skipped node, its entire still-active sub-tree vanished from the
     * hierarchy too, not merely the gone node itself. This directly
     * violated FR-007 ("the full helper hierarchy... MUST be traceable,
     * not only its immediate helpers") and was inconsistent with
     * helpersFor()'s own established 'gone' handling (research.md D4) for
     * the identical underlying situation.
     */
    #[Test]
    public function fr007_hierarchy_endpoint_marks_a_gone_middle_node_and_keeps_its_subtree_traceable(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $agentA = $this->makeAgent($owner, 'hierarchy-gone-a', '"*"');
        $agentB = $this->makeAgent($owner, 'hierarchy-gone-b', '"*"');
        $agentC = $this->makeAgent($owner, 'hierarchy-gone-c', '"*"');

        // A -> B -> C, both hops seeded via passing assignments.
        $this->actingAs($owner, 'api')->postJson($this->helpersUrl($agentA->id), [
            'helper_agent_id' => $agentB->id,
        ])->assertStatus(201);
        $this->actingAs($owner, 'api')->postJson($this->helpersUrl($agentB->id), [
            'helper_agent_id' => $agentC->id,
        ])->assertStatus(201);

        // B is soft-deleted entirely (research.md D4 case 1) -- its own
        // A->B assignment row is left untouched, no cascade, and B's own
        // B->C assignment row is likewise untouched.
        $agentB->delete();

        $response = $this->actingAs($owner, 'api')->getJson($this->helpersUrl($agentA->id).'/hierarchy');
        $response->assertStatus(200);

        $data = collect($response->json('data'));
        $this->assertCount(2, $data, 'B must still appear (marked gone) and C must remain traceable beneath it, not silently dropped');

        $bEntry = $data->firstWhere('agent_id', $agentB->id);
        $this->assertNotNull($bEntry, 'the soft-deleted B must still appear in the hierarchy, marked, not omitted');
        $this->assertSame('gone', $bEntry['helper_status']);
        $this->assertSame(1, $bEntry['depth']);

        $cEntry = $data->firstWhere('agent_id', $agentC->id);
        $this->assertNotNull($cEntry, 'C must remain traceable beneath a gone B -- the assignment graph beneath a retired/removed node never cascades away');
        $this->assertSame(2, $cEntry['depth']);
        $this->assertSame([$agentA->id, $agentB->id, $agentC->id], $cEntry['path']);
    }

    // ---------------------------------------------------------------
    // T054 -- US4 (retirement/removal handling, data-model.md §1
    // state-transition table, research.md D4)
    //
    // Written first, confirmed RED: the deactivated/gone cases exercise
    // Phase 3's plain, non-trash-inclusive helpersFor() lookup (fixed in
    // T056); the DELETE-related assertions exercise a route that does not
    // exist yet at all (added in T058), so they 404 via Laravel's own
    // route-not-found handling.
    // ---------------------------------------------------------------

    #[Test]
    public function us4_ac1_a_deactivated_helper_still_appears_marked_deactivated(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $parent = $this->makeAgent($owner, 'us4-parent-deactivate', '"*"');
        $helper = $this->makeAgent($owner, 'us4-helper-deactivate', 'contacts.*');

        $this->actingAs($owner, 'api')->postJson($this->helpersUrl($parent->id), [
            'helper_agent_id' => $helper->id,
        ])->assertStatus(201);

        $this->actingAs($owner, 'api')->postJson($this->agentUrl($helper->id).'/deactivate')->assertStatus(200);

        $list = $this->actingAs($owner, 'api')->getJson($this->helpersUrl($parent->id));
        $list->assertStatus(200);

        $row = collect($list->json('data'))->firstWhere('helper_agent_id', $helper->id);
        $this->assertNotNull($row, 'a deactivated helper\'s row must still appear');
        $this->assertSame('deactivated', $row['helper_status']);
    }

    #[Test]
    public function us4_ac1_a_soft_deleted_helper_still_appears_marked_gone(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $parent = $this->makeAgent($owner, 'us4-parent-gone', '"*"');
        $helperTwo = $this->makeAgent($owner, 'us4-helper-gone', 'contacts.*');

        $this->actingAs($owner, 'api')->postJson($this->helpersUrl($parent->id), [
            'helper_agent_id' => $helperTwo->id,
        ])->assertStatus(201);

        // No HTTP hard-delete endpoint exists for agents (only
        // activate/deactivate, confirmed via `grep -n "Route::delete"
        // src/Routes.php` and StoredAgentController -- no destroy()
        // method), so soft-deleted directly at the model layer, mirroring
        // Agent's own SoftDeletes trait.
        $helperTwo->delete();

        $list = $this->actingAs($owner, 'api')->getJson($this->helpersUrl($parent->id));
        $list->assertStatus(200);

        $row = collect($list->json('data'))->firstWhere('helper_agent_id', $helperTwo->id);
        $this->assertNotNull($row, 'a soft-deleted helper\'s row must still appear, distinct from the deactivated case');
        $this->assertSame('gone', $row['helper_status']);
    }

    #[Test]
    public function us4_ac2_delete_removes_the_assignment_entirely_while_both_agents_still_resolve(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $parent = $this->makeAgent($owner, 'us4-parent-delete', '"*"');
        $helper = $this->makeAgent($owner, 'us4-helper-delete', 'contacts.*');

        $this->actingAs($owner, 'api')->postJson($this->helpersUrl($parent->id), [
            'helper_agent_id' => $helper->id,
        ])->assertStatus(201);

        $deleteResponse = $this->actingAs($owner, 'api')->deleteJson($this->helpersUrl($parent->id).'/'.$helper->id);
        $deleteResponse->assertStatus(204);

        $list = $this->actingAs($owner, 'api')->getJson($this->helpersUrl($parent->id));
        $list->assertStatus(200);
        $this->assertNotContains(
            $helper->id,
            collect($list->json('data'))->pluck('helper_agent_id')->all(),
            'a removed assignment must not appear at all -- contrast with the deactivated/gone cases above, where the row stayed and was merely marked',
        );

        $this->actingAs($owner, 'api')->getJson($this->agentUrl($parent->id))->assertStatus(200);
        $this->actingAs($owner, 'api')->getJson($this->agentUrl($helper->id))->assertStatus(200);
    }

    #[Test]
    public function us4_reassignment_after_removal_restores_the_same_row_not_a_duplicate(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $agentA = $this->makeAgent($owner, 'us4-reassign-a', '"*"');
        $agentB = $this->makeAgent($owner, 'us4-reassign-b', 'contacts.*');

        $firstAssign = $this->actingAs($owner, 'api')->postJson($this->helpersUrl($agentA->id), [
            'helper_agent_id' => $agentB->id,
        ]);
        $firstAssign->assertStatus(201);

        $originalCreatedAt = DB::table('agent_helper_assignments')
            ->where('parent_agent_id', $agentA->id)
            ->where('helper_agent_id', $agentB->id)
            ->value('created_at');

        $this->actingAs($owner, 'api')->deleteJson($this->helpersUrl($agentA->id).'/'.$agentB->id)->assertStatus(204);

        $secondAssign = $this->actingAs($owner, 'api')->postJson($this->helpersUrl($agentA->id), [
            'helper_agent_id' => $agentB->id,
        ]);
        $secondAssign->assertStatus(200);

        $rows = DB::table('agent_helper_assignments')
            ->where('parent_agent_id', $agentA->id)
            ->where('helper_agent_id', $agentB->id)
            ->get();

        $this->assertCount(1, $rows, 'exactly one row must exist for (A, B) across the whole sequence, never a duplicate');
        $this->assertEquals($originalCreatedAt, $rows->first()->created_at, 'created_at must be unchanged across remove()+re-assign()');
        $this->assertNull($rows->first()->deleted_at, 'deleted_at must be null again after restore');
    }
}
