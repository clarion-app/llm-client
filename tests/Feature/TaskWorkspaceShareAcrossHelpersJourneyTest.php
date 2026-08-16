<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
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
 * 108-shared-task-workspace, Phase 3 (US1), tasks.md T016.
 *
 * Full acceptance journey for quickstart.md scenario 1 (the entire reason
 * the feature exists, US1's own Why Priority): a two-part managed task,
 * two helpers. Part A's own helper conversation calls `record_task_note`
 * with a specific, checkable fact before its part is accepted. Asserts:
 *
 *  (a) `record_task_note` is present in part A's helper conversation's own
 *      `buildToolsPayload()` output -- proving the gate is
 *      `resolveManagedTaskIdForConversation() !== null`, strictly WIDER
 *      than 103's own `channel === 'managed-task'` gate (mutation-checklist
 *      row 9's own named risk).
 *  (b) once part B's own helper conversation is created and its first
 *      turn actually runs, the real system prompt sent to the provider for
 *      that turn contains a "## Shared Task Notes" section whose text
 *      includes part A's helper's exact note -- captured live from the
 *      scripted provider's own `chat()` call, not recomputed after the
 *      fact.
 *
 * Drives the real ManagerService -> DelegationService -> AgentLoopService::
 * run() chain (never mocked), mirroring ManagedTaskCoherentResponseJourneyTest.php's
 * own established scaffolding.
 */
class TaskWorkspaceShareAcrossHelpersJourneyTest extends TestCase
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

        DB::table('task_workspace_entries')->delete();
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

    /** @var array<int, array{messages: array, tools: array}> */
    private array $capturedCalls = [];

    private function serviceWithScriptedProvider(array $responses): AgentLoopService
    {
        $this->capturedCalls = [];

        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturnUsing(function ($messages, $tools, $options = []) use (&$responses) {
            $this->assertNotEmpty($responses, 'the scripted response queue was exhausted -- the loop made more provider calls than this test expected');

            $this->capturedCalls[] = ['messages' => $messages, 'tools' => $tools];

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

    #[Test]
    public function a_finding_recorded_by_one_helper_is_visible_in_a_second_helpers_own_next_turn(): void
    {
        $manager = $this->makeAgent('manager-shared-workspace');
        $helperA = $this->makeAgent('Research Assistant');
        $helperB = $this->makeAgent('Copy Editor');
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

        [$partA, $partB] = ManagedTaskPart::where('managed_task_id', $task->id)->orderBy('sequence')->get();

        $noteContent = 'The staging environment has no TLS certificate; use the fallback HTTP endpoint for now.';

        $service = $this->serviceWithScriptedProvider([
            // Manager: assign part A to helper A.
            $this->toolCallReply([$this->toolCall('assign_part', [
                'part_id' => $partA->id, 'helper_agent_id' => $helperA->id, 'task' => 'Do part one.',
            ], 'call_assign_a')]),
            // Helper A's own first turn: record a finding before finishing.
            $this->toolCallReply([$this->toolCall('record_task_note', [
                'content' => $noteContent,
            ], 'call_note')]),
            // Helper A's own second turn: the delegation's final result.
            $this->delegationResultReply('success', 'Part one is complete.'),
            // Manager: assign part B to helper B.
            $this->toolCallReply([$this->toolCall('assign_part', [
                'part_id' => $partB->id, 'helper_agent_id' => $helperB->id, 'task' => 'Do part two.',
            ], 'call_assign_b')]),
            // Helper B's own first (and only) turn.
            $this->delegationResultReply('success', 'Part two is complete.'),
            // Manager's own final reply.
            $this->plainReply('Both parts assigned.'),
        ]);
        $this->app->instance(AgentLoopService::class, $service);

        $service->run($conversation, 'Continue.', ['max_iterations' => 20]);

        // ---------------------------------------------------------------
        // Fixture sanity: the note was actually recorded.
        // ---------------------------------------------------------------
        $delegationA = Delegation::where('managed_task_id', $task->id)->where('part_id', $partA->id)->first();
        $this->assertNotNull($delegationA, 'fixture sanity -- part A must have a delegation row');
        $helperAConversation = Conversation::find($delegationA->helper_conversation_id);
        $this->assertNotNull($helperAConversation, 'fixture sanity');

        $this->assertDatabaseHas('task_workspace_entries', [
            'managed_task_id' => $task->id,
            'content' => $noteContent,
        ]);

        // ---------------------------------------------------------------
        // (a) record_task_note is present in part A's own helper
        //     conversation's buildToolsPayload() -- the widened gate.
        // ---------------------------------------------------------------
        $toolsForHelperA = $service->buildToolsPayload([], $helperAConversation);
        $namesForHelperA = array_map(fn (array $t) => $t['function']['name'], $toolsForHelperA);
        $this->assertContains('record_task_note', $namesForHelperA, 'record_task_note must be injected for a helper conversation, not only the manager\'s own -- the widened gate this feature exists to add');

        // ---------------------------------------------------------------
        // (b) part B's own first real turn actually received the note in
        //     its live system prompt -- captured from the scripted
        //     provider's own chat() call, not recomputed after the fact.
        // ---------------------------------------------------------------
        $delegationB = Delegation::where('managed_task_id', $task->id)->where('part_id', $partB->id)->first();
        $this->assertNotNull($delegationB, 'fixture sanity -- part B must have a delegation row');

        $helperBFirstTurnCall = collect($this->capturedCalls)->first(function (array $call) {
            foreach ($call['messages'] as $message) {
                if (str_contains((string) ($message['content'] ?? ''), 'Do part two.')) {
                    return true;
                }
            }

            return false;
        });

        $this->assertNotNull($helperBFirstTurnCall, 'fixture sanity -- must be able to identify helper B\'s own first provider call from its seeded task text');

        $systemMessage = collect($helperBFirstTurnCall['messages'])->firstWhere('role', 'system');
        $this->assertNotNull($systemMessage, 'fixture sanity -- helper B\'s first turn must carry a system message');
        $this->assertStringContainsString('## Shared Task Notes', $systemMessage['content']);
        $this->assertStringContainsString($noteContent, $systemMessage['content'], 'helper B must see helper A\'s exact note in its own first turn\'s system prompt, without having produced it itself');
    }
}
