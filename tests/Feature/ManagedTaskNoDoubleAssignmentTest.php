<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\ManagedTask;
use ClarionApp\LlmClient\Models\ManagedTaskPart;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentHelperService;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\ManagerService;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\Services\RoleAssignmentService;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use Dedoc\Scramble\Generator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 103-manager-agent, Phase 8 (US6), tasks.md T060.
 *
 * Quickstart scenario 6 (FR-014, SC-009) -- a dedicated, falsifiable proof
 * that a completed part is never reassigned and an outstanding part is
 * never double-assigned, across a full multi-part task's ENTIRE assignment
 * history, using the guard `ManagerService::admitAssignmentRound()` already
 * built in Phase 3 (tasks.md "Ordering rationale": this phase is proof, not
 * first implementation -- no new production code is expected unless this
 * test finds a genuine gap the per-call unit tests
 * (`ManagerServiceAssignPartTest`, T016) could not see).
 *
 * Drives `ManagerService` directly (not through `AgentLoopService`'s tool
 * dispatch) for the two refusal scenarios -- "directly attempt" in the
 * quickstart wording means bypassing normal manager-turn flow, simulating
 * a model (or a genuinely concurrent second caller) that tries anyway --
 * mirroring `ManagerServiceAssignPartTest.php`'s own precedent for
 * constructing an "outstanding" delegation.
 */
