<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentHelperAssignment;
use ClarionApp\LlmClient\Models\CapabilityOffering;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Services\AgentDefinitionParser;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\GitDefinitionFileReader;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 109-agent-as-capability, Phase 5 (US3), tasks.md T033 (quickstart
 * scenario 4, US3 AC1/AC2, mutation-checklist rows 4/5).
 *
 * The HTTP-contract-level companion to CapabilityOfferingServiceTest's own
 * service-level union-graph cycle proof (T013) and to
 * CapabilityOfferingControllerTest's own equivalent case -- both must
 * pass, proving the config-time refusal holds through the real controller
 * too. Adds the one assertion neither of those files makes: that a
 * refused cycle attempt (direct self-offer, or the indirect
 * helper-assignment + capability-offering loop) never writes so much as
 * one `Delegation` row -- confirming the refusal happens strictly before
 * any of the three relationships is ever invoked, not merely before the
 * refused offering itself is created.
 *
 * No new production code is expected here -- the config-time union-graph
 * DFS (`AgentHelperQuery::wouldCreateCycle()`/`wouldOfferingCreateCycle()`)
 * and the trivial self-offer check were both already built and proven
 * correct in Phase 2/Foundational. This file's job, like Phase 4 (US2)'s
 * own, is to prove the existing behavior holds through the real HTTP
 * surface, not to build anything new (tasks.md's own "Ordering
 * rationale" section).
 */
class CapabilityAgentIndirectCycleTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('agent_delegations')->delete();
        DB::table('agent_capability_offerings')->delete();
        DB::table('agent_helper_assignments')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers (mirrors CapabilityOfferingControllerTest.php's own fixture
    // helpers)
    // ---------------------------------------------------------------

    private function agentService(): AgentService
    {
        return new AgentService(new AgentDefinitionParser(), new GitDefinitionFileReader());
    }

    private function user(): User
    {
        return User::factory()->create();
    }

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

    private function seedThreeOperationCatalog(): void
    {
        $this->seedOperationCatalog([
            'contacts.store' => ['path' => '/api/contacts', 'method' => 'post', 'summary' => 'Store a contact'],
            'contacts.index' => ['path' => '/api/contacts', 'method' => 'get', 'summary' => 'List contacts'],
            'weather.get_forecast' => ['path' => '/api/weather', 'method' => 'get', 'summary' => 'Get forecast'],
        ]);
    }

    private function agent(User $owner, string $name): Agent
    {
        $yaml = <<<YAML
name: {$name}
instructions: Assist customers.
tools:
  allow:
    - "*"
YAML;

        return $this->agentService()->create($owner->id, $yaml);
    }

    private function offeringsUrl(string $offeredAgentId): string
    {
        return "/api/clarion-app/llm-client/agents/{$offeredAgentId}/capability-offerings";
    }

    // ---------------------------------------------------------------
    // Direct self-offer (quickstart scenario 4's own "separately" clause)
    // ---------------------------------------------------------------

    #[Test]
    public function a_direct_self_offer_is_refused_with_self_offering_and_writes_no_delegation_row(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $agent = $this->agent($owner, 'indirect-cycle-self-offer-agent');

        $response = $this->actingAs($owner, 'api')->postJson($this->offeringsUrl($agent->id), [
            'caller_agent_id' => $agent->id,
            'capability_name' => 'x',
            'capability_description' => 'x',
            'input_description' => 'x',
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['error' => 'self_offering']);
        $this->assertSame(0, CapabilityOffering::count(), 'a refused self-offer must never create a row');
        $this->assertSame(
            0,
            Delegation::count(),
            'a refused self-offer must never write any Delegation row -- it is refused before any relationship is ever invoked',
        );
    }

    // ---------------------------------------------------------------
    // Indirect cycle: A -> (helper) -> B, B -> (offering) -> C, attempt
    // C -> (offering) -> A.
    // ---------------------------------------------------------------

    #[Test]
    public function an_indirect_cycle_spanning_a_helper_assignment_and_two_capability_offerings_is_refused_before_any_relationship_is_ever_invoked(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $agentA = $this->agent($owner, 'indirect-cycle-agent-a');
        $agentB = $this->agent($owner, 'indirect-cycle-agent-b');
        $agentC = $this->agent($owner, 'indirect-cycle-agent-c');

        // A -> (helper assignment, 097's own mechanism, unrelated to this
        // feature) -> B.
        AgentHelperAssignment::create([
            'parent_agent_id' => $agentA->id,
            'helper_agent_id' => $agentB->id,
            'owner_user_id' => $owner->id,
        ]);

        // B -> (capability offering) -> C.
        $this->actingAs($owner, 'api')->postJson($this->offeringsUrl($agentC->id), [
            'caller_agent_id' => $agentB->id,
            'capability_name' => 'b-calls-c',
            'capability_description' => 'B calls C.',
            'input_description' => 'Input for C.',
        ])->assertStatus(200);

        // Attempt C -> (capability offering) -> A -- completing the loop
        // A -> B -> C -> A.
        $response = $this->actingAs($owner, 'api')->postJson($this->offeringsUrl($agentA->id), [
            'caller_agent_id' => $agentC->id,
            'capability_name' => 'c-calls-a',
            'capability_description' => 'C calls A.',
            'input_description' => 'Input for A.',
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['error' => 'capability_offering_cycle']);
        $cyclePath = $response->json('cycle_path');
        $this->assertIsArray($cyclePath);
        $this->assertContains($agentA->id, $cyclePath, 'the cycle path must name agent A');
        $this->assertContains($agentB->id, $cyclePath, 'the cycle path must name agent B');
        $this->assertContains($agentC->id, $cyclePath, 'the cycle path must name agent C');

        $this->assertSame(
            0,
            CapabilityOffering::where('offered_agent_id', $agentA->id)->where('caller_agent_id', $agentC->id)->count(),
            'the refused offering must never be created',
        );
        $this->assertSame(
            0,
            Delegation::count(),
            'no Delegation row of any kind exists afterward -- the cycle is refused entirely at configuration time, before any of the three relationships (the A->B helper assignment, the B->C offering, or the refused C->A offering) is ever invoked',
        );
    }
}
