<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
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
use ClarionApp\LlmClient\Services\ResultAggregationService;
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
 * 103-manager-agent, Phase 5 (US3), tasks.md T039.
 *
 * Full acceptance journey for quickstart.md scenario 14 (US3 AC1/AC3 --
 * `final_response` reads as one coherent answer, with the breakdown
 * reachable only on request) and scenario 12 (FR-016, Edge Cases --
 * conflicting accepted parts are surfaced, not silently resolved,
 * end-to-end through `finalize_task`).
 *
 * Drives the real ManagerService -> DelegationService -> AgentLoopService::
 * run() chain (never mocked) with a scripted LlmProvider, mirroring
 * ManagedTaskCorrectionJourneyTest.php's own convention: the manager's own
 * tool-call choice is scripted, so what each scenario proves is that the
 * MECHANISM behaves correctly -- `final_response` is stored verbatim
 * without the mechanism itself injecting helper names/ids into it, and
 * `GET /managed-tasks/{id}/parts` is a genuinely separate channel for that
 * attribution; the conflict-detection pipeline actually runs and
 * populates `conflict_note` when `finalize_task` is called.
 *
 * Written before ManagerService::finalize()/ResultAggregationService::
 * combineForManagedTask()/the finalize_task tool wiring exist -- every
 * scenario below is expected to FAIL red until T040-T043 land.
 */
