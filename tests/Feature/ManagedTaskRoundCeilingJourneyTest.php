<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Jobs\RunManagedTaskStepJob;
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
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 103-manager-agent, Phase 6 (US4), tasks.md T046.
 *
 * Full acceptance journey for quickstart.md scenarios 4 and 5 (US4,
 * FR-009/FR-010/FR-011/FR-017, SC-006/SC-007). Drives ManagerService's
 * already-complete transactional guard (Phase 3, "Ordering rationale")
 * directly -- the same convention ManagerServiceAssignPartTest.php
 * established -- to reach the round ceiling deterministically, then
 * exercises the genuinely NEW mechanism this phase adds:
 * RunManagedTaskStepJob::handle()'s pre-step ceiling check (T050) calling
 * ManagerService::finalizeWithShortfall() (T049) directly, with
 * AgentLoopService::run() never invoked once the ceiling is reached.
 *
 * Written before finalizeWithShortfall()/the job's pre-check exist --
 * every scenario below is expected to FAIL red until T049/T050 land.
 */
class ManagedTaskRoundCeilingJourneyTest extends TestCase
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

    private function partialResultReply(string $summary): array
    {
        return $this->plainReply(json_encode([
            'status' => 'partial',
            'summary' => $summary,
            'output' => [],
            'undone' => 'Some aspects remain incomplete.',
        ], JSON_FORCE_OBJECT));
    }

    private function successResultReply(string $summary): array
    {
        return $this->plainReply(json_encode([
            'status' => 'success',
            'summary' => $summary,
            'output' => [],
            'undone' => '',
        ], JSON_FORCE_OBJECT));
    }

    /**
     * @return array{ManagedTask, ManagedTaskPart, ManagedTaskPart, Agent, Agent}
     */
    private function makeManagedTaskWithTwoParts(int $roundCeiling): array
    {
        config(['llm-client.manager.max_rounds' => $roundCeiling]);

        $manager = $this->makeAgent('manager-'.uniqid());
        $helper1 = $this->makeAgent('helper1-'.uniqid());
        $helper2 = $this->makeAgent('helper2-'.uniqid());
        app(AgentHelperService::class)->assign($this->user->id, $manager->id, $helper1->id);
        app(AgentHelperService::class)->assign($this->user->id, $manager->id, $helper2->id);

        $task = app(ManagerService::class)->createManagedTask($this->user->id, $manager->id, 'A two-part task.');
        $this->assertSame($roundCeiling, $task->round_ceiling, 'fixture sanity: round_ceiling must be snapshotted from config at creation time');

        [$partA, $partB] = app(ManagerService::class)->planParts($task, ['Part A.', 'Part B.']);

        return [$task, $partA, $partB, $helper1, $helper2];
    }

    private function assignPart(ManagedTask $task, ManagedTaskPart $part, Agent $helper, string $taskText, ?string $context, array $scriptedResponses): array
    {
        $this->app->instance(AgentLoopService::class, $this->serviceWithScriptedProvider($scriptedResponses));

        return app(ManagerService::class)->assignPart($task, $part, $helper->id, $taskText, $context);
    }

    // =================================================================
    // Scenario 4: the round ceiling is real, whole-task, and always
    // still delivers a response.
    // =================================================================

    #[Test]
    public function the_round_ceiling_is_whole_task_refuses_a_fourth_round_and_finalizes_with_shortfall_automatically(): void
    {
        [$task, $partA, $partB, $helper1, $helper2] = $this->makeManagedTaskWithTwoParts(roundCeiling: 3);

        // Round 1: initial assignment of part A -- partial.
        $result1 = $this->assignPart($task, $partA, $helper1, 'Do part A.', null, [
            $this->partialResultReply('First attempt at A, still incomplete.'),
        ]);
        $this->assertSame('partial', $result1['status'] ?? null);

        // Round 2: a REASSIGNMENT of part A to a DIFFERENT helper -- still
        // partial. Proves reassignment counts against the same rounds_used
        // counter as an ordinary same-helper correction would.
        $result2 = $this->assignPart($task, $partA, $helper2, 'Try part A instead.', 'First helper left it incomplete.', [
            $this->partialResultReply('Second attempt at A (different helper), still incomplete.'),
        ]);
        $this->assertSame('partial', $result2['status'] ?? null);

        // Round 3: initial assignment of part B -- partial. rounds_used
        // now equals round_ceiling (3).
        $result3 = $this->assignPart($task, $partB, $helper1, 'Do part B.', null, [
            $this->partialResultReply('First attempt at B, still incomplete.'),
        ]);
        $this->assertSame('partial', $result3['status'] ?? null);

        $task->refresh();
        $this->assertSame(3, $task->rounds_used, 'fixture sanity: exactly three rounds have been genuinely spent');
        $this->assertSame(3, Delegation::where('managed_task_id', $task->id)->count(), 'fixture sanity: exactly three Delegation rows exist so far');

        // Round 4 attempt: refused BEFORE any further Delegation row is
        // created -- no scripted provider response is even needed, since
        // the guard must refuse before DelegationService::delegate() is
        // ever called.
        $this->app->instance(AgentLoopService::class, $this->serviceWithScriptedProvider([]));
        $result4 = app(ManagerService::class)->assignPart($task, $partA, $helper1, 'One more try at A.', 'Please finish it this time.');

        $this->assertSame('round_ceiling_reached', $result4['error'] ?? null);

        $task->refresh();
        $this->assertSame(3, $task->rounds_used, 'a refused 4th round must never increment rounds_used past the ceiling');
        $this->assertSame(3, Delegation::where('managed_task_id', $task->id)->count(), 'a refused 4th round must never create a further Delegation row');
        $this->assertSame('in_progress', $task->status, 'the ceiling being reached does not itself finalize the task -- that is the step job\'s own job (T050)');

        // The step job's pre-step ceiling check now runs -- BEFORE calling
        // AgentLoopService::run() at all -- and finalizes with shortfall
        // directly.
        $agentLoopService = Mockery::mock(AgentLoopService::class);
        $agentLoopService->shouldReceive('run')->never();

        Queue::fake();

        (new RunManagedTaskStepJob($task->id))->handle($agentLoopService);

        Queue::assertNotPushed(RunManagedTaskStepJob::class, null, 'a task force-finalized by the pre-step ceiling check must never be re-dispatched');

        $task->refresh();
        $this->assertSame('completed_with_shortfalls', $task->status);
        $this->assertNotNull($task->final_response, 'FR-011: a final response must always be delivered, even when every part fell short');
        $this->assertNotNull($task->shortfall_note);
        $this->assertStringContainsString('Part A.', $task->shortfall_note, 'shortfall_note must name the still-unaccepted part(s) specifically');
        $this->assertStringContainsString('Part B.', $task->shortfall_note, 'shortfall_note must name the still-unaccepted part(s) specifically');
        $this->assertNotNull($task->completed_at);
        $this->assertSame(3, $task->rounds_used, 'the forced finalize must never itself consume a round');

        $partA->refresh();
        $partB->refresh();
        $this->assertSame('reported_as_shortfall', $partA->state);
        $this->assertSame('reported_as_shortfall', $partB->state);
        $this->assertNotNull($partA->shortfall_reason);
        $this->assertNotNull($partB->shortfall_reason);
    }

    // =================================================================
    // Scenario 5: a task that never needs the ceiling never mentions it.
    // =================================================================

    #[Test]
    public function a_task_that_never_needs_the_ceiling_completes_normally_without_any_shortfall(): void
    {
        [$task, $partA, $partB, $helper1, $helper2] = $this->makeManagedTaskWithTwoParts(roundCeiling: 3);

        $resultA = $this->assignPart($task, $partA, $helper1, 'Do part A.', null, [
            $this->successResultReply('Part A fully completed.'),
        ]);
        $this->assertSame('success', $resultA['status'] ?? null);
        app(ManagerService::class)->acceptPart($task, $partA);

        $resultB = $this->assignPart($task, $partB, $helper2, 'Do part B.', null, [
            $this->successResultReply('Part B fully completed.'),
        ]);
        $this->assertSame('success', $resultB['status'] ?? null);
        app(ManagerService::class)->acceptPart($task, $partB);

        $task->refresh();
        $partA->refresh();
        $partB->refresh();
        $this->assertSame('accepted', $partA->state);
        $this->assertSame('accepted', $partB->state);
        $this->assertSame(2, $task->rounds_used);
        $this->assertLessThan($task->round_ceiling, $task->rounds_used, 'fixture sanity: the ceiling was never approached');

        $this->assertNull(app(ManagerService::class)->finalizeRefusal($task, null), 'every part is accepted -- finalize must be admitted');
        app(ManagerService::class)->finalize($task, 'Both parts are done.', null);

        $task->refresh();
        $this->assertSame('completed', $task->status, 'a task that never needs the ceiling must complete normally, never completed_with_shortfalls');
        $this->assertNull($task->shortfall_note, 'a task that never needs the ceiling must never mention a shortfall');
        $this->assertLessThan($task->round_ceiling, $task->rounds_used);
    }
}
