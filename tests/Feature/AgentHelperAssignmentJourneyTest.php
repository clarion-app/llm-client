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
 * Cycle/depth checks and the hierarchy endpoint are deliberately not
 * covered here (Phase 4/US3, per the Ordering grounding note) — assign()
 * is not yet expected to guard against either.
 *
 * Written first, confirmed RED: no `agents/{id}/helpers` route exists yet,
 * so every call below 404s via Laravel's own route-not-found handling
 * rather than AgentHelperController.
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
}
