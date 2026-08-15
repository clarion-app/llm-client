<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\DelegationService;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use Dedoc\Scramble\Generator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 101-parallel-subagent-execution, Phase 3 (US1), tasks.md T013.
 *
 * Unit tests for the not-yet-built batch-detection wiring ahead of
 * AgentLoopService's two per-tool-call loops (Grounding note item 6 --
 * run()'s at L1072/L1142, resumeSync()'s at L1673/L1735). DelegationService
 * is replaced with a Mockery double throughout: delegateBatch() does not
 * exist on the real class yet (it lands in T019), so every expectation set
 * on it here is a "quick definition" Mockery adds dynamically for a
 * concrete class -- the same technique this session's other TDD-first
 * tests already rely on (T011/T012's own "mock/spy the not-yet-built
 * method" framing).
 *
 * Both call sites are exercised for real -- a real AgentLoopService with a
 * scripted LlmProvider (DelegationServiceTest's own established
 * serviceWithScriptedProvider() pattern), never a mock of AgentLoopService
 * itself, since the wiring under test lives inside its own two loops.
 * DelegationService is mocked because eligibility/isolation/the nested
 * run() call are DelegationService's own concern (098/099), not this
 * feature's -- the only thing this file cares about is whether
 * AgentLoopService calls delegate() or delegateBatch(), with what
 * arguments, and how it threads the results back into the tool-result
 * message it assembles.
 *
 * Every delegate_to_helper call in this file uses an arbitrary
 * helper_agent_id string -- real assigned-helper eligibility is
 * DelegationService's own concern, entirely bypassed by the mock, so no
 * Agent/AgentHelperAssignment fixture is needed anywhere in this file.
 *
 * Written before any batch-detection code exists ahead of either loop --
 * every "2+" scenario below is expected to FAIL red: a batch of
 * delegate_to_helper calls today runs through the SAME loop body as any
 * other tool call, each landing in handleDelegateToHelper() ->
 * DelegationService::delegate() one at a time, in strict sequence -- so
 * this file's own delegateBatch() expectations go unmet (Mockery reports
 * the expected call was never made) and delegate() is instead called once
 * per delegate_to_helper entry, tripping the "delegate() must never be
 * called" expectations in the 2+ tests.
 */
