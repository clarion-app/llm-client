<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Events\EvalRunCaseResultRecorded;
use ClarionApp\LlmClient\Events\EvalRunUpdated;
use ClarionApp\LlmClient\Jobs\RunEvalCaseJob;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\EvalCaseExecutor;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every route this feature adds is operator-gated, reads included:
 * GET /agent-eval-dashboard/{agentLabel} and
 * GET /eval-runs/{runId}/cases/{caseResultId}/detail both refuse a
 * non-operator with 403 (quickstart step 11, mutation-checklist row 8) and
 * both succeed for an operator on the identical calls (positive control,
 * the EvalRunOperatorGateJourneyTest/078 and
 * EvalRegressionOperatorGateJourneyTest/080 precedent) -- so a gate that
 * refuses everyone equally cannot pass this test by accident.
 *
 * The second half of quickstart step 11 -- a non-operator's own
 * authenticated connection never receives EvalRunUpdated /
 * EvalRunCaseResultRecorded -- is proven here end-to-end, from a real,
 * fully-executed run, by inspecting each dispatched event's own
 * broadcastOn() channel list: every configured operator's channel is
 * present, and the non-operator's own User.{id} channel is never among
 * them, since broadcasting here targets every configured operator, not
 * the caller or any conversation owner.
 */
class EvalDashboardOperatorGateJourneyTest extends TestCase
{
    private User $operator;
    private User $nonOperator;
    private string $suiteId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->declareSupportingSchema();

        $this->operator = User::factory()->create();
        $this->nonOperator = User::factory()->create();
        config(['llm-client.cost.operator_user_ids' => [$this->operator->id]]);

        $server = Server::create([
            'name' => 'Dashboard operator gate fixture server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'provider_type' => 'openai',
        ]);

        RoleAssignment::create([
            'role' => 'inference',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $server->id,
            'model' => 'test-model',
        ]);

        $this->fakeProvider();

        $suite = $this->actingAs($this->operator)->postJson($this->suitesBase(), [
            'name' => 'Dashboard operator gate fixture suite',
            'agent_identifier' => 'dashboard-operator-gate-agent',
        ])->assertStatus(200)->json();
        $this->suiteId = $suite['id'];

        $this->actingAs($this->operator)->postJson($this->suitesBase().'/'.$this->suiteId.'/cases', [
            'given' => 'Say the word echo',
            'expected_behavior' => 'Answer with the single word echo.',
            'expectations' => [['kind' => 'text_match', 'expected_text' => 'echo']],
        ])->assertStatus(200);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        DB::table('eval_pass_rate_summaries')->delete();
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

    private function dashboardUrl(string $agentLabel): string
    {
        return '/api/clarion-app/llm-client/agent-eval-dashboard/'.$agentLabel;
    }

    private function caseDetailUrl(string $runId, string $caseResultId): string
    {
        return $this->runsBase().'/'.$runId.'/cases/'.$caseResultId.'/detail';
    }

    /**
     * AgentLoopService::run() consults ConversationCondenser on every
     * call, unconditionally -- the RegressionReportJourneyTest/
     * EvalRegressionOperatorGateJourneyTest precedent.
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
     * Runs the fixture suite to completion as the operator, through the
     * real EvalCaseExecutor write path (not a hand-built fixture row), so
     * the events under test are the genuine article -- and returns the
     * completed run plus its one case result id.
     *
     * @return array{run: array<string, mixed>, caseResultId: string}
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

        $completedRun = $this->actingAs($this->operator)
            ->getJson($this->runsBase().'/'.$run['id'])
            ->assertStatus(200)
            ->json();

        $caseResultId = DB::table('eval_case_results')->where('run_id', $run['id'])->value('id');
        $this->assertNotNull($caseResultId, 'Fixture precondition: the run must have produced a real case result');

        return ['run' => $completedRun, 'caseResultId' => $caseResultId];
    }

    // ---------------------------------------------------------------
    // Non-operator is refused on every HTTP route this feature adds
    // ---------------------------------------------------------------

    #[Test]
    public function a_non_operator_is_refused_on_every_route_this_feature_adds(): void
    {
        ['run' => $run, 'caseResultId' => $caseResultId] = $this->runToCompletionAsOperator();

        $as = fn () => $this->actingAs($this->nonOperator);

        $as()->getJson($this->dashboardUrl($run['agent_label']))->assertStatus(403);
        $as()->getJson($this->caseDetailUrl($run['id'], $caseResultId))->assertStatus(403);
    }

    // ---------------------------------------------------------------
    // Positive control -- an operator succeeds on the identical calls
    // ---------------------------------------------------------------

    #[Test]
    public function an_operator_succeeds_on_the_identical_calls(): void
    {
        ['run' => $run, 'caseResultId' => $caseResultId] = $this->runToCompletionAsOperator();

        $this->actingAs($this->operator)
            ->getJson($this->dashboardUrl($run['agent_label']))
            ->assertStatus(200);

        $this->actingAs($this->operator)
            ->getJson($this->caseDetailUrl($run['id'], $caseResultId))
            ->assertStatus(200);
    }

    // ---------------------------------------------------------------
    // A non-operator's own connection never receives either broadcast
    // event this feature fires, end-to-end from a real run.
    // ---------------------------------------------------------------

    #[Test]
    public function a_non_operators_channel_never_appears_on_either_broadcast_event_fired_by_a_real_run(): void
    {
        Event::fake([EvalRunUpdated::class, EvalRunCaseResultRecorded::class]);

        $this->runToCompletionAsOperator();

        $operatorChannel = 'private-User.'.$this->operator->id;
        $nonOperatorChannel = 'private-User.'.$this->nonOperator->id;

        Event::assertDispatched(EvalRunUpdated::class, function (EvalRunUpdated $event) use ($operatorChannel, $nonOperatorChannel) {
            $channelNames = array_map(fn (PrivateChannel $c) => (string) $c, $event->broadcastOn());

            $this->assertContains($operatorChannel, $channelNames, 'the configured operator must receive the run-level update');
            $this->assertNotContains($nonOperatorChannel, $channelNames, 'a non-operator must never receive the run-level update, even though they triggered nothing');

            return true;
        });

        Event::assertDispatched(EvalRunCaseResultRecorded::class, function (EvalRunCaseResultRecorded $event) use ($operatorChannel, $nonOperatorChannel) {
            $channelNames = array_map(fn (PrivateChannel $c) => (string) $c, $event->broadcastOn());

            $this->assertContains($operatorChannel, $channelNames, 'the configured operator must receive the case-level update');
            $this->assertNotContains($nonOperatorChannel, $channelNames, 'a non-operator must never receive the case-level update');

            return true;
        });
    }
}