class ManagedTaskCoherentResponseJourneyTest extends TestCase
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

    private function delegationResultReply(string $status, string $summary, array $output = []): array
    {
        return $this->plainReply(json_encode([
            'status' => $status,
            'summary' => $summary,
            'output' => $output,
            'undone' => $status === 'partial' ? 'Some aspects were not covered.' : '',
        ], JSON_FORCE_OBJECT));
    }

    // =================================================================
    // Quickstart scenario 14: final_response reads as one coherent
    // answer; attribution is a separate channel (GET .../parts).
    // =================================================================

    #[Test]
    public function final_response_contains_no_literal_helper_names_or_ids_while_parts_exposes_full_attribution(): void
    {
        $manager = $this->makeAgent('manager-coherence');
        $helperA = $this->makeAgent('Data Analyst');
        $helperB = $this->makeAgent('Report Writer');
        app(AgentHelperService::class)->assign($this->user->id, $manager->id, $helperA->id);
        app(AgentHelperService::class)->assign($this->user->id, $manager->id, $helperB->id);

        $task = app(ManagerService::class)->createManagedTask($this->user->id, $manager->id, 'A three-part task.');
        $conversation = Conversation::find($task->conversation_id);

        $service = $this->serviceWithScriptedProvider([
            $this->toolCallReply([
                $this->toolCall('plan_parts', [
                    'parts' => [
                        ['description' => 'Part one.'],
                        ['description' => 'Part two.'],
                        ['description' => 'Part three.'],
                    ],
                ], 'call_plan'),
            ]),
        ]);
        $this->app->instance(AgentLoopService::class, $service);
        $service->run($conversation, $task->original_request, ['max_iterations' => 1]);

        [$partOne, $partTwo, $partThree] = ManagedTaskPart::where('managed_task_id', $task->id)->orderBy('sequence')->get();

        $service = $this->serviceWithScriptedProvider([
            $this->toolCallReply([$this->toolCall('assign_part', [
                'part_id' => $partOne->id, 'helper_agent_id' => $helperA->id, 'task' => 'Do part one.',
            ], 'call_a1')]),
            $this->delegationResultReply('success', 'Part one is complete.'),
            $this->toolCallReply([$this->toolCall('assign_part', [
                'part_id' => $partTwo->id, 'helper_agent_id' => $helperB->id, 'task' => 'Do part two.',
            ], 'call_a2')]),
            $this->delegationResultReply('success', 'Part two is complete.'),
            $this->toolCallReply([$this->toolCall('assign_part', [
                'part_id' => $partThree->id, 'helper_agent_id' => $helperA->id, 'task' => 'Do part three.',
            ], 'call_a3')]),
            $this->delegationResultReply('success', 'Part three is complete.'),
            $this->toolCallReply([
                $this->toolCall('accept_part', ['part_id' => $partOne->id], 'call_accept_1'),
                $this->toolCall('accept_part', ['part_id' => $partTwo->id], 'call_accept_2'),
                $this->toolCall('accept_part', ['part_id' => $partThree->id], 'call_accept_3'),
            ]),
            $this->toolCallReply([$this->toolCall('finalize_task', [
                'final_response' => 'All three sections of the requested work are complete and combined below into a single overview.',
            ], 'call_finalize')]),
            $this->plainReply('Task finalized.'),
        ]);
        $this->app->instance(AgentLoopService::class, $service);

        $service->run($conversation, 'Continue.', ['max_iterations' => 20]);

        $task->refresh();
        $this->assertSame('completed', $task->status, 'fixture sanity -- the task must actually reach a terminal status');
        $this->assertNotNull($task->final_response);

        $this->assertStringNotContainsString('Data Analyst', $task->final_response);
        $this->assertStringNotContainsString('Report Writer', $task->final_response);
        $this->assertStringNotContainsString($helperA->id, $task->final_response);
        $this->assertStringNotContainsString($helperB->id, $task->final_response);
        $this->assertStringNotContainsString($partOne->id, $task->final_response);
        $this->assertStringNotContainsString($partTwo->id, $task->final_response);
        $this->assertStringNotContainsString($partThree->id, $task->final_response);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/clarion-app/llm-client/managed-tasks/{$task->id}/parts");
        $response->assertStatus(200);

        $byId = collect($response->json())->keyBy('part_id');
        $this->assertSame($helperA->id, $byId[$partOne->id]['assigned_helper_agent_id']);
        $this->assertSame('Data Analyst', $byId[$partOne->id]['assigned_helper_agent_name']);
        $this->assertSame($helperB->id, $byId[$partTwo->id]['assigned_helper_agent_id']);
        $this->assertSame('Report Writer', $byId[$partTwo->id]['assigned_helper_agent_name']);
        $this->assertSame($helperA->id, $byId[$partThree->id]['assigned_helper_agent_id']);
    }

    // =================================================================
    // Quickstart scenario 12: conflicting accepted parts, end to end
    // through finalize_task.
    // =================================================================

    #[Test]
    public function a_genuine_conflict_between_accepted_parts_populates_conflict_note_through_finalize_task(): void
    {
        $manager = $this->makeAgent('manager-conflict');
        $helperA = $this->makeAgent('helper-conflict-one');
        $helperB = $this->makeAgent('helper-conflict-two');
        app(AgentHelperService::class)->assign($this->user->id, $manager->id, $helperA->id);
        app(AgentHelperService::class)->assign($this->user->id, $manager->id, $helperB->id);

        $task = app(ManagerService::class)->createManagedTask($this->user->id, $manager->id, 'A two-part task.');
        $conversation = Conversation::find($task->conversation_id);

        $service = $this->serviceWithScriptedProvider([
            $this->toolCallReply([
                $this->toolCall('plan_parts', [
                    'parts' => [['description' => 'Part one.'], ['description' => 'Part two.']],
                ], 'call_plan'),
            ]),
        ]);
        $this->app->instance(AgentLoopService::class, $service);
        $service->run($conversation, $task->original_request, ['max_iterations' => 1]);

        [$partOne, $partTwo] = ManagedTaskPart::where('managed_task_id', $task->id)->orderBy('sequence')->get();

        $service = $this->serviceWithScriptedProvider([
            $this->toolCallReply([$this->toolCall('assign_part', [
                'part_id' => $partOne->id, 'helper_agent_id' => $helperA->id, 'task' => 'Do part one.',
            ], 'call_a1')]),
            $this->delegationResultReply('success', 'Part one is complete.', ['recommendation' => 'Proceed with Option A.']),
            $this->toolCallReply([$this->toolCall('assign_part', [
                'part_id' => $partTwo->id, 'helper_agent_id' => $helperB->id, 'task' => 'Do part two.',
            ], 'call_a2')]),
            $this->delegationResultReply('success', 'Part two is complete.', ['recommendation' => 'Proceed with Option B.']),
            $this->toolCallReply([
                $this->toolCall('accept_part', ['part_id' => $partOne->id], 'call_accept_1'),
                $this->toolCall('accept_part', ['part_id' => $partTwo->id], 'call_accept_2'),
            ]),
            $this->plainReply('Both parts accepted.'),
        ]);
        $this->app->instance(AgentLoopService::class, $service);
        $service->run($conversation, 'Continue.', ['max_iterations' => 10]);

        $partOne->refresh();
        $partTwo->refresh();
        $this->assertSame('accepted', $partOne->state, 'fixture sanity');
        $this->assertSame('accepted', $partTwo->state, 'fixture sanity');

        // combineForManagedTask() called directly first, per T039's own
        // scenario 12 instruction.
        $combined = app(ResultAggregationService::class)->combineForManagedTask($task->id);
        $this->assertNotNull($combined);
        $this->assertArrayNotHasKey('recommendation', $combined['combined_output']);
        $this->assertCount(1, $combined['conflicts']);
        $this->assertSame('recommendation', $combined['conflicts'][0]['key']);
        $this->assertCount(2, $combined['conflicts'][0]['values']);

        $byHelper = collect($combined['conflicts'][0]['values'])->keyBy('helper_agent_id');
        $this->assertSame('Proceed with Option A.', $byHelper[$helperA->id]['value']);
        $this->assertSame('Proceed with Option B.', $byHelper[$helperB->id]['value']);

        // Now drive finalize_task itself and confirm conflict_note is
        // populated as a side effect of the SAME pipeline.
        $service = $this->serviceWithScriptedProvider([
            $this->toolCallReply([$this->toolCall('finalize_task', [
                'final_response' => 'Both sections are complete; note that they recommend different options.',
            ], 'call_finalize')]),
            $this->plainReply('Task finalized.'),
        ]);
        $this->app->instance(AgentLoopService::class, $service);
        $service->run($conversation, 'Continue.', ['max_iterations' => 5]);

        $task->refresh();
        $this->assertSame('completed', $task->status);
        $this->assertNotNull($task->conflict_note, 'a genuine conflict between accepted parts must populate conflict_note (FR-016) end-to-end through finalize_task');
    }
}
