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
 * US2 Acceptance Scenarios 4-5 / FR-006 / FR-007 / SC-004: a case pending
 * human judgment and a case that could not be scored at all must each
 * land on their own outcome -- needs_human_review and unjudged
 * respectively -- excluded from pass_count and fail_count and from each
 * other's count, both in the agent's current pass rate and in the
 * day-bucketed rollup a single case-recording write updates. Neither
 * category ever gets folded into the other or into a pass/fail count,
 * and both appear as their own independently-non-zero counts in the same
 * GET /agent-eval-dashboard/{agentLabel} response when both occur in the
 * same window.
 *
 * The rubric-judged case is left unjudged by never assigning a judge
 * role at all -- the "judge role is entirely unassigned" cause already
 * proven to converge on unjudged elsewhere in this suite -- so this file
 * needs no judge-side fixture provider.
 */
class PendingAndUnjudgedDistinctnessJourneyTest extends TestCase
{
    private const AGENT_LABEL = 'distinctness-agent';

    private User $operator;
    private Server $agentServer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->declareSupportingSchema();

        $this->operator = User::factory()->create();
        config(['llm-client.cost.operator_user_ids' => [$this->operator->id]]);

        $this->agentServer = Server::create([
            'name' => 'Distinctness journey agent server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'provider_type' => 'openai',
        ]);

        RoleAssignment::create([
            'role' => 'inference',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $this->agentServer->id,
            'model' => 'agent-test-model',
        ]);

        $this->fakeProviders();
        $this->seedApiDocsCache();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->seedApiDocsCache(null);

        DB::table('eval_pass_rate_summaries')->delete();
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

