<?php

namespace ClarionApp\LlmClient\Tests\Unit\Services;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\ManagedTask;
use ClarionApp\LlmClient\Models\ManagedTaskPart;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\DelegationService;
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
 * 103-manager-agent, Phase 3 (US1), tasks.md T019.
 *
 * Unit tests for the not-yet-widened `buildToolsPayload()` gate
 * (plan_parts/assign_part appended only for a channel === 'managed-task'
 * conversation) and the not-yet-widened `resolveDelegateToHelperBatchResults()`
 * filter (research.md D2, Grounding note item 6 -- assign_part counted
 * alongside delegate_to_helper for the existing 101 batch-detection path).
 *
 * Mirrors AgentLoopServiceDelegationBatchTest.php's own established
 * scaffolding exactly (a real AgentLoopService driven by a scripted
 * LlmProvider, DelegationService replaced with a Mockery double so this
 * file cares only about WHETHER/HOW AgentLoopService calls delegate()/
 * delegateBatch(), never DelegationService's own internals) -- that file's
 * own delegate_to_helper-only scenarios are left untouched and continue
 * to prove the widened filter has not regressed the pre-existing
 * behavior. ManagerService is NOT mocked -- admitAssignmentRound()'s own
 * guard runs for real against a real ManagedTask/ManagedTaskPart fixture,
 * so this file also proves the guard genuinely gates which assign_part
 * calls ever reach the merged calls array DelegationService::
 * delegateBatch() receives.
 *
 * Written before buildToolsPayload()'s $conversation parameter and the
 * widened filter exist -- every test below is expected to FAIL red until
 * T025 lands.
 */
class AgentLoopServiceManagedTaskToolsTest extends TestCase
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

        DB::table('messages')->delete();
        if (Schema::hasTable('mcp_sessions')) {
            DB::table('mcp_sessions')->delete();
        }
        DB::table('managed_task_parts')->delete();
        DB::table('managed_tasks')->delete();
        if (Schema::hasTable('agent_delegations')) {
            DB::table('agent_delegations')->delete();
        }
        DB::table('conversations')->delete();
        DB::table('agent_versions')->delete();
        DB::table('agents')->delete();
        DB::table('llm_role_assignments')->delete();
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

    private function makeConversation(?Agent $agent = null, string $channel = 'web'): Conversation
    {
        return Conversation::factory()->create([
            'user_id' => $this->user->id,
            'model' => 'test-model',
            'channel' => $channel,
            'agent_id' => $agent?->id,
            'agent_version_id' => $agent?->current_version_id,
        ]);
    }

    private function serviceWithScriptedProvider(array $responses): AgentLoopService
    {
        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturnUsing(function ($messages, $tools, $options = []) use (&$responses) {
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

    private function sixFieldResult(string $delegationId, string $helper, string $summary): array
    {
        return [
            'delegation_id' => $delegationId,
            'helper' => $helper,
            'status' => 'success',
            'summary' => $summary,
            'output' => [],
            'undone' => '',
            'truncated' => false,
            'reason' => null,
        ];
    }

    private function bindMockedDelegationService(): Mockery\MockInterface
    {
        $mock = Mockery::mock(DelegationService::class);
        $this->app->instance(DelegationService::class, $mock);

        return $mock;
    }

    private function firstToolDataMessage(string $conversationId): ?Message
    {
        return Message::where('conversation_id', $conversationId)
            ->where('role', 'assistant')
            ->whereNotNull('tool_data')
            ->orderBy('created_at')
            ->first();
    }

    /** @return array{0: ManagedTask, 1: ManagedTaskPart[], 2: Conversation} */
    private function makeManagedTaskWithParts(int $partCount): array
    {
        $manager = $this->makeAgent('manager-'.uniqid());
        $task = app(ManagerService::class)->createManagedTask($this->user->id, $manager->id, 'A managed task.');
        $descriptions = [];
        for ($i = 0; $i < $partCount; $i++) {
            $descriptions[] = "Part {$i}.";
        }
        $parts = app(ManagerService::class)->planParts($task, $descriptions);
        $conversation = Conversation::find($task->conversation_id);

        return [$task, $parts, $conversation];
    }

    // =================================================================
    // buildToolsPayload() gating
    // =================================================================

    #[Test]
    public function build_tools_payload_includes_plan_parts_and_assign_part_for_a_managed_task_conversation(): void
    {
        [, , $conversation] = $this->makeManagedTaskWithParts(1);

        $service = app(AgentLoopService::class);
        $tools = $service->buildToolsPayload([], $conversation);

        $names = array_map(fn (array $t) => $t['function']['name'], $tools);
        $this->assertContains('plan_parts', $names);
        $this->assertContains('assign_part', $names);
    }

    #[Test]
    public function build_tools_payload_excludes_plan_parts_and_assign_part_for_an_ordinary_interactive_conversation(): void
    {
        $conversation = $this->makeConversation(channel: 'web');

        $service = app(AgentLoopService::class);
        $tools = $service->buildToolsPayload([], $conversation);

        $names = array_map(fn (array $t) => $t['function']['name'], $tools);
        $this->assertNotContains('plan_parts', $names);
        $this->assertNotContains('assign_part', $names);
    }

    #[Test]
    public function build_tools_payload_excludes_plan_parts_and_assign_part_for_a_helper_delegation_conversation(): void
    {
        $conversation = $this->makeConversation(channel: 'agent-delegation');

        $service = app(AgentLoopService::class);
        $tools = $service->buildToolsPayload([], $conversation);

        $names = array_map(fn (array $t) => $t['function']['name'], $tools);
        $this->assertNotContains('plan_parts', $names);
        $this->assertNotContains('assign_part', $names, 'a manager\'s own helper conversation must never see assign_part -- only the manager itself calls it');
    }

    #[Test]
    public function build_tools_payload_excludes_plan_parts_and_assign_part_when_no_conversation_is_given(): void
    {
        $service = app(AgentLoopService::class);
        $tools = $service->buildToolsPayload([]);

        $names = array_map(fn (array $t) => $t['function']['name'], $tools);
        $this->assertNotContains('plan_parts', $names);
        $this->assertNotContains('assign_part', $names);
    }

    // =================================================================
    // Widened batch filter -- run()
    // =================================================================

    #[Test]
    public function run_with_two_assign_part_calls_for_different_parts_calls_delegate_batch_exactly_once(): void
    {
        [$task, $parts, $conversation] = $this->makeManagedTaskWithParts(2);
        [$partA, $partB] = $parts;

        $mock = $this->bindMockedDelegationService();
        $mock->shouldNotReceive('delegate');
        $mock->shouldReceive('delegateBatch')
            ->once()
            ->with(
                Mockery::on(fn ($c) => $c->id === $conversation->id),
                Mockery::on(function (array $calls) use ($task, $partA, $partB) {
                    return count($calls) === 2
                        && ($calls[0]['tool_call_id'] ?? null) === 'call_a'
                        && ($calls[0]['managed_task_id'] ?? null) === $task->id
                        && ($calls[0]['part_id'] ?? null) === $partA->id
                        && ($calls[1]['tool_call_id'] ?? null) === 'call_b'
                        && ($calls[1]['managed_task_id'] ?? null) === $task->id
                        && ($calls[1]['part_id'] ?? null) === $partB->id;
                }),
            )
            ->andReturn([
                'call_a' => $this->sixFieldResult('del-a', 'Helper A', 'Result A.'),
                'call_b' => $this->sixFieldResult('del-b', 'Helper B', 'Result B.'),
            ]);

        $service = $this->serviceWithScriptedProvider([
            $this->toolCallReply([
                $this->toolCall('assign_part', ['part_id' => $partA->id, 'helper_agent_id' => 'helper-a', 'task' => 'Task A.'], 'call_a'),
                $this->toolCall('assign_part', ['part_id' => $partB->id, 'helper_agent_id' => 'helper-b', 'task' => 'Task B.'], 'call_b'),
            ]),
            $this->plainReply('Both parts assigned.'),
        ]);
        $this->app->instance(AgentLoopService::class, $service);

        $result = $service->run($conversation, 'Assign both parts.');

        $this->assertSame('completed', $result['status'] ?? null);

        $partA->refresh();
        $partB->refresh();
        $this->assertSame('del-a', $partA->current_delegation_id);
        $this->assertSame('del-b', $partB->current_delegation_id);
    }

    #[Test]
    public function run_with_a_mix_of_delegate_to_helper_and_assign_part_calls_delegate_batch_exactly_once_with_the_full_ordered_set(): void
    {
        [$task, $parts, $conversation] = $this->makeManagedTaskWithParts(1);
        [$partA] = $parts;

        $mock = $this->bindMockedDelegationService();
        $mock->shouldNotReceive('delegate');
        $mock->shouldReceive('delegateBatch')
            ->once()
            ->with(
                Mockery::any(),
                Mockery::on(function (array $calls) use ($task, $partA) {
                    return count($calls) === 2
                        && ($calls[0]['tool_call_id'] ?? null) === 'call_delegate'
                        && !array_key_exists('managed_task_id', $calls[0])
                        && ($calls[1]['tool_call_id'] ?? null) === 'call_assign'
                        && ($calls[1]['managed_task_id'] ?? null) === $task->id
                        && ($calls[1]['part_id'] ?? null) === $partA->id;
                }),
            )
            ->andReturn([
                'call_delegate' => $this->sixFieldResult('del-x', 'Helper X', 'Result X.'),
                'call_assign' => $this->sixFieldResult('del-y', 'Helper Y', 'Result Y.'),
            ]);

        $service = $this->serviceWithScriptedProvider([
            $this->toolCallReply([
                $this->toolCall('delegate_to_helper', ['helper_agent_id' => 'helper-x', 'task' => 'Task X.'], 'call_delegate'),
                $this->toolCall('assign_part', ['part_id' => $partA->id, 'helper_agent_id' => 'helper-y', 'task' => 'Task Y.'], 'call_assign'),
            ]),
            $this->plainReply('Mixed batch complete.'),
        ]);
        $this->app->instance(AgentLoopService::class, $service);

        $result = $service->run($conversation, 'Mix delegate_to_helper and assign_part.');

        $this->assertSame('completed', $result['status'] ?? null);

        $task->refresh();
        $this->assertSame(1, $task->rounds_used, 'the assign_part call must have been admitted through ManagerService\'s own guard exactly once');
    }

    #[Test]
    public function a_refused_assign_part_call_within_a_batch_never_reaches_delegate_batch_and_returns_its_own_error(): void
    {
        [$task, $parts, $conversation] = $this->makeManagedTaskWithParts(1);
        [$part] = $parts;
        $part->state = 'accepted';
        $part->save();

        $mock = $this->bindMockedDelegationService();
        $mock->shouldReceive('delegateBatch')
            ->once()
            ->with(
                Mockery::any(),
                Mockery::on(fn (array $calls) => count($calls) === 1 && $calls[0]['tool_call_id'] === 'call_delegate'),
            )
            ->andReturn(['call_delegate' => $this->sixFieldResult('del-x', 'Helper X', 'Result X.')]);

        $service = $this->serviceWithScriptedProvider([
            $this->toolCallReply([
                $this->toolCall('delegate_to_helper', ['helper_agent_id' => 'helper-x', 'task' => 'Task X.'], 'call_delegate'),
                $this->toolCall('assign_part', ['part_id' => $part->id, 'helper_agent_id' => 'helper-y', 'task' => 'Task Y.'], 'call_assign'),
            ]),
            $this->plainReply('Handled the refusal.'),
        ]);
        $this->app->instance(AgentLoopService::class, $service);

        $result = $service->run($conversation, 'One will be refused.');

        $this->assertSame('completed', $result['status'] ?? null);

        $message = $this->firstToolDataMessage($conversation->id);
        $results = $message->tool_data['tool_results'];
        $assignResult = collect($results)->firstWhere('tool_call_id', 'call_assign');
        $this->assertNotNull($assignResult);
        $this->assertSame('part_already_finalized', json_decode($assignResult['content'], true)['error'] ?? null);
    }

    // =================================================================
    // Widened batch filter -- resumeSync()
    // =================================================================

    private function pausedConfirmationMessage(Conversation $conversation): Message
    {
        return Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => '',
            'responseTime' => 0,
            'tool_data' => [
                'tool_calls' => [
                    $this->toolCall('execute_operation', ['operationId' => 'some.operation', 'parameters' => []], 'call_confirm'),
                ],
                'tool_results' => null,
                'iteration' => 1,
                'pending_confirmation' => [
                    'operationId' => 'some.operation',
                    'tool_name' => 'execute_operation',
                    'method' => 'DELETE',
                    'path' => '/api/some/operation',
                    'arguments' => [],
                    'expires_at' => now()->addMinutes(5)->toIso8601String(),
                ],
            ],
        ]);
    }

    #[Test]
    public function resume_sync_with_a_mix_of_delegate_to_helper_and_assign_part_calls_calls_delegate_batch_exactly_once(): void
    {
        [$task, $parts, $conversation] = $this->makeManagedTaskWithParts(1);
        [$partA] = $parts;
        $conversation->update(['is_processing' => true]);
        $message = $this->pausedConfirmationMessage($conversation);

        $mock = $this->bindMockedDelegationService();
        $mock->shouldNotReceive('delegate');
        $mock->shouldReceive('delegateBatch')
            ->once()
            ->with(
                Mockery::any(),
                Mockery::on(function (array $calls) use ($task, $partA) {
                    return count($calls) === 2
                        && ($calls[0]['tool_call_id'] ?? null) === 'call_delegate'
                        && ($calls[1]['tool_call_id'] ?? null) === 'call_assign'
                        && ($calls[1]['managed_task_id'] ?? null) === $task->id
                        && ($calls[1]['part_id'] ?? null) === $partA->id;
                }),
            )
            ->andReturn([
                'call_delegate' => $this->sixFieldResult('del-x', 'Helper X', 'Result X.'),
                'call_assign' => $this->sixFieldResult('del-y', 'Helper Y', 'Result Y.'),
            ]);

        $service = $this->serviceWithScriptedProvider([
            $this->toolCallReply([
                $this->toolCall('delegate_to_helper', ['helper_agent_id' => 'helper-x', 'task' => 'Task X.'], 'call_delegate'),
                $this->toolCall('assign_part', ['part_id' => $partA->id, 'helper_agent_id' => 'helper-y', 'task' => 'Task Y.'], 'call_assign'),
            ]),
            $this->plainReply('Continuation batch complete.'),
        ]);
        $this->app->instance(AgentLoopService::class, $service);

        $result = $service->resumeSync($conversation, $message, false);

        $this->assertSame('completed', $result['status'] ?? null);
        $task->refresh();
        $this->assertSame(1, $task->rounds_used);
    }
}
