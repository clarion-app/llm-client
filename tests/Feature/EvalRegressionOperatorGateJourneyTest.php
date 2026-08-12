<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Jobs\RunEvalCaseJob;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\EvalCaseExecutor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every route this feature adds is operator-gated, reads included: a
 * non-operator is refused with a 403 on all five (designate a reference,
 * both suite-scoped reference reads, the run comparison, and the
 * case-level comparison detail), with no eval_reference_designations row
 * written and no other state changed by any refused attempt, and an
 * operator succeeds on the identical calls — the positive control, so a
 * gate that refuses everyone equally cannot pass this test by accident
 * (the EvalJudgmentOperatorGateJourneyTest/079 and
 * EvalRunOperatorGateJourneyTest/078 precedent applied to this feature's
 * own five routes).
 */
class EvalRegressionOperatorGateJourneyTest extends TestCase
{
    private User $operator;
    private User $nonOperator;
    private Server $agentServer;
    private string $agentLabel = 'operator-gate-fixture-agent';
    private string $suiteId;
    private string $caseId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->declareSupportingSchema();

        $this->operator = User::factory()->create();
        $this->nonOperator = User::factory()->create();
        config(['llm-client.cost.operator_user_ids' => [$this->operator->id]]);

        $this->agentServer = Server::create([
            'name' => 'Operator gate fixture agent server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'provider_type' => 'openai',
        ]);

        RoleAssignment::create([
            'role' => 'inference',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $this->agentServer->id,
            'model' => 'test-model',
        ]);

        $this->fakeProvider();

        $suite = $this->actingAs($this->operator)->postJson($this->suitesBase(), [
            'name' => 'Operator gate fixture suite',
            'agent_identifier' => $this->agentLabel,
        ])->assertStatus(200)->json();
        $this->suiteId = $suite['id'];

        $case = $this->actingAs($this->operator)->postJson($this->suitesBase().'/'.$this->suiteId.'/cases', [
            'given' => 'Say the word echo',
            'expected_behavior' => 'Answer with the single word echo.',
            'expectations' => [['kind' => 'text_match', 'expected_text' => 'echo']],
        ])->assertStatus(200)->json();
        $this->caseId = $case['id'];
    }

    protected function tearDown(): void
    {
        Mockery::close();

        DB::table('eval_reference_designations')->delete();
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
    // Fixture helpers
    // ---------------------------------------------------------------

    private function suitesBase(): string
    {
        return '/api/clarion-app/llm-client/agent-eval-suites';
    }

    private function runsBase(): string
    {
        return '/api/clarion-app/llm-client/eval-runs';
    }

    private function referenceUrl(string $runId): string
    {
        return $this->runsBase().'/'.$runId.'/reference';
    }

    private function suiteReferenceUrl(): string
    {
        return $this->suitesBase().'/'.$this->suiteId.'/reference';
    }

    private function suiteReferenceHistoryUrl(): string
    {
        return $this->suitesBase().'/'.$this->suiteId.'/reference/history';
    }

    private function comparisonUrl(string $runId): string
    {
        return $this->runsBase().'/'.$runId.'/comparison';
    }

    private function caseDetailUrl(string $runId): string
    {
        return $this->comparisonUrl($runId).'/cases/'.$this->caseId;
    }

    /**
     * AgentLoopService::run() consults ConversationCondenser on every
     * call, unconditionally — the RegressionReportJourneyTest precedent.
     */
    private function declareSupportingSchema(): void
    {
        if (!Schema::hasTable('condensation_states')) {
            Schema::create('condensation_states', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('conversation_id')->unique();
                $table->unsignedInteger('consecutive_failures')->default(0);
                $table->timestamp('cooldown_until')->nullable();
                $table->timestamps();
            });
        }
    }

    private function textChatResponse(string $content): array
    {
        return [
            'choices' => [['message' => ['content' => $content]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ];
    }

    private function fakeProvider(): void
    {
        Http::fake();

        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturnUsing(fn () => $this->textChatResponse('echo'));
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);
        $this->app->instance(ProviderRegistry::class, $registry);
    }

    /**
     * Runs the fixture suite to completion as the operator and returns
     * the completed run — a genuinely finished run, not a hand-built
     * fixture row, so every route under test (including FR-014's "must
     * have finished" rule) sees the real thing.
     */
    private function runToCompletionAsOperator(): array
    {
        Bus::fake([RunEvalCaseJob::class]);

        $run = $this->actingAs($this->operator)
            ->postJson($this->suitesBase().'/'.$this->suiteId.'/runs')
            ->assertStatus(201)
            ->json();

        foreach (Bus::dispatched(RunEvalCaseJob::class) as $job) {
            $job->handle(app(EvalCaseExecutor::class));
        }

        return $this->actingAs($this->operator)
            ->getJson($this->runsBase().'/'.$run['id'])
            ->assertStatus(200)
            ->json();
    }

    // ---------------------------------------------------------------
    // Non-operator refused on every route, no row created or changed
    // ---------------------------------------------------------------

    #[Test]
    public function a_non_operator_is_refused_on_every_route_and_no_row_is_created_or_changed(): void
    {
        $runA = $this->runToCompletionAsOperator();
        $this->actingAs($this->operator)->postJson($this->referenceUrl($runA['id']))->assertStatus(201);
        $runB = $this->runToCompletionAsOperator();

        $designationsCountBefore = DB::table('eval_reference_designations')->count();
        $designationRowBefore = (array) DB::table('eval_reference_designations')
            ->where('run_id', $runA['id'])->first();

        $as = fn () => $this->actingAs($this->nonOperator);

        $as()->postJson($this->referenceUrl($runB['id']))->assertStatus(403);
        $as()->getJson($this->suiteReferenceUrl())->assertStatus(403);
        $as()->getJson($this->suiteReferenceHistoryUrl())->assertStatus(403);
        $as()->getJson($this->comparisonUrl($runB['id']))->assertStatus(403);
        $as()->getJson($this->caseDetailUrl($runB['id']))->assertStatus(403);

        // No refused write attempt above may have created a new
        // designation, and the existing one must be byte-identical.
        $this->assertSame(
            $designationsCountBefore,
            DB::table('eval_reference_designations')->count(),
            'no eval_reference_designations row may be created by a refused POST .../reference'
        );
        $designationRowAfter = (array) DB::table('eval_reference_designations')
            ->where('run_id', $runA['id'])->first();
        $this->assertSame(
            $designationRowBefore,
            $designationRowAfter,
            'the existing designation must be byte-identical after every refused route'
        );
    }

    // ---------------------------------------------------------------
    // Positive control — an operator succeeds on the identical calls.
    // ---------------------------------------------------------------

    #[Test]
    public function an_operator_succeeds_on_the_identical_calls(): void
    {
        $runA = $this->runToCompletionAsOperator();
        $this->actingAs($this->operator)->postJson($this->referenceUrl($runA['id']))->assertStatus(201);
        $runB = $this->runToCompletionAsOperator();

        $this->actingAs($this->operator)
            ->postJson($this->referenceUrl($runB['id']))
            ->assertStatus(201);

        $this->actingAs($this->operator)
            ->getJson($this->suiteReferenceUrl())
            ->assertStatus(200);

        $this->actingAs($this->operator)
            ->getJson($this->suiteReferenceHistoryUrl())
            ->assertStatus(200);

        $this->actingAs($this->operator)
            ->getJson($this->comparisonUrl($runB['id']))
            ->assertStatus(200);

        $this->actingAs($this->operator)
            ->getJson($this->caseDetailUrl($runB['id']))
            ->assertStatus(200);
    }
}
