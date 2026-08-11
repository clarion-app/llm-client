<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Models\Server;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * spec.md Edge Case / FR-014 / SC-006 / quickstart.md step 7: when the
 * installation has no effective inference model, the run itself still
 * starts — contracts §2's explicit note that this is a 201, not an error
 * status — but its own status carries the one clear reason, and zero
 * per-case failures are ever fabricated to stand in for it (the literal
 * thing FR-014/SC-006 rule out).
 */
class AgentUnavailableJourneyTest extends TestCase
{
    private User $operator;
    private string $suiteId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = User::factory()->create();
        config(['llm-client.cost.operator_user_ids' => [$this->operator->id]]);

        $suite = $this->actingAs($this->operator)->postJson($this->suitesBase(), [
            'name' => 'Agent-unavailable fixture suite',
            'agent_identifier' => 'home-automation-agent',
        ])->assertStatus(200)->json();
        $this->suiteId = $suite['id'];

        $this->actingAs($this->operator)->postJson($this->suitesBase().'/'.$this->suiteId.'/cases', [
            'given' => 'Hello there.',
            'expected_behavior' => 'Say hello back.',
            'expectations' => [['kind' => 'text_match', 'expected_text' => 'Hello!']],
        ])->assertStatus(200);
    }

    protected function tearDown(): void
    {
        DB::table('eval_case_results')->delete();
        DB::table('eval_run_cases')->delete();
        DB::table('eval_runs')->delete();
        DB::table('eval_case_versions')->delete();
        DB::table('eval_cases')->delete();
        DB::table('eval_suites')->delete();
        DB::table('llm_role_assignments')->delete();
        DB::table('llm_servers')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function suitesBase(): string
    {
        return '/api/clarion-app/llm-client/agent-eval-suites';
    }

    private function runsBase(): string
    {
        return '/api/clarion-app/llm-client/eval-runs';
    }

    private function assertRunFailsToStartCleanly(): void
    {
        $response = $this->actingAs($this->operator)
            ->postJson($this->suitesBase().'/'.$this->suiteId.'/runs');

        // The request itself succeeds — this is not a rejection of the
        // start attempt, it is the run's own status reporting the failure.
        $response->assertStatus(201);
        $body = $response->json();
        $this->assertSame('failed_to_start', $body['status']);
        $this->assertNotEmpty($body['failure_reason']);

        $casesResponse = $this->actingAs($this->operator)
            ->getJson($this->runsBase().'/'.$body['id'].'/cases');
        $casesResponse->assertStatus(200);
        $this->assertEmpty(
            $casesResponse->json('data'),
            'A failed-to-start run must list zero cases — never one entry per case marked individually failed'
        );

        $listResponse = $this->actingAs($this->operator)
            ->getJson($this->suitesBase().'/'.$this->suiteId.'/runs');
        $listResponse->assertStatus(200);
        $listedIds = collect($listResponse->json('data'))->pluck('id')->all();
        $this->assertContains(
            $body['id'],
            $listedIds,
            'A failed-to-start run must still be shown to the operator, not hidden (FR-014)'
        );
    }

    // ---------------------------------------------------------------
    // Unassigned role
    // ---------------------------------------------------------------

    #[Test]
    public function with_no_inference_role_assigned_the_run_still_starts_but_fails_cleanly_with_no_per_case_failures(): void
    {
        // No RoleAssignment row of any kind exists — RoleResolver::resolve()
        // returns an unassigned resolution.
        $this->assertRunFailsToStartCleanly();
    }

    // ---------------------------------------------------------------
    // Role pointed at a deleted server (broken, not merely unassigned)
    // ---------------------------------------------------------------

    #[Test]
    public function with_the_inference_role_pointed_at_a_deleted_server_the_run_still_starts_but_fails_cleanly(): void
    {
        $server = Server::create([
            'name' => 'Deleted server',
            'server_url' => 'https://example.test/v1/chat/completions',
            'provider_type' => 'openai',
        ]);

        RoleAssignment::create([
            'role' => 'inference',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $server->id,
            'model' => 'test-model',
        ]);

        $server->delete();

        $this->assertRunFailsToStartCleanly();
    }
}
