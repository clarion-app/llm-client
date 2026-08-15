<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Events\RunActionUpdated;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\AgentHelperService;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\DelegationService;
use ClarionApp\LlmClient\Services\RoleAssignmentService;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\ModelRole;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Dedoc\Scramble\Generator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 101-parallel-subagent-execution, Phase 4 (US2), tasks.md T024.
 *
 * Confirms -- with a dedicated, falsifiable test, not by assumption -- that
 * FR-003/SC-002 already hold for a concurrent batch exactly as they hold for
 * a sequential delegation (quickstart.md scenario 3): plan.md's Summary and
 * this phase's own Goal both claim NO new production code is needed, because
 * DelegationService::createDelegationRow()/runDelegatedTask() (Phase 3, T018)
 * open and close the same ActionType::Delegation action via the same
 * RunTraceRecorder::openAction()/closeAction() calls the solo delegate()
 * path already used -- and those two methods already broadcast
 * RunActionUpdated (070-run-execution-graph). This test dispatches a
 * three-member batch with staggered nested-run completion times and proves,
 * directly against the real RunActionUpdated event, that:
 *
 *  - three separate "opened" events fire, one per member's own action, all
 *    clustered together (near-simultaneously) BEFORE any member's job has
 *    even run -- delegateBatch() opens every member's action up front, in
 *    its own createDelegationRow() loop, before a single job is dispatched
 *    (contracts §1) -- never one shared event for the whole batch;
 *  - three separate "closed" events follow, staggered across measurably
 *    different times as each member's own nested run() actually finishes --
 *    never all three closing together, i.e. never indistinguishable from a
 *    single atomic batch-wide update;
 *  - there is no gap between delegateBatch()'s own call and the first
 *    update reaching the event bus.
 *
 * The queue connection is left at its ordinary test-default ('sync'), never
 * Bus::fake()'d, per ParallelDelegationJourneyTest's own established
 * precedent (Phase 3) -- RunDelegationBatchMemberJob::handle() must actually
 * run for its own closeAction() call (inside runDelegatedTask(), reached via
 * DelegationService::runBatchMember()) to fire a real, independently-timed
 * RunActionUpdated event.
 *
 * A real Event::listen() (not Event::fake()) is used deliberately: faking
 * RunActionUpdated would swallow it before any listener runs, which would
 * make it impossible to capture the actual wall-clock time each event fired
 * at -- exactly the signal this test needs to tell "near-simultaneous opens"
 * apart from "independently staggered closes".
 */
class ParallelDelegationLiveProgressTest extends TestCase
{
    private User $user;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

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
    // Operation-catalog scaffolding (mirrors ParallelDelegationJourneyTest)
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

    // =================================================================
    // Quickstart scenario 3 (US2, FR-003/SC-002)
    // =================================================================

