<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A starting point whose tools.allow patterns cannot currently resolve
 * (the coding template's three codingWorkspace operations, and its bare
 * GET verb, left unregistered in the operation catalog -- the same empty
 * catalog OperationGroupPattern::resolve() short-circuits to [] for any
 * pattern, per its own implementation) must say so at both the point the
 * user is browsing and the point they try to create from it, and must
 * never silently produce an agent already known to fail on first use.
 *
 * Once the catalog carries every operation coding.yaml's tools.allow
 * depends on (the same fixture CodingAgentProvisionerTest::seedCodingCatalog()
 * establishes), the same starting point reports satisfied and creation
 * proceeds normally -- the identical check reused in both places, not a
 * separate "warning" path with its own logic.
 */
class StartingPointRequirementRefusalJourneyTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

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

    private function base(): string
    {
        return '/api/clarion-app/llm-client/agent-starting-points';
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

    /**
     * Registers every operation coding.yaml's tools.allow depends on --
     * its four named codingWorkspace operationIds plus one bare GET, via
     * conversations.index -- the same set CodingAgentProvisionerTest's own
     * seedCodingCatalog() registers for ensureForUser().
     */
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
                'summary' => "Run a registered project's own test command",
            ],
            'clarionApp.llmClient.codingWorkspace.runCommand' => [
                'path' => '/api/coding-project/{project}/run-command',
                'method' => 'post',
                'summary' => "Run a shell command in a registered project's sandboxed workspace",
            ],
            'clarionApp.llmClient.codingWorkspace.runCode' => [
                'path' => '/api/coding-project/{project}/run-code',
                'method' => 'post',
                'summary' => "Run a code snippet in a registered project's sandboxed workspace",
            ],
            'clarionApp.llmClient.codingWorkspace.gitCommit' => [
                'path' => '/api/coding-project/{project}/git-commit',
                'method' => 'post',
                'summary' => "Stage and commit a registered project's changes",
            ],
            'clarionApp.llmClient.codingWorkspace.gitPush' => [
                'path' => '/api/coding-project/{project}/git-push',
                'method' => 'post',
                'summary' => "Publish committed changes to a registered project's configured remote",
            ],
            'clarionApp.llmClient.codingWorkspace.gitBranch' => [
                'path' => '/api/coding-project/{project}/git-branch',
                'method' => 'post',
                'summary' => "Create a new branch in a registered project",
            ],
            'clarionApp.llmClient.codingWorkspace.gitRewriteHistory' => [
                'path' => '/api/coding-project/{project}/git-rewrite-history',
                'method' => 'post',
                'summary' => "Reset the branch pointer, optionally discarding uncommitted changes",
            ],
            'clarionApp.llmClient.conversations.index' => [
                'path' => '/api/conversations',
                'method' => 'get',
                'summary' => 'List conversations',
            ],
        ]);
    }

    private function codingEntry(array $data): ?array
    {
        foreach ($data as $entry) {
            if ($entry['slug'] === 'coding') {
                return $entry;
            }
        }

        return null;
    }

    // ---------------------------------------------------------------
    // Unmet requirement: reported at list time, refused at create time
    // ---------------------------------------------------------------

    #[Test]
    public function an_unmet_requirement_is_reported_at_list_time_and_refused_at_create_time_until_addressed(): void
    {
        // Nothing coding.yaml's tools.allow depends on is registered --
        // an entirely empty operation catalog, not merely a partial one,
        // so every one of its patterns (including the bare GET verb)
        // fails to resolve.
        $this->seedOperationCatalog([]);

        $listResponse = $this->actingAs($this->user)->getJson($this->base());
        $listResponse->assertStatus(200);

        $coding = $this->codingEntry($listResponse->json('data'));
        $this->assertNotNull($coding, 'the coding starting point must still appear in the list even when its requirements are unmet');
        $this->assertFalse($coding['requirements_satisfied'], 'coding must report its requirements as unmet against an empty operation catalog');
        $this->assertNotEmpty($coding['problems'], 'an unmet requirement must carry at least one reported problem');

        $kinds = array_column($coding['problems'], 'kind');
        $this->assertContains('EmptyOperationPattern', $kinds, 'the specific reported problem must name the unresolved pattern kind');

        $this->assertSame(0, Agent::count(), 'no agent may exist before attempting creation');

        $createResponse = $this->actingAs($this->user)->postJson($this->base().'/coding');
        $createResponse->assertStatus(422);
        $createResponse->assertJson([
            'valid' => false,
        ]);
        $createResponse->assertJsonStructure(['valid', 'problems', 'warnings']);

        $createProblemKinds = array_column($createResponse->json('problems'), 'kind');
        $this->assertContains('EmptyOperationPattern', $createProblemKinds);

        $this->assertSame(
            0,
            Agent::count(),
            'creation must not silently succeed while the starting point is known to be unsatisfied -- zero Agent rows may exist after a 422, not merely a non-201 status'
        );

        // -----------------------------------------------------------
        // Addressing the gap: the same starting point, now satisfied
        // -----------------------------------------------------------

        $this->seedCodingCatalog();

        $listResponseAfter = $this->actingAs($this->user)->getJson($this->base());
        $listResponseAfter->assertStatus(200);

        $codingAfter = $this->codingEntry($listResponseAfter->json('data'));
        $this->assertNotNull($codingAfter);
        $this->assertTrue($codingAfter['requirements_satisfied'], 'once the operation catalog carries everything coding.yaml depends on, its requirement is satisfied');
        $this->assertSame([], $codingAfter['problems'], 'a satisfied starting point must report no problems');

        $createResponseAfter = $this->actingAs($this->user)->postJson($this->base().'/coding');
        $createResponseAfter->assertStatus(201);

        $this->assertSame(
            1,
            Agent::count(),
            'creation must proceed normally once the previously unmet requirement is addressed'
        );
    }
}
