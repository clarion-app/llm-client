<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentHelperAssignment;
use ClarionApp\LlmClient\Models\CapabilityOffering;
use ClarionApp\LlmClient\Services\AgentDefinitionParser;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\GitDefinitionFileReader;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature test for the not-yet-built CapabilityOfferingController
 * (109-agent-as-capability, Phase 2/Foundational, tasks.md T015,
 * contracts/capability-offering-api.md), mirroring AgentHelperController's
 * exact shape and posture (Grounding note 7): ownership of both agents
 * named in a request is resolved by the controller itself before either
 * service class is called; a non-owner caller gets the package's uniform
 * 404, never a bespoke exception from the service layer.
 *
 * Written first, confirmed RED: CapabilityOfferingController and its three
 * routes do not exist yet, so every request below hits Laravel's own
 * "route not found" 404 -- a different body/shape from what the eventual
 * controller returns, so every assertion here is a genuine, non-vacuous
 * failure right now.
 */
class CapabilityOfferingControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('agent_capability_offerings')->delete();
        DB::table('agent_helper_assignments')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers (mirrors CapabilityOfferingServiceTest.php's own fixture
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

    private function agent(User $owner, string $name, string $toolsAllowPattern = '"*"'): Agent
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

    private function offeringsUrl(string $offeredAgentId): string
    {
        return "/api/clarion-app/llm-client/agents/{$offeredAgentId}/capability-offerings";
    }

    private function withdrawUrl(string $offeredAgentId, string $callerAgentId): string
    {
        return "/api/clarion-app/llm-client/agents/{$offeredAgentId}/capability-offerings/{$callerAgentId}";
    }

    // ---------------------------------------------------------------
    // POST /agents/{offeredAgentId}/capability-offerings
    // ---------------------------------------------------------------

    #[Test]
    public function offer_happy_path_returns_200_with_the_capability_offering_shape(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $offered = $this->agent($owner, 'summarizer-agent');
        $caller = $this->agent($owner, 'caller-agent');

        $response = $this->actingAs($owner, 'api')->postJson($this->offeringsUrl($offered->id), [
            'caller_agent_id' => $caller->id,
            'capability_name' => 'summarize_document',
            'capability_description' => 'Produces a concise summary of a supplied document.',
            'input_description' => 'The document text to summarize.',
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'offered_agent_id' => $offered->id,
            'caller_agent_id' => $caller->id,
            'capability_name' => 'summarize_document',
        ]);
        $this->assertDatabaseHas('agent_capability_offerings', [
            'offered_agent_id' => $offered->id,
            'caller_agent_id' => $caller->id,
            'owner_user_id' => $owner->id,
        ]);
    }

    #[Test]
    public function offer_returns_404_when_the_offered_agent_is_not_owned_by_the_caller(): void
    {
        $owner = $this->user();
        $stranger = $this->user();
        $this->seedThreeOperationCatalog();
        $offered = $this->agent($owner, 'not-mine-offered-agent');
        $caller = $this->agent($stranger, 'strangers-caller-agent');

        $response = $this->actingAs($stranger, 'api')->postJson($this->offeringsUrl($offered->id), [
            'caller_agent_id' => $caller->id,
            'capability_name' => 'x',
            'capability_description' => 'x',
            'input_description' => 'x',
        ]);

        $response->assertStatus(404);
        $this->assertSame(0, CapabilityOffering::count());
    }

    #[Test]
    public function offer_returns_404_when_the_caller_agent_is_not_owned_by_the_caller(): void
    {
        $owner = $this->user();
        $stranger = $this->user();
        $this->seedThreeOperationCatalog();
        $offered = $this->agent($owner, 'mine-offered-agent');
        $notMyCaller = $this->agent($stranger, 'not-mine-caller-agent');

        $response = $this->actingAs($owner, 'api')->postJson($this->offeringsUrl($offered->id), [
            'caller_agent_id' => $notMyCaller->id,
            'capability_name' => 'x',
            'capability_description' => 'x',
            'input_description' => 'x',
        ]);

        $response->assertStatus(404);
        $this->assertSame(0, CapabilityOffering::count());
    }

    #[Test]
    public function offer_returns_422_self_offering(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $agent = $this->agent($owner, 'self-offer-agent');

        $response = $this->actingAs($owner, 'api')->postJson($this->offeringsUrl($agent->id), [
            'caller_agent_id' => $agent->id,
            'capability_name' => 'x',
            'capability_description' => 'x',
            'input_description' => 'x',
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['error' => 'self_offering']);
        $this->assertSame(0, CapabilityOffering::count());
    }

    #[Test]
    public function offer_returns_422_capability_offering_cycle_with_cycle_path(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $agentA = $this->agent($owner, 'ctrl-cycle-agent-a');
        $agentB = $this->agent($owner, 'ctrl-cycle-agent-b');
        $agentC = $this->agent($owner, 'ctrl-cycle-agent-c');

        AgentHelperAssignment::create([
            'parent_agent_id' => $agentA->id,
            'helper_agent_id' => $agentB->id,
            'owner_user_id' => $owner->id,
        ]);

        $this->actingAs($owner, 'api')->postJson($this->offeringsUrl($agentC->id), [
            'caller_agent_id' => $agentB->id,
            'capability_name' => 'b-calls-c',
            'capability_description' => 'B calls C.',
            'input_description' => 'Input for C.',
        ])->assertStatus(200);

        $response = $this->actingAs($owner, 'api')->postJson($this->offeringsUrl($agentA->id), [
            'caller_agent_id' => $agentC->id,
            'capability_name' => 'c-calls-a',
            'capability_description' => 'C calls A.',
            'input_description' => 'Input for A.',
        ]);

        $response->assertStatus(422);
        $response->assertJson(fn ($json) => $json->where('error', 'capability_offering_cycle')
            ->has('cycle_path')
            ->etc());
        $cyclePath = $response->json('cycle_path');
        $this->assertContains($agentA->id, $cyclePath);
        $this->assertContains($agentB->id, $cyclePath);
        $this->assertContains($agentC->id, $cyclePath);
        $this->assertSame(
            0,
            CapabilityOffering::where('offered_agent_id', $agentA->id)->where('caller_agent_id', $agentC->id)->count(),
        );
    }

    // ---------------------------------------------------------------
    // GET /agents/{offeredAgentId}/capability-offerings
    // ---------------------------------------------------------------

    #[Test]
    public function list_returns_every_active_offering_made_by_the_given_agent(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $offered = $this->agent($owner, 'list-offered-agent');
        $callerOne = $this->agent($owner, 'list-caller-one');
        $callerTwo = $this->agent($owner, 'list-caller-two');

        $this->actingAs($owner, 'api')->postJson($this->offeringsUrl($offered->id), [
            'caller_agent_id' => $callerOne->id,
            'capability_name' => 'first',
            'capability_description' => 'First.',
            'input_description' => 'Input.',
        ])->assertStatus(200);

        $this->actingAs($owner, 'api')->postJson($this->offeringsUrl($offered->id), [
            'caller_agent_id' => $callerTwo->id,
            'capability_name' => 'second',
            'capability_description' => 'Second.',
            'input_description' => 'Input.',
        ])->assertStatus(200);

        $response = $this->actingAs($owner, 'api')->getJson($this->offeringsUrl($offered->id));

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(2, $data);
    }

    #[Test]
    public function list_returns_404_for_a_non_owner(): void
    {
        $owner = $this->user();
        $stranger = $this->user();
        $this->seedThreeOperationCatalog();
        $offered = $this->agent($owner, 'list-404-offered-agent');

        $response = $this->actingAs($stranger, 'api')->getJson($this->offeringsUrl($offered->id));

        $response->assertStatus(404);
    }

    // ---------------------------------------------------------------
    // DELETE /agents/{offeredAgentId}/capability-offerings/{callerAgentId}
    // ---------------------------------------------------------------

    #[Test]
    public function withdraw_is_idempotent_true_then_false(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $offered = $this->agent($owner, 'withdraw-ctrl-offered-agent');
        $caller = $this->agent($owner, 'withdraw-ctrl-caller-agent');

        $this->actingAs($owner, 'api')->postJson($this->offeringsUrl($offered->id), [
            'caller_agent_id' => $caller->id,
            'capability_name' => 'x',
            'capability_description' => 'x',
            'input_description' => 'x',
        ])->assertStatus(200);

        $first = $this->actingAs($owner, 'api')->deleteJson($this->withdrawUrl($offered->id, $caller->id));
        $first->assertStatus(200);
        $first->assertJson(['removed' => true]);

        $second = $this->actingAs($owner, 'api')->deleteJson($this->withdrawUrl($offered->id, $caller->id));
        $second->assertStatus(200);
        $second->assertJson(['removed' => false]);
    }
}
