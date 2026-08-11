<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
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
 * non-operator is refused with a 403 on all four (GET a judgment, POST an
 * override, POST a consistency check, GET the consistency-check list), with
 * no judgment/override/sample row created or changed by any refused write
 * attempt, and an operator succeeds on the identical calls — the positive
 * control, so a gate that refuses everyone equally cannot pass this test by
 * accident (the same discipline EvalRunOperatorGateJourneyTest/078 and
 * EvalSuiteOperatorGateJourneyTest/077 already established for their own
 * routes).
 */
class EvalJudgmentOperatorGateJourneyTest extends TestCase
{
    private User $operator;
    private User $nonOperator;
    private Server $agentServer;
    private Server $judgeServer;

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

        $this->judgeServer = Server::create([
            'name' => 'Operator gate fixture judge server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'provider_type' => 'openai',
        ]);

        RoleAssignment::create([
            'role' => 'inference',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $this->agentServer->id,
            'model' => 'agent-test-model',
        ]);

        RoleAssignment::create([
            'role' => 'judge',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $this->judgeServer->id,
            'model' => 'judge-test-model',
        ]);

        $this->fakeProviders();
        $this->seedApiDocsCache();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->seedApiDocsCache(null);

        DB::table('eval_judgment_consistency_samples')->delete();
        DB::table('eval_judgment_overrides')->delete();
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

    private function judgmentUrl(string $judgmentId): string
    {
        return '/api/clarion-app/llm-client/eval-judgments/'.$judgmentId;
    }

    private function overrideUrl(string $judgmentId): string
    {
        return $this->judgmentUrl($judgmentId).'/override';
    }

    private function consistencyChecksUrl(string $suiteId, string $caseId): string
    {
        return $this->suitesBase().'/'.$suiteId.'/cases/'.$caseId.'/consistency-checks';
    }

    /**
     * AgentLoopService::run() consults ConversationCondenser on every call,
     * unconditionally — the RubricJudgmentJourneyTest precedent.
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

    private function fakeProviders(): void
    {
        Http::fake();

        $agentProvider = Mockery::mock(LlmProvider::class);
        $agentProvider->shouldReceive('chat')->andReturnUsing(function () {
            return $this->textChatResponse(
                "I understand this has been frustrating, and I'm sorry for the delay. Let me help make this right for you."
            );
        });
        $agentProvider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $judgeProvider = Mockery::mock(LlmProvider::class);
        $judgeProvider->shouldReceive('chat')->andReturnUsing(function () {
            return $this->textChatResponse(json_encode([
                'score' => 8,
                'justification' => "Acknowledges the customer's frustration before offering a solution.",
            ]));
        });
        $judgeProvider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $agentServerId = $this->agentServer->id;
        $judgeServerId = $this->judgeServer->id;

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturnUsing(
            function (Server $server) use ($agentServerId, $judgeServerId, $agentProvider, $judgeProvider) {
                return $server->id === $judgeServerId ? $judgeProvider : $agentProvider;
            }
        );
        $registry->shouldReceive('resolveByType')->andReturn($agentProvider);
        $this->app->instance(ProviderRegistry::class, $registry);
    }

    /**
     * Authors a single rubric-judged case, runs it to completion as the
     * operator, and returns the identifiers every route under test needs —
     * a genuinely produced judgment, not one hand-assembled purely for this
     * gate test.
     *
     * @return array{suite_id: string, case_id: string, case_result_id: string, judgment_id: string}
     */
    private function produceJudgedCase(): array
    {
        $suiteId = $this->actingAs($this->operator)->postJson($this->suitesBase(), [
            'name' => 'Operator gate fixture suite',
            'agent_identifier' => 'customer-support-agent',
        ])->assertStatus(200)->json('id');

        $case = $this->actingAs($this->operator)->postJson($this->suitesBase().'/'.$suiteId.'/cases', [
            'given' => 'The customer says the delivery was three days late and is very upset.',
            'expected_behavior' => "Acknowledge the customer's frustration before offering a solution.",
            'expectations' => [[
                'kind' => 'rubric_judgment',
                'criteria' => "The response must acknowledge the customer's frustration before offering a solution.",
            ]],
        ])->assertStatus(200)->json();

        Bus::fake([RunEvalCaseJob::class]);
        $run = $this->actingAs($this->operator)
            ->postJson($this->suitesBase().'/'.$suiteId.'/runs')
            ->assertStatus(201)
            ->json();

        foreach (Bus::dispatched(RunEvalCaseJob::class) as $job) {
            $job->handle(app(EvalCaseExecutor::class));
        }

        $cases = $this->actingAs($this->operator)
            ->getJson($this->runsBase().'/'.$run['id'].'/cases')
            ->assertStatus(200)
            ->json();

        $result = collect($cases['data'])->firstWhere('eval_case_id', $case['id']);
        $expectation = $result['expectation_results'][0];

        return [
            'suite_id' => $suiteId,
            'case_id' => $case['id'],
            'case_result_id' => $result['id'],
            'judgment_id' => $expectation['judgment_id'],
        ];
    }

