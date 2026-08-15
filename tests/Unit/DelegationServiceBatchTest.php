<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Jobs\RunDelegationBatchMemberJob;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\AgentHelperService;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\DelegationService;
use ClarionApp\LlmClient\Services\RoleAssignmentService;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use Dedoc\Scramble\Generator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 101-parallel-subagent-execution, Phase 3 (US1), tasks.md T012.
 *
 * Unit tests for the not-yet-built `DelegationService::delegateBatch(
 * Conversation $parentConversation, array $calls): array` (contracts §1,
 * research.md D1/D2, tasks.md T019). `$calls` is the ordered list of one
 * iteration's `delegate_to_helper` tool calls, each carrying its own
 * `tool_call_id`/`helper_agent_id`/`task`/`context` -- the same shape
 * `AgentLoopServiceDelegationBatchTest` (T013) exercises from the caller's
 * side.
 *
 * Fixture scaffolding (operation catalog, mcp_sessions/episodic_memories/
 * condensation_states) mirrors `tests/Unit/Services/DelegationServiceTest.php`'s
 * own established precedent exactly -- `delegateBatch()` reuses the same
 * `resolveAndValidate()`/`createDelegationRow()` extraction (T018) the
 * solo `delegate()` path already exercises, so the same fixtures apply.
 *
 * `RunDelegationBatchMemberJob` is faked via `Bus::fake()` throughout --
 * this file is about what `delegateBatch()` WRITES and DISPATCHES, never
 * about a member's own nested execution (T011's own concern).
 *
 * Written before `DelegationService::delegateBatch()` exists -- every test
 * below is expected to FAIL red (undefined method) until T019 creates it.
 */