class AgentLoopServiceDelegationBatchTest extends TestCase
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

        // buildMessagesPayload()/applyContextWindowTrim() (both in the
        // run()/resumeSync() funnel) read these tables regardless of
        // whether auto-memory retrieval or condensation ever actually
        // triggers -- DelegationServiceTest's own established precedent
        // for this exact set of tables.
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
        // AgentLoopServiceTest's own established precedent: a run() that
        // hits an unmet Mockery expectation mid-loop can leave PHP's own
        // error/exception handler stack disturbed, which otherwise bleeds
        // into a later test in the same process.
        restore_error_handler();
        restore_exception_handler();

        $this->clearOperationCatalog();
        Mockery::close();

        DB::table('messages')->delete();
        if (Schema::hasTable('mcp_sessions')) {
            DB::table('mcp_sessions')->delete();
        }
        if (Schema::hasTable('agent_delegations')) {
            DB::table('agent_delegations')->delete();
        }
        DB::table('conversations')->delete();
        DB::table('llm_servers')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Operation-catalog scaffolding (DelegationServiceTest's own
    // established precedent) -- buildToolsPayload() always advertises
    // execute_operation/search_operations regardless of catalog contents,
    // so an empty catalog is enough.
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

    private function makeConversation(): Conversation
    {
        return Conversation::factory()->create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'title' => 'Already titled',
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

    /** The six-field delegate_to_helper result shape (098/099), merged with delegation_id/helper. */
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

    // =================================================================
    // run() -- Site 2 (L1072/L1142)
    // =================================================================

    #[Test]
    public function run_with_zero_delegate_to_helper_calls_never_calls_delegate_batch(): void
    {
        $conversation = $this->makeConversation();
        $mock = $this->bindMockedDelegationService();
        $mock->shouldNotReceive('delegateBatch');
        $mock->shouldNotReceive('delegate');

        $service = $this->serviceWithScriptedProvider([
            $this->toolCallReply([$this->toolCall('list_applications', [], 'call_list')]),
            $this->plainReply('Here are the applications.'),
        ]);
        $this->app->instance(AgentLoopService::class, $service);

        $result = $service->run($conversation, 'List the applications.');

        $this->assertSame('completed', $result['status'] ?? null, 'fixture sanity: the turn must complete');
    }

    #[Test]
    public function run_with_exactly_one_delegate_to_helper_call_uses_the_existing_inline_path_and_never_calls_delegate_batch(): void
    {
        $conversation = $this->makeConversation();
        $mock = $this->bindMockedDelegationService();
        $mock->shouldNotReceive('delegateBatch');
        $mock->shouldReceive('delegate')
            ->once()
            ->with(Mockery::on(fn ($c) => $c->id === $conversation->id), 'helper-solo', 'Solo task.', 'Solo context.')
            ->andReturn($this->sixFieldResult('del-solo', 'Helper Solo', 'Solo result.'));

        $service = $this->serviceWithScriptedProvider([
            $this->toolCallReply([
                $this->toolCall('delegate_to_helper', [
                    'helper_agent_id' => 'helper-solo',
                    'task' => 'Solo task.',
                    'context' => 'Solo context.',
                ], 'call_solo'),
            ]),
            $this->plainReply('Solo delegation complete.'),
        ]);
        $this->app->instance(AgentLoopService::class, $service);

        $result = $service->run($conversation, 'Delegate one task.');

        $this->assertSame('completed', $result['status'] ?? null, 'fixture sanity: the turn must complete');

        $message = $this->firstToolDataMessage($conversation->id);
        $this->assertNotNull($message);
        $this->assertSame(
            'Solo result.',
            json_decode($message->tool_data['tool_results'][0]['content'], true)['summary'] ?? null,
        );
    }

    #[Test]
    public function run_with_two_delegate_to_helper_calls_calls_delegate_batch_exactly_once_with_the_full_ordered_set(): void
    {
        $conversation = $this->makeConversation();
        $mock = $this->bindMockedDelegationService();
        $mock->shouldNotReceive('delegate');
        $mock->shouldReceive('delegateBatch')
            ->once()
            ->with(
                Mockery::on(fn ($c) => $c->id === $conversation->id),
                Mockery::on(function (array $calls) {
                    return count($calls) === 2
                        && ($calls[0]['tool_call_id'] ?? null) === 'call_a'
                        && ($calls[0]['helper_agent_id'] ?? null) === 'helper-a'
                        && ($calls[0]['task'] ?? null) === 'Task A.'
                        && ($calls[0]['context'] ?? null) === 'Context A.'
                        && ($calls[1]['tool_call_id'] ?? null) === 'call_b'
                        && ($calls[1]['helper_agent_id'] ?? null) === 'helper-b'
                        && ($calls[1]['task'] ?? null) === 'Task B.'
                        && ($calls[1]['context'] ?? null) === 'Context B.';
                }),
            )
            // Deliberately returned in the OPPOSITE order to the request,
            // keyed by tool_call_id -- proving the final assembly below
            // depends on the id lookup, never on this array's own order
            // (contracts §1's ordering guarantee).
            ->andReturn([
                'call_b' => $this->sixFieldResult('del-b', 'Helper B', 'Result B.'),
                'call_a' => $this->sixFieldResult('del-a', 'Helper A', 'Result A.'),
            ]);

        $service = $this->serviceWithScriptedProvider([
            $this->toolCallReply([
                $this->toolCall('delegate_to_helper', ['helper_agent_id' => 'helper-a', 'task' => 'Task A.', 'context' => 'Context A.'], 'call_a'),
                $this->toolCall('list_applications', [], 'call_list'),
                $this->toolCall('delegate_to_helper', ['helper_agent_id' => 'helper-b', 'task' => 'Task B.', 'context' => 'Context B.'], 'call_b'),
            ]),
            $this->plainReply('Both delegations are complete.'),
        ]);
        $this->app->instance(AgentLoopService::class, $service);

        $result = $service->run($conversation, 'Delegate two independent tasks.');

        $this->assertSame('completed', $result['status'] ?? null, 'fixture sanity: the turn must complete');

        $message = $this->firstToolDataMessage($conversation->id);
        $this->assertNotNull($message, 'the iteration containing the batch must still write its own tool_data message, exactly like any other iteration');

        $results = $message->tool_data['tool_results'];
        $this->assertCount(3, $results, 'every tool call in the iteration -- both delegate_to_helper calls and the unrelated list_applications call -- must produce exactly one tool result each');

        // Original request order, regardless of delegateBatch()'s own
        // scrambled return order and regardless of which member the batch
        // itself finished first (contracts §1).
        $this->assertSame('call_a', $results[0]['tool_call_id']);
        $this->assertSame('Result A.', json_decode($results[0]['content'], true)['summary'] ?? null);

        $this->assertSame('call_list', $results[1]['tool_call_id'], 'the unrelated list_applications call must still execute at its own original position, unaffected by the batch surrounding it');
        $this->assertIsArray(json_decode($results[1]['content'], true), 'list_applications must still have genuinely executed, not been swallowed by the batch handling');

        $this->assertSame('call_b', $results[2]['tool_call_id']);
        $this->assertSame('Result B.', json_decode($results[2]['content'], true)['summary'] ?? null);
    }

    // =================================================================
    // resumeSync() -- Site 6 (L1673/L1735)
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
    public function resume_sync_with_zero_delegate_to_helper_calls_in_the_continuation_never_calls_delegate_batch(): void
    {
        $conversation = $this->makeConversation();
        $conversation->update(['is_processing' => true]);
        $message = $this->pausedConfirmationMessage($conversation);

        $mock = $this->bindMockedDelegationService();
        $mock->shouldNotReceive('delegateBatch');
        $mock->shouldNotReceive('delegate');

        $service = $this->serviceWithScriptedProvider([
            $this->toolCallReply([$this->toolCall('list_applications', [], 'call_list')]),
            $this->plainReply('Here are the applications.'),
        ]);
        $this->app->instance(AgentLoopService::class, $service);

        // Declined -- resumeSync() still continues the loop with the model
        // told the call was refused (EntryPathCoverageJourneyTest's own
        // established precedent), never reaching a real execute_operation
        // call, so no operation catalog/MCP session fixture is needed
        // anywhere in this file.
        $result = $service->resumeSync($conversation, $message, false);

        $this->assertSame('completed', $result['status'] ?? null, 'fixture sanity: the continuation must complete');
    }

    #[Test]
    public function resume_sync_with_two_delegate_to_helper_calls_in_the_continuation_calls_delegate_batch_exactly_once(): void
    {
        $conversation = $this->makeConversation();
        $conversation->update(['is_processing' => true]);
        $message = $this->pausedConfirmationMessage($conversation);

        $mock = $this->bindMockedDelegationService();
        $mock->shouldNotReceive('delegate');
        $mock->shouldReceive('delegateBatch')
            ->once()
            ->with(
                Mockery::on(fn ($c) => $c->id === $conversation->id),
                Mockery::on(function (array $calls) {
                    return count($calls) === 2
                        && ($calls[0]['tool_call_id'] ?? null) === 'call_a'
                        && ($calls[0]['helper_agent_id'] ?? null) === 'helper-a'
                        && ($calls[1]['tool_call_id'] ?? null) === 'call_b'
                        && ($calls[1]['helper_agent_id'] ?? null) === 'helper-b';
                }),
            )
            ->andReturn([
                'call_b' => $this->sixFieldResult('del-b', 'Helper B', 'Result B.'),
                'call_a' => $this->sixFieldResult('del-a', 'Helper A', 'Result A.'),
            ]);

        $service = $this->serviceWithScriptedProvider([
            $this->toolCallReply([
                $this->toolCall('delegate_to_helper', ['helper_agent_id' => 'helper-a', 'task' => 'Task A.', 'context' => 'Context A.'], 'call_a'),
                $this->toolCall('list_applications', [], 'call_list'),
                $this->toolCall('delegate_to_helper', ['helper_agent_id' => 'helper-b', 'task' => 'Task B.', 'context' => 'Context B.'], 'call_b'),
            ]),
            $this->plainReply('Both delegations are complete.'),
        ]);
        $this->app->instance(AgentLoopService::class, $service);

        $result = $service->resumeSync($conversation, $message, false);

        $this->assertSame('completed', $result['status'] ?? null, 'fixture sanity: the continuation must complete');

        // The declined confirmation's own message is UPDATED in place
        // (resumeSync() calls $message->update(), not Message::create())
        // -- so ordering by created_at ascending, the batch iteration's
        // own row (genuinely created later) is the LAST tool_data message
        // this continuation produces.
        $messages = Message::where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->whereNotNull('tool_data')
            ->orderBy('created_at')
            ->get();
        $batchMessage = $messages->last();

        $this->assertNotNull($batchMessage);
        $results = $batchMessage->tool_data['tool_results'];
        $this->assertCount(3, $results);

        $this->assertSame('call_a', $results[0]['tool_call_id']);
        $this->assertSame('Result A.', json_decode($results[0]['content'], true)['summary'] ?? null);
        $this->assertSame('call_list', $results[1]['tool_call_id']);
        $this->assertSame('call_b', $results[2]['tool_call_id']);
        $this->assertSame('Result B.', json_decode($results[2]['content'], true)['summary'] ?? null);
    }
}
