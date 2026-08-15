<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Jobs\RunManagedTaskStepJob;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 103-manager-agent, Phase 6 (US4), tasks.md T048.
 *
 * Full acceptance journey for quickstart.md scenarios 7 and 8 (research.md
 * D7 -- restart survival reuses 101's sweep-and-re-drive shape). Drives the
 * real ManagerService -> DelegationService -> AgentLoopService::run() chain
 * (never mocked) via the scripted-LlmProvider convention
 * ManagedTaskCorrectionJourneyTest.php established, plus
 * `llm-client:resolve-stalled-managed-tasks` (T051) and
 * `RunManagedTaskStepJob::handle()` (T050) called directly to simulate the
 * dispatched job actually running after a crash.
 *
 * Written before finalizeWithShortfall()/the job's pre-check/the sweep
 * command exist -- every scenario below is expected to FAIL red until
 * T049-T051 land.
 */
class ManagedTaskRestartSurvivalJourneyTest extends TestCase
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

        config(['llm-client.manager.stale_after_minutes' => 10]);

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

    private function toolCall(string $name, array $arguments, string $id): array
    {
        return [
            'id' => $id,
            'type' => 'function',
            'function' => ['name' => $name, 'arguments' => json_encode($arguments)],
        ];
    }

    private function toolCallReply(array $calls): array
    {
        return ['choices' => [['message' => ['content' => '', 'tool_calls' => $calls]]]];
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
     * Builds a manager agent, two helpers, a channel='managed-task'
     * ManagedTask with two ManagedTaskPart rows, and part A already
     * assigned + accepted -- i.e. the state of a task the moment before
     * its worker "crashes" mid-task (one round genuinely spent, one part
     * done, one part still untouched).
     *
     * @return array{ManagedTask, ManagedTaskPart, ManagedTaskPart, Agent, Agent, Conversation}
     */
    private function makeCrashedTaskWithOneAcceptedPart(): array
    {
        $manager = $this->makeAgent('manager-'.uniqid());
        $helper1 = $this->makeAgent('helper1-'.uniqid());
        $helper2 = $this->makeAgent('helper2-'.uniqid());
        app(AgentHelperService::class)->assign($this->user->id, $manager->id, $helper1->id);
        app(AgentHelperService::class)->assign($this->user->id, $manager->id, $helper2->id);

        $task = app(ManagerService::class)->createManagedTask($this->user->id, $manager->id, 'A two-part task.');
        [$partA, $partB] = app(ManagerService::class)->planParts($task, ['Part A.', 'Part B.']);

        $this->app->instance(AgentLoopService::class, $this->serviceWithScriptedProvider([
            $this->successResultReply('Part A fully completed.'),
        ]));
        app(ManagerService::class)->assignPart($task, $partA, $helper1->id, 'Do part A.', null);
        app(ManagerService::class)->acceptPart($task, $partA);

        $partA->refresh();
        $task->refresh();
        $this->assertSame('accepted', $partA->state, 'fixture sanity');
        $this->assertSame(1, $task->rounds_used, 'fixture sanity: exactly one round genuinely spent before the crash');

        $conversation = Conversation::find($task->conversation_id);

        return [$task, $partA, $partB, $helper1, $helper2, $conversation];
    }

    // =================================================================
    // Scenario 7: worker crash mid-task, resumed by the sweep, no
    // duplicate work for the part that already survived.
    // =================================================================

    #[Test]
    public function a_crashed_task_within_its_wall_clock_bound_is_resumed_by_the_sweep_and_completes_using_the_surviving_accepted_part(): void
    {
        [$task, $partA, $partB, $helper1, $helper2, $conversation] = $this->makeCrashedTaskWithOneAcceptedPart();

        $acceptedDelegationId = $partA->accepted_delegation_id;
        $acceptedSummary = $partA->accepted_summary;

        // Simulate the crash: the worker that would have re-dispatched the
        // next RunManagedTaskStepJob died before doing so. Age
        // last_progress_at past the stale threshold, but keep started_at
        // well within max_seconds.
        $task->last_progress_at = now()->subMinutes(15);
        $task->started_at = now()->subMinutes(5);
        $task->save();

        Queue::fake();

        $exitCode = Artisan::call('llm-client:resolve-stalled-managed-tasks');
        $this->assertSame(0, $exitCode);

        Queue::assertPushed(RunManagedTaskStepJob::class, function (RunManagedTaskStepJob $job) use ($task) {
            return $job->managedTaskId === $task->id;
        });

        // The already-accepted part must be completely untouched by the
        // sweep itself.
        $partA->refresh();
        $this->assertSame('accepted', $partA->state);
        $this->assertSame($acceptedDelegationId, $partA->accepted_delegation_id);
        $this->assertSame($acceptedSummary, $partA->accepted_summary);

        $task->refresh();
        $this->assertSame('in_progress', $task->status, 'a resumed (not force-finalized) task must remain in_progress at this point');

        // Now the dispatched job actually runs -- it must proceed using
        // the EXISTING accepted part, only genuinely running further
        // rounds for the still-outstanding part.
        $service = $this->serviceWithScriptedProvider([
            $this->toolCallReply([
                $this->toolCall('assign_part', [
                    'part_id' => $partB->id,
                    'helper_agent_id' => $helper2->id,
                    'task' => 'Do part B.',
                ], 'call_assign_b'),
            ]),
            $this->successResultReply('Part B fully completed.'),
            $this->toolCallReply([
                $this->toolCall('accept_part', ['part_id' => $partB->id], 'call_accept_b'),
            ]),
            $this->toolCallReply([
                $this->toolCall('finalize_task', [
                    'final_response' => 'Both parts are done.',
                ], 'call_finalize'),
            ]),
            $this->plainReply('Task finalized.'),
        ]);
        $this->app->instance(AgentLoopService::class, $service);

        (new RunManagedTaskStepJob($task->id))->handle($service);

        $task->refresh();
        $partB->refresh();

        $this->assertSame('completed', $task->status);
        $this->assertSame('accepted', $partB->state);
        $this->assertSame(2, $task->rounds_used, 'the part that survived the crash must never be double-counted -- only part B\'s own genuinely-run round is added to the one part A already spent');
    }

    // =================================================================
    // Scenario 8: worker crash AND the task's wall-clock bound has
    // already been exceeded by sweep time -- force-finalize, never
    // resumed forever.
    // =================================================================

    #[Test]
    public function a_crashed_task_past_its_wall_clock_bound_is_force_finalized_by_the_sweep_not_resumed(): void
    {
        [$task, $partA, $partB, $helper1, $helper2, $conversation] = $this->makeCrashedTaskWithOneAcceptedPart();

        $acceptedDelegationId = $partA->accepted_delegation_id;
        $acceptedSummary = $partA->accepted_summary;

        // Same crash setup, but max_seconds has ALREADY been exceeded by
        // the time the sweep runs.
        $task->last_progress_at = now()->subMinutes(15);
        $task->started_at = now()->subMinutes(40);
        $task->max_seconds = 1800; // 30 minutes -- 40 elapsed minutes has exceeded this
        $task->save();

        Queue::fake();

        $exitCode = Artisan::call('llm-client:resolve-stalled-managed-tasks');
        $this->assertSame(0, $exitCode);

        Queue::assertNotPushed(RunManagedTaskStepJob::class, null, 'a task past its own wall-clock bound must never be resumed');

        $task->refresh();
        $this->assertSame('completed_with_shortfalls', $task->status);
        $this->assertNotNull($task->final_response);
        $this->assertNotNull($task->completed_at);
        $this->assertSame(1, $task->rounds_used, 'the forced finalize must never itself consume a round');

        // The already-accepted part is untouched...
        $partA->refresh();
        $this->assertSame('accepted', $partA->state);
        $this->assertSame($acceptedDelegationId, $partA->accepted_delegation_id);
        $this->assertSame($acceptedSummary, $partA->accepted_summary);

        // ...while the never-assigned part is closed as a shortfall with
        // a system-composed reason.
        $partB->refresh();
        $this->assertSame('reported_as_shortfall', $partB->state);
        $this->assertNotNull($partB->shortfall_reason);
    }
}
