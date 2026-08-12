<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Jobs\RunEvalCaseJob;
use ClarionApp\LlmClient\Models\EvalCaseResult;
use ClarionApp\LlmClient\Models\EvalJudgment;
use ClarionApp\LlmClient\Models\EvalRunCase;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\EvalCaseExecutor;
use ClarionApp\LlmClient\Services\EvalCaseService;
use ClarionApp\LlmClient\Services\EvalJudgmentOverrideService;
use ClarionApp\LlmClient\Services\EvalRunService;
use ClarionApp\LlmClient\Services\EvalSuiteService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * research.md D2's "reconcile with those records, not scan them" claim,
 * mirroring RollupReconciliationJourneyTest (073) exactly for
 * eval_pass_rate_summaries: several hundred case results (plus a smaller
 * batch including at least one judgment override, exercising
 * adjustForOverride()) are recorded through the real EvalCaseExecutor/
 * EvalJudgmentOverrideService write paths -- never direct DB seeding -- and
 * the rollup's summed counts, grouped by (agent_label, period_date), must
 * equal an independent GROUP BY COALESCE(outcome_override, outcome),
 * DATE(created_at) count over eval_case_results for the same agent, to the
 * last row. RecomputeEvalPassRateSummariesCommand, run against the same
 * seeded data with eval_pass_rate_summaries first truncated, must reproduce
 * byte-identical rows (aside from id/updated_at) to what the live write
 * path already produced.
 */
class EvalPassRateReconciliationJourneyTest extends TestCase
{
    private const AGENT_LABEL = 'reconciliation-agent';

    // "Several hundred" (spec.md's own wording) case results driven through
    // the real, single-chat-call checkable write path.
    private const MAIN_COUNT = 200;

    // A smaller batch of rubric-judged results, at least one of which is
    // corrected via a real override -- exercising adjustForOverride().
    private const OVERRIDE_BATCH_COUNT = 6;

    private User $operator;
    private Server $agentServer;
    private Server $judgeServer;

    /** @var array<int, string> queued in order, one per checkable case execution */
    private array $agentResponses = [];

    /** @var array<int, int> queued in order, one per rubric judge chat() call */
    private array $judgeScores = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->declareSupportingSchema();

        $this->operator = User::factory()->create();
        config(['llm-client.cost.operator_user_ids' => [$this->operator->id]]);

        $this->agentServer = Server::create([
            'name' => 'Reconciliation agent server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'provider_type' => 'openai',
        ]);

