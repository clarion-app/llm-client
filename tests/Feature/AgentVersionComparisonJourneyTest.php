<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Services\AgentService;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * spec.md US3 Acceptance Scenarios 1-3, FR-007/FR-008/FR-009,
 * quickstart.md steps 9-11 — Phase 5/T032 (090-agent-version-binding),
 * through the real HTTP endpoint `GET agents/versions/compare` (contracts
 * §4).
 *
 * Written first, confirmed FAILS — no such route is registered yet
 * (StoredAgentController's own routes are the only `agents/...` routes
 * present before T037), so every request here 404s at the router itself,
 * not at the controller's own ownership check.
 */
class AgentVersionComparisonJourneyTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->seedOperationCatalog([
            'contacts.list' => ['path' => '/api/contacts', 'method' => 'get', 'summary' => 'List contacts'],
            'contacts.create' => ['path' => '/api/contacts', 'method' => 'post', 'summary' => 'Create contact'],
        ]);
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

    private function base(): string
    {
        return '/api/clarion-app/llm-client/agents';
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
     * Creates an agent with three versions: v1 (instructions "Initial
     * instructions.", tools.allow ["contacts.list"]); v2, a content-
     * identical PUT (exists only so v3 is genuinely the third version, not
     * the second); v3, changing instructions AND adding one tools.allow
     * pattern only (contacts.create), leaving contacts.list untouched.
     *
     * @return array{agentId: string, v1: string, v3: string}
     */
    private function createThreeVersionAgent(): array
    {
        $v1Raw = "name: journey-agent\ninstructions: Initial instructions.\ntools:\n  allow:\n    - contacts.list";

        $createResponse = $this->actingAs($this->user, 'api')->postJson($this->base(), [
            'definition' => $v1Raw,
        ]);
        $createResponse->assertStatus(201);
        $agentId = $createResponse->json('id');
        $v1Id = DB::table('agents')->where('id', $agentId)->value('current_version_id');

        // v2: content-identical PUT, exists only to make v3 genuinely the
        // third version.
        $this->actingAs($this->user, 'api')->putJson($this->base()."/{$agentId}", [
            'definition' => $v1Raw,
        ])->assertStatus(200);

        // v3: instructions change AND one tools.allow pattern added only —
        // contacts.list stays, contacts.create is new.
        $v3Response = $this->actingAs($this->user, 'api')->putJson($this->base()."/{$agentId}", [
            'definition' => "name: journey-agent\ninstructions: Updated instructions.\ntools:\n  allow:\n    - contacts.list\n    - contacts.create",
        ]);
        $v3Response->assertStatus(200);
        $v3Id = DB::table('agents')->where('id', $agentId)->value('current_version_id');

        return ['agentId' => $agentId, 'v1' => $v1Id, 'v3' => $v3Id];
    }

    // ---------------------------------------------------------------
    // Two versions that differ report exactly what differs (AC1, FR-007).
    // ---------------------------------------------------------------

    #[Test]
    public function two_versions_that_differ_report_exactly_what_differs(): void
    {
        $fixture = $this->createThreeVersionAgent();

        $response = $this->actingAs($this->user, 'api')
            ->getJson($this->base()."/versions/compare?left={$fixture['v1']}&right={$fixture['v3']}");

        $response->assertStatus(200);
        $response->assertJsonPath('identical', false);
        $response->assertJsonPath('left_version_id', $fixture['v1']);
        $response->assertJsonPath('right_version_id', $fixture['v3']);

        $fieldDifferences = $response->json('field_differences');
        $this->assertCount(1, $fieldDifferences, 'exactly one field difference must be reported: ' . json_encode($fieldDifferences));
        $this->assertSame('instructions', $fieldDifferences[0]['field']);
        $this->assertSame('Initial instructions.', $fieldDifferences[0]['from']);
        $this->assertSame('Updated instructions.', $fieldDifferences[0]['to']);

        $listDifferences = $response->json('list_differences');
        $this->assertCount(1, $listDifferences, 'exactly one list difference must be reported: ' . json_encode($listDifferences));
        $this->assertSame('tools_allow', $listDifferences[0]['field'], 'wire field name must be snake_case');
        $this->assertSame(['contacts.create'], $listDifferences[0]['added']);
        $this->assertSame([], $listDifferences[0]['removed']);
    }

    // ---------------------------------------------------------------
    // Two identical versions report no differences (AC2, FR-008/SC-004) —
    // distinct from the SameVersion-refusal case (different version ids,
    // identical content).
    // ---------------------------------------------------------------

    #[Test]
    public function two_identical_versions_report_no_differences(): void
    {
        $createResponse = $this->actingAs($this->user, 'api')->postJson($this->base(), [
            'definition' => "name: restore-agent\ninstructions: Original.",
        ]);
        $createResponse->assertStatus(201);
        $agentId = $createResponse->json('id');
        $v1Id = DB::table('agents')->where('id', $agentId)->value('current_version_id');

        // 087's restore() always writes a new, content-identical version.
        $restoreResponse = $this->actingAs($this->user, 'api')
            ->postJson($this->base()."/{$agentId}/versions/{$v1Id}/restore");
        $restoreResponse->assertStatus(200);
        $v2Id = DB::table('agents')->where('id', $agentId)->value('current_version_id');

        $this->assertNotSame($v1Id, $v2Id, 'restore must always produce a genuinely new version id');

        $response = $this->actingAs($this->user, 'api')
            ->getJson($this->base()."/versions/compare?left={$v1Id}&right={$v2Id}");

        $response->assertStatus(200);
        $response->assertJsonPath('identical', true);
        $response->assertJsonPath('field_differences', []);
        $response->assertJsonPath('list_differences', []);
    }

    // ---------------------------------------------------------------
    // An unchanged setting never appears (AC3, FR-009).
    // ---------------------------------------------------------------

    #[Test]
    public function an_unchanged_setting_never_appears_in_the_response(): void
    {
        $fixture = $this->createThreeVersionAgent();

        $response = $this->actingAs($this->user, 'api')
            ->getJson($this->base()."/versions/compare?left={$fixture['v1']}&right={$fixture['v3']}");

        $response->assertStatus(200);

        $fieldNames = array_column($response->json('field_differences'), 'field');
        $listNames = array_column($response->json('list_differences'), 'field');

        $this->assertNotContains('name', $fieldNames, 'name is unchanged ("journey-agent" both sides) and must never appear');
        $this->assertNotContains('model', $fieldNames, 'model is unchanged (null both sides) and must never appear');
        $this->assertNotContains('capabilities', $listNames, 'capabilities are unchanged (both default) and must never appear');
    }

    // ---------------------------------------------------------------
    // Ownership scoping — a version belonging to a DIFFERENT user cannot
    // be compared, ever (uniform 404, never a 200 leaking cross-user data).
    // ---------------------------------------------------------------

    #[Test]
    public function a_version_belonging_to_a_different_user_cannot_be_compared(): void
    {
        $myAgentResponse = $this->actingAs($this->user, 'api')->postJson($this->base(), [
            'definition' => "name: my-agent",
        ]);
        $myAgentResponse->assertStatus(201);
        $myVersionId = DB::table('agents')->where('id', $myAgentResponse->json('id'))->value('current_version_id');

        $otherUser = User::factory()->create();
        $otherAgent = app(AgentService::class)->create($otherUser->id, "name: other-users-agent");
        $otherVersionId = $otherAgent->current_version_id;

        $response = $this->actingAs($this->user, 'api')
            ->getJson($this->base()."/versions/compare?left={$myVersionId}&right={$otherVersionId}");

        $response->assertStatus(404);
        $this->assertNotSame(200, $response->getStatusCode(), 'a version belonging to another user must never produce a 200, leaking its existence or content');
    }
}
