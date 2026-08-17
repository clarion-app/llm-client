<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentVersion;
use ClarionApp\LlmClient\Services\AgentDefinitionParser;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\GitDefinitionFileReader;
use ClarionApp\LlmClient\Services\SchedulerAgentProvisioner;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for SchedulerAgentProvisioner::ensureForUser() — idempotent
 * create-if-absent per-user provisioning of the `scheduler` agent from the
 * src/Templates/scheduler.yaml template, mirroring
 * ResearchAgentProvisionerTest/CodingAgentProvisionerTest/
 * DataAgentProvisionerTest exactly.
 *
 * scheduler.yaml's tools.allow and safety.* lists are all empty, so no
 * operation needs to resolve in the catalog for a valid parse — the
 * catalog is still seeded (with zero operations) rather than left
 * unseeded, so parse()'s unconditional catalog resolution never falls
 * through to the real doc generator.
 */
class SchedulerAgentProvisionerTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function provisioner(): SchedulerAgentProvisioner
    {
        return new SchedulerAgentProvisioner(
            new AgentService(new AgentDefinitionParser(), new GitDefinitionFileReader()),
        );
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

    private function seedSchedulerCatalog(): void
    {
        $this->seedOperationCatalog([]);
    }

    // ---------------------------------------------------------------
    // ensureForUser() — fresh user
    // ---------------------------------------------------------------

    #[Test]
    public function a_fresh_user_is_provisioned_a_scheduler_agent_bound_to_the_templates_first_version(): void
    {
        $this->seedSchedulerCatalog();
        $user = $this->user();

        $agent = $this->provisioner()->ensureForUser($user->id);

        $this->assertInstanceOf(Agent::class, $agent);
        $this->assertSame($user->id, $agent->user_id);
        $this->assertSame('scheduler', $agent->name);
        $this->assertNotNull($agent->current_version_id);

        $version = AgentVersion::find($agent->current_version_id);
        $this->assertNotNull($version, 'current_version_id must point at the just-created version');
        $this->assertSame(1, (int) $version->version_number);
        $this->assertSame($user->id, $version->changed_by_user_id);

        // The stored definition is the template's, byte-for-byte.
        $this->assertSame(
            file_get_contents(__DIR__.'/../../../src/Templates/scheduler.yaml'),
            $version->raw_definition,
        );
    }

    // ---------------------------------------------------------------
    // ensureForUser() — idempotent for the same user
    // ---------------------------------------------------------------

    #[Test]
    public function a_second_call_for_the_same_user_returns_the_existing_agent_with_no_duplicate_rows(): void
    {
        $this->seedSchedulerCatalog();
        $user = $this->user();

        $first = $this->provisioner()->ensureForUser($user->id);
        $second = $this->provisioner()->ensureForUser($user->id);

        $this->assertSame($first->id, $second->id, 'the second call must return the existing agent, not a new one');
        $this->assertSame($first->current_version_id, $second->current_version_id);

        $this->assertSame(
            1,
            Agent::where('user_id', $user->id)->where('name', 'scheduler')->count(),
            'no duplicate scheduler agent may be created for the same user',
        );
        $this->assertSame(
            1,
            AgentVersion::where('agent_id', $first->id)->count(),
            'no duplicate agent version may be created on a repeat provision',
        );
    }

    // ---------------------------------------------------------------
    // ensureForUser() — independent per user
    // ---------------------------------------------------------------

    #[Test]
    public function a_different_user_gets_their_own_independent_scheduler_agent(): void
    {
        $this->seedSchedulerCatalog();
        $userA = $this->user();
        $userB = $this->user();

        $agentA = $this->provisioner()->ensureForUser($userA->id);
        $agentB = $this->provisioner()->ensureForUser($userB->id);

        $this->assertNotSame($agentA->id, $agentB->id, 'each user gets their own scheduler agent');
        $this->assertSame($userA->id, $agentA->user_id);
        $this->assertSame($userB->id, $agentB->user_id);
        $this->assertSame('scheduler', $agentA->name);
        $this->assertSame('scheduler', $agentB->name);
        $this->assertNotSame($agentA->current_version_id, $agentB->current_version_id);
    }
}
