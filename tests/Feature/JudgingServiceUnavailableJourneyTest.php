<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Jobs\RunEvalCaseJob;
use ClarionApp\LlmClient\Models\CostSummary;
use ClarionApp\LlmClient\Models\EvalJudgment;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\SpendingCeiling;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\EvalCaseExecutor;
use ClarionApp\LlmClient\Services\SpendingCeilingService;
use ClarionApp\LlmClient\ValueObjects\BudgetScope;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * spec.md Edge Case / FR-013 / SC-004 / quickstart.md step 9, through real
 * HTTP: whatever the cause, a judging-side failure must never turn an
 * already-successful agent execution into anything but "unjudged" — never a
 * silently-assumed pass or fail, and never the case's own `errored` outcome
 * (mutation-checklist row 1 — the case a regression here would turn into
 * `errored`, swallowing the agent's already-successful response).
 *
 * Three independent causes are exercised, each converging on the same
 * observable shape: an unassigned judge role, a tripped spending ceiling,
 * and a provider that throws on chat(). The unassigned-role and
 * provider-throws causes run a full suite through the real,
 * individually-dispatched RunEvalCaseJob/EvalCaseExecutor — not
 * Queue::fake() — matching the harness RubricJudgmentJourneyTest already
 * established.
 *
 * The spending-ceiling cause deliberately does NOT run a full in-run case
 * execution — see the dedicated scenario below for why a tripped-ceiling
 * refusal is genuinely reachable through this package's real HTTP surface
 * only via the standalone consistency-check endpoint (US2), never via an
 * in-run judgment for a case whose own agent call already succeeded in the
 * same job.
 */
class JudgingServiceUnavailableJourneyTest extends TestCase
{
    private User $operator;
    private Server $agentServer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->declareSupportingSchema();

        $this->operator = User::factory()->create();
        config(['llm-client.cost.operator_user_ids' => [$this->operator->id]]);

        $this->agentServer = Server::create([
            'name' => 'Unavailable journey agent server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'provider_type' => 'openai',
        ]);

        RoleAssignment::create([
            'role' => 'inference',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $this->agentServer->id,
            'model' => 'agent-test-model',
        ]);

        $this->seedApiDocsCache();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->seedApiDocsCache(null);

        DB::table('eval_judgments')->delete();
        DB::table('eval_case_results')->delete();
        DB::table('eval_run_cases')->delete();
        DB::table('eval_runs')->delete();
        DB::table('eval_case_versions')->delete();
        DB::table('eval_cases')->delete();
        DB::table('eval_suites')->delete();
        DB::table('messages')->delete();
        DB::table('conversations')->delete();
        DB::table('llm_role_assignments')->delete();
        DB::table('llm_servers')->delete();
        DB::table('spending_ceilings')->delete();
        DB::table('cost_summaries')->delete();
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

    private function consistencyChecksUrl(string $suiteId, string $caseId): string
    {
        return $this->suitesBase().'/'.$suiteId.'/cases/'.$caseId.'/consistency-checks';
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

    private function seedApiDocsCache(?array $doc = ['paths' => []]): void
    {
        $prop = (new \ReflectionClass(ApiManager::class))->getProperty('apiDocsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, $doc);
    }

    private function textChatResponse(string $content): array
    {
        return [
            'choices' => [['message' => ['content' => $content]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            'model' => 'test-model',
        ];
    }

    /**
     * Registers a fixture ProviderRegistry whose agent server always
     * answers normally. $judgeChat, when given, is used for calls resolved
     * against $judgeServer specifically; when omitted, no judge server is
     * registered with the mock at all (the "role points at a server the
     * registry can't resolve for" isn't exercised here — the unassigned-role
     * scenario never reaches ProviderRegistry::resolve() in the first
     * place).
     */
    private function fakeProviders(?Server $judgeServer, ?callable $judgeChat): void
    {
        Http::fake();

        $agentProvider = Mockery::mock(LlmProvider::class);
        $agentProvider->shouldReceive('chat')->andReturnUsing(function () {
            return $this->textChatResponse(
                "I understand this has been frustrating, and I'm sorry for the delay. Let me help make this right for you."
            );
        });
        $agentProvider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $judgeProvider = null;

        if ($judgeChat !== null) {
            $judgeProvider = Mockery::mock(LlmProvider::class);
            $judgeProvider->shouldReceive('chat')->andReturnUsing($judgeChat);
            $judgeProvider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));
        }

        $agentServerId = $this->agentServer->id;
        $judgeServerId = $judgeServer?->id;

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturnUsing(
            function (Server $server) use ($agentServerId, $judgeServerId, $agentProvider, $judgeProvider) {
                if ($judgeServerId !== null && $server->id === $judgeServerId) {
                    return $judgeProvider;
                }

                return $agentProvider;
            }
        );
        $registry->shouldReceive('resolveByType')->andReturn($agentProvider);
        $this->app->instance(ProviderRegistry::class, $registry);
    }

    private function createSuite(string $name): string
    {
        return $this->actingAs($this->operator)->postJson($this->suitesBase(), [
            'name' => $name,
            'agent_identifier' => 'customer-support-agent',
        ])->assertStatus(200)->json('id');
    }

    private function createRubricCase(string $suiteId): array
    {
        return $this->actingAs($this->operator)->postJson($this->suitesBase().'/'.$suiteId.'/cases', [
            'given' => 'The customer says the delivery was three days late and is very upset.',
            'expected_behavior' => "Acknowledge the customer's frustration before offering a solution.",
            'expectations' => [[
                'kind' => 'rubric_judgment',
                'criteria' => "The response must acknowledge the customer's frustration before offering a solution.",
            ]],
        ])->assertStatus(200)->json();
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

    private function getRunCases(string $runId): array
    {
        return $this->actingAs($this->operator)->getJson($this->runsBase().'/'.$runId.'/cases')->assertStatus(200)->json();
    }

    /**
     * Common assertions every scenario below must satisfy: the run
     * completes successfully, the rubric expectation lands on `unjudged`
     * with `met`/`score` both null and a populated reason, the case's own
     * outcome is `unjudged` (never `pass`/`fail`/`errored`), and the
     * agent's own produced_response is still present — only judging
     * failed, not the case's own execution.
     */
    private function assertConvergesOnUnjudged(string $runId, string $caseId): void
    {
        $runDetail = $this->actingAs($this->operator)->getJson($this->runsBase().'/'.$runId)->assertStatus(200)->json();
        $this->assertSame('completed', $runDetail['status'], 'a judging-side failure must never prevent the run from completing');

        $cases = $this->getRunCases($runId);
        $result = collect($cases['data'])->firstWhere('eval_case_id', $caseId);

        $this->assertSame('unjudged', $result['outcome'], 'a judging-side failure must never surface as pass/fail/errored');
        $this->assertNotEmpty($result['produced_response'], 'the agent-under-test\'s own response must still be recorded — only judging failed');

        $expectation = $result['expectation_results'][0];
        $this->assertSame('rubric_judgment', $expectation['kind']);
        $this->assertSame('unjudged', $expectation['status']);
        $this->assertNull($expectation['score']);
        $this->assertNull($expectation['met']);
    }

    // ---------------------------------------------------------------
    // Cause 1: the judge role is entirely unassigned.
    // ---------------------------------------------------------------

    #[Test]
    public function an_unassigned_judge_role_converges_on_unjudged_and_the_run_still_completes(): void
    {
        $this->fakeProviders(null, null);

        $suiteId = $this->createSuite('Unavailable journey — unassigned role suite');
        $case = $this->createRubricCase($suiteId);

        $run = $this->runToCompletion($suiteId);

        $this->assertConvergesOnUnjudged($run['id'], $case['id']);
    }

    // ---------------------------------------------------------------
    // Cause 2: the judge role is assigned, but the installation's
    // spending ceiling has already been reached.
    //
    // This scenario deliberately does NOT drive a full in-run case
    // execution the way the other two causes do. BudgetGate (076) admits a
    // scope ("installation" for a null user id — the scope both the
    // in-run agent conversation and every RubricJudge call share) at most
    // once per request/job: `admit()` memoizes on `$this->admitted[$scopeKey]`
    // and every later call for the same scope in the same
    // scoped(BudgetGate::class) instance returns immediately without
    // re-evaluating the ceiling at all. Inside EvalCaseExecutor::execute(),
    // the case's own agent inference (AgentLoopService::run(), which admits
    // BudgetWorkKind::Interactive for the eval conversation's null user id)
    // always runs — and is always admitted first — before RubricJudge's own
    // admit() call for the same case's rubric expectation. So: if the
    // ceiling is already tripped when the case's job starts, the AGENT's
    // own call is refused first, and the case lands on `errored` (078's
    // own pre-existing, correct behavior for an agent-side budget refusal —
    // not a judging failure, and outside FR-013's scope, since the case
    // never produced a response to judge in the first place). If the
    // ceiling is NOT yet tripped when the job starts, the agent's call is
    // admitted, memoizing the scope, and RubricJudge's own later admit()
    // call for that same case is then a free pass for the rest of that
    // job — no matter what happens to the ceiling in between — so a
    // judge-specific refusal can never be observed for an in-run judgment
    // once its case's own agent call has already succeeded. (Verified
    // directly: tripping the ceiling as a side effect of the agent
    // fixture's own chat() callback — after the agent's admit() already
    // passed, before the judge's admit() runs — still results in the judge
    // being admitted for free, confirming this is the scope memoization
    // itself, not a timing artifact.)
    //
    // The one place a budget-refused judge call IS genuinely reachable
    // through this package's real HTTP surface is the standalone
    // consistency-check endpoint (US2) — a request that never contains a
    // preceding admitted call for the "installation" scope, since it makes
    // no agent call of its own at all. This is the real, honest proof of
    // FR-013's budget-refusal cause via HTTP; see quickstart.md step 4/
    // research.md D13 for the consistency-check endpoint's own standalone,
    // synchronous framing.
    // ---------------------------------------------------------------

    #[Test]
    public function a_tripped_spending_ceiling_converges_on_unjudged_via_the_standalone_consistency_check_endpoint(): void
    {
        $judgeServer = Server::create([
            'name' => 'Unavailable journey judge server (budget)',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'provider_type' => 'openai',
        ]);

        RoleAssignment::create([
            'role' => 'judge',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $judgeServer->id,
            'model' => 'judge-test-model',
        ]);

        // This provider would happily return a passing score if ever
        // called — proving the ceiling, not a malformed response, is what
        // produces the unjudged outcome below.
        $this->fakeProviders(
            $judgeServer,
            fn () => $this->textChatResponse(json_encode(['score' => 8, 'justification' => 'Fine.'])),
        );

        // SpendingCeilingService rejects a literal zero amount outright, so
        // a ceiling is only ever "reached" by comparing real consumption
        // against a positive amount — set a small positive ceiling and seed
        // a cost_summaries row already above it (the RubricJudgeTest
        // precedent).
        app(SpendingCeilingService::class)->upsert(
            BudgetScope::Installation,
            SpendingCeiling::INSTALLATION_SCOPE_ID,
            ['amount' => '0.01', 'period_type' => 'month', 'enforcement_mode' => 'stop'],
        );

        DB::table('cost_summaries')->insert([
            'id' => (string) Str::uuid(),
            'entity_type' => CostSummary::ENTITY_USER,
            'entity_id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'period_date' => now()->toDateString(),
            'request_count' => 1,
            'priced_cost_total' => '1.0000000000',
            'zero_priced_request_count' => 0,
            'unpriced_request_count' => 0,
            'unpriced_total_tokens' => 0,
            'estimated_request_count' => 0,
            'updated_at' => now(),
        ]);

        $suiteId = $this->createSuite('Unavailable journey — budget refusal suite');
        $case = $this->createRubricCase($suiteId);

        // A fresh HTTP request: no agent call precedes this one, so
        // BudgetGate's "installation" scope has not been admitted yet in
        // this request/job — the ceiling is genuinely, freshly evaluated.
        $response = $this->actingAs($this->operator)->postJson($this->consistencyChecksUrl($suiteId, $case['id']), [
            'expectation_index' => 0,
            'response_text' => "I understand this has been frustrating, and I'm sorry for the delay.",
            'sample_size' => 1,
        ]);

        $response->assertStatus(201);
        $sample = $response->json();

        $this->assertSame(0, $sample['judged_count'], 'the tripped ceiling must refuse every repeat, never silently score one');
        $this->assertSame(1, $sample['unjudged_count']);
        $this->assertSame([], $sample['scores']);
        $this->assertNull($sample['flagged_unstable'], 'insufficient data to assess stability when nothing was judged');

        $this->assertCount(1, $sample['judgment_ids']);
        $judgment = EvalJudgment::find($sample['judgment_ids'][0]);
        $this->assertSame('unjudged', $judgment->status);
        $this->assertNotEmpty($judgment->unjudged_reason);
        $this->assertNull($judgment->score);
    }

    // ---------------------------------------------------------------
    // Cause 3: the judge role is assigned and the budget allows the
    // call, but the provider itself throws (network/timeout-shaped).
    // ---------------------------------------------------------------

    #[Test]
    public function a_throwing_provider_converges_on_unjudged_and_the_run_still_completes(): void
    {
        $judgeServer = Server::create([
            'name' => 'Unavailable journey judge server (throws)',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'provider_type' => 'openai',
        ]);

        RoleAssignment::create([
            'role' => 'judge',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $judgeServer->id,
            'model' => 'judge-test-model',
        ]);

        $this->fakeProviders($judgeServer, function () {
            throw new \RuntimeException('Connection timed out');
        });

        $suiteId = $this->createSuite('Unavailable journey — provider throws suite');
        $case = $this->createRubricCase($suiteId);

        $run = $this->runToCompletion($suiteId);

        $this->assertConvergesOnUnjudged($run['id'], $case['id']);
    }
}