    private function dashboardUrl(string $agentLabel): string
    {
        return '/api/clarion-app/llm-client/agent-eval-dashboard/'.$agentLabel;
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
     * Only the agent server is ever registered -- no judge role is
     * assigned at all, so a rubric_judgment expectation can never be
     * scored and must converge on unjudged, mirroring
     * JudgingServiceUnavailableJourneyTest's "unassigned role" cause.
     */
    private function fakeProviders(): void
    {
        Http::fake();

        $agentProvider = Mockery::mock(LlmProvider::class);
        $agentProvider->shouldReceive('chat')->andReturnUsing(function (array $messages) {
            $firstUser = collect($messages)->firstWhere('role', 'user')['content'] ?? '';

            if (str_contains($firstUser, '2 + 2')) {
                return $this->textChatResponse('4');
            }

            return $this->textChatResponse('Acknowledged.');
        });
        $agentProvider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($agentProvider);
        $registry->shouldReceive('resolveByType')->andReturn($agentProvider);
        $this->app->instance(ProviderRegistry::class, $registry);
    }

    private function createSuite(): string
    {
        return $this->actingAs($this->operator)->postJson($this->suitesBase(), [
            'name' => 'Distinctness journey suite',
            'agent_identifier' => self::AGENT_LABEL,
        ])->assertStatus(200)->json('id');
    }

    private function addPassingCheckableCase(string $suiteId): array
    {
        return $this->actingAs($this->operator)->postJson($this->suitesBase().'/'.$suiteId.'/cases', [
            'given' => 'What is 2 + 2?',
            'expected_behavior' => 'State the correct sum.',
            'expectations' => [['kind' => 'text_match', 'expected_text' => '4']],
        ])->assertStatus(200)->json();
    }

    private function addHumanJudgmentCase(string $suiteId): array
    {
        return $this->actingAs($this->operator)->postJson($this->suitesBase().'/'.$suiteId.'/cases', [
            'given' => 'User asks the agent to write a condolence message.',
            'expected_behavior' => 'The agent writes something warm and appropriately toned.',
            'expectations' => [['kind' => 'human_judgment', 'note' => 'Judge tone, not exact wording.']],
        ])->assertStatus(200)->json();
    }

    private function addRubricCase(string $suiteId): array
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

    private function startRun(string $suiteId): array
    {
        Bus::fake([RunEvalCaseJob::class]);

        return $this->actingAs($this->operator)
            ->postJson($this->suitesBase().'/'.$suiteId.'/runs')
            ->assertStatus(201)
            ->json();
    }

    private function driveDispatchedCaseJobsToCompletion(): void
    {
        foreach (Bus::dispatched(RunEvalCaseJob::class) as $job) {
            $job->handle(app(EvalCaseExecutor::class));
        }
    }

    private function getRunCases(string $runId): array
    {
        return $this->actingAs($this->operator)->getJson($this->runsBase().'/'.$runId.'/cases')->assertStatus(200)->json();
    }

    private function getDashboard(string $agentLabel): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->operator)->getJson($this->dashboardUrl($agentLabel));
    }

    // ---------------------------------------------------------------
    // Both outcomes appear, independently, distinct from pass/fail and
    // from each other.
    // ---------------------------------------------------------------

    #[Test]
    public function needs_human_review_and_unjudged_are_each_counted_on_their_own_never_folded_into_pass_fail_or_each_other(): void
    {
        $suiteId = $this->createSuite();
        $passingCase = $this->addPassingCheckableCase($suiteId);
        $humanCase = $this->addHumanJudgmentCase($suiteId);
        $rubricCase = $this->addRubricCase($suiteId);

        $started = $this->startRun($suiteId);
        $this->driveDispatchedCaseJobsToCompletion();

        $cases = $this->getRunCases($started['id']);
        $byCaseId = collect($cases['data'])->keyBy('eval_case_id');

        $this->assertSame('pass', $byCaseId[$passingCase['id']]['outcome']);
        $this->assertSame(
            'needs_human_review',
            $byCaseId[$humanCase['id']]['outcome'],
            'a human_judgment expectation must land needs_human_review, never pass/fail/unjudged'
        );
        $this->assertSame(
            'unjudged',
            $byCaseId[$rubricCase['id']]['outcome'],
            'a rubric_judgment expectation with no judge role assigned must land unjudged, never pass/fail/needs_human_review'
        );

        $response = $this->getDashboard(self::AGENT_LABEL);
        $response->assertStatus(200);

        $current = $response->json('current_pass_rate');
        $this->assertNotNull($current, 'fixture precondition: the run must have completed and produced a current pass rate');

        // pass/fail/errored are exactly what the one genuinely-checkable
        // case produced -- neither pending category may have moved them.
        $this->assertSame(1, $current['pass_count']);
        $this->assertSame(0, $current['fail_count']);
        $this->assertSame(0, $current['errored_count']);

        // Both pending categories are counted, independently, on their own.
        $this->assertSame(1, $current['needs_human_review_count']);
        $this->assertSame(1, $current['unjudged_count']);

        // pass_rate excludes both pending categories from its denominator
        // entirely (research.md D8): 1 pass / (1 pass + 0 fail + 0 errored).
        // assertEqualsWithDelta rather than assertSame -- a whole-number
        // ratio round-trips through JSON as a bare integer (PHP's
        // json_encode does not preserve a trailing .0 by default), so the
        // decoded value's exact int/float type is not itself under test
        // here.
        $this->assertEqualsWithDelta(1.0, $current['pass_rate'], 0.0001);

        // The day-bucketed rollup a single case-recording write updates
        // must show the identical per-column distinctness -- folding
        // either pending category into another column here must turn
        // this assertion red.
        $today = now()->toDateString();
        $bucket = DB::table('eval_pass_rate_summaries')
            ->where('agent_label', self::AGENT_LABEL)
            ->where('period_date', $today)
            ->first();

        $this->assertNotNull($bucket, 'the live rollup write path must have produced a bucket row for this agent/day');
        $this->assertSame(1, (int) $bucket->pass_count);
        $this->assertSame(0, (int) $bucket->fail_count);
        $this->assertSame(0, (int) $bucket->errored_count);
        $this->assertSame(1, (int) $bucket->needs_human_review_count);
        $this->assertSame(1, (int) $bucket->unjudged_count);
        $this->assertSame(3, (int) $bucket->total_count);
    }
}
