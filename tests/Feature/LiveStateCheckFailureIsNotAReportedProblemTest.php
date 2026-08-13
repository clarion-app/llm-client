<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * POST /agents/check (088-agent-definition-validator, spec's own Edge Cases
 * section, quickstart.md step 10, mutation-checklist row 7's HTTP-level
 * confirmation): a check that cannot complete -- because live installation
 * state itself failed to resolve -- must never be indistinguishable from a
 * check that completed and found problems. A genuine infrastructure
 * failure surfaces as an ordinary uncaught-exception 500 (Laravel's
 * default, no new handler), never a 200 body with a problems entry
 * describing it (research.md D6).
 */
class LiveStateCheckFailureIsNotAReportedProblemTest extends TestCase
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

    #[Test]
    public function a_live_database_failure_resolving_the_stated_model_surfaces_as_an_uncaught_500_never_a_200_problem(): void
    {
        // Forces LanguageModel::where('name', ...)->exists() to fail as a
        // genuine infrastructure error -- the identical fixture technique
        // AgentDefinitionParserCollectTest/AgentDefinitionValidatorTest use
        // at the Unit level, applied here through the real HTTP endpoint.
        Schema::drop('language_models');

        $raw = <<<YAML
name: broken-agent
model: some-model
YAML;

        $response = $this->actingAs($this->user)
            ->postJson($this->checkUrl(), ['definition' => $raw]);

        $response->assertStatus(500);

        // Never converted into a completed-check shape describing the
        // failure as a "problem" -- the one distinction this Edge Case
        // exists to preserve.
        $this->assertNull($response->json('valid'));
        $this->assertNull($response->json('problems'));
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