class DelegationServiceBatchTest extends TestCase
{
    private User $user;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

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
        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('agent_delegations')->delete();
        DB::table('conversations')->delete();
        DB::table('agent_helper_assignments')->delete();
        DB::table('llm_role_assignments')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('llm_servers')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Operation-catalog scaffolding (DelegationServiceTest's own
    // established precedent)
    // -----------------------------------------------------------------

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

    // -----------------------------------------------------------------
    // Fixture helpers
    // -----------------------------------------------------------------

    private function makeAgent(string $name): Agent
    {
        return app(AgentService::class)->create($this->user->id, "name: {$name}\ninstructions: I am {$name}.");
    }

    private function makeConversation(?Agent $agent): Conversation
    {
        return Conversation::factory()->create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'title' => 'Already titled',
            'agent_id' => $agent?->id,
            'agent_version_id' => $agent?->current_version_id,
        ]);
    }

    private function delegateCall(string $toolCallId, string $helperAgentId, string $task, ?string $context = null): array
    {
        return [
            'tool_call_id' => $toolCallId,
            'helper_agent_id' => $helperAgentId,
            'task' => $task,
            'context' => $context,
        ];
    }

    // =================================================================
    // N >= 2 valid calls -> N queued rows, one shared batch_id
    // =================================================================

    #[Test]
    public function delegatebatch_creates_n_rows_sharing_one_freshly_generated_batch_id_all_queued_and_dispatches_a_job_per_row(): void
    {
        Bus::fake([RunDelegationBatchMemberJob::class]);

        $parent = $this->makeAgent('parent-agent-batch');
        $helperA = $this->makeAgent('helper-agent-batch-a');
        $helperB = $this->makeAgent('helper-agent-batch-b');
        $helperC = $this->makeAgent('helper-agent-batch-c');
        app(AgentHelperService::class)->assign($this->user->id, $parent->id, $helperA->id);
        app(AgentHelperService::class)->assign($this->user->id, $parent->id, $helperB->id);
        app(AgentHelperService::class)->assign($this->user->id, $parent->id, $helperC->id);

        $conversation = $this->makeConversation($parent);

        $calls = [
            $this->delegateCall('call_a', $helperA->id, 'Task A.', 'Context A.'),
            $this->delegateCall('call_b', $helperB->id, 'Task B.', 'Context B.'),
            $this->delegateCall('call_c', $helperC->id, 'Task C.', 'Context C.'),
        ];

        $results = app(DelegationService::class)->delegateBatch($conversation, $calls);

        $this->assertIsArray($results);

        $rows = Delegation::where('parent_conversation_id', $conversation->id)->get();
        $this->assertCount(3, $rows, 'delegateBatch() with 3 valid calls must create exactly 3 Delegation rows');

        $batchIds = $rows->pluck('batch_id')->unique();
        $this->assertCount(1, $batchIds, 'every row from one delegateBatch() invocation must share exactly one batch_id');
        $this->assertNotNull($batchIds->first(), 'the shared batch_id must actually be set, never left null');

        foreach ($rows as $row) {
            $this->assertSame('queued', $row->status, 'every freshly created batch row must start queued, never in_progress -- only DelegationConcurrencyGate::tryAdmit() may make that transition');
        }

        Bus::assertDispatchedTimes(RunDelegationBatchMemberJob::class, 3);
        foreach ($rows as $row) {
            Bus::assertDispatched(RunDelegationBatchMemberJob::class, fn (RunDelegationBatchMemberJob $job) => $job->delegationId === $row->id);
        }
    }

    // =================================================================
    // A refused call is skipped -- no row, no job -- while the rest of
    // the same invocation still proceeds
    // =================================================================

    #[Test]
    public function delegatebatch_refuses_an_invalid_call_immediately_with_no_row_and_no_job_while_the_other_valid_calls_still_proceed_together(): void
    {
        Bus::fake([RunDelegationBatchMemberJob::class]);

        $parent = $this->makeAgent('parent-agent-batch-mixed');
        $helperA = $this->makeAgent('helper-agent-batch-mixed-a');
        $helperC = $this->makeAgent('helper-agent-batch-mixed-c');
        $notAHelper = $this->makeAgent('not-an-assigned-helper-batch-mixed');
        app(AgentHelperService::class)->assign($this->user->id, $parent->id, $helperA->id);
        app(AgentHelperService::class)->assign($this->user->id, $parent->id, $helperC->id);
        // Deliberately NOT assigning $notAHelper -- the refusal case.

        $conversation = $this->makeConversation($parent);

        $calls = [
            $this->delegateCall('call_a', $helperA->id, 'Task A.', null),
            $this->delegateCall('call_b_invalid', $notAHelper->id, 'Task B (should be refused).', null),
            $this->delegateCall('call_c', $helperC->id, 'Task C.', null),
        ];

        $results = app(DelegationService::class)->delegateBatch($conversation, $calls);

        $rows = Delegation::where('parent_conversation_id', $conversation->id)->get();
        $this->assertCount(2, $rows, 'only the 2 VALID calls may create a Delegation row -- the refused call gets none');
        $this->assertEqualsCanonicalizing(
            [$helperA->id, $helperC->id],
            $rows->pluck('helper_agent_id')->all(),
            'the two rows that DO exist must belong to the two valid calls, not the refused one',
        );

        $batchIds = $rows->pluck('batch_id')->unique();
        $this->assertCount(1, $batchIds, 'the two valid calls must still share one batch_id -- a refusal elsewhere in the same invocation must not fragment the batch');

        Bus::assertDispatchedTimes(RunDelegationBatchMemberJob::class, 2, 'exactly 2 jobs -- one per valid row -- must be dispatched; none for the refused call');

        $this->assertIsArray($results, 'fixture sanity: delegateBatch() must still return a result set covering every original call, valid or refused');
        $this->assertArrayHasKey('call_b_invalid', $results, 'the refused call must still be represented in the returned result set, keyed by its own tool_call_id, so the caller can answer the model for every tool_call it asked about');
        $this->assertSame(
            'not_an_assigned_helper',
            $results['call_b_invalid']['error'] ?? null,
            'the refusal shape must be the same error code delegate() already uses for this exact validation failure',
        );
    }

    #[Test]
    public function delegatebatch_refuses_every_call_with_no_bound_agent_when_the_parent_conversation_has_no_agent_at_all(): void
    {
        Bus::fake([RunDelegationBatchMemberJob::class]);

        $conversation = $this->makeConversation(null);
        $this->assertNull($conversation->agent_id, 'fixture sanity: the conversation must start unbound');

        $someAgent = $this->makeAgent('some-agent-no-bound-agent-batch');

        $results = app(DelegationService::class)->delegateBatch($conversation, [
            $this->delegateCall('call_a', $someAgent->id, 'Task A.', null),
            $this->delegateCall('call_b', $someAgent->id, 'Task B.', null),
        ]);

        $this->assertSame(0, Delegation::count(), 'an unbound parent conversation has no assigned helpers at all -- neither call may create a row');
        Bus::assertNothingDispatched();
        $this->assertSame('no_bound_agent', $results['call_a']['error'] ?? null);
        $this->assertSame('no_bound_agent', $results['call_b']['error'] ?? null);
    }
}
