<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Jobs\RunDelegationBatchMemberJob;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\AgentHelperService;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\RoleAssignmentService;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use Dedoc\Scramble\Generator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 104-multi-agent-consensus, Phase 7 (US5), tasks.md T048.
 *
 * quickstart.md scenario 6 (US5 AC2, FR-010, SC-005's converse): same
 * 3-contributor setup as ConsensusContributorFailureJourneyTest (T047), but
 * contributors 2 AND 3 both fail/time out, leaving only contributor 1
 * successful. quorum_required = 2 (default), so successful_count (1) falls
 * BELOW quorum. Asserts: status: 'insufficient_quorum' (not 'completed');
 * reconciled_answer: null, agreement_classification: null -- contributor
 * 1's lone answer is never presented as reconciled or agreed-upon;
 * actual_additional_cost is still populated (contributors still ran and
 * still cost money, even though the outcome was refused); the response's
 * message names how many responded versus the required minimum.
 *
 * Mirrors T047's own technique exactly: DelegationService is NOT mocked --
 * delegateBatch() runs for real, with AgentLoopService mocked for the
 * underlying model call and TWO of the three members' own
 * RunDelegationBatchMemberJob selectively faked via Bus::fake() so they
 * never run, exercising the REAL, unmodified forceFinalizeBatchJoinTimeout()
 * for both.
 */
class ConsensusInsufficientQuorumJourneyTest extends TestCase
{
    private User $user;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        config(['llm-client.delegation.max_seconds' => 1]);
        config(['llm-client.delegation.concurrency.join_poll_interval_ms' => 20]);
        config(['queue.default' => 'sync']);

        $this->user = User::factory()->create();
        $this->seedOperationCatalog();

        $this->server = Server::create([
            'name' => 'Test Server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'sk-test',
        ]);

        app(RoleAssignmentService::class)->set(ModelRole::Inference, $this->user->id, $this->server->id, 'test-model');

        if (!Schema::hasTable('mcp_sessions')) {
            Schema::create('mcp_sessions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id');
                $table->string('protocol_version');
                $table->string('client_name')->nullable();
                $table->string('client_version')->nullable();
                $table->json('capabilities')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index('user_id');
            });
        }

        if (!Schema::hasTable('episodic_memories')) {
            Schema::create('episodic_memories', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id');
                $table->uuid('conversation_id');
                $table->text('summary');
                $table->json('topics');
                $table->boolean('protected')->default(false);
                $table->unsignedInteger('word_count');
                $table->unsignedInteger('summary_word_count');
                $table->json('embedding')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

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

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();

        $this->clearOperationCatalog();
        Mockery::close();
        Context::forget('run_id');

        DB::table('consensus_requests')->delete();
        DB::table('agent_delegations')->delete();
        DB::table('messages')->delete();
        if (Schema::hasTable('mcp_sessions')) {
            DB::table('mcp_sessions')->delete();
        }
        if (Schema::hasTable('agent_run_actions')) {
            DB::table('agent_run_actions')->delete();
        }
        if (Schema::hasTable('agent_run_steps')) {
            DB::table('agent_run_steps')->delete();
        }
        if (Schema::hasTable('agent_runs')) {
            DB::table('agent_runs')->delete();
        }
        DB::table('agent_helper_assignments')->delete();
        DB::table('llm_role_assignments')->delete();
        DB::table('conversations')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('llm_servers')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    private function seedOperationCatalog(): void
    {
        $doc = ['paths' => []];

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

    private function makeAgent(User $owner, string $name): Agent
    {
        return app(AgentService::class)->create($owner->id, "name: {$name}\ninstructions: I am {$name}.");
    }

    private function makeConversation(User $owner, ?Agent $agent): Conversation
    {
        return Conversation::create([
            'user_id' => $owner->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'character' => 'Clarion',
            'agent_id' => $agent?->id,
            'agent_version_id' => $agent?->current_version_id,
        ]);
    }

    private function makeParentWithHelpers(User $owner, int $n, string $label): array
    {
        $parent = $this->makeAgent($owner, "parent-{$label}");
        $helpers = [];
        for ($i = 0; $i < $n; $i++) {
            $helper = $this->makeAgent($owner, "helper-{$label}-{$i}");
            app(AgentHelperService::class)->assign($owner->id, $parent->id, $helper->id);
            $helpers[] = $helper;
        }

        return ['parent' => $parent, 'helpers' => $helpers, 'conversation' => $this->makeConversation($owner, $parent)];
    }

    private function successResult(string $summary): array
    {
        return [
            'status' => 'completed',
            'content' => json_encode(['status' => 'success', 'summary' => $summary, 'output' => [], 'undone' => '']),
            'validated' => ['status' => 'success', 'summary' => $summary, 'output' => [], 'undone' => ''],
            'message_id' => null,
        ];
    }

    // =================================================================
    // Scenario 6 (US5 AC2, FR-010, SC-005's converse): too few successful
    // contributors correctly refuses to present a thin result as complete.
    // =================================================================

    #[Test]
    public function scenario_6_falling_below_quorum_refuses_a_thin_result_but_still_reports_the_cost_incurred(): void
    {
        $fixture = $this->makeParentWithHelpers($this->user, 3, 'quorum');
        [$helperA, $helperB, $helperC] = $fixture['helpers'];

        $mockAgentLoopService = Mockery::mock(AgentLoopService::class);
        $mockAgentLoopService->shouldReceive('run')->andReturnUsing(function (Conversation $conversation, string $message) use ($helperA, $helperB, $helperC) {
            return match ($conversation->agent_id) {
                $helperA->id => $this->successResult('This is safe to proceed.'),
                $helperB->id, $helperC->id => throw new \RuntimeException('Contributors B/C\'s jobs must never actually run in this scenario -- if this fires, the Bus::fake() matcher below is not selecting the right jobs.'),
                default => throw new \RuntimeException('Unexpected helper conversation: '.$conversation->agent_id),
            };
        });
        $this->app->instance(AgentLoopService::class, $mockAgentLoopService);

        // Selectively fake BOTH contributor B and C's own dispatched jobs --
        // simulating a worker that never picks either up, leaving only
        // contributor A to complete for real.
        Bus::fake([function ($job) use ($helperB, $helperC) {
            if (!$job instanceof RunDelegationBatchMemberJob) {
                return false;
            }
            $row = Delegation::find($job->delegationId);

            return $row !== null && in_array($row->helper_agent_id, [$helperB->id, $helperC->id], true);
        }]);

        Context::add('run_id', (string) Str::uuid());

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/clarion-app/llm-client/consensus-requests', [
                'question' => 'Is it safe to run this migration?',
                'conversation_id' => $fixture['conversation']->id,
            ]);

        $response->assertStatus(200);
        $this->assertSame('insufficient_quorum', $response->json('status'), 'only 1 of 3 succeeded, below the default quorum_required of 2 -- must never be reported as completed');
        $this->assertSame(1, $response->json('successful_count'));
        $this->assertSame(3, $response->json('dispatched_count'));
        $this->assertSame(2, $response->json('quorum_required'));

        // FR-010: contributor A's lone answer must never be presented as if
        // it were a reconciled or agreed-upon result.
        $this->assertNull($response->json('reconciled_answer'));
        $this->assertNull($response->json('agreement_classification'));
        $this->assertNull($response->json('answer_message_id'));
        $this->assertNull($response->json('disagreement_detail'));

        // FR-013's after-figure does not condition on quorum being met --
        // contributors 1-3 still ran and still cost money, even though the
        // outcome was refused.
        $actualAdditionalCost = $response->json('actual_additional_cost');
        $this->assertNotNull($actualAdditionalCost, 'actual_additional_cost must still be populated even though the outcome was refused for insufficient quorum');
        $this->assertIsNumeric($actualAdditionalCost);

        // The response's message must name how many responded versus the
        // required minimum (contracts/consensus-api.md §1).
        $message = $response->json('message');
        $this->assertIsString($message);
        $this->assertStringContainsString('1', $message);
        $this->assertStringContainsString('3', $message);
        $this->assertStringContainsString('2', $message);

        $rows = Delegation::where('parent_conversation_id', $fixture['conversation']->id)->get()->keyBy('helper_agent_id');
        $this->assertSame('completed', $rows[$helperA->id]->status);
        $this->assertSame('exhausted', $rows[$helperB->id]->status, 'never-run contributors must be force-finalized exhausted by the REAL, unmodified forceFinalizeBatchJoinTimeout()');
        $this->assertSame('exhausted', $rows[$helperC->id]->status);
        $this->assertSame('batch_join_timeout', $rows[$helperB->id]->result_reason);
        $this->assertSame('batch_join_timeout', $rows[$helperC->id]->result_reason);

        // GET .../{id} must read back the identical shape (contracts §2).
        $requestId = $response->json('consensus_request_id');
        $showResponse = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/consensus-requests/{$requestId}");
        $showResponse->assertStatus(200);
        $this->assertSame('insufficient_quorum', $showResponse->json('status'));
        $this->assertSame($actualAdditionalCost, $showResponse->json('actual_additional_cost'));
    }
}
