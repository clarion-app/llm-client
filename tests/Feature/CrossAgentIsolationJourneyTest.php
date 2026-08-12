<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Jobs\RunEvalCaseJob;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\EvalCaseExecutor;
use ClarionApp\LlmClient\Services\EvalReferenceService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-012: a reference designation and a comparison are both scoped to one
 * agent (research.md D4 — EvalRun.agent_label, never suite_id). Designating
 * a reference for a second, unrelated agent must never change what a
 * first agent's own run compares against, and EvalReferenceService itself
 * must have no way to be told a caller-supplied scope that mismatches the
 * run it names — the scope always comes from the run's own agent_label
 * column.
 */
class CrossAgentIsolationJourneyTest extends TestCase
{
    private User $operator;
    private Server $server;
    private string $agentLabelA = 'cross-isolation-agent-a';
    private string $agentLabelB = 'cross-isolation-agent-b';
    private string $suiteAId;
    private string $suiteBId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->declareSupportingSchema();

        $this->operator = User::factory()->create();
        config(['llm-client.cost.operator_user_ids' => [$this->operator->id]]);

        $this->server = Server::create([
            'name' => 'Cross-agent isolation fixture server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'provider_type' => 'openai',
        ]);

        RoleAssignment::create([
            'role' => 'inference',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $this->server->id,
            'model' => 'test-model',
        ]);

        $this->fakeProvider();

        $this->suiteAId = $this->createSuiteWithOneCheckableCase($this->agentLabelA, 'alpha');
        $this->suiteBId = $this->createSuiteWithOneCheckableCase($this->agentLabelB, 'bravo');
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

    private function comparisonUrl(string $runId): string
    {
        return $this->runsBase().'/'.$runId.'/comparison';
    }

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

    private function createSuiteWithOneCheckableCase(string $agentIdentifier, string $word): string
    {
        $suite = $this->actingAs($this->operator)->postJson($this->suitesBase(), [
            'name' => "Cross-agent isolation fixture suite ({$agentIdentifier})",
            'agent_identifier' => $agentIdentifier,
        ])->assertStatus(200)->json();

        $this->actingAs($this->operator)->postJson($this->suitesBase().'/'.$suite['id'].'/cases', [
            'given' => "Say the word {$word}",
            'expected_behavior' => "Answer with the single word {$word}.",
            'expectations' => [['kind' => 'text_match', 'expected_text' => $word]],
        ])->assertStatus(200)->json();

        return $suite['id'];
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
        $provider->shouldReceive('chat')->andReturnUsing(function (array $messages) {
            $firstUser = collect($messages)->firstWhere('role', 'user')['content'] ?? '';

            if (preg_match('/Say the word (\S+)/', $firstUser, $m)) {
                return $this->textChatResponse($m[1]);
            }

            return $this->textChatResponse('Acknowledged.');
        });
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);
        $this->app->instance(ProviderRegistry::class, $registry);
    }

    private function runToCompletion(string $suiteId): array
    {
        Bus::fake([RunEvalCaseJob::class]);

        $started = $this->actingAs($this->operator)
            ->postJson($this->suitesBase().'/'.$suiteId.'/runs')
            ->assertStatus(201)
            ->json();

        foreach (Bus::dispatched(RunEvalCaseJob::class) as $job) {
            $job->handle(app(EvalCaseExecutor::class));
        }

        return $this->actingAs($this->operator)
            ->getJson($this->runsBase().'/'.$started['id'])
            ->assertStatus(200)
            ->json();
    }

    // ---------------------------------------------------------------
    // FR-012: designating a reference for agent B must never affect
    // agent A's own comparison.
    // ---------------------------------------------------------------

    #[Test]
    public function a_reference_designated_for_a_second_unrelated_agent_never_affects_the_first_agents_own_comparison(): void
    {
        $runA1 = $this->runToCompletion($this->suiteAId);
        $this->actingAs($this->operator)->postJson($this->referenceUrl($runA1['id']))->assertStatus(201);

        $runA2 = $this->runToCompletion($this->suiteAId);

        // Agent B gets its own, entirely independent reference designated
        // in between agent A's two runs.
        $runB1 = $this->runToCompletion($this->suiteBId);
        $this->actingAs($this->operator)->postJson($this->referenceUrl($runB1['id']))->assertStatus(201);

        $comparisonA2 = $this->actingAs($this->operator)->getJson($this->comparisonUrl($runA2['id']));
        $comparisonA2->assertStatus(200);
        $this->assertSame(
            $runA1['id'],
            $comparisonA2->json('reference_run_id'),
            'agent B\'s own reference designation must never change what agent A\'s run resolves against (FR-012)'
        );

        // Symmetrically, agent B's own run must resolve only against its
        // own reference, never agent A's.
        $runB2 = $this->runToCompletion($this->suiteBId);
        $comparisonB2 = $this->actingAs($this->operator)->getJson($this->comparisonUrl($runB2['id']));
        $comparisonB2->assertStatus(200);
        $this->assertSame($runB1['id'], $comparisonB2->json('reference_run_id'));
        $this->assertNotSame($runA1['id'], $comparisonB2->json('reference_run_id'));
    }

    // ---------------------------------------------------------------
    // FR-012/research.md D4: the scope is derived, never accepted.
    // ---------------------------------------------------------------

    #[Test]
    public function designate_derives_agent_label_solely_from_the_designated_runs_own_column_never_from_a_caller_supplied_string(): void
    {
        $reflection = new \ReflectionMethod(EvalReferenceService::class, 'designate');
        $paramNames = collect($reflection->getParameters())->map(fn ($p) => $p->getName())->all();

        $this->assertSame(
            ['runId', 'userId'],
            $paramNames,
            'designate() must derive agent_label solely from the named run itself (research.md D4) — a third, caller-supplied scope parameter would let a designation be recorded under a scope mismatching the run it names'
        );

        $run = $this->runToCompletion($this->suiteAId);

        $designation = app(EvalReferenceService::class)->designate($run['id'], $this->operator->id);

        $this->assertSame(
            $this->agentLabelA,
            $designation->agent_label,
            'the written row\'s agent_label must come from the run\'s own column, matching the suite it was actually run against'
        );
    }
}
