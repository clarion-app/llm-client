<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\AgentVersion;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * POST /agents/check (088-agent-definition-validator, spec.md US2
 * Acceptance Scenarios 1-3, FR-006, SC-003, quickstart.md steps 1/2/4/5,
 * mutation-checklist row 3): an on-demand check and an actual save attempt
 * reach the identical conclusion for the same content, in every case --
 * same validity, same problems, byte-for-byte identical body on rejection.
 */
class PreSaveCheckEquivalenceJourneyTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->seedOperationCatalog([
            'contacts.store' => ['path' => '/api/contacts', 'method' => 'post', 'summary' => 'Store a contact'],
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

    private function checkUrl(): string
    {
        return $this->base() . '/check';
    }

    private function agentUrl(string $id): string
    {
        return $this->base() . '/' . $id;
    }

    /**
     * A multi-problem document: an unrecognized top-level key
     * (structural/UnknownKey), an unavailable model (semantic/UnknownModel),
     * and an operation pattern matching nothing (semantic/EmptyOperationPattern).
     */
    private function multiProblemDefinition(): string
    {
        return <<<YAML
name: my-agent
memroy:
  scratch: enabled
model: totally-not-a-real-model
tools:
  allow:
    - contakts.*
YAML;
    }

    private function cleanDefinition(string $name = 'clean-agent'): string
    {
        return "name: {$name}";
    }

    // ---------------------------------------------------------------
    // A multi-problem document, checked on demand (AC1/AC2/FR-006).
    // ---------------------------------------------------------------

    #[Test]
    public function checking_a_multi_problem_document_returns_200_with_every_problem_and_its_own_category(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson($this->checkUrl(), ['definition' => $this->multiProblemDefinition()]);

        $response->assertStatus(200);
        $response->assertJson(['valid' => false]);
        $this->assertCount(3, $response->json('problems'));

        $this->assertSame('structural', $response->json('problems.0.category'));
        $this->assertSame('UnknownKey', $response->json('problems.0.kind'));
        $this->assertSame('memroy', $response->json('problems.0.key'));

        $this->assertSame('semantic', $response->json('problems.1.category'));
        $this->assertSame('UnknownModel', $response->json('problems.1.kind'));

        $this->assertSame('semantic', $response->json('problems.2.category'));
        $this->assertSame('EmptyOperationPattern', $response->json('problems.2.kind'));

        $this->assertSame([], $response->json('warnings'));
    }

    // ---------------------------------------------------------------
    // The identical content submitted directly for saving, without a
    // prior check (AC2/FR-006/FR-007/FR-008).
    // ---------------------------------------------------------------

    #[Test]
    public function saving_the_identical_multi_problem_content_directly_is_rejected_with_a_byte_for_byte_identical_body(): void
    {
        $checkResponse = $this->actingAs($this->user)
            ->postJson($this->checkUrl(), ['definition' => $this->multiProblemDefinition()]);
        $checkResponse->assertStatus(200);

        $storeResponse = $this->actingAs($this->user)
            ->postJson($this->base(), ['definition' => $this->multiProblemDefinition()]);

        $storeResponse->assertStatus(422);
        $this->assertSame($checkResponse->json('valid'), $storeResponse->json('valid'));
        $this->assertSame($checkResponse->json('problems'), $storeResponse->json('problems'));
        $this->assertSame($checkResponse->json('warnings'), $storeResponse->json('warnings'));

        $this->assertSame(0, Agent::count(), 'a rejected store() must create no Agent row');
        $this->assertSame(0, AgentVersion::count(), 'a rejected store() must create no AgentVersion row');
    }

    // ---------------------------------------------------------------
    // A clean document: check and save reach the same conclusion (AC3).
    // ---------------------------------------------------------------

    #[Test]
    public function a_clean_document_checks_as_valid_then_saves_successfully_unchanged(): void
    {
        $checkResponse = $this->actingAs($this->user)
            ->postJson($this->checkUrl(), ['definition' => $this->cleanDefinition()]);

        $checkResponse->assertStatus(200);
        $checkResponse->assertExactJson([
            'valid' => true,
            'problems' => [],
            'warnings' => [],
        ]);

        $storeResponse = $this->actingAs($this->user)
            ->postJson($this->base(), ['definition' => $this->cleanDefinition()]);

        $storeResponse->assertStatus(201);
        $this->assertSame(1, Agent::count());
    }

    // ---------------------------------------------------------------
    // PUT /agents/{id} with the multi-problem content (contracts §3).
    // ---------------------------------------------------------------

    #[Test]
    public function updating_an_existing_agent_with_multi_problem_content_returns_the_identical_422_shape_and_leaves_current_version_unchanged(): void
    {
        $agentId = $this->actingAs($this->user)
            ->postJson($this->base(), ['definition' => $this->cleanDefinition('weather-agent')])
            ->assertStatus(201)
            ->json('id');

        $currentVersionIdBefore = DB::table('agents')->where('id', $agentId)->value('current_version_id');

        $checkResponse = $this->actingAs($this->user)
            ->postJson($this->checkUrl(), ['definition' => $this->multiProblemDefinition()]);
        $checkResponse->assertStatus(200);

        $updateResponse = $this->actingAs($this->user)
            ->putJson($this->agentUrl($agentId), ['definition' => $this->multiProblemDefinition()]);

        $updateResponse->assertStatus(422);
        $this->assertSame($checkResponse->json('valid'), $updateResponse->json('valid'));
        $this->assertSame($checkResponse->json('problems'), $updateResponse->json('problems'));
        $this->assertSame($checkResponse->json('warnings'), $updateResponse->json('warnings'));

        $this->assertSame(
            $currentVersionIdBefore,
            DB::table('agents')->where('id', $agentId)->value('current_version_id'),
            'a rejected update must leave current_version_id unchanged',
        );
    }

    /**
     * Seeds both of ApiManager's live-catalog seams -- see
     * AgentDefinitionFullJourneyTest/AgentDefinitionSafetyCeilingJourneyTest
     * for the established convention this mirrors exactly.
     *
     * @param array<string, array{path: string, method: string, summary: string}> $operations
     */
    private function seedOperationCatalog(array $operations): void
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
}
