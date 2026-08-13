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
 * A risky-but-valid definition warns without blocking
 * (088-agent-definition-validator, spec.md US3 Acceptance Scenarios 1-3,
 * FR-010/FR-011, SC-005/SC-006, quickstart.md steps 7-8, mutation-checklist
 * rows 9-10): a definition permitting a destructive (config('llm-client.
 * confirm_methods')) operation via tools.allow with no covering
 * safety.confirmation_required entry is flagged with a warning, distinct
 * from any blocking problem, and can still be saved -- the warning alone
 * never overrides a genuine blocking problem, and never blocks saving on
 * its own.
 */
class RiskyButValidWarningJourneyTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->seedOperationCatalog([
            'contacts.destroy' => ['path' => '/api/contacts/{id}', 'method' => 'delete', 'summary' => 'Delete a contact'],
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

    /**
     * Permits contacts.destroy (a DELETE-method operation, the default
     * config('llm-client.confirm_methods')) via tools.allow, with no
     * covering safety.confirmation_required entry -- the exact shape
     * research.md D2 names.
     */
    private function warningDefinition(): string
    {
        return <<<YAML
name: risky-agent
tools:
  allow:
    - contacts.destroy
YAML;
    }

    /**
     * The identical warning-triggering shape above, combined with an
     * unrecognized capability -- a genuine blocking problem alongside the
     * non-blocking warning.
     */
    private function warningAndProblemDefinition(): string
    {
        return <<<YAML
name: risky-agent
capabilities:
  - not_a_real_capability
tools:
  allow:
    - contacts.destroy
YAML;
    }

    // ---------------------------------------------------------------
    // Step 7: the warning does not block (AC1/AC2, mutation-checklist
    // row 9).
    // ---------------------------------------------------------------

    #[Test]
    public function checking_a_document_with_the_destructive_shape_reports_valid_true_with_one_warning(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson($this->checkUrl(), ['definition' => $this->warningDefinition()]);

        $response->assertStatus(200);
        $response->assertJson(['valid' => true]);
        $this->assertSame([], $response->json('problems'));

        $this->assertCount(1, $response->json('warnings'));
        $this->assertSame('DestructiveOperationWithoutConfirmation', $response->json('warnings.0.kind'));
        $this->assertSame('contacts.destroy', $response->json('warnings.0.operation_id'));
        $this->assertSame('DELETE', $response->json('warnings.0.method'));
    }

    #[Test]
    public function saving_the_identical_document_succeeds_and_the_201_body_carries_the_identical_warning(): void
    {
        $checkResponse = $this->actingAs($this->user)
            ->postJson($this->checkUrl(), ['definition' => $this->warningDefinition()]);
        $checkResponse->assertStatus(200);

        $storeResponse = $this->actingAs($this->user)
            ->postJson($this->base(), ['definition' => $this->warningDefinition()]);

        $storeResponse->assertStatus(201);
        $this->assertSame(1, Agent::count());

        // The 201 body's own warnings array is not omitted -- a warning
        // that only ever appears on POST /agents/check and vanishes the
        // moment the definition is actually saved would defeat the
        // point of "warn" as an ongoing property of the saved agent.
        $this->assertSame($checkResponse->json('warnings'), $storeResponse->json('warnings'));
        $this->assertCount(1, $storeResponse->json('warnings'));
    }

    // ---------------------------------------------------------------
    // Step 8: a warning and a blocking problem both reported, only the
    // blocking one prevents saving (AC3, mutation-checklist row 10).
    // ---------------------------------------------------------------

    #[Test]
    public function checking_a_document_with_both_a_warning_and_a_blocking_problem_reports_both_clearly_separated(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson($this->checkUrl(), ['definition' => $this->warningAndProblemDefinition()]);

        $response->assertStatus(200);
        $response->assertJson(['valid' => false]);

        $this->assertCount(1, $response->json('problems'));
        $this->assertSame('UnknownCapability', $response->json('problems.0.kind'));

        $this->assertCount(1, $response->json('warnings'));
        $this->assertSame('DestructiveOperationWithoutConfirmation', $response->json('warnings.0.kind'));
        $this->assertSame('contacts.destroy', $response->json('warnings.0.operation_id'));
    }

    #[Test]
    public function saving_the_document_with_the_blocking_problem_is_rejected_despite_the_warning_alone_being_harmless(): void
    {
        $storeResponse = $this->actingAs($this->user)
            ->postJson($this->base(), ['definition' => $this->warningAndProblemDefinition()]);

        // The warning never overrides the blocking problem -- it is
        // still rejected exactly as it would be without the warning
        // shape present at all.
        $storeResponse->assertStatus(422);
        $this->assertSame(0, Agent::count());
        $this->assertSame(0, AgentVersion::count());
    }

    #[Test]
    public function fixing_only_the_capability_and_leaving_the_warning_shape_untouched_now_saves_successfully_with_the_warning_present(): void
    {
        // Fix only the unrecognized capability -- the destructive-
        // without-confirmation shape is deliberately left untouched.
        $storeResponse = $this->actingAs($this->user)
            ->postJson($this->base(), ['definition' => $this->warningDefinition()]);

        $storeResponse->assertStatus(201);
        $this->assertSame(1, Agent::count());
        $this->assertNotSame([], $storeResponse->json('warnings'));
        $this->assertCount(1, $storeResponse->json('warnings'));
        $this->assertSame('DestructiveOperationWithoutConfirmation', $storeResponse->json('warnings.0.kind'));
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
