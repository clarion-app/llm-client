<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Jobs\RunEvalCaseJob;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Models\Server;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-019 across every route this feature adds, reads included (contracts/
 * eval-runs-api.md's opening note — this gate is not waived under a
 * tripped spending ceiling, research.md D10): a non-operator is refused
 * everywhere with no run created or changed by any write attempt, and an
 * operator succeeds on the identical calls (positive control, the
 * EvalSuiteOperatorGateJourneyTest/077 precedent) — so a gate that refuses
 * everyone equally cannot pass this test by accident.
 *
 * A real Server + inference RoleAssignment is seeded so a started run
 * genuinely reaches status "in_progress" (not "failed_to_start") and
 * POST .../resume genuinely returns 200 for the operator rather than the
 * 422 "already finished" a terminal run would produce — the positive
 * control has to be a real success, not merely "not 403". RunEvalCaseJob
 * dispatch is captured via Bus::fake() so no case is actually executed —
 * this test is about the gate, not the agent loop.
 */
class EvalRunOperatorGateJourneyTest extends TestCase
{
    private User $operator;
    private User $nonOperator;
    private string $suiteId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = User::factory()->create();
        $this->nonOperator = User::factory()->create();
        config(['llm-client.cost.operator_user_ids' => [$this->operator->id]]);

        $server = Server::create([
            'name' => 'Operator gate fixture server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'provider_type' => 'openai',
        ]);

        RoleAssignment::create([
            'role' => 'inference',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $server->id,
            'model' => 'test-model',
        ]);

        $suite = $this->actingAs($this->operator)->postJson($this->suitesBase(), [
            'name' => 'Operator gate fixture suite',
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

    /**
     * Starts a run as the operator with dispatch captured (not executed)
     * by Bus::fake() — this test needs a real, in_progress run to probe
     * every read/resume route against, not a fully-executed one.
     */
    private function startRunAsOperator(): array
    {
        Bus::fake([RunEvalCaseJob::class]);

        return $this->actingAs($this->operator)
            ->postJson($this->suitesBase().'/'.$this->suiteId.'/runs')
            ->assertStatus(201)
            ->json();
    }

    // ---------------------------------------------------------------
    // Non-operator is refused on every route, no row created or changed
    // ---------------------------------------------------------------

    #[Test]
    public function a_non_operator_is_refused_on_every_route_and_no_run_is_created_or_changed(): void
    {
        $run = $this->startRunAsOperator();
        $runId = $run['id'];
        $this->assertSame('in_progress', $run['status'], 'Fixture precondition: the run must genuinely be in_progress, not failed_to_start');

        $runsCountBefore = DB::table('eval_runs')->count();
        $runRowBefore = (array) DB::table('eval_runs')->where('id', $runId)->first();
        $runCaseRowsBefore = DB::table('eval_run_cases')->where('run_id', $runId)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $resultsCountBefore = DB::table('eval_case_results')->count();

        // Re-fake to clear the one dispatch startRunAsOperator() itself
        // made — this assertion is about what the refused attempts below
        // dispatch, not about the legitimate operator start above.
        Bus::fake([RunEvalCaseJob::class]);

        $as = fn () => $this->actingAs($this->nonOperator);

        // Every route this feature adds (contracts/eval-runs-api.md §2-3).
        $as()->postJson($this->suitesBase().'/'.$this->suiteId.'/runs')->assertStatus(403);
        $as()->getJson($this->suitesBase().'/'.$this->suiteId.'/runs')->assertStatus(403);
        $as()->getJson($this->runsBase().'/'.$runId)->assertStatus(403);
        $as()->getJson($this->runsBase().'/'.$runId.'/cases')->assertStatus(403);
        $as()->postJson($this->runsBase().'/'.$runId.'/resume')->assertStatus(403);

        // No write attempt above may have created or changed anything —
        // no new eval_runs row (the refused POST .../runs), no case
        // redispatched or status-changed (the refused POST .../resume).
        $this->assertSame($runsCountBefore, DB::table('eval_runs')->count(), 'No eval_runs row may be created by a refused start');
        $runRowAfter = (array) DB::table('eval_runs')->where('id', $runId)->first();
        $this->assertSame($runRowBefore, $runRowAfter, 'The existing run must be byte-identical after every refused route');

        $runCaseRowsAfter = DB::table('eval_run_cases')->where('run_id', $runId)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $this->assertSame($runCaseRowsBefore, $runCaseRowsAfter, 'No case may be redispatched by a refused resume');

        $this->assertSame($resultsCountBefore, DB::table('eval_case_results')->count(), 'No result row may be created by a refused route');

        Bus::assertNotDispatched(RunEvalCaseJob::class);
    }

    // ---------------------------------------------------------------
    // Positive control — an operator succeeds on the identical calls
    // ---------------------------------------------------------------

    #[Test]
    public function an_operator_succeeds_on_the_identical_calls(): void
    {
        $run = $this->startRunAsOperator();
        $runId = $run['id'];
        $this->assertSame('in_progress', $run['status']);

        $this->actingAs($this->operator)
            ->getJson($this->suitesBase().'/'.$this->suiteId.'/runs')
            ->assertStatus(200);

        $this->actingAs($this->operator)
            ->getJson($this->runsBase().'/'.$runId)
            ->assertStatus(200);

        $this->actingAs($this->operator)
            ->getJson($this->runsBase().'/'.$runId.'/cases')
            ->assertStatus(200);

        $this->actingAs($this->operator)
            ->postJson($this->runsBase().'/'.$runId.'/resume')
            ->assertStatus(200);
    }
}
