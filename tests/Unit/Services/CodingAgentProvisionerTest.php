<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentVersion;
use ClarionApp\LlmClient\Services\AgentDefinitionParser;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\CodingAgentProvisioner;
use ClarionApp\LlmClient\Services\GitDefinitionFileReader;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for CodingAgentProvisioner::ensureForUser() (112-coding-agent,
 * Foundational, D3, FR-001) — idempotent create-if-absent per-user
 * provisioning of the `coding` agent from src/Templates/coding.yaml,
 * mirroring ResearchAgentProvisionerTest exactly.
 *
 * coding.yaml's tools.allow/safety.confirmation_required name explicit
 * operationIds (not a glob, unlike research.yaml's fetchPage.* — each
 * coding-workspace POST must be named individually, D3), so the operation
 * catalog seeded here must contain exact matches for every one of them
 * plus one bare GET operation.
 */
class CodingAgentProvisionerTest extends TestCase
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

    private function provisioner(): CodingAgentProvisioner
    {
        return new CodingAgentProvisioner(
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

    private function seedCodingCatalog(): void
    {
        $this->seedOperationCatalog([
            'clarionApp.llmClient.codingWorkspace.writeFile' => [
                'path' => '/api/coding-project/{project}/file',
                'method' => 'post',
                'summary' => 'Write a file in a registered project',
            ],
            'clarionApp.llmClient.codingWorkspace.deleteFile' => [
                'path' => '/api/coding-project/{project}/file',
                'method' => 'delete',
                'summary' => 'Delete a file in a registered project',
            ],
            'clarionApp.llmClient.codingWorkspace.runTests' => [
                'path' => '/api/coding-project/{project}/run-tests',
                'method' => 'post',
                'summary' => 'Run a registered project\'s own test command',
            ],
            'clarionApp.llmClient.codingWorkspace.runCommand' => [
                'path' => '/api/coding-project/{project}/run-command',
                'method' => 'post',
                'summary' => 'Run a shell command in a registered project\'s sandboxed workspace',
            ],
            'clarionApp.llmClient.codingWorkspace.runCode' => [
                'path' => '/api/coding-project/{project}/run-code',
                'method' => 'post',
                'summary' => 'Run a code snippet in a registered project\'s sandboxed workspace',
            ],
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
    public function a_fresh_user_is_provisioned_a_coding_agent_bound_to_the_templates_first_version(): void
    {
        $this->seedCodingCatalog();
        $user = $this->user();

        $agent = $this->provisioner()->ensureForUser($user->id);

        $this->assertInstanceOf(Agent::class, $agent);
        $this->assertSame($user->id, $agent->user_id);
        $this->assertSame('coding', $agent->name);
        $this->assertNotNull($agent->current_version_id);

        $version = AgentVersion::find($agent->current_version_id);
        $this->assertNotNull($version, 'current_version_id must point at the just-created version');
        $this->assertSame(1, (int) $version->version_number);
        $this->assertSame($user->id, $version->changed_by_user_id);

        // The stored definition is the template's, byte-for-byte.
        $this->assertSame(
            file_get_contents(__DIR__.'/../../../src/Templates/coding.yaml'),
            $version->raw_definition,
        );
    }

    // ---------------------------------------------------------------
    // ensureForUser() — idempotent for the same user
    // ---------------------------------------------------------------

    #[Test]
    public function a_second_call_for_the_same_user_returns_the_existing_agent_with_no_duplicate_rows(): void
    {
        $this->seedCodingCatalog();
        $user = $this->user();

        $first = $this->provisioner()->ensureForUser($user->id);
        $second = $this->provisioner()->ensureForUser($user->id);

        $this->assertSame($first->id, $second->id, 'the second call must return the existing agent, not a new one');
        $this->assertSame($first->current_version_id, $second->current_version_id);

        $this->assertSame(
            1,
            Agent::where('user_id', $user->id)->where('name', 'coding')->count(),
            'no duplicate coding agent may be created for the same user',
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
    public function a_different_user_gets_their_own_independent_coding_agent(): void
    {
        $this->seedCodingCatalog();
        $userA = $this->user();
        $userB = $this->user();

        $agentA = $this->provisioner()->ensureForUser($userA->id);
        $agentB = $this->provisioner()->ensureForUser($userB->id);

        $this->assertNotSame($agentA->id, $agentB->id, 'each user gets their own coding agent');
        $this->assertSame($userA->id, $agentA->user_id);
        $this->assertSame($userB->id, $agentB->user_id);
        $this->assertSame('coding', $agentA->name);
        $this->assertSame('coding', $agentB->name);
        $this->assertNotSame($agentA->current_version_id, $agentB->current_version_id);
    }
}