        $this->judgeServer = Server::create([
            'name' => 'Reconciliation judge server',
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

        DB::table('eval_pass_rate_summaries')->delete();
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

    /**
     * AgentLoopService::run() consults ConversationCondenser on every call,
     * unconditionally -- the RunSuiteJourneyTest/RubricJudgmentJourneyTest
     * precedent -- so this table must exist even though no case here ever
     * triggers a real tool call.
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

    /**
     * Two distinct fixture providers, resolved per-server exactly like
     * JudgmentOverrideJourneyTest: the agent consumes $this->agentResponses
     * in order (one per checkable case execution), and the judge consumes
     * $this->judgeScores in order (one per rubric judge chat() call).
     */
    private function fakeProviders(): void
    {
        Http::fake();

        $agentProvider = Mockery::mock(LlmProvider::class);
        $agentProvider->shouldReceive('chat')->andReturnUsing(function () {
            $content = array_shift($this->agentResponses) ?? '4';

            return $this->textChatResponse($content);
        });
        $agentProvider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $judgeProvider = Mockery::mock(LlmProvider::class);
        $judgeProvider->shouldReceive('chat')->andReturnUsing(function () {
            $score = array_shift($this->judgeScores) ?? 9;

            return $this->textChatResponse(json_encode([
                'score' => $score,
                'justification' => 'Automated reconciliation-fixture justification.',
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
     * Starts a one-case run of $suiteId and drives its single dispatched
     * case job to completion synchronously, through the real
     * EvalCaseExecutor -- the RunSuiteJourneyTest/JudgmentOverrideJourneyTest
     * "real queue-synchronous test harness" precedent -- returning the
     * resulting EvalCaseResult.
     */
    private function runOneCaseToCompletion(\ClarionApp\LlmClient\Models\EvalSuite $suite): EvalCaseResult
    {
        Bus::fake([RunEvalCaseJob::class]);

        $run = app(EvalRunService::class)->start($suite);
        $runCase = EvalRunCase::where('run_id', $run->id)->firstOrFail();

        app(EvalCaseExecutor::class)->execute($run->id, $runCase->id);

        return EvalCaseResult::where('run_id', $run->id)->where('eval_run_case_id', $runCase->id)->firstOrFail();
    }

    // ---------------------------------------------------------------
    // Reconciliation
    // ---------------------------------------------------------------

    #[Test]
    public function the_rollups_summed_counts_reconcile_exactly_with_an_independent_count_over_eval_case_results_including_an_override(): void
    {
        $suiteService = app(EvalSuiteService::class);
        $caseService = app(EvalCaseService::class);

        // A: several hundred checkable case results, a deliberate mix of
        // pass/fail (every 5th fails).
        $mainSuite = $suiteService->create('Reconciliation main suite', self::AGENT_LABEL);
        $caseService->addCase(
            $mainSuite,
            'What is 2+2?',
            'Answer with the number 4.',
            [['kind' => 'text_match', 'expected_text' => '4']],
        );

        for ($i = 0; $i < self::MAIN_COUNT; $i++) {
            $this->agentResponses[] = ($i % 5 === 0) ? 'I have no idea.' : '4';
        }

        for ($i = 0; $i < self::MAIN_COUNT; $i++) {
            $this->runOneCaseToCompletion($mainSuite);
        }

        $this->assertSame(
            self::MAIN_COUNT,
            EvalCaseResult::whereIn('run_id', function ($q) use ($mainSuite) {
                $q->select('id')->from('eval_runs')->where('suite_id', $mainSuite->id);
            })->count(),
            'fixture precondition: every main-batch iteration must have produced exactly one case result'
        );

        // B: a smaller batch of rubric-judged results, one of which is then
        // corrected via a real override.
        $rubricSuite = $suiteService->create('Reconciliation rubric suite', self::AGENT_LABEL);
        $rubricCase = $caseService->addCase(
            $rubricSuite,
            'The customer says the delivery was three days late and is very upset.',
            "Acknowledge the customer's frustration before offering a solution.",
            [['kind' => 'rubric_judgment', 'criteria' => 'Acknowledges the frustration first.']],
        );

        // Deliberately mixed judge scores against the passing_score=7
        // threshold: pass, pass, fail, pass, fail, pass.
        $this->judgeScores = [9, 8, 3, 9, 2, 8];
        $this->assertSame(self::OVERRIDE_BATCH_COUNT, count($this->judgeScores));

        $rubricResults = [];
        for ($i = 0; $i < self::OVERRIDE_BATCH_COUNT; $i++) {
            $rubricResults[] = $this->runOneCaseToCompletion($rubricSuite);
        }

        // Correct the first rubric result's judgment from its original
        // passing score down below the threshold -- flips its effective
        // outcome from pass to fail via EvalJudgmentOverrideService, the
        // sole write path for eval_judgment_overrides / outcome_override
        // recomputation (data-model.md §1's adjustForOverride() call site).
        $firstJudgmentId = $rubricResults[0]->expectation_results[0]['judgment_id'];
        $judgment = EvalJudgment::findOrFail($firstJudgmentId);
        $this->assertNull($rubricResults[0]->fresh()->outcome_override, 'fixture precondition: no override has been applied yet');

        app(EvalJudgmentOverrideService::class)->override(
            $judgment,
            2,
            'Reconciliation fixture: correcting the score below the passing threshold.',
            $this->operator->id,
        );

        $this->assertSame(
            'fail',
            $rubricResults[0]->fresh()->outcome_override,
            'fixture precondition: the override must have flipped the first rubric result\'s effective outcome to fail'
        );

        // Independent count over eval_case_results (joined to eval_runs for
        // agent_label), by effective outcome -- COALESCE(outcome_override,
        // outcome), never the raw outcome column alone.
        $independent = DB::table('eval_case_results')
            ->join('eval_runs', 'eval_runs.id', '=', 'eval_case_results.run_id')
            ->where('eval_runs.agent_label', self::AGENT_LABEL)
            ->selectRaw('COALESCE(eval_case_results.outcome_override, eval_case_results.outcome) as eff, COUNT(*) as cnt')
            ->groupBy(DB::raw('COALESCE(eval_case_results.outcome_override, eval_case_results.outcome)'))
            ->pluck('cnt', 'eff');

        $totalResults = (int) $independent->sum();
        $this->assertSame(
            self::MAIN_COUNT + self::OVERRIDE_BATCH_COUNT,
            $totalResults,
            'fixture precondition: every recorded result across both batches must be accounted for'
        );

        // Every result recorded above happened within this single test
        // method's execution, so all of it falls on exactly one calendar
        // day -- exactly one eval_pass_rate_summaries row for this agent.
        $today = now()->toDateString();
        $bucket = DB::table('eval_pass_rate_summaries')
            ->where('agent_label', self::AGENT_LABEL)
            ->where('period_date', $today)
            ->first();

        $this->assertNotNull($bucket, 'the live rollup write path must have produced exactly one bucket row for this agent/day');

        $columnsByOutcome = [
            'pass' => 'pass_count',
            'fail' => 'fail_count',
            'needs_human_review' => 'needs_human_review_count',
            'errored' => 'errored_count',
            'unjudged' => 'unjudged_count',
        ];

        foreach ($columnsByOutcome as $outcome => $column) {
            $this->assertSame(
                (int) ($independent[$outcome] ?? 0),
                (int) $bucket->{$column},
                "the rollup's {$column} must reconcile exactly with an independent count of eval_case_results rows whose effective outcome is '{$outcome}'"
            );
        }

        $this->assertSame(
            $totalResults,
            (int) $bucket->total_count,
            'total_count must reconcile exactly with the total number of recorded results, to the last row'
        );

        // RecomputeEvalPassRateSummariesCommand, run against the same
        // seeded eval_case_results with eval_pass_rate_summaries first
        // truncated, must reproduce byte-identical rows (aside from
        // id/updated_at, which are necessarily regenerated) to what the
        // live write path already produced.
        $liveRows = DB::table('eval_pass_rate_summaries')
            ->where('agent_label', self::AGENT_LABEL)
            ->orderBy('period_date')
            ->get()
            ->map(fn ($row) => collect((array) $row)->except(['id', 'updated_at'])->all())
            ->all();

        DB::table('eval_pass_rate_summaries')->where('agent_label', self::AGENT_LABEL)->delete();

        $exitCode = Artisan::call('llm-client:recompute-eval-pass-rate-summaries', ['--agent-label' => self::AGENT_LABEL]);
        $this->assertSame(0, $exitCode);

        $rebuiltRows = DB::table('eval_pass_rate_summaries')
            ->where('agent_label', self::AGENT_LABEL)
            ->orderBy('period_date')
            ->get()
            ->map(fn ($row) => collect((array) $row)->except(['id', 'updated_at'])->all())
            ->all();

        $this->assertSame(
            $liveRows,
            $rebuiltRows,
            'RecomputeEvalPassRateSummariesCommand must reproduce byte-identical rows to what the live write path already produced'
        );
    }
}
