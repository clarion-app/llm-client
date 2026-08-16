<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\CapabilityOffering;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\DelegationService;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 110-delegation-deadlock-timeout, Phase 4 (US2, tasks.md T018,
 * research.md D1, contracts/delegation-chain-bounds.md §1).
 *
 * DelegationService::invokeAsCapability() has no `delegation_chain_time_exceeded`
 * check yet -- it is added by T024, mirroring resolveAndValidate()'s own
 * T023 check exactly (same chainRootStartedAt() comparison against
 * config('llm-client.delegation.max_chain_seconds')), returning before
 * createDelegationRow() is ever reached -- identical to how the existing
 * depth/identity refusals in this method already short-circuit before any
 * row is written.
 *
 * Because the check does not exist yet, this test's scenario -- a caller
 * conversation whose own chain is already older than max_chain_seconds --
 * currently sails straight through into createDelegationRow() and the
 * nested AgentLoopService::run() call. To keep this a controlled,
 * Unit-level test of the refusal path alone (never a live model call), the
 * AgentLoopService collaborator is replaced with a Mockery double bound
 * into the container BEFORE DelegationService is resolved (mirroring
 * CapabilityAgentFailureTranslationTest's own `$this->app->instance(...)`
 * substitution, but at the collaborator-injection level rather than the
 * LlmProvider level, since this file never needs a real agent loop to run
 * at all) -- scripted to return an ordinary completed six-field-shaped
 * result. Today, with no chain-time check in place, invokeAsCapability()
 * reaches that stub and returns its (empty) output content directly,
 * producing a result that is NOT the plain {"error": "..."} refusal shape
 * this test expects -- the correct, expected red for this phase, until
 * T024 lands.
 */
class DelegationServiceCapabilityChainTimeBoundTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('agent_delegations')->delete();
        DB::table('agent_capability_offerings')->delete();
        DB::table('conversations')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Operation-catalog scaffolding
    // -----------------------------------------------------------------

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

    // -----------------------------------------------------------------
    // Fixture helpers
    // -----------------------------------------------------------------

    private function user(): User
    {
        return User::factory()->create();
    }

    private function makeAgent(User $owner, string $name): Agent
    {
        $this->seedOperationCatalog();

        return app(AgentService::class)->create($owner->id, "name: {$name}\ninstructions: I am {$name}.");
    }

    private function conversation(User $owner, ?Agent $agent = null): Conversation
    {
        return Conversation::factory()->create([
            'user_id' => $owner->id,
            'title' => 'Already titled',
            'agent_id' => $agent?->id,
            'agent_version_id' => $agent?->current_version_id,
        ]);
    }

    private function seedDelegationRow(array $overrides = []): Delegation
    {
        return Delegation::create(array_merge([
            'parent_conversation_id' => (string) Str::uuid(),
            'parent_agent_id' => (string) Str::uuid(),
            'helper_agent_id' => (string) Str::uuid(),
            'helper_conversation_id' => (string) Str::uuid(),
            'owner_user_id' => (string) Str::uuid(),
            'task' => 'Do a thing.',
            'depth' => 1,
            'status' => 'in_progress',
            'started_at' => now(),
            'origin' => 'delegate_to_helper',
        ], $overrides));
    }

    /**
     * Replaces AgentLoopService with a stub that returns an ordinary
     * completed six-field-shaped result -- never a real model call. Bound
     * into the container so DelegationService (which is resolved fresh
     * inside the test, after this call) receives it via constructor
     * injection.
     */
    private function stubAgentLoopServiceWithCompletedResult(): void
    {
        $mock = Mockery::mock(AgentLoopService::class);
        $mock->shouldReceive('run')->andReturn([
            'status' => 'completed',
            'content' => 'stub completed content',
            'validated' => [
                'status' => 'success',
                'summary' => 'stub summary',
                'output' => [],
                'undone' => '',
            ],
        ]);

        $this->app->instance(AgentLoopService::class, $mock);
    }

    private function delegationService(): DelegationService
    {
        return app(DelegationService::class);
    }

    // =================================================================
    // T018 -- the identical chain-time refusal, applied to
    // invokeAsCapability()'s own caller conversation, reaches the caller
    // as the plain {"error": "..."} shape execute_operation always uses
    // -- never the raw six-field envelope, and never a live model call
    // (FR-016/FR-017's own shape, already proven for every other
    // invokeAsCapability() failure path by CapabilityAgentFailureTranslationTest).
    // =================================================================

    #[Test]
    public function a_chain_whose_cumulative_elapsed_time_exceeds_max_chain_seconds_is_refused_through_invoke_as_capability(): void
    {
        config(['llm-client.delegation.max_chain_seconds' => 5]);

        $owner = $this->user();
        $agentB = $this->makeAgent($owner, 'cap-chain-time-b');
        $agentC = $this->makeAgent($owner, 'cap-chain-time-c');

        $offeringForC = CapabilityOffering::create([
            'offered_agent_id' => $agentC->id,
            'caller_agent_id' => $agentB->id,
            'owner_user_id' => $owner->id,
            'capability_name' => 'do_a_thing',
            'capability_description' => 'Does a thing.',
            'input_description' => 'What to do.',
        ]);

        // conv0 (an arbitrary, unbound root) -> conv1(B): conv1 is B's own
        // live conversation, already part of a chain whose root hop began
        // well past max_chain_seconds ago. B now attempts to invoke C's
        // offered capability -- one hop further on the SAME chain.
        $conv0 = Conversation::factory()->create(['user_id' => $owner->id, 'title' => 'Already titled']);
        $conv1 = $this->conversation($owner, $agentB);

        $this->seedDelegationRow([
            'parent_conversation_id' => $conv0->id,
            'helper_conversation_id' => $conv1->id,
            'owner_user_id' => $owner->id,
            'depth' => 1,
            'started_at' => now()->subSeconds(30),
        ]);

        $this->stubAgentLoopServiceWithCompletedResult();

        $before = Delegation::count();

        $result = $this->delegationService()->invokeAsCapability($conv1, $offeringForC, 'Please do the thing.');

        $this->assertSame(
            ['error'],
            array_keys($result),
            'a chain-time-exceeded capability call must be refused with the plain {"error": "..."} shape execute_operation always uses -- never the raw six-field delegation envelope (status/helper/delegation_id/reason), and never the offered agent\'s own (stubbed) output content reaching the caller',
        );
        $this->assertIsString($result['error'] ?? null);
        $this->assertNotSame('', $result['error'] ?? '');

        foreach (['status', 'helper', 'delegation_id', 'reason'] as $forbiddenField) {
            $this->assertArrayNotHasKey(
                $forbiddenField,
                $result,
                "no delegation envelope field (\"{$forbiddenField}\") may leak through on the chain-time-refused path",
            );
        }

        $this->assertSame(
            $before,
            Delegation::count(),
            'a chain-time-refused capability call must never write a new Delegation row for the offered agent -- identical "refused before it executes" contract the existing depth/identity refusals in invokeAsCapability() already honor',
        );
    }
}
