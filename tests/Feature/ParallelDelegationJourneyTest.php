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
use ClarionApp\LlmClient\Services\DelegationConcurrencyGate;
use ClarionApp\LlmClient\Services\DelegationQuery;
use ClarionApp\LlmClient\Services\DelegationService;
use ClarionApp\LlmClient\Services\ResultAggregationService;
use ClarionApp\LlmClient\Services\RoleAssignmentService;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\RunKind;
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
 * 101-parallel-subagent-execution, Phase 3 (US1 + US3), tasks.md T015.
 *
 * The end-to-end acceptance journey for `DelegationService::delegateBatch()`
 * (contracts §1, research.md D1/D3/D4), covering quickstart.md scenarios 1,
 * 2, 4, 5, 9, and 11, plus FR-012's batch-recoverability surface
 * (contracts §2/§3).
 *
 * `AgentLoopService` is replaced with a Mockery double throughout scenarios
 * 1/4/5/9 -- matching `DelegationServiceTest`'s own established
 * scripted-provider-equivalent technique (research.md D1: a real,
 * unmodified `DelegationService`/`DelegationConcurrencyGate`/
 * `RunDelegationBatchMemberJob` against a MOCKED nested `run()` call, never
 * a live provider) -- distinguishing which member is which by matching on
 * the member's own composed seed message content (each contains its own
 * `task` verbatim, contracts/delegation-protocol-meta-tool.md), which is
 * robust regardless of dispatch order.
 *
 * The queue connection is the ordinary test-default (`sync` unless
 * overridden), so `RunDelegationBatchMemberJob::dispatch()` runs each
 * member's `handle()` inline as part of `delegateBatch()`'s own dispatch
 * loop. **This deliberately means scenarios 1/4/5/9 below cannot, and do
 * not attempt to, prove genuine multi-process wall-clock concurrency** --
 * a single PHPUnit process has no such capability without real background
 * workers, and this package's own nearest precedent for an analogous claim
 * (`tests/Feature/EvalRunConcurrencyLimitTest.php`, covering the eval-run
 * throughput/D9 claim) settles for the same kind of structural proxy for
 * exactly this reason: it proves "every unit of work is dispatched
 * immediately, not serialized one at a time" rather than a literal elapsed-
 * time measurement across real concurrent workers. Genuine cross-process
 * timing is `tests/RealDatabase/DelegationConcurrencyTest.php`'s own
 * job (Phase 5, T029, research.md D6) for the ceiling's atomicity
 * specifically; nothing in this repository's fast suite can honestly claim
 * more for the elapsed-time side of SC-001 than the structural proxy below
 * does. Scenario 9's "a member's job never runs" is instead simulated via
 * Laravel's own closure-based `Bus::fake()` matcher, selectively faking
 * only ONE member's dispatched job by looking up its own row's
 * helper_agent_id at fake-evaluation time (the row already exists by then
 * -- delegateBatch() creates every row before dispatching any job,
 * contracts §1) -- the other member's job dispatches and runs for real.
 *
 * Scenario 11 (deterministic combination) and the six-field-shape parts of
 * FR-012 exercise only PRE-EXISTING, unmodified code
 * (`ResultAggregationService::combineForRun()`, `DelegationController`'s
 * existing endpoints) via directly-written rows -- per quickstart.md's own
 * mutation-checklist row 9 framing ("this row exists to confirm the
 * EXISTING guarantee this feature depends on is still intact, not to test
 * new code"), those specific assertions are expected to ALREADY PASS
 * today, mirroring the carve-out tasks.md T012 makes for its own
 * regression check against the pre-existing solo delegate() tests.
 *
 * Written before `DelegationConcurrencyGate`, `RunDelegationBatchMemberJob`,
 * `DelegationService::delegateBatch()`, and `DelegationQuery::membersForBatch()`
 * exist, and before `DelegationController::delegationRows()` includes
 * `batch_id` -- every scenario that depends on any of these is expected to
 * FAIL red.
 */
class ParallelDelegationJourneyTest extends TestCase
{
    private User $user;

    private User $otherUser;

    private Server $server;

    private RunTraceRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'sync']);

        $this->user = User::factory()->create();
        $this->otherUser = User::factory()->create();
        $this->recorder = $this->app->make(RunTraceRecorder::class);
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

    // -----------------------------------------------------------------
    // Operation-catalog scaffolding
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

    private function makeAgent(User $owner, string $name): Agent
    {
        return app(AgentService::class)->create($owner->id, "name: {$name}\ninstructions: I am {$name}.");
    }

    private function makeConversation(User $owner, ?Agent $agent): Conversation
    {
        return Conversation::factory()->create([
            'user_id' => $owner->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'title' => 'Already titled',
            'agent_id' => $agent?->id,
            'agent_version_id' => $agent?->current_version_id,
        ]);
    }

    private function delegateCall(string $toolCallId, string $helperAgentId, string $task): array
    {
        return [
            'tool_call_id' => $toolCallId,
            'helper_agent_id' => $helperAgentId,
            'task' => $task,
            'context' => null,
        ];
    }

    /** A parent agent with N assigned helper agents, and a conversation bound to the parent. */
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

    private function makeRun(User $owner): string
    {
        $runId = $this->recorder->openRun(RunKind::Interactive, $owner->id);
        $this->recorder->closeRun($runId, RunEndState::Completed);

        return $runId;
    }

    private function makeDelegationRow(User $owner, ?string $parentRunId, array $overrides = []): Delegation
    {
        $parentAgent = $this->makeAgent($owner, 'row-parent-'.Str::random(8));
        $helperAgent = $this->makeAgent($owner, 'row-helper-'.Str::random(8));
        $parentConversation = $this->makeConversation($owner, $parentAgent);
        $helperConversation = $this->makeConversation($owner, $helperAgent);

        return Delegation::create(array_merge([
            'parent_conversation_id' => $parentConversation->id,
            'parent_agent_id' => $parentAgent->id,
            'helper_agent_id' => $helperAgent->id,
            'helper_conversation_id' => $helperConversation->id,
            'helper_agent_version_id' => $helperAgent->current_version_id,
            'owner_user_id' => $owner->id,
            'task' => 'A directly-written fixture row.',
            'depth' => 1,
            'status' => 'completed',
            'parent_run_id' => $parentRunId,
            'started_at' => now(),
            'completed_at' => now(),
        ], $overrides));
    }

    // =================================================================
    // Scenario 1 (US1 AC1/AC2, FR-001/FR-002, SC-001): three independent
    // delegations dispatched together, sharing one batch_id
    // =================================================================

    #[Test]
    public function scenario_1_all_members_of_a_batch_are_dispatched_together_not_serialized_one_completion_at_a_time(): void
    {
        Bus::fake([RunDelegationBatchMemberJob::class]);

        $fixture = $this->makeParentWithHelpers($this->user, 3, 'scenario1-dispatch');
        [$helperA, $helperB, $helperC] = $fixture['helpers'];

        Context::add('run_id', (string) Str::uuid());

        app(DelegationService::class)->delegateBatch($fixture['conversation'], [
            $this->delegateCall('call_a', $helperA->id, 'Task A.'),
            $this->delegateCall('call_b', $helperB->id, 'Task B.'),
            $this->delegateCall('call_c', $helperC->id, 'Task C.'),
        ]);

        // A batch that fell back to the old one-at-a-time inline path
        // (mutation-checklist row 1) would never dispatch this job at all
        // -- this is what actually distinguishes "genuinely batched" from
        // "silently sequential" within a single-process test.
        Bus::assertDispatchedTimes(RunDelegationBatchMemberJob::class, 3, 'every member of the batch must be dispatched as one real, individually queued job each -- not run one at a time inline');
    }

    #[Test]
    public function scenario_1_three_independent_delegations_share_one_batch_id_and_each_completes_with_its_own_correct_result(): void
    {
        $fixture = $this->makeParentWithHelpers($this->user, 3, 'scenario1');
        [$helperA, $helperB, $helperC] = $fixture['helpers'];

        $mockAgentLoopService = Mockery::mock(AgentLoopService::class);
        $mockAgentLoopService->shouldReceive('run')->andReturnUsing(function ($conversation, string $message) {
            return match (true) {
                str_contains($message, 'Task A.') => $this->successResult('Result A.'),
                str_contains($message, 'Task B.') => $this->successResult('Result B.'),
                str_contains($message, 'Task C.') => $this->successResult('Result C.'),
                default => throw new \RuntimeException('Unexpected seed message: '.$message),
            };
        });
        $this->app->instance(AgentLoopService::class, $mockAgentLoopService);

        Context::add('run_id', (string) Str::uuid());

        $results = app(DelegationService::class)->delegateBatch($fixture['conversation'], [
            $this->delegateCall('call_a', $helperA->id, 'Task A.'),
            $this->delegateCall('call_b', $helperB->id, 'Task B.'),
            $this->delegateCall('call_c', $helperC->id, 'Task C.'),
        ]);

        $rows = Delegation::where('parent_conversation_id', $fixture['conversation']->id)->get();
        $this->assertCount(3, $rows);

        $batchIds = $rows->pluck('batch_id')->unique();
        $this->assertCount(1, $batchIds, 'all three independent delegations must share one batch_id');
        $this->assertNotNull($batchIds->first());

        foreach ($rows as $row) {
            $this->assertSame('completed', $row->status, 'every genuinely independent member must reach its own correct terminal status');
        }

        $this->assertSame('Result A.', $results['call_a']['summary'] ?? null);
        $this->assertSame('Result B.', $results['call_b']['summary'] ?? null);
        $this->assertSame('Result C.', $results['call_c']['summary'] ?? null);
    }

    // =================================================================
    // Scenario 2 (US1 AC3): a solo call is completely unaffected --
    // proven against PRE-EXISTING, unmodified delegate(); expected to
    // ALREADY PASS today.
    // =================================================================

    #[Test]
    public function scenario_2_a_solo_delegate_to_helper_call_never_touches_batch_id_or_the_concurrency_gate(): void
    {
        $fixture = $this->makeParentWithHelpers($this->user, 1, 'scenario2');
        [$helper] = $fixture['helpers'];

        $gate = Mockery::mock(DelegationConcurrencyGate::class);
        $gate->shouldNotReceive('tryAdmit');
        $this->app->instance(DelegationConcurrencyGate::class, $gate);

        $mockAgentLoopService = Mockery::mock(AgentLoopService::class);
        $mockAgentLoopService->shouldReceive('run')->once()->andReturn($this->successResult('Solo result.'));
        $this->app->instance(AgentLoopService::class, $mockAgentLoopService);

        $result = app(DelegationService::class)->delegate($fixture['conversation'], $helper->id, 'Solo task.', null);

        $this->assertSame('success', $result['status'] ?? null);

        $row = Delegation::where('parent_conversation_id', $fixture['conversation']->id)->first();
        $this->assertNotNull($row);
        $this->assertNull($row->batch_id, 'a solo delegation must never carry a batch_id');
        $this->assertSame('completed', $row->status, 'a solo delegation transitions straight to completed -- queued must never be observed on this path');
    }

    // =================================================================
    // Scenario 4 (US3 AC2, FR-004/FR-005, SC-003): one of three members
    // throws -- the other two are individually attributed, the failed one
    // is a distinct terminal failed row, never omitted.
    // =================================================================

    #[Test]
    public function scenario_4_one_of_three_members_throwing_never_discards_the_others_own_completed_work(): void
    {
        $fixture = $this->makeParentWithHelpers($this->user, 3, 'scenario4');
        [$helperA, $helperB, $helperC] = $fixture['helpers'];

        $mockAgentLoopService = Mockery::mock(AgentLoopService::class);
        $mockAgentLoopService->shouldReceive('run')->andReturnUsing(function ($conversation, string $message) {
            return match (true) {
                str_contains($message, 'Task A.') => $this->successResult('Result A.'),
                str_contains($message, 'Task B.') => throw new \RuntimeException('Member B blew up.'),
                str_contains($message, 'Task C.') => $this->successResult('Result C.'),
                default => throw new \RuntimeException('Unexpected seed message: '.$message),
            };
        });
        $this->app->instance(AgentLoopService::class, $mockAgentLoopService);

        $runId = (string) Str::uuid();
        Context::add('run_id', $runId);

        app(DelegationService::class)->delegateBatch($fixture['conversation'], [
            $this->delegateCall('call_a', $helperA->id, 'Task A.'),
            $this->delegateCall('call_b', $helperB->id, 'Task B.'),
            $this->delegateCall('call_c', $helperC->id, 'Task C.'),
        ]);

        $rows = Delegation::where('parent_conversation_id', $fixture['conversation']->id)->get()->keyBy('helper_agent_id');
        $this->assertSame('completed', $rows[$helperA->id]->status);
        $this->assertSame('failed', $rows[$helperB->id]->status, 'member B\'s own thrown exception must be mapped to a distinct terminal failed row, never silently dropped');
        $this->assertSame('completed', $rows[$helperC->id]->status);

        $combined = app(ResultAggregationService::class)->combineForRun($runId);
        $this->assertNotNull($combined);

        $contributorIds = collect($combined['contributors'])->pluck('delegation_id')->all();
        $this->assertContains($rows[$helperA->id]->id, $contributorIds, 'A\'s own successful output must be individually attributed in the combined view');
        $this->assertContains($rows[$helperC->id]->id, $contributorIds, 'C\'s own successful output must be individually attributed in the combined view');
    }

    // =================================================================
    // Scenario 5 (US3 AC3): both of two members throw -- both terminal
    // failed, honestly reported, never a fabricated success.
    // =================================================================

    #[Test]
    public function scenario_5_both_members_throwing_are_both_reported_as_terminal_failures_never_a_fabricated_success(): void
    {
        $fixture = $this->makeParentWithHelpers($this->user, 2, 'scenario5');
        [$helperA, $helperB] = $fixture['helpers'];

        $mockAgentLoopService = Mockery::mock(AgentLoopService::class);
        $mockAgentLoopService->shouldReceive('run')->andThrow(new \RuntimeException('Both members fail.'));
        $this->app->instance(AgentLoopService::class, $mockAgentLoopService);

        Context::add('run_id', (string) Str::uuid());

        app(DelegationService::class)->delegateBatch($fixture['conversation'], [
            $this->delegateCall('call_a', $helperA->id, 'Task A.'),
            $this->delegateCall('call_b', $helperB->id, 'Task B.'),
        ]);

        $rows = Delegation::where('parent_conversation_id', $fixture['conversation']->id)->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame('failed', $row->status);
            $this->assertSame('failure', $row->result_status);
        }
    }

    // =================================================================
    // Scenario 9 (FR-010, SC-007): a member's job never runs (simulating
    // a worker that never picks it up) -- delegateBatch() still returns
    // within a bounded time, the hung member force-finalized
    // exhausted/batch_join_timeout, the other member's real result intact.
    // =================================================================

    #[Test]
    public function scenario_9_a_member_whose_job_never_runs_is_force_finalized_within_a_bounded_time_without_discarding_the_other_members_real_result(): void
    {
        config(['llm-client.delegation.max_seconds' => 1]);
        config(['llm-client.delegation.concurrency.join_poll_interval_ms' => 20]);

        $fixture = $this->makeParentWithHelpers($this->user, 2, 'scenario9');
        [$helperA, $helperB] = $fixture['helpers'];

        $mockAgentLoopService = Mockery::mock(AgentLoopService::class);
        $mockAgentLoopService->shouldReceive('run')->andReturnUsing(function ($conversation, string $message) {
            return str_contains($message, 'Task A.')
                ? $this->successResult('Result A.')
                : throw new \RuntimeException('Member B\'s job must never actually run in this scenario -- if this fires, the Bus::fake() matcher below is not selecting the right job.');
        });
        $this->app->instance(AgentLoopService::class, $mockAgentLoopService);

        // Selectively fake ONLY member B's own dispatched job -- looked up
        // by its row's helper_agent_id, which already exists by
        // fake-evaluation time (delegateBatch() creates every row before
        // dispatching any job, contracts §1). Member A's job is left
        // completely real.
        Bus::fake([function ($job) use ($helperB) {
            if (!$job instanceof RunDelegationBatchMemberJob) {
                return false;
            }
            $row = Delegation::find($job->delegationId);

            return $row !== null && $row->helper_agent_id === $helperB->id;
        }]);

        Context::add('run_id', (string) Str::uuid());

        $start = microtime(true);
        $results = app(DelegationService::class)->delegateBatch($fixture['conversation'], [
            $this->delegateCall('call_a', $helperA->id, 'Task A.'),
            $this->delegateCall('call_b', $helperB->id, 'Task B.'),
        ]);
        $elapsedSeconds = microtime(true) - $start;

        $this->assertLessThan(
            10.0,
            $elapsedSeconds,
            'the parent must never be held open indefinitely by a member whose job never runs -- it must return once its own bounded join-wait deadline passes',
        );

        $rows = Delegation::where('parent_conversation_id', $fixture['conversation']->id)->get()->keyBy('helper_agent_id');
        $this->assertSame('completed', $rows[$helperA->id]->status, 'the healthy member\'s own real result must remain intact');
        $this->assertSame('exhausted', $rows[$helperB->id]->status, 'the never-run member must be force-finalized exhausted, never left queued/in_progress forever');
        $this->assertSame('batch_join_timeout', $rows[$helperB->id]->result_reason);

        $this->assertSame('Result A.', $results['call_a']['summary'] ?? null);
        $this->assertSame('failure', $results['call_b']['status'] ?? null);
        $this->assertSame('batch_join_timeout', $results['call_b']['reason'] ?? null);
    }

    // =================================================================
    // Scenario 9b (FR-010, research.md D4 layer 2 -- Phase 7 Polish
    // reconciliation): the ZERO-PROGRESS case, distinct from scenario 9
    // above. Scenario 9 has one healthy member alongside one hung member,
    // so joinWait() observes real progress (member A going terminal) and
    // already force-finalizes the straggler correctly. This scenario
    // covers the case where NO member of the batch ever shows any sign of
    // life at all -- every job is simply never run, simulating a worker
    // pool that is entirely down. delegateBatch() must still return within
    // a bounded time AND every member must be force-finalized
    // exhausted/batch_join_timeout, exactly as research.md D4 layer 2
    // states ("If any member is still non-terminal when this deadline
    // passes, the parent force-finalizes it directly... and then proceeds
    // exactly as if that member had reported failure on its own") -- with
    // no carve-out for the case where nothing in the batch ever progressed.
    // =================================================================

    #[Test]
    public function scenario_9b_a_whole_batch_whose_jobs_never_run_at_all_is_still_force_finalized_within_a_bounded_time(): void
    {
        config(['llm-client.delegation.max_seconds' => 1]);
        config(['llm-client.delegation.concurrency.join_poll_interval_ms' => 20]);

        $fixture = $this->makeParentWithHelpers($this->user, 2, 'scenario9b');
        [$helperA, $helperB] = $fixture['helpers'];

        $mockAgentLoopService = Mockery::mock(AgentLoopService::class);
        $mockAgentLoopService->shouldReceive('run')->never();
        $this->app->instance(AgentLoopService::class, $mockAgentLoopService);

        // Fake EVERY dispatched job in this batch -- simulating a worker
        // pool that never picks anything up at all, not just one hung
        // member alongside a healthy sibling (scenario 9 above).
        Bus::fake([RunDelegationBatchMemberJob::class]);

        Context::add('run_id', (string) Str::uuid());

        $start = microtime(true);
        $results = app(DelegationService::class)->delegateBatch($fixture['conversation'], [
            $this->delegateCall('call_a', $helperA->id, 'Task A.'),
            $this->delegateCall('call_b', $helperB->id, 'Task B.'),
        ]);
        $elapsedSeconds = microtime(true) - $start;

        $this->assertLessThan(
            10.0,
            $elapsedSeconds,
            'the parent must never be held open indefinitely even when NO member of the batch ever shows any progress at all',
        );

        $rows = Delegation::where('parent_conversation_id', $fixture['conversation']->id)->get()->keyBy('helper_agent_id');
        $this->assertSame('exhausted', $rows[$helperA->id]->status, 'a member that never even reached in_progress must still be force-finalized once the join-wait deadline passes, not left queued forever');
        $this->assertSame('exhausted', $rows[$helperB->id]->status);
        $this->assertSame('batch_join_timeout', $rows[$helperA->id]->result_reason);
        $this->assertSame('batch_join_timeout', $rows[$helperB->id]->result_reason);

        // The parent's own turn must receive an HONEST failure account for
        // each member -- never a null-filled six-field result reconstructed
        // from a row that was silently left non-terminal.
        foreach (['call_a', 'call_b'] as $toolCallId) {
            $this->assertSame('failure', $results[$toolCallId]['status'] ?? null, "{$toolCallId}'s own result must be an honest failure, never a null status from a row left non-terminal");
            $this->assertSame('batch_join_timeout', $results[$toolCallId]['reason'] ?? null);
        }
    }

    // =================================================================
    // Scenario 11 (FR-011, SC-006): completion order never changes the
    // combined outcome -- PRE-EXISTING, unmodified combineForRun();
    // expected to ALREADY PASS today (mutation-checklist row 9).
    // =================================================================

    #[Test]
    public function scenario_11_combineforrun_is_byte_identical_across_every_completion_order_of_three_batch_members(): void
    {
        $runId = $this->makeRun($this->user);
        $batchId = (string) Str::uuid();

        $memberSpecs = [
            ['helper' => 'alpha', 'summary' => 'Alpha summary.', 'output' => ['alpha_key' => 'alpha_value']],
            ['helper' => 'beta', 'summary' => 'Beta summary.', 'output' => ['beta_key' => 'beta_value']],
            ['helper' => 'gamma', 'summary' => 'Gamma summary.', 'output' => ['gamma_key' => 'gamma_value']],
        ];

        $orders = [
            [0, 1, 2], [0, 2, 1], [1, 0, 2], [1, 2, 0], [2, 0, 1], [2, 1, 0],
        ];

        // Every identifier below is generated ONCE, outside the loop, and
        // reused across every permutation pass -- only the ORDER these
        // rows' own INSERT statements run in varies per pass (rows are
        // deleted and rewritten each pass). Regenerating a fresh
        // delegation_id/helper_agent_id per pass would make the
        // byte-identical comparison meaningless -- every pass would
        // legitimately differ by identifier alone, regardless of whether
        // combineForRun() itself is order-independent.
        $parentAgent = $this->makeAgent($this->user, 'scenario11-parent');
        $parentConversation = $this->makeConversation($this->user, $parentAgent);
        foreach ($memberSpecs as $index => &$spec) {
            $helperAgent = $this->makeAgent($this->user, 'scenario11-'.$spec['helper']);
            $spec['delegation_id'] = (string) Str::uuid();
            $spec['helper_agent_id'] = $helperAgent->id;
            $spec['helper_conversation_id'] = $this->makeConversation($this->user, $helperAgent)->id;
            $spec['started_at'] = now()->addSeconds($index); // fixed regardless of insertion order
        }
        unset($spec);

        $referenceEncoded = null;

        foreach ($orders as $order) {
            DB::table('agent_delegations')->delete();

            foreach ($order as $memberIndex) {
                $spec = $memberSpecs[$memberIndex];
                // Direct DB::table() insert, not Delegation::create(): 'id'
                // is deliberately NOT in the model's $fillable, so a mass-
                // assigned 'id' is silently dropped and the model's own
                // creating() hook would mint a fresh random one every pass
                // -- exactly the identifier instability this test exists
                // to eliminate.
                DB::table('agent_delegations')->insert([
                    'id' => $spec['delegation_id'],
                    'parent_conversation_id' => $parentConversation->id,
                    'parent_agent_id' => $parentAgent->id,
                    'helper_agent_id' => $spec['helper_agent_id'],
                    'helper_conversation_id' => $spec['helper_conversation_id'],
                    'owner_user_id' => $this->user->id,
                    'task' => 'Scenario 11 fixture row.',
                    'depth' => 1,
                    'status' => 'completed',
                    'batch_id' => $batchId,
                    'parent_run_id' => $runId,
                    'result_status' => 'success',
                    'result_summary' => $spec['summary'],
                    'result_output' => json_encode($spec['output']),
                    'result_undone' => '',
                    'result_truncated' => false,
                    'started_at' => $spec['started_at']->toDateTimeString(),
                    'completed_at' => $spec['started_at']->toDateTimeString(),
                ]);
            }

            $combined = app(ResultAggregationService::class)->combineForRun($runId);
            $this->assertNotNull($combined);

            $encoded = json_encode([
                $combined['contributors'],
                $combined['combined_output'],
                $combined['conflicts'],
            ]);

            if ($referenceEncoded === null) {
                $referenceEncoded = $encoded;
            } else {
                $this->assertSame(
                    $referenceEncoded,
                    $encoded,
                    'combineForRun()\'s own contributors/combined_output/conflicts must be byte-identical regardless of the order these rows were written in',
                );
            }
        }
    }

    // =================================================================
    // Phase 7 Polish (mutation-checklist row 9): scenario 11 above proves
    // order-INDEPENDENCE with respect to INSERTION order, because its own
    // fixture sets each row's completed_at equal to its own started_at --
    // so reordering combineForRun()'s query by completed_at instead of
    // started_at (row 9's own literal mutation) coincidentally produces
    // the identical result and stays invisible to that test alone. This
    // test decouples the two columns -- started_at ascending order is
    // deliberately the REVERSE of completed_at ascending order across the
    // three rows -- so it can actually distinguish "ordered by started_at"
    // (099's own real, documented behavior) from "ordered by completed_at"
    // (row 9's mutation).
    // =================================================================

    #[Test]
    public function combineforrun_orders_contributors_by_started_at_not_completed_at(): void
    {
        $runId = $this->makeRun($this->user);

        $parentAgent = $this->makeAgent($this->user, 'orderfix-parent');
        $parentConversation = $this->makeConversation($this->user, $parentAgent);

        $specs = [
            // label => [started_at offset, completed_at offset]
            'first-started-last-completed' => [0, 20],
            'second-started-second-completed' => [10, 10],
            'last-started-first-completed' => [20, 0],
        ];

        $delegationIdsByLabel = [];
        $base = now();

        foreach ($specs as $label => [$startedOffset, $completedOffset]) {
            $helperAgent = $this->makeAgent($this->user, 'orderfix-'.$label);
            $helperConversation = $this->makeConversation($this->user, $helperAgent);
            $delegationId = (string) Str::uuid();
            $delegationIdsByLabel[$label] = $delegationId;

            DB::table('agent_delegations')->insert([
                'id' => $delegationId,
                'parent_conversation_id' => $parentConversation->id,
                'parent_agent_id' => $parentAgent->id,
                'helper_agent_id' => $helperAgent->id,
                'helper_conversation_id' => $helperConversation->id,
                'owner_user_id' => $this->user->id,
                'task' => 'Row-9 order-decoupling fixture.',
                'depth' => 1,
                'status' => 'completed',
                'parent_run_id' => $runId,
                'result_status' => 'success',
                'result_summary' => $label,
                'result_output' => json_encode([]),
                'result_undone' => '',
                'result_truncated' => false,
                'started_at' => $base->copy()->addSeconds($startedOffset)->toDateTimeString(),
                'completed_at' => $base->copy()->addSeconds($completedOffset)->toDateTimeString(),
            ]);
        }

        $combined = app(ResultAggregationService::class)->combineForRun($runId);
        $this->assertNotNull($combined);

        $observedOrder = collect($combined['contributors'])->pluck('delegation_id')->all();

        $this->assertSame(
            [
                $delegationIdsByLabel['first-started-last-completed'],
                $delegationIdsByLabel['second-started-second-completed'],
                $delegationIdsByLabel['last-started-first-completed'],
            ],
            $observedOrder,
            'combineForRun() must order contributors by started_at -- if it were ordered by completed_at instead (row 9\'s mutation), this exact fixture would observe the REVERSE order',
        );
    }

    // =================================================================
    // FR-012 (batch recoverability, contracts §2/§3)
    // =================================================================

    #[Test]
    public function fr012_membersforbatch_returns_every_member_with_its_own_outcome_and_is_owner_scoped(): void
    {
        $runId = $this->makeRun($this->user);
        $batchId = (string) Str::uuid();

        $memberA = $this->makeDelegationRow($this->user, $runId, ['batch_id' => $batchId]);
        $memberB = $this->makeDelegationRow($this->user, $runId, ['batch_id' => $batchId]);
        // An unrelated solo delegation on the same run -- must never appear.
        $this->makeDelegationRow($this->user, $runId, ['batch_id' => null]);

        $members = app(DelegationQuery::class)->membersForBatch($this->user->id, $batchId);

        $this->assertIsArray($members);
        $this->assertCount(2, $members, 'membersForBatch() must return exactly the batch\'s own members -- no more, no less');
        $memberIds = array_map(fn (Delegation $d) => $d->id, $members);
        $this->assertEqualsCanonicalizing([$memberA->id, $memberB->id], $memberIds);

        $this->assertNull(
            app(DelegationQuery::class)->membersForBatch($this->otherUser->id, $batchId),
            'a batch id must resolve to null for a caller who does not own it -- never leak another user\'s batch membership',
        );
    }

    #[Test]
    public function fr012_the_two_existing_delegation_read_endpoints_include_the_additive_batch_id_field(): void
    {
        $runId = $this->makeRun($this->user);
        $batchId = (string) Str::uuid();

        $batchMemberOne = $this->makeDelegationRow($this->user, $runId, ['batch_id' => $batchId]);
        $batchMemberTwo = $this->makeDelegationRow($this->user, $runId, ['batch_id' => $batchId]);
        $soloDelegation = $this->makeDelegationRow($this->user, $runId, ['batch_id' => null]);

        $listResponse = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/agent-runs/{$runId}/delegations");
        $listResponse->assertStatus(200);

        $byId = collect($listResponse->json())->keyBy('id');
        $this->assertArrayHasKey('batch_id', $byId[$batchMemberOne->id] ?? [], 'GET .../delegations rows must include the additive batch_id field');
        $this->assertSame($batchId, $byId[$batchMemberOne->id]['batch_id'] ?? null);
        $this->assertSame($batchId, $byId[$batchMemberTwo->id]['batch_id'] ?? null);
        $this->assertArrayHasKey('batch_id', $byId[$soloDelegation->id] ?? [], 'GET .../delegations rows must include the additive batch_id field');
        $this->assertNull($byId[$soloDelegation->id]['batch_id'], 'a solo delegation\'s batch_id must be null, never omitted or a placeholder');

        $showResponse = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/delegations/{$batchMemberOne->id}");
        $showResponse->assertStatus(200);
        $this->assertArrayHasKey('batch_id', $showResponse->json());
        $this->assertSame($batchId, $showResponse->json('batch_id'));
    }
}
