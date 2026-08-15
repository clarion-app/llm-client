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
 * 103-manager-agent, Phase 3 (US1), tasks.md T020.
 *
 * Full acceptance journey for quickstart.md scenarios 1 and 2 (US1 AC1/
 * AC3, FR-001/FR-002/FR-003, SC-001/SC-002): a manager agent decomposing a
 * task and routing each part to a suitable helper via the real
 * ManagerService -> DelegationService -> AgentLoopService::run() chain
 * (never mocked -- the whole point is to prove the real mechanism, not
 * just the wiring), mirroring DelegationServiceTest.php's own scripted-
 * LlmProvider convention for exercising a real nested delegate() call
 * without a live provider.
 *
 * Written before ManagerService/the widened AgentLoopService exist --
 * every scenario below is expected to FAIL red until T021/T025 land.
 */
class ManagedTaskSuitabilityJourneyTest extends TestCase
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

    private function serviceWithScriptedProvider(array $responses, ?array &$capturedMessages = null): AgentLoopService
    {
        $capturedMessages = [];
        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturnUsing(function ($messages, $tools, $options = []) use (&$responses, &$capturedMessages) {
            $this->assertNotEmpty($responses, 'the scripted response queue was exhausted -- the loop made more provider calls than this test expected');
            $capturedMessages[] = $messages;

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

    private function delegationResultReply(string $summary): array
    {
        return $this->plainReply(json_encode([
            'status' => 'success',
            'summary' => $summary,
            'output' => [],
            'undone' => '',
        ], JSON_FORCE_OBJECT));
    }

    // =================================================================
    // Scenario 1 (US1 AC1, FR-001/FR-002, SC-001)
    // =================================================================

    #[Test]
    public function a_task_needing_two_kinds_of_work_is_split_and_each_part_routed_to_the_matching_helper(): void
    {
        $manager = $this->makeAgent('manager-suitability');
        $research = $this->makeAgent('Research Assistant');
        $copyEdit = $this->makeAgent('Copy Editor');
        app(AgentHelperService::class)->assign($this->user->id, $manager->id, $research->id);
        app(AgentHelperService::class)->assign($this->user->id, $manager->id, $copyEdit->id);

        $task = app(ManagerService::class)->createManagedTask(
            $this->user->id,
            $manager->id,
            'Research the competitor landscape, then copy-edit the resulting draft for publication.',
        );
        $conversation = Conversation::find($task->conversation_id);

        $service = $this->serviceWithScriptedProvider([
            // Iteration 1: decompose.
            $this->toolCallReply([
                $this->toolCall('plan_parts', [
                    'parts' => [
                        ['description' => 'Research the competitor landscape.'],
                        ['description' => 'Copy-edit the resulting draft for publication.'],
                    ],
                ], 'call_plan'),
            ]),
        ], $capturedMessages);
        $this->app->instance(AgentLoopService::class, $service);

        // plan_parts itself needs no nested LLM call (pure DB write), so
        // running with max_iterations => 1 is enough to learn the real
        // part ids before scripting the assign_part calls that reference
        // them -- the "max_iterations reached" result is expected and
        // irrelevant here (this call's only purpose is to drive plan_parts
        // once), not a failure of the journey.
        $result = $service->run($conversation, $task->original_request, ['max_iterations' => 1]);
        $this->assertSame('max_iterations', $result['code'] ?? null, 'fixture sanity: exactly one provider turn was scripted');

        // T027 (verification-only): buildKnownHelpersSection() already
        // takes ANY Conversation and needs no code change to fire for the
        // manager's own -- confirm here that the system prompt genuinely
        // includes both of the manager's own helpers' suitability
        // statements, since ManagedTaskController::store()'s own
        // no_assigned_helpers guard already ensures at least one exists by
        // the time a managed task can be created at all.
        $systemPrompt = collect($capturedMessages[0])->firstWhere('role', 'system')['content'] ?? '';
        $this->assertStringContainsString('## Known Helpers', $systemPrompt);
        $this->assertStringContainsString('Research Assistant', $systemPrompt);
        $this->assertStringContainsString('Copy Editor', $systemPrompt);

        $parts = ManagedTaskPart::where('managed_task_id', $task->id)->orderBy('sequence')->get();
        $this->assertCount(2, $parts, 'plan_parts must have created exactly two parts');
        $researchPart = $parts[0];
        $editPart = $parts[1];

        // Continue the SAME conversation with the real assign_part/nested-
        // delegation sequence now that part ids are known.
        $service2 = $this->serviceWithScriptedProvider([
            // Iteration: assign the research part to the Research Assistant.
            $this->toolCallReply([
                $this->toolCall('assign_part', [
                    'part_id' => $researchPart->id,
                    'helper_agent_id' => $research->id,
                    'task' => 'Research the competitor landscape.',
                ], 'call_assign_research'),
            ]),
            // Consumed by the NESTED run() call DelegationService::delegate()
            // makes for the Research Assistant's own helper conversation.
            $this->delegationResultReply('Compiled the competitor landscape.'),
            // Iteration: assign the copy-edit part to the Copy Editor.
            $this->toolCallReply([
                $this->toolCall('assign_part', [
                    'part_id' => $editPart->id,
                    'helper_agent_id' => $copyEdit->id,
                    'task' => 'Copy-edit the resulting draft for publication.',
                ], 'call_assign_edit'),
            ]),
            $this->delegationResultReply('Copy-edited the draft.'),
            // Final turn-ending reply.
            $this->plainReply('Both parts have been assigned.'),
        ]);
        $this->app->instance(AgentLoopService::class, $service2);

        $service2->run($conversation, 'Continue.', ['max_iterations' => 10]);

        $researchDelegations = Delegation::where('part_id', $researchPart->id)->get();
        $editDelegations = Delegation::where('part_id', $editPart->id)->get();

        $this->assertCount(1, $researchDelegations, 'the research part must have exactly one delegation');
        $this->assertSame($research->id, $researchDelegations->first()->helper_agent_id, 'the research part must be routed to the Research Assistant, matching its own kind of work');

        $this->assertCount(1, $editDelegations, 'the copy-edit part must have exactly one delegation');
        $this->assertSame($copyEdit->id, $editDelegations->first()->helper_agent_id, 'the copy-edit part must be routed to the Copy Editor, matching its own kind of work');

        $this->assertNotSame(
            $researchDelegations->first()->helper_agent_id,
            $editDelegations->first()->helper_agent_id,
            'the two parts must never both be routed to the same helper',
        );
    }

    // =================================================================
    // Scenario 2 (US1 AC3, FR-003, SC-002)
    // =================================================================

    #[Test]
    public function a_small_single_purpose_task_produces_exactly_one_part_and_no_second_assign_part_call(): void
    {
        $manager = $this->makeAgent('manager-single-purpose');
        $helper = $this->makeAgent('General Helper');
        app(AgentHelperService::class)->assign($this->user->id, $manager->id, $helper->id);

        $task = app(ManagerService::class)->createManagedTask(
            $this->user->id,
            $manager->id,
            'Summarize this short paragraph in one sentence.',
        );
        $conversation = Conversation::find($task->conversation_id);

        $service = $this->serviceWithScriptedProvider([
            $this->toolCallReply([
                $this->toolCall('plan_parts', [
                    'parts' => [
                        ['description' => 'Summarize the paragraph in one sentence.'],
                    ],
                ], 'call_plan'),
            ]),
        ]);
        $this->app->instance(AgentLoopService::class, $service);
        $service->run($conversation, $task->original_request, ['max_iterations' => 1]);

        $parts = ManagedTaskPart::where('managed_task_id', $task->id)->get();
        $this->assertCount(1, $parts, 'plan_parts must be called with exactly one part for a genuinely single-purpose task');
        $onlyPart = $parts->first();

        $service2 = $this->serviceWithScriptedProvider([
            $this->toolCallReply([
                $this->toolCall('assign_part', [
                    'part_id' => $onlyPart->id,
                    'helper_agent_id' => $helper->id,
                    'task' => 'Summarize the paragraph in one sentence.',
                ], 'call_assign'),
            ]),
            $this->delegationResultReply('The paragraph is about X.'),
            $this->plainReply('Done.'),
        ]);
        $this->app->instance(AgentLoopService::class, $service2);
        $service2->run($conversation, 'Continue.', ['max_iterations' => 10]);

        $delegations = Delegation::where('managed_task_id', $task->id)->get();
        $this->assertCount(1, $delegations, 'no second assign_part call may ever target a second part_id for a single-part task');
        $this->assertSame($onlyPart->id, $delegations->first()->part_id);
    }
}
