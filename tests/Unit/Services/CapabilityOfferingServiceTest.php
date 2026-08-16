<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Exceptions\CapabilityOfferingCycleException;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentHelperAssignment;
use ClarionApp\LlmClient\Models\CapabilityOffering;
use ClarionApp\LlmClient\Services\AgentDefinitionParser;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\CapabilityOfferingService;
use ClarionApp\LlmClient\Services\GitDefinitionFileReader;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for the not-yet-built CapabilityOfferingService::offer()/
 * withdraw() (109-agent-as-capability, Phase 2/Foundational, tasks.md T013,
 * data-model.md §2).
 *
 * Mirrors AgentHelperServiceTest.php's own established style and fixture
 * helpers (offer() has no subset-of-parent check to test, per data-model.md
 * §1's own deliberate asymmetry — see the dedicated asymmetry-proof test
 * below).
 *
 * Written first, confirmed RED: CapabilityOfferingService does not exist
 * yet.
 */
class CapabilityOfferingServiceTest extends TestCase
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
    // Helpers (mirrors AgentHelperServiceTest.php's own fixture helpers)
    // ---------------------------------------------------------------

    private function service(): CapabilityOfferingService
    {
        return app(CapabilityOfferingService::class);
    }

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

    // ---------------------------------------------------------------
    // offer() — happy path + upsert-restore idempotency
    // ---------------------------------------------------------------

    #[Test]
    public function offer_creates_an_active_row_with_the_correct_fields(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $offered = $this->agent($owner, 'summarizer-agent', '"*"');
        $caller = $this->agent($owner, 'caller-agent', 'contacts.*');

        $result = $this->service()->offer(
            $owner->id,
            $offered->id,
            $caller->id,
            'summarize_document',
            'Produces a concise summary of a supplied document.',
            'The document text to summarize.',
        );

        $this->assertInstanceOf(CapabilityOffering::class, $result);
        $this->assertSame($offered->id, $result->offered_agent_id);
        $this->assertSame($caller->id, $result->caller_agent_id);
        $this->assertSame($owner->id, $result->owner_user_id);
        $this->assertSame('summarize_document', $result->capability_name);
        $this->assertSame('Produces a concise summary of a supplied document.', $result->capability_description);
        $this->assertSame('The document text to summarize.', $result->input_description);
        $this->assertDatabaseHas('agent_capability_offerings', [
            'offered_agent_id' => $offered->id,
            'caller_agent_id' => $caller->id,
            'owner_user_id' => $owner->id,
            'capability_name' => 'summarize_document',
        ]);
    }

    #[Test]
    public function offer_after_withdraw_restores_the_same_row_rather_than_inserting_a_duplicate(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $offered = $this->agent($owner, 'restore-offered-agent', '"*"');
        $caller = $this->agent($owner, 'restore-caller-agent', 'contacts.*');

        $original = $this->service()->offer(
            $owner->id, $offered->id, $caller->id,
            'do_thing', 'Does a thing.', 'What to do.',
        );
        $originalId = $original->id;
        $originalCreatedAt = $original->created_at;

        $this->service()->withdraw($owner->id, $offered->id, $caller->id);

        $restored = $this->service()->offer(
            $owner->id, $offered->id, $caller->id,
            'do_thing_v2', 'Does a thing, updated.', 'What to do, updated.',
        );

        $this->assertSame($originalId, $restored->id, 're-offering must restore the SAME row, not insert a new one');
        $this->assertEquals($originalCreatedAt, $restored->created_at, 'created_at must be unchanged across withdraw()+re-offer()');
        $this->assertNull($restored->deleted_at, 'deleted_at must be cleared on restore');
        $this->assertSame('do_thing_v2', $restored->capability_name, 're-offering must update the capability fields');
        $this->assertSame(
            1,
            CapabilityOffering::withTrashed()
                ->where('offered_agent_id', $offered->id)
                ->where('caller_agent_id', $caller->id)
                ->count(),
            'exactly one lifetime row must exist for this pair, never a duplicate',
        );
    }

    // ---------------------------------------------------------------
    // offer() — self-offer refusal
    // ---------------------------------------------------------------

    #[Test]
    public function offer_rejects_self_offering_before_any_db_write(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $agent = $this->agent($owner, 'self-offer-agent', '"*"');

        try {
            $this->service()->offer(
                $owner->id, $agent->id, $agent->id,
                'do_thing', 'Does a thing.', 'What to do.',
            );
            $this->fail('offer() must reject offeredAgentId === callerAgentId');
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertSame(0, CapabilityOffering::count(), 'a rejected offer() attempt must not create a row');
    }

    // ---------------------------------------------------------------
    // offer() — union-graph cycle refusal spanning both edge types
    // ---------------------------------------------------------------

    #[Test]
    public function offer_rejects_a_cycle_spanning_both_a_helper_assignment_edge_and_a_capability_offering_edge(): void
    {
        // A -> (helper assignment) -> B, B -> (capability offering) -> C.
        // Attempting to offer A as a capability to C would close the loop
        // A -> B -> C -> A (quickstart scenario 4's own exact shape).
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $agentA = $this->agent($owner, 'cycle-agent-a', '"*"');
        $agentB = $this->agent($owner, 'cycle-agent-b', '"*"');
        $agentC = $this->agent($owner, 'cycle-agent-c', '"*"');

        AgentHelperAssignment::create([
            'parent_agent_id' => $agentA->id,
            'helper_agent_id' => $agentB->id,
            'owner_user_id' => $owner->id,
        ]);

        $this->service()->offer(
            $owner->id, $agentC->id, $agentB->id,
            'b-calls-c', 'B calls C.', 'Input for C.',
        );

        try {
            $this->service()->offer(
                $owner->id, $agentA->id, $agentC->id,
                'c-calls-a', 'C calls A.', 'Input for A.',
            );
            $this->fail('offer() must refuse an offering that closes a cycle spanning both edge types');
        } catch (CapabilityOfferingCycleException $e) {
            $this->assertSame($agentA->id, $e->offeredAgentId);
            $this->assertSame($agentC->id, $e->callerAgentId);
            $this->assertContains($agentA->id, $e->cyclePath, 'the cycle path must name agent A');
            $this->assertContains($agentB->id, $e->cyclePath, 'the cycle path must name agent B');
            $this->assertContains($agentC->id, $e->cyclePath, 'the cycle path must name agent C');
        }

        $this->assertSame(
            0,
            CapabilityOffering::where('offered_agent_id', $agentA->id)->where('caller_agent_id', $agentC->id)->count(),
            'a rejected offer() attempt must not create a row',
        );
    }

    // ---------------------------------------------------------------
    // offer() — deliberate asymmetry from AgentHelperAssignment: no subset
    // check (data-model.md §1, Grounding note 6)
    // ---------------------------------------------------------------

    #[Test]
    public function offer_succeeds_even_when_the_offered_agents_permitted_operations_exceed_the_callers_own(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $offered = $this->agent($owner, 'wide-offered-agent', '"*"');
        $caller = $this->agent($owner, 'narrow-caller-agent', 'contacts.*');

        $result = $this->service()->offer(
            $owner->id, $offered->id, $caller->id,
            'do_broad_thing', 'Does something broad.', 'What to do.',
        );

        $this->assertInstanceOf(
            CapabilityOffering::class,
            $result,
            'unlike AgentHelperAssignment, offering a broadly-capable agent to a narrowly-permitted caller must succeed at configuration time',
        );
        $this->assertDatabaseHas('agent_capability_offerings', [
            'offered_agent_id' => $offered->id,
            'caller_agent_id' => $caller->id,
        ]);
    }

    // ---------------------------------------------------------------
    // withdraw() — soft-delete / idempotent-false semantics
    // ---------------------------------------------------------------

    #[Test]
    public function withdraw_soft_deletes_an_active_row(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $offered = $this->agent($owner, 'withdraw-offered-agent', '"*"');
        $caller = $this->agent($owner, 'withdraw-caller-agent', 'contacts.*');

        $this->service()->offer(
            $owner->id, $offered->id, $caller->id,
            'do_thing', 'Does a thing.', 'What to do.',
        );

        $result = $this->service()->withdraw($owner->id, $offered->id, $caller->id);

        $this->assertTrue($result);
        $row = CapabilityOffering::withTrashed()
            ->where('offered_agent_id', $offered->id)
            ->where('caller_agent_id', $caller->id)
            ->first();
        $this->assertNotNull($row);
        $this->assertNotNull($row->deleted_at, 'withdraw() must soft-delete the row, not hard-delete it');
    }

    #[Test]
    public function withdraw_returns_false_and_never_throws_when_no_active_row_exists_for_the_pair(): void
    {
        $owner = $this->user();
        $this->seedThreeOperationCatalog();
        $offered = $this->agent($owner, 'withdraw-noop-offered-agent', '"*"');
        $caller = $this->agent($owner, 'withdraw-noop-caller-agent', 'contacts.*');

        // Deliberately never offered -- no active row for this pair.
        $result = $this->service()->withdraw($owner->id, $offered->id, $caller->id);

        $this->assertFalse($result, 'withdrawing a pair with no active offering must be a false, idempotent no-op, never an exception');
    }
}