class ManagedTaskNoDoubleAssignmentTest extends TestCase
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
        restore_error_handler();
        restore_exception_handler();

        $this->clearOperationCatalog();
        Mockery::close();

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
        DB::table('managed_task_parts')->delete();
        DB::table('managed_tasks')->delete();
        DB::table('agent_helper_assignments')->delete();
        DB::table('llm_role_assignments')->delete();
        DB::table('conversations')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('llm_servers')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Fixture helpers (ManagerServiceAssignPartTest.php precedent)
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

    private function makeAgent(string $name): Agent
    {
        return app(AgentService::class)->create($this->user->id, "name: {$name}\ninstructions: I am {$name}.");
    }

    private function serviceWithScriptedProvider(array $responses): AgentLoopService
    {
        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturnUsing(function ($messages, $tools, $options = []) use (&$responses) {
            $this->assertNotEmpty($responses, 'the scripted response queue was exhausted -- the loop made more provider calls than this test expected');

            return array_shift($responses);
        });
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);

        return new AgentLoopService(
            app(McpToolRegistry::class),
            app(McpToolExecutor::class),
            app(OperationCache::class),
            $registry,
            presetRegistry: app(\ClarionApp\LlmClient\Services\StructuredOutputPresetRegistry::class),
            runTraceRecorder: config('llm-client.run_trace.enabled', false) ? app(RunTraceRecorder::class) : null,
        );
    }

    private function plainReply(string $content): array
    {
        return ['choices' => [['message' => ['content' => $content, 'tool_calls' => []]]]];
    }

    private function delegationResultReply(string $status, string $summary): array
    {
        return $this->plainReply(json_encode([
            'status' => $status,
            'summary' => $summary,
            'output' => [],
            'undone' => $status === 'partial' ? 'Some aspects were not covered.' : '',
        ], JSON_FORCE_OBJECT));
    }

    /**
     * Builds a manager agent, one helper assigned to it, a
     * channel='managed-task' ManagedTask, and three ManagedTaskPart rows
     * (A, B, C in sequence order) via ManagerService::planParts() directly
     * (ManagerServiceAssignPartTest.php's own precedent -- plan_parts needs
     * no nested LLM call).
     *
     * @return array{ManagedTask, ManagedTaskPart, ManagedTaskPart, ManagedTaskPart, Agent}
     */
    private function makeThreePartTask(): array
    {
        $manager = $this->makeAgent('manager-'.uniqid());
        $helper = $this->makeAgent('helper-'.uniqid());
        app(AgentHelperService::class)->assign($this->user->id, $manager->id, $helper->id);

        $task = app(ManagerService::class)->createManagedTask($this->user->id, $manager->id, 'A three-part task.');

        [$partA, $partB, $partC] = app(ManagerService::class)->planParts($task, [
            'Part A.',
            'Part B.',
            'Part C.',
        ]);

        return [$task, $partA, $partB, $partC, $helper];
    }

    #[Test]
    public function a_completed_part_is_never_reassigned_an_outstanding_part_is_never_double_assigned_and_every_part_ends_up_terminal(): void
    {
        [$task, $partA, $partB, $partC, $helper] = $this->makeThreePartTask();

        // =============================================================
        // Part A: accept it, then directly attempt assign_part(part_id:
        // A, ...) again -- simulating a model that tries anyway despite
        // the part already being finalized.
        // =============================================================

        $this->app->instance(AgentLoopService::class, $this->serviceWithScriptedProvider([
            $this->delegationResultReply('success', 'Part A fully completed.'),
        ]));
        $resultA = app(ManagerService::class)->assignPart($task, $partA, $helper->id, 'Do part A.', null);
        $this->assertArrayNotHasKey('error', $resultA, 'fixture sanity: the first assignment of part A must be admitted');

        app(ManagerService::class)->acceptPart($task, $partA);
        $partA->refresh();
        $this->assertSame('accepted', $partA->state, 'fixture sanity: part A must be accepted before the reassignment attempt');

        $task->refresh();
        $roundsUsedBeforeReassignmentAttempt = $task->rounds_used;
        $delegationCountBeforeReassignmentAttempt = Delegation::count();

        $reassignmentAttempt = app(ManagerService::class)->assignPart($task, $partA, $helper->id, 'Try part A again.', null);

        $this->assertSame('part_already_finalized', $reassignmentAttempt['error'] ?? null, 'a directly-attempted reassignment of an already-accepted part must be refused');
        $this->assertSame($delegationCountBeforeReassignmentAttempt, Delegation::count(), 'a refused reassignment attempt must never create a new Delegation row');

        $task->refresh();
        $partA->refresh();
        $this->assertSame($roundsUsedBeforeReassignmentAttempt, $task->rounds_used, 'a refused reassignment attempt must never consume a round');
        $this->assertSame('accepted', $partA->state, 'a refused reassignment attempt must never change the part\'s state');

        // =============================================================
        // Part B: leave it out_for_assignment (an unresolved first
        // attempt), then attempt a SECOND, concurrent assign_part before
        // the first resolves.
        // =============================================================

        $outstandingDelegation = Delegation::create([
            'parent_conversation_id' => $task->conversation_id,
            'helper_agent_id' => $helper->id,
            'helper_conversation_id' => (string) Str::uuid(),
            'owner_user_id' => $this->user->id,
            'task' => 'Do part B.',
            'depth' => 1,
            'status' => 'in_progress',
            'started_at' => now(),
            'managed_task_id' => $task->id,
            'part_id' => $partB->id,
        ]);
        $partB->state = 'out_for_assignment';
        $partB->assignment_count = 1;
        $partB->current_delegation_id = $outstandingDelegation->id;
        $partB->save();
        $task->rounds_used += 1;
        $task->save();

        $roundsUsedBeforeConcurrentAttempt = $task->rounds_used;

        $concurrentAttempt = app(ManagerService::class)->assignPart($task, $partB, $helper->id, 'Do part B (concurrent second attempt).', null);

        $this->assertSame('assignment_already_outstanding', $concurrentAttempt['error'] ?? null, 'a genuinely concurrent second assign_part call on the same part must be refused');

        $partBDelegations = Delegation::where('part_id', $partB->id)->get();
        $this->assertCount(1, $partBDelegations, 'a refused concurrent attempt must never create a SECOND Delegation row for the same part');
        $nonTerminalCount = $partBDelegations->filter(fn (Delegation $d) => in_array($d->status, ['queued', 'in_progress'], true))->count();
        $this->assertSame(1, $nonTerminalCount, 'agent_delegations for part B must never show two rows both non-terminal at once');

        $task->refresh();
        $this->assertSame($roundsUsedBeforeConcurrentAttempt, $task->rounds_used, 'a refused concurrent attempt must never consume a round');

        // =============================================================
        // Resolve part B's outstanding delegation (as if the first,
        // legitimate attempt finally completed) and accept it.
        // =============================================================

        $outstandingDelegation->status = 'completed';
        $outstandingDelegation->result_status = 'success';
        $outstandingDelegation->result_summary = 'Part B fully completed.';
        $outstandingDelegation->completed_at = now();
        $outstandingDelegation->save();

        app(ManagerService::class)->acceptPart($task, $partB);
        $partB->refresh();
        $this->assertSame('accepted', $partB->state);

        // =============================================================
        // Part C: assign it, the helper fails, and the manager reports
        // it as a shortfall rather than fabricating completion.
        // =============================================================

        $this->app->instance(AgentLoopService::class, $this->serviceWithScriptedProvider([
            $this->delegationResultReply('failure', 'Could not complete part C.'),
        ]));
        $resultC = app(ManagerService::class)->assignPart($task, $partC, $helper->id, 'Do part C.', null);
        $this->assertArrayNotHasKey('error', $resultC, 'fixture sanity: the first assignment of part C must be admitted');

        app(ManagerService::class)->reportShortfall($task, $partC, 'The helper reported failure and no suitable alternative was available.');
        $partC->refresh();
        $this->assertSame('reported_as_shortfall', $partC->state);

        // =============================================================
        // Run the whole task to completion -- every part's final state
        // must be exactly one of accepted/reported_as_shortfall, never
        // left non-terminal (US6 AC3).
        // =============================================================

        $finalizeRefusal = app(ManagerService::class)->finalizeRefusal($task, 'Part C could not be completed.');
        $this->assertNull($finalizeRefusal, 'every part is terminal (accepted or reported_as_shortfall) -- finalize must be admitted');

        app(ManagerService::class)->finalize($task, 'Parts A and B were completed; part C could not be.', 'Part C could not be completed.');

        $task->refresh();
        $this->assertSame('completed_with_shortfalls', $task->status);
        $this->assertNotNull($task->completed_at);

        foreach ([$partA, $partB, $partC] as $part) {
            $part->refresh();
            $this->assertContains(
                $part->state,
                ['accepted', 'reported_as_shortfall'],
                "part {$part->sequence} must end in exactly one of accepted/reported_as_shortfall once the task is terminal, never left out_for_assignment/out_for_correction/not_yet_assigned"
            );
        }

        $this->assertSame('accepted', $partA->state);
        $this->assertSame('accepted', $partB->state);
        $this->assertSame('reported_as_shortfall', $partC->state);
    }
}
