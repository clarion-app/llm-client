<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Contracts\ProviderType;
use ClarionApp\LlmClient\Jobs\RunDelegationBatchMemberJob;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\RoleAssignment;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
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
 * 104-multi-agent-consensus, Phase 7 (US5), tasks.md T047.
 *
 * quickstart.md scenario 5 (US5 AC1, FR-009/FR-011, SC-005): 3 contributors
 * dispatched, quorum_required = 2 (default). Contributor 3's delegation
 * never reaches a terminal status within its own bound, so
 * DelegationService::delegateBatch()'s forceFinalizeBatchJoinTimeout()
 * (101, unmodified -- this feature reuses it as-is, research.md D1/D4)
 * marks it result_status: 'failure', result_reason: 'batch_join_timeout'.
 * Contributors 1 and 2 succeed and agree. Asserts: status: 'completed',
 * successful_count: 2, agreement_classification: 'agreed' (computed from
 * contributors 1/2 only); the response discloses that a contributor did
 * not respond; total wall time bounded, not an indefinite wait.
 *
 * Unlike every earlier Phase 3-6 Consensus journey test, DelegationService
 * itself is NOT mocked here -- delegateBatch() runs for real (mirroring
 * ParallelDelegationJourneyTest's own scenario 9 technique exactly:
 * AgentLoopService mocked for the underlying model call, one member's own
 * RunDelegationBatchMemberJob selectively faked via Bus::fake() so it
 * never runs and forceFinalizeBatchJoinTimeout() is exercised for real),
 * because this test's whole point is to prove the REAL, unmodified
 * timeout/force-finalize machinery composes correctly with
 * ConsensusService::finalize() -- a mocked delegateBatch() would prove
 * nothing about that composition.
 */
class ConsensusContributorFailureJourneyTest extends TestCase
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

    /**
     * ConsensusReconciliationJudge is final and cannot be mocked directly
     * -- seeds a real judge-role RoleAssignment plus a fake ProviderRegistry
     * provider returning the given JSON content (established technique from
     * Phase 3's ConsensusReliableAnswerJourneyTest onward).
     */
    private function seedJudge(array $jsonResponse): void
    {
        RoleAssignment::create([
            'role' => 'judge',
            'user_id' => RoleAssignment::INSTALLATION_SCOPE_ID,
            'server_id' => $this->server->id,
            'model' => 'judge-model',
        ]);

        $provider = $this->createMock(LlmProvider::class);
        $provider->method('chat')->willReturn([
            'choices' => [['message' => ['role' => 'assistant', 'content' => json_encode($jsonResponse)]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10, 'total_tokens' => 20],
            'model' => 'judge-model',
        ]);
        app(ProviderRegistry::class)->register(ProviderType::OpenAI, fn () => $provider);
    }

    // =================================================================
    // Scenario 5 (US5 AC1, FR-009/FR-011, SC-005): a timed-out contributor
    // never blocks a meaningful outcome once quorum is still met by the
    // remaining two -- and the failure is disclosed, not silently dropped.
    // =================================================================

    #[Test]
    public function scenario_5_a_timed_out_contributor_does_not_block_a_reconciled_outcome_when_quorum_is_still_met(): void
    {
        $fixture = $this->makeParentWithHelpers($this->user, 3, 'failure');
        [$helperA, $helperB, $helperC] = $fixture['helpers'];

        $mockAgentLoopService = Mockery::mock(AgentLoopService::class);
        $mockAgentLoopService->shouldReceive('run')->andReturnUsing(function (Conversation $conversation, string $message) use ($helperA, $helperB, $helperC) {
            return match ($conversation->agent_id) {
                $helperA->id => $this->successResult('This is safe to proceed.'),
                $helperB->id => $this->successResult("There's no issue running this."),
                $helperC->id => throw new \RuntimeException('Contributor C\'s job must never actually run in this scenario -- if this fires, the Bus::fake() matcher below is not selecting the right job.'),
                default => throw new \RuntimeException('Unexpected helper conversation: '.$conversation->agent_id),
            };
        });
        $this->app->instance(AgentLoopService::class, $mockAgentLoopService);

        // Selectively fake ONLY contributor C's own dispatched job --
        // simulating a worker that never picks it up, exactly
        // ParallelDelegationJourneyTest's own scenario 9 technique.
        Bus::fake([function ($job) use ($helperC) {
            if (!$job instanceof RunDelegationBatchMemberJob) {
                return false;
            }
            $row = Delegation::find($job->delegationId);

            return $row !== null && $row->helper_agent_id === $helperC->id;
        }]);

        $this->seedJudge([
            'classification' => 'agreed',
            'reconciled_answer' => 'Yes, safe -- both responding contributors agree.',
            'positions' => [],
        ]);

        Context::add('run_id', (string) Str::uuid());

        $start = microtime(true);
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/clarion-app/llm-client/consensus-requests', [
                'question' => 'Is it safe to run this migration?',
                'conversation_id' => $fixture['conversation']->id,
            ]);
        $elapsedSeconds = microtime(true) - $start;

        $this->assertLessThan(
            10.0,
            $elapsedSeconds,
            'the whole request must be bounded by delegation.max_seconds + grace, never an indefinite wait for the hung contributor',
        );

        $response->assertStatus(200);
        $this->assertSame('completed', $response->json('status'));
        $this->assertSame(2, $response->json('successful_count'));
        $this->assertSame(3, $response->json('dispatched_count'));
        $this->assertSame('agreed', $response->json('agreement_classification'), 'computed from contributors 1/2 only -- the timed-out contributor must never be counted toward successful_count');

        // FR-011: the response must disclose that a contributor did not
        // respond -- either inline via `message`, or discoverable via
        // GET .../contributors.
        $message = $response->json('message');
        $requestId = $response->json('consensus_request_id');

        $contributorsResponse = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/consensus-requests/{$requestId}/contributors");
        $contributorsResponse->assertStatus(200);

        $contributors = collect($contributorsResponse->json('contributors'))->keyBy('helper_agent_id');
        $this->assertArrayHasKey($helperC->id, $contributors->all(), 'the timed-out contributor must still be individually inspectable');
        $this->assertSame('failure', $contributors[$helperC->id]['result_status']);
        $this->assertSame('batch_join_timeout', $contributors[$helperC->id]['result_reason']);

        $disclosedInline = is_string($message) && str_contains($message, '1') && (str_contains($message, 'did not respond') || str_contains($message, 'timed out') || str_contains($message, 'timeout'));
        $this->assertTrue(
            $disclosedInline,
            'FR-011 requires the response to disclose that a contributor did not respond -- either the message field must name it plainly, or (proven above) GET .../contributors must show its failure plainly; the inline message is checked here and must not be silently absent',
        );

        $rows = Delegation::where('parent_conversation_id', $fixture['conversation']->id)->get()->keyBy('helper_agent_id');
        $this->assertSame('exhausted', $rows[$helperC->id]->status, 'the never-run contributor must be force-finalized exhausted by the REAL, unmodified forceFinalizeBatchJoinTimeout(), never left queued/in_progress forever');
        $this->assertSame('batch_join_timeout', $rows[$helperC->id]->result_reason);
    }
}
