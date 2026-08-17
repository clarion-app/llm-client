<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentVersion;
use ClarionApp\LlmClient\Services\AgentDefinitionParser;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\DataAgentProvisioner;
use ClarionApp\LlmClient\Services\GitDefinitionFileReader;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for DataAgentProvisioner::ensureForUser() (Foundational,
 * FR-001) — idempotent create-if-absent per-user provisioning of the
 * `data` agent from the src/Templates/data.yaml template, mirroring
 * ResearchAgentProvisionerTest/CodingAgentProvisionerTest exactly.
 *
 * data.yaml's tools.allow is a bare GET verb (no glob, unlike
 * research.yaml's fetchPage.* glob), so the operation catalog seeded here
 * needs only one GET operation to resolve non-empty.
 */
class DataAgentProvisionerTest extends TestCase
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

    private function provisioner(): DataAgentProvisioner
    {
        return new DataAgentProvisioner(
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

    private function seedDataCatalog(): void
    {
        $this->seedOperationCatalog([
            'clarionApp.llmClient.conversations.index' => [
                'path' => '/api/conversations',
                'method' => 'get',
                'summary' => 'List conversations',
            ],
        ]);
    }

    // ---------------------------------------------------------------
    // ensureForUser() — fresh user
    // ---------------------------------------------------------------

    #[Test]
    public function a_fresh_user_is_provisioned_a_data_agent_bound_to_the_templates_first_version(): void
    {
        $this->seedDataCatalog();
        $user = $this->user();

        $agent = $this->provisioner()->ensureForUser($user->id);

        $this->assertInstanceOf(Agent::class, $agent);
        $this->assertSame($user->id, $agent->user_id);
        $this->assertSame('data', $agent->name);
        $this->assertNotNull($agent->current_version_id);

        $version = AgentVersion::find($agent->current_version_id);
        $this->assertNotNull($version, 'current_version_id must point at the just-created version');
        $this->assertSame(1, (int) $version->version_number);
        $this->assertSame($user->id, $version->changed_by_user_id);

        // The stored definition is the template's, byte-for-byte.
        $this->assertSame(
            file_get_contents(__DIR__.'/../../../src/Templates/data.yaml'),
            $version->raw_definition,
        );
    }

    // ---------------------------------------------------------------
    // ensureForUser() — idempotent for the same user
    // ---------------------------------------------------------------

    #[Test]
    public function a_second_call_for_the_same_user_returns_the_existing_agent_with_no_duplicate_rows(): void
    {
        $this->seedDataCatalog();
        $user = $this->user();

        $first = $this->provisioner()->ensureForUser($user->id);
        $second = $this->provisioner()->ensureForUser($user->id);

        $this->assertSame($first->id, $second->id, 'the second call must return the existing agent, not a new one');
        $this->assertSame($first->current_version_id, $second->current_version_id);

        $this->assertSame(
            1,
            Agent::where('user_id', $user->id)->where('name', 'data')->count(),
            'no duplicate data agent may be created for the same user',
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
    public function a_different_user_gets_their_own_independent_data_agent(): void
    {
        $this->seedDataCatalog();
        $userA = $this->user();
        $userB = $this->user();

        $agentA = $this->provisioner()->ensureForUser($userA->id);
        $agentB = $this->provisioner()->ensureForUser($userB->id);

        $this->assertNotSame($agentA->id, $agentB->id, 'each user gets their own data agent');
        $this->assertSame($userA->id, $agentA->user_id);
        $this->assertSame($userB->id, $agentB->user_id);
        $this->assertSame('data', $agentA->name);
        $this->assertSame('data', $agentB->name);
        $this->assertNotSame($agentA->current_version_id, $agentB->current_version_id);
    }
}
