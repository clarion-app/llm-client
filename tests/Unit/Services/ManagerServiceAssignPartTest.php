<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

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
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 103-manager-agent, Phase 3 (US1), tasks.md T016.
 *
 * Unit tests for the not-yet-built `ManagerService::assignPart()`'s full
 * transactional guard (data-model.md §5, research.md D4, contracts/
 * manager-agent-meta-tools.md §2, tasks.md "Ordering rationale" -- the
 * complete FR-014/FR-009 guard ships in Phase 3, not deferred).
 *
 * Mirrors DelegationServiceTest.php's own scaffolding (seedOperationCatalog,
 * the auxiliary tables buildMessagesPayload()/applyContextWindowTrim()
 * touch regardless, serviceWithScriptedProvider()) since assignPart()'s
 * success path calls DelegationService::delegate(), which runs a real,
 * unmodified AgentLoopService::run() against a mocked LlmProvider.
 *
 * Written before ManagerService exists -- every test below is expected to
 * FAIL red (class not found) until T021 creates it.
 */
class ManagerServiceAssignPartTest extends TestCase
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
    // Fixture helpers (DelegationServiceTest.php precedent)
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
            $next = array_shift($responses);

            return is_callable($next) ? $next() : $next;
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

    private function successResultReply(): array
    {
        return $this->plainReply(json_encode([
            'status' => 'success',
            'summary' => 'Helper completed the task.',
            'output' => [],
            'undone' => '',
        ], JSON_FORCE_OBJECT));
    }

    /**
     * Builds a manager agent, a helper agent assigned to it, a
     * channel='managed-task' ManagedTask, and one ManagedTaskPart.
     *
     * @return array{ManagedTask, ManagedTaskPart, Agent}
     */
    private function makeManagedTaskWithPart(int $roundCeiling = 30): array
    {
        $manager = $this->makeAgent('manager-'.uniqid());
        $helper = $this->makeAgent('helper-'.uniqid());
        app(AgentHelperService::class)->assign($this->user->id, $manager->id, $helper->id);

        $task = app(ManagerService::class)->createManagedTask($this->user->id, $manager->id, 'A task with one part.');
        $task->round_ceiling = $roundCeiling;
        $task->save();

        [$part] = app(ManagerService::class)->planParts($task, ['The only part.']);

        return [$task, $part, $helper];
    }

    // =================================================================
    // State guard
    // =================================================================

    #[Test]
    public function refuses_when_the_part_is_already_accepted(): void
    {
        [$task, $part, $helper] = $this->makeManagedTaskWithPart();
        $part->state = 'accepted';
        $part->save();

        $this->app->instance(AgentLoopService::class, $this->serviceWithScriptedProvider([]));

        $result = app(ManagerService::class)->assignPart($task, $part, $helper->id, 'Do it.', null);

        $this->assertSame('part_already_finalized', $result['error'] ?? null);
        $this->assertSame(0, Delegation::count(), 'a refused assignPart() call must never create a Delegation row');

        $task->refresh();
        $part->refresh();
        $this->assertSame(0, $task->rounds_used, 'a refused call must never consume a round');
        $this->assertSame('accepted', $part->state, 'a refused call must never change the part\'s state');
    }

    #[Test]
    public function refuses_when_the_part_is_already_reported_as_shortfall(): void
    {
        [$task, $part, $helper] = $this->makeManagedTaskWithPart();
        $part->state = 'reported_as_shortfall';
        $part->save();

        $result = app(ManagerService::class)->assignPart($task, $part, $helper->id, 'Do it.', null);

        $this->assertSame('part_already_finalized', $result['error'] ?? null);
        $this->assertSame(0, Delegation::count());
        $task->refresh();
        $this->assertSame(0, $task->rounds_used);
    }

    /**
     * "Outstanding" means genuinely unresolved (research.md D2 -- a
     * correction/reassignment MUST remain reachable via a later
     * assign_part() call once the prior delegation has resolved, or US2/
     * US5 would be structurally impossible). This is proven directly: a
     * part in out_for_assignment whose own current_delegation_id still
     * points at a non-terminal ('in_progress') Delegation row is refused
     * -- the genuinely concurrent case (quickstart scenario 6, US6) --
     * while a correction attempted once that delegation has ALREADY
     * resolved is admitted, proven separately below.
     */
    #[Test]
    public function refuses_when_the_parts_current_delegation_has_not_yet_resolved_out_for_assignment(): void
    {
        [$task, $part, $helper] = $this->makeManagedTaskWithPart();

        $outstanding = Delegation::create([
            'parent_conversation_id' => $task->conversation_id,
            'helper_agent_id' => $helper->id,
            'helper_conversation_id' => (string) \Illuminate\Support\Str::uuid(),
            'owner_user_id' => $this->user->id,
            'task' => 'Still running.',
            'depth' => 1,
            'status' => 'in_progress',
            'started_at' => now(),
            'managed_task_id' => $task->id,
            'part_id' => $part->id,
        ]);
        $part->state = 'out_for_assignment';
        $part->current_delegation_id = $outstanding->id;
        $part->save();

        $result = app(ManagerService::class)->assignPart($task, $part, $helper->id, 'Do it again.', null);

        $this->assertSame('assignment_already_outstanding', $result['error'] ?? null);
        $this->assertSame(1, Delegation::count(), 'a refused call must never create a SECOND Delegation row');
        $task->refresh();
        $this->assertSame(0, $task->rounds_used);
    }

    #[Test]
    public function refuses_when_the_parts_current_delegation_has_not_yet_resolved_out_for_correction(): void
    {
        [$task, $part, $helper] = $this->makeManagedTaskWithPart();

        $outstanding = Delegation::create([
            'parent_conversation_id' => $task->conversation_id,
            'helper_agent_id' => $helper->id,
            'helper_conversation_id' => (string) \Illuminate\Support\Str::uuid(),
            'owner_user_id' => $this->user->id,
            'task' => 'Still running (a correction round).',
            'depth' => 1,
            'status' => 'queued',
            'started_at' => now(),
            'managed_task_id' => $task->id,
            'part_id' => $part->id,
        ]);
        $part->state = 'out_for_correction';
        $part->current_delegation_id = $outstanding->id;
        $part->save();

        $result = app(ManagerService::class)->assignPart($task, $part, $helper->id, 'Correct it again.', null);

        $this->assertSame('assignment_already_outstanding', $result['error'] ?? null);
        $this->assertSame(1, Delegation::count());
    }

    // =================================================================
    // Round-ceiling guard (evaluated against ManagedTask.rounds_used)
    // =================================================================

    #[Test]
    public function refuses_when_the_managed_tasks_own_rounds_used_has_reached_the_round_ceiling(): void
    {
        [$task, $part, $helper] = $this->makeManagedTaskWithPart(roundCeiling: 2);
        $task->rounds_used = 2;
        $task->save();

        $result = app(ManagerService::class)->assignPart($task, $part, $helper->id, 'One more, please.', null);

        $this->assertSame('round_ceiling_reached', $result['error'] ?? null);
        $this->assertSame(0, Delegation::count());
        $part->refresh();
        $this->assertSame('not_yet_assigned', $part->state, 'a ceiling refusal must never change the part\'s state');
    }

    #[Test]
    public function the_ceiling_check_is_whole_task_never_a_single_parts_own_assignment_count(): void
    {
        // ManagedTask.round_ceiling = 5, rounds_used = 1 (well under
        // ceiling) even though ONE part's own assignment_count is already
        // 6 (over the ceiling on its own) -- constructed to prove the
        // check reads ManagedTask.rounds_used, never a part's own counter
        // (tasks.md T016/mutation-checklist row 2).
        $manager = $this->makeAgent('manager-wholetask');
        $helper = $this->makeAgent('helper-wholetask');
        app(AgentHelperService::class)->assign($this->user->id, $manager->id, $helper->id);

        $task = app(ManagerService::class)->createManagedTask($this->user->id, $manager->id, 'Whole-task ceiling check.');
        $task->round_ceiling = 5;
        $task->rounds_used = 1;
        $task->save();

        [$busyPart, $freshPart] = app(ManagerService::class)->planParts($task, ['Busy part.', 'Fresh part.']);
        $busyPart->assignment_count = 6;
        $busyPart->save();

        $this->app->instance(AgentLoopService::class, $this->serviceWithScriptedProvider([
            $this->successResultReply(),
        ]));

        $result = app(ManagerService::class)->assignPart($task, $freshPart, $helper->id, 'Do the fresh part.', null);

        $this->assertArrayNotHasKey('error', $result, 'rounds_used (1) is under round_ceiling (5) -- the call must be admitted regardless of any single part\'s own assignment_count');

        $task->refresh();
        $this->assertSame(2, $task->rounds_used);
    }

    #[Test]
    public function assigning_the_busy_part_itself_is_still_admitted_when_only_its_own_assignment_count_exceeds_the_ceiling(): void
    {
        // Direct discriminator for mutation-checklist row 2: the sibling
        // test above only ever assigns the FRESH part (assignment_count
        // 0), so a mutated check reading the PART's own assignment_count
        // instead of ManagedTask.rounds_used would still admit that call
        // too -- both a correct and a mutated check agree there. This
        // test assigns the BUSY part itself (assignment_count already
        // over what would be the ceiling if read per-part) while
        // ManagedTask.rounds_used stays well under round_ceiling --
        // correct code (rounds_used-based) admits it; code mutated to
        // check the part's own assignment_count instead would wrongly
        // refuse it.
        $manager = $this->makeAgent('manager-busypart-wholetask');
        $helper = $this->makeAgent('helper-busypart-wholetask');
        app(AgentHelperService::class)->assign($this->user->id, $manager->id, $helper->id);

        $task = app(ManagerService::class)->createManagedTask($this->user->id, $manager->id, 'Busy-part whole-task ceiling check.');
        $task->round_ceiling = 5;
        $task->rounds_used = 1;
        $task->save();

        // Bump assignment_count alone, leaving state/current_delegation_id
        // at planParts()'s own not_yet_assigned/null defaults -- touching
        // either would trip the SEPARATE "assignment already outstanding"
        // guard (a null current_delegation_id while state is
        // out_for_assignment/out_for_correction is treated defensively as
        // still-outstanding), which is not what this test isolates.
        [$busyPart] = app(ManagerService::class)->planParts($task, ['Busy part.']);
        $busyPart->assignment_count = 6;
        $busyPart->save();

        $this->app->instance(AgentLoopService::class, $this->serviceWithScriptedProvider([
            $this->successResultReply(),
        ]));

        $result = app(ManagerService::class)->assignPart($task, $busyPart, $helper->id, 'Correct the busy part again.', null);

        $this->assertArrayNotHasKey('error', $result, 'the check must read ManagedTask.rounds_used (1, under the ceiling of 5), never this part\'s own assignment_count (6, already over it)');

        $task->refresh();
        $this->assertSame(2, $task->rounds_used);
    }

    // =================================================================
    // Success path -- atomic writes, ordering, Delegation stamping
    // =================================================================

    #[Test]
    public function on_success_increments_rounds_used_and_transitions_a_never_assigned_part_to_out_for_assignment(): void
    {
        [$task, $part, $helper] = $this->makeManagedTaskWithPart();
        $this->assertSame('not_yet_assigned', $part->state, 'fixture sanity');
        $this->assertSame(0, $part->assignment_count, 'fixture sanity');

        $this->app->instance(AgentLoopService::class, $this->serviceWithScriptedProvider([
            $this->successResultReply(),
        ]));

        $result = app(ManagerService::class)->assignPart($task, $part, $helper->id, 'Do the part.', 'Some context.');

        $this->assertSame('success', $result['status'] ?? null);
        $this->assertArrayHasKey('delegation_id', $result);

        $task->refresh();
        $part->refresh();

        $this->assertSame(1, $task->rounds_used);
        $this->assertSame('out_for_assignment', $part->state);
        $this->assertSame(1, $part->assignment_count);
        $this->assertSame($result['delegation_id'], $part->current_delegation_id);

        $delegation = Delegation::find($result['delegation_id']);
        $this->assertNotNull($delegation);
        $this->assertSame($task->id, $delegation->managed_task_id, 'the created Delegation row must be stamped with managed_task_id');
        $this->assertSame($part->id, $delegation->part_id, 'the created Delegation row must be stamped with part_id');
    }

    #[Test]
    public function on_success_transitions_a_previously_assigned_part_to_out_for_correction(): void
    {
        [$task, $part, $helper] = $this->makeManagedTaskWithPart();

        $this->app->instance(AgentLoopService::class, $this->serviceWithScriptedProvider([
            $this->successResultReply(),
        ]));
        app(ManagerService::class)->assignPart($task, $part, $helper->id, 'First attempt.', null);

        $part->refresh();
        $task->refresh();
        $this->assertSame('out_for_assignment', $part->state, 'fixture sanity: first assignment');

        // The first delegation already resolved synchronously (its own
        // status is 'completed'), so a SECOND assign_part() call on the
        // SAME part_id -- a correction, research.md D2/US2 -- must be
        // admitted even though the part's own bookkeeping state is still
        // out_for_assignment (nothing else has closed it -- accept_part
        // doesn't exist until Phase 4).
        $this->app->instance(AgentLoopService::class, $this->serviceWithScriptedProvider([
            $this->successResultReply(),
        ]));

        $result = app(ManagerService::class)->assignPart($task, $part, $helper->id, 'Correction.', 'What was wrong.');

        $this->assertArrayNotHasKey('error', $result, 'a correction on a part whose prior delegation already resolved must be admitted, not refused');

        $part->refresh();
        $task->refresh();
        $this->assertSame('out_for_correction', $part->state, 'a part with a prior assignment (assignment_count > 0) must transition to out_for_correction, not out_for_assignment');
        $this->assertSame(2, $part->assignment_count);
        $this->assertSame(2, $task->rounds_used);
    }

    #[Test]
    public function every_write_is_visible_before_the_nested_delegate_call_is_made(): void
    {
        [$task, $part, $helper] = $this->makeManagedTaskWithPart();
        $taskId = $task->id;
        $partId = $part->id;

        $this->app->instance(AgentLoopService::class, $this->serviceWithScriptedProvider([
            function () use ($taskId, $partId) {
                // Reached from inside AgentLoopService::run(), which is
                // only ever called AFTER admitAssignmentRound()'s own
                // transaction has committed (research.md D4's ordering
                // requirement) -- a fresh read here must already see the
                // admitted state.
                $freshTask = ManagedTask::find($taskId);
                $freshPart = ManagedTaskPart::find($partId);

                if ($freshTask->rounds_used !== 1 || $freshPart->state !== 'out_for_assignment') {
                    throw new \RuntimeException('admitAssignmentRound()\'s own writes were not yet visible when the nested delegate() call ran.');
                }

                return $this->successResultReply();
            },
        ]));

        $result = app(ManagerService::class)->assignPart($task, $part, $helper->id, 'Do it.', null);

        $this->assertSame('success', $result['status'] ?? null, 'the ordering assertion inside the scripted provider must not have thrown');
    }
}