    #[Test]
    public function three_member_batch_opens_all_actions_near_simultaneously_then_closes_each_independently_as_its_own_member_finishes(): void
    {
        $fixture = $this->makeParentWithHelpers($this->user, 3, 'liveprogress');
        [$helperA, $helperB, $helperC] = $fixture['helpers'];

        // Staggered nested-run completion times: A finishes almost
        // immediately, B noticeably later, C later still. With the sync
        // queue connection, RunDelegationBatchMemberJob::dispatch() runs
        // each member's handle() to completion before the next one starts,
        // so these usleep()s are what actually separate the three CLOSE
        // events in wall-clock time -- proving they are independently timed
        // updates, not one shared batch-wide event.
        $mockAgentLoopService = Mockery::mock(AgentLoopService::class);
        $mockAgentLoopService->shouldReceive('run')->andReturnUsing(function ($conversation, string $message) {
            return match (true) {
                str_contains($message, 'Task A.') => $this->successResult('Result A.'),
                str_contains($message, 'Task B.') => tap($this->successResult('Result B.'), fn () => usleep(100_000)),
                str_contains($message, 'Task C.') => tap($this->successResult('Result C.'), fn () => usleep(200_000)),
                default => throw new \RuntimeException('Unexpected seed message: '.$message),
            };
        });
        $this->app->instance(AgentLoopService::class, $mockAgentLoopService);

        // A genuinely OPEN run + step -- not just a random uuid poked into
        // Context -- is required for createDelegationRow()'s own
        // currentOpenStepId() lookup to resolve a real step id and for
        // openAction() to actually write a row (and fire RunActionUpdated)
        // rather than no-op on a null step id. openRun() itself sets the
        // ambient Context['run_id'], mirroring how a real parent turn
        // arrives at delegateBatch() already inside an open run/step.
        $recorder = app(RunTraceRecorder::class);
        $runId = $recorder->openRun(RunKind::Interactive, $this->user->id);
        $recorder->openStep($runId);

        // A real listener, not Event::fake() -- captures the actual
        // wall-clock time and DB-observed outcome of every RunActionUpdated
        // fired for this batch, which is exactly the "live update" signal
        // spec 070's own PrivateChannel('User.{id}') broadcast pushes to a
        // watching user.
        $log = [];
        Event::listen(RunActionUpdated::class, function (RunActionUpdated $event) use (&$log) {
            $log[] = [
                'action_id' => $event->actionId,
                'time' => microtime(true),
                'outcome' => DB::table('agent_run_actions')->where('id', $event->actionId)->value('outcome'),
            ];
        });

        $dispatchStart = microtime(true);

        app(DelegationService::class)->delegateBatch($fixture['conversation'], [
            $this->delegateCall('call_a', $helperA->id, 'Task A.'),
            $this->delegateCall('call_b', $helperB->id, 'Task B.'),
            $this->delegateCall('call_c', $helperC->id, 'Task C.'),
        ]);

        $rows = Delegation::where('parent_conversation_id', $fixture['conversation']->id)->get()->keyBy('helper_agent_id');

        // --- Structural shape: exactly six events -- one open + one close
        // per member, never one shared event for the whole batch. ---
        $this->assertCount(6, $log, 'expected exactly one open and one close RunActionUpdated event per batch member (3 members x 2 = 6), never a single shared event for the whole batch');
        $expectedActionIds = [
            $rows[$helperA->id]->parent_action_id,
            $rows[$helperB->id]->parent_action_id,
            $rows[$helperC->id]->parent_action_id,
        ];
        $this->assertNotContains(null, $expectedActionIds, 'every batch member must have its own ActionType::Delegation action opened via the shared createDelegationRow() path');
        $this->assertCount(3, array_unique($expectedActionIds), 'each batch member must own a DISTINCT action row -- never a shared action for the whole batch');

        $opens = array_slice($log, 0, 3);
        $closes = array_slice($log, 3, 3);

        // --- The first three events observed are each member's own OPEN
        // (in_progress) -- fired synchronously inside delegateBatch()'s own
        // createDelegationRow() loop, before a single job is dispatched. ---
        foreach ($opens as $entry) {
            $this->assertSame('in_progress', $entry['outcome'], 'the first three RunActionUpdated events must each be an "opened" (in_progress) update, one per member');
        }
        $this->assertEqualsCanonicalizing($expectedActionIds, array_column($opens, 'action_id'), 'the three open events must cover the three batch members\' own distinct actions, one each');

        // --- The next three events observed are each member's own CLOSE
        // (a terminal outcome) -- fired independently, from inside each
        // member's own queued job, as its own nested run() actually
        // finishes. ---
        foreach ($closes as $entry) {
            $this->assertSame('success', $entry['outcome'], 'the last three RunActionUpdated events must each be a "closed" (terminal) update, one per member');
        }
        $this->assertEqualsCanonicalizing($expectedActionIds, array_column($closes, 'action_id'), 'the three close events must cover the same three batch members\' own distinct actions, one each');

        // --- No silent gap between delegateBatch()'s own call and the
        // first live update reaching the event bus (US2's own Independent
        // Test wording). ---
        $this->assertLessThan(
            1.0,
            $opens[0]['time'] - $dispatchStart,
            'there must be no gap between delegateBatch() being called and the first RunActionUpdated update -- the very first member\'s action opens before any job is even dispatched',
        );

        // --- Opens cluster together: all three fire back-to-back inside
        // one synchronous loop, well before any member's nested run() has
        // even started. ---
        $openSpreadSeconds = $opens[2]['time'] - $opens[0]['time'];
        $this->assertLessThan(
            0.5,
            $openSpreadSeconds,
            'all three members\' own actions must open near-simultaneously -- they are all created in one synchronous loop before any job is dispatched, never staggered the way completions are',
        );

        // --- Closes are independently timed, spread measurably further
        // apart than the opens were -- proving the three members' progress
        // is never indistinguishable from one atomic, all-at-once update. ---
        $closeSpreadSeconds = $closes[2]['time'] - $closes[0]['time'];
        $this->assertGreaterThan(
            0.2,
            $closeSpreadSeconds,
            'the three members\' own close events must be spread across measurably different times, each firing independently as its own nested run() actually finishes -- never all three closing together',
        );
        $this->assertGreaterThan(
            $openSpreadSeconds * 5,
            $closeSpreadSeconds,
            'the close events\' own spread must be substantially wider than the opens\' -- opens bunch together up front, closes never do',
        );

        // --- Each successive close event is itself individually
        // attributable to a distinct member finishing, not a batch of
        // closes landing together at the end. ---
        $this->assertGreaterThan(0.05, $closes[1]['time'] - $closes[0]['time'], 'the second close must land measurably after the first, not bunched with it');
        $this->assertGreaterThan(0.05, $closes[2]['time'] - $closes[1]['time'], 'the third close must land measurably after the second, not bunched with it');
    }
}
