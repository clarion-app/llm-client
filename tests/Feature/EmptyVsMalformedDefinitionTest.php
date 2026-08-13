<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * POST /agents/check (088-agent-definition-validator, spec.md Edge Cases,
 * FR-012, research.md D5, quickstart.md step 9): an empty definition is
 * distinguishable, in the check's result, from a definition that is
 * structurally malformed -- both produce a specific, non-generic problem,
 * but a *different* kind in every one of the five sub-cases below.
 */
class EmptyVsMalformedDefinitionTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->seedOperationCatalog([]);
    }

    protected function tearDown(): void
    {
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('users')->delete();

        parent::tearDown();
    }

    private function checkUrl(): string
    {
        return '/api/clarion-app/llm-client/agents/check';
    }

    /**
     * Empty string, whitespace-only, and an explicit "{}" all fall through
     * to the ordinary MissingName check (research.md D5) -- never
     * MalformedYaml, and never a separate "empty" kind (D5's own "no new
     * enum case" decision).
     */
    #[Test]
    public function an_empty_a_whitespace_only_and_an_explicit_empty_mapping_document_each_report_missing_name(): void
    {
        foreach (['', "   \n", '{}'] as $raw) {
            $response = $this->actingAs($this->user)
                ->postJson($this->checkUrl(), ['definition' => $raw]);

            $response->assertStatus(200, 'input: ' . var_export($raw, true));
            $response->assertJson(['valid' => false], 'input: ' . var_export($raw, true));
            $this->assertCount(1, $response->json('problems'), 'input: ' . var_export($raw, true));
            $this->assertSame('MissingName', $response->json('problems.0.kind'), 'input: ' . var_export($raw, true));
            $this->assertSame('structural', $response->json('problems.0.category'), 'input: ' . var_export($raw, true));
        }
    }

    /**
     * A bare scalar root and a non-empty list root are genuinely
     * malformed -- a different kind from every one of the three empty
     * cases above.
     */
    #[Test]
    public function a_bare_scalar_and_a_non_empty_list_root_each_report_malformed_yaml(): void
    {
        foreach (['hello', "- a\n- b"] as $raw) {
            $response = $this->actingAs($this->user)
                ->postJson($this->checkUrl(), ['definition' => $raw]);

            $response->assertStatus(200, 'input: ' . var_export($raw, true));
            $response->assertJson(['valid' => false], 'input: ' . var_export($raw, true));
            $this->assertCount(1, $response->json('problems'), 'input: ' . var_export($raw, true));
            $this->assertSame('MalformedYaml', $response->json('problems.0.kind'), 'input: ' . var_export($raw, true));
        }
    }

    /**
     * FR-006's "same terms" guarantee applies to a save *attempt*, not
     * only to the dedicated check endpoint (research.md D8/D9) -- an
     * empty-string or whitespace-only `definition` posted directly to
     * `POST /agents`/`PUT /agents/{id}` must report the identical
     * {valid, problems, warnings} 422 shape `POST /agents/check` reports
     * for the same content (MissingName), not Laravel's differently-
     * shaped default `required`-rule validation-error body. store()/
     * update() use the same `present|nullable|string` rule as check()
     * for exactly this reason.
     */
    #[Test]
    public function an_empty_or_whitespace_only_definition_posted_directly_to_store_reports_missing_name_not_a_generic_validation_error(): void
    {
        foreach (['', "   \n"] as $raw) {
            $response = $this->actingAs($this->user)
                ->postJson('/api/clarion-app/llm-client/agents', ['definition' => $raw]);

            $response->assertStatus(422, 'input: ' . var_export($raw, true));
            $response->assertJson(['valid' => false], 'input: ' . var_export($raw, true));
            $this->assertCount(1, $response->json('problems'), 'input: ' . var_export($raw, true));
            $this->assertSame('MissingName', $response->json('problems.0.kind'), 'input: ' . var_export($raw, true));
        }
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