    // ---------------------------------------------------------------
    // Non-operator refused on every route, reads included, no row
    // created or changed by any refused write attempt.
    // ---------------------------------------------------------------

    #[Test]
    public function a_non_operator_is_refused_on_every_route_and_no_row_is_created_or_changed(): void
    {
        $produced = $this->produceJudgedCase();

        $judgmentRowBefore = (array) DB::table('eval_judgments')->where('id', $produced['judgment_id'])->first();
        $overridesCountBefore = DB::table('eval_judgment_overrides')->count();
        $samplesCountBefore = DB::table('eval_judgment_consistency_samples')->count();

        $as = fn () => $this->actingAs($this->nonOperator);

        $as()->getJson($this->judgmentUrl($produced['judgment_id']))->assertStatus(403);
        $as()->postJson($this->overrideUrl($produced['judgment_id']), ['score' => 1, 'justification' => 'forced down'])->assertStatus(403);
        $as()->postJson($this->consistencyChecksUrl($produced['suite_id'], $produced['case_id']), [
            'expectation_index' => 0,
            'source_eval_case_result_id' => $produced['case_result_id'],
        ])->assertStatus(403);
        $as()->getJson($this->consistencyChecksUrl($produced['suite_id'], $produced['case_id']))->assertStatus(403);

        // No refused write attempt above may have created or changed
        // anything.
        $judgmentRowAfter = (array) DB::table('eval_judgments')->where('id', $produced['judgment_id'])->first();
        $this->assertSame($judgmentRowBefore, $judgmentRowAfter, 'the judgment row must be byte-identical after a refused override attempt');
        $this->assertSame($overridesCountBefore, DB::table('eval_judgment_overrides')->count(), 'no override row may be created by a refused route');
        $this->assertSame($samplesCountBefore, DB::table('eval_judgment_consistency_samples')->count(), 'no consistency sample row may be created by a refused route');
    }

    // ---------------------------------------------------------------
    // Positive control — an operator succeeds on the identical calls.
    // ---------------------------------------------------------------

    #[Test]
    public function an_operator_succeeds_on_the_identical_calls(): void
    {
        $produced = $this->produceJudgedCase();

        $this->actingAs($this->operator)
            ->getJson($this->judgmentUrl($produced['judgment_id']))
            ->assertStatus(200);

        $this->actingAs($this->operator)
            ->postJson($this->overrideUrl($produced['judgment_id']), [
                'score' => 5,
                'justification' => 'Adjusted after operator review.',
            ])
            ->assertStatus(200);

        $this->actingAs($this->operator)
            ->postJson($this->consistencyChecksUrl($produced['suite_id'], $produced['case_id']), [
                'expectation_index' => 0,
                'source_eval_case_result_id' => $produced['case_result_id'],
                'sample_size' => 1,
            ])
            ->assertStatus(201);

        $this->actingAs($this->operator)
            ->getJson($this->consistencyChecksUrl($produced['suite_id'], $produced['case_id']))
            ->assertStatus(200);
    }
}
