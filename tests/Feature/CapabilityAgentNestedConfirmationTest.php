<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\ApiManager;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Models\Agent;
use ClarionApp\LlmClient\Models\CapabilityOffering;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Delegation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\AgentService;
use ClarionApp\LlmClient\Services\CapabilityOfferingService;
use ClarionApp\LlmClient\Services\DelegationQuery;
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
 * 109-agent-as-capability, Phase 7 (US5), tasks.md T045.
 *
 * Quickstart scenario 6 / contracts/capability-agent-call.md "Confirmation
 * raised from inside a nested capability-agent call". A dedicated,
 * standalone proof (distinct from CapabilityAgentPermissionCompositionTest's
 * T029(b), which confirms the installation-wide confirm_methods RULE still
 * fires through a capability-agent call): this file's own job is to prove
 * the RESULT SHAPE the caller sees is the ordinary plain {"error": "..."}
 * ExecuteOperation shape -- never a raw `__requires_confirmation` marker,
 * which means something only to a live, human-facing turn and must never
 * leak to a nested caller with no human present to answer it -- and that
 * the underlying Delegation row (origin: 'capability_offering') is
 * reconstructible afterward via the existing DelegationQuery surface
 * (FR-020).
 *
 * Driven end-to-end through the real, unmocked call chain --
 * AgentLoopService::run() (real) -> handleExecuteOperation() (real) ->
 * DelegationService::invokeAsCapability() (real) -> a nested run() for the
 * offered agent (real) -- with only the LlmProvider collaborator scripted,
 * mirroring CapabilityAgentCallJourneyTest.php's own established
 * research.md D1 convention. Belongs in tests/Feature/, never
 * tests/Integration/ (Grounding note 18).
 */
class CapabilityAgentNestedConfirmationTest extends TestCase
{
    private User $user;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->seedOperationCatalog([
            'op.confirm' => ['path' => '/api/op-confirm', 'method' => 'delete', 'summary' => 'Confirm-required op'],
        ]);

        $this->server = Server::create([
            'name' => 'Test Server',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'sk-test',
        ]);

        app(RoleAssignmentService::class)->set(ModelRole::Inference, $this->user->id, $this->server->id, 'test-model');

        config(['llm-client.confirm_methods' => ['DELETE']]);

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
        DB::table('agent_capability_offerings')->delete();
        DB::table('messages')->delete();
        if (Schema::hasTable('mcp_sessions')) {
            DB::table('mcp_sessions')->delete();
        }
        if (Schema::hasTable('usage_records')) {
            DB::table('usage_records')->delete();
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
    // Operation-catalog scaffolding (DelegationJourneyTest's own
    // established precedent)
    // -----------------------------------------------------------------

    private function seedOperationCatalog(array $operations = []): void
    {
        $paths = [];
        foreach ($operations as $operationId => $entry) {
            $paths[$entry['path']][$entry['method']] = [
                'operationId' => $operationId,
                'summary' => $entry['summary'],
            ];
        }
        $doc = ['paths' => $paths];

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

    private function makeAgentPermitting(string $name, array $operationIds): Agent
    {
        $allowLines = implode("\n", array_map(fn (string $id) => "    - {$id}", $operationIds));

        $yaml = <<<YAML
name: {$name}
instructions: I am {$name}.
tools:
  allow:
{$allowLines}
YAML;

        return app(AgentService::class)->create($this->user->id, $yaml);
    }

    private function offerCapability(Agent $offered, Agent $caller, string $name, string $description, string $inputDescription): CapabilityOffering
    {
        return app(CapabilityOfferingService::class)->offer(
            $this->user->id,
            $offered->id,
            $caller->id,
            $name,
            $description,
            $inputDescription,
        );
    }

    private function makeConversation(?Agent $agent): Conversation
    {
        return Conversation::factory()->create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'title' => 'Already titled',
            'agent_id' => $agent?->id,
            'agent_version_id' => $agent?->current_version_id,
        ]);
    }

    // -----------------------------------------------------------------
    // Scripted-provider scaffolding (DelegationJourneyTest's own
    // established precedent, research.md D1)
    // -----------------------------------------------------------------

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
            metricsRecorder: new \ClarionApp\LlmClient\Services\MetricsRecorder(),
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

    private function executeOperationCall(string $operationId, string $input, string $callId): array
    {
        return $this->toolCall('execute_operation', [
            'operationId' => $operationId,
            'parameters' => ['input' => $input],
        ], $callId);
    }

    private function firstToolResult(Conversation $conversation): ?array
    {
        $toolResultMessage = Message::where('conversation_id', $conversation->id)
            ->whereNotNull('tool_data')
            ->orderBy('created_at')
            ->first();

        if ($toolResultMessage === null) {
            return null;
        }

        $toolResults = $toolResultMessage->tool_data['tool_results'] ?? [];
        if (empty($toolResults)) {
            return null;
        }

        return json_decode($toolResults[0]['content'] ?? '', true);
    }

    // =================================================================
    // T045 -- the offered agent's only viable next operation requires
    // confirmation. It must never execute, the caller must see the
    // ordinary plain {"error": "..."} shape (never a raw
    // __requires_confirmation marker), and the underlying Delegation row
    // must be reconstructible afterward via DelegationQuery (FR-020).
    // =================================================================

    #[Test]
    public function a_confirmation_required_operation_inside_the_offered_agent_never_executes_and_the_caller_sees_the_ordinary_error_shape(): void
    {
        $callerA = $this->makeAgentPermitting('t045-caller-a', ['op.confirm']);
        $offeredB = $this->makeAgentPermitting('t045-offered-b', ['op.confirm']);

        $offering = $this->offerCapability(
            $offeredB,
            $callerA,
            'do_confirm_op_capability',
            'Attempts the confirm-required operation on behalf of the caller.',
            'What to attempt.',
        );

        $conversationA = $this->makeConversation($callerA);

        $service = $this->serviceWithScriptedProvider([
            // A's own turn: invoke B's capability.
            $this->toolCallReply([$this->executeOperationCall($offering->id, 'Please perform the confirm-required operation.', 'call_a_invoke_b_confirm')]),
            // B's own nested turn: attempt the confirm-required operation
            // -- must pause for confirmation, never execute. Exactly ONE
            // scripted response is ever consumed for B's own run() (it
            // returns immediately with status 'confirmation_required'),
            // mirroring SubagentToolRestrictionRuntimeJourneyTest's own
            // scenario 7 precedent for the delegate_to_helper path -- no
            // human is present inside a nested capability-agent call to
            // ever answer the confirmation, so B's own run must simply
            // stop here rather than wait or retry.
            $this->toolCallReply([$this->toolCall('execute_operation', ['operationId' => 'op.confirm', 'parameters' => []], 'call_b_attempt_confirm')]),
            // A's own finishing reply, after receiving B's translated
            // confirmation-required failure.
            $this->plainReply('B could not complete the task without confirmation.'),
        ]);
        $this->app->instance(AgentLoopService::class, $service);

        $result = $service->run($conversationA->fresh(), 'Please have B perform the confirm-required operation.');
        $this->assertSame('completed', $result['status'], 'fixture sanity: the top-level turn itself must complete');

        $helperConversationB = Conversation::whereHas('agent', fn ($q) => $q->where('id', $offeredB->id))
            ->latest('created_at')
            ->first();
        $this->assertNotNull($helperConversationB, 'fixture sanity: the offered agent\'s own nested conversation must exist');

        // No side effect occurred -- the operation was paused, never
        // executed. The confirm-gated tool call's own tool_results stays
        // null (not merely absent) -- it was never run.
        $pausedMessage = Message::where('conversation_id', $helperConversationB->id)
            ->where('role', 'assistant')
            ->whereNotNull('tool_data')
            ->orderBy('id')
            ->first();
        $this->assertNotNull($pausedMessage, 'fixture sanity: B must actually have attempted the confirm-required operation');
        $this->assertNotNull(
            $pausedMessage->tool_data['pending_confirmation'] ?? null,
            'the confirm-required operation must have paused for confirmation inside the offered agent\'s own nested run',
        );
        $this->assertArrayHasKey('tool_results', $pausedMessage->tool_data ?? []);
        $this->assertNull(
            $pausedMessage->tool_data['tool_results'],
            'the confirm-gated operation must never have produced a tool result -- confirming it was never executed, no side effect occurred',
        );

        // The caller sees the ordinary plain {"error": "..."} shape --
        // never a raw __requires_confirmation marker, which means
        // something only to a live, human-facing turn.
        $aToolMessage = Message::where('conversation_id', $conversationA->id)
            ->where('role', 'assistant')
            ->whereNotNull('tool_data')
            ->orderBy('id')
            ->first();
        $this->assertNotNull($aToolMessage);
        $aToolResult = $this->firstToolResult($conversationA);
        $this->assertNotNull($aToolResult, 'fixture sanity: A must actually have invoked the capability');
        $this->assertSame(
            ['error'],
            array_keys($aToolResult),
            'A must receive EXACTLY the plain {"error": "..."} shape -- never a raw __requires_confirmation marker and never the six-field delegation envelope',
        );
        $this->assertArrayNotHasKey(
            '__requires_confirmation',
            $aToolResult,
            'the raw confirmation marker must never leak to a nested caller with no human present to answer it',
        );
        $this->assertSame(
            'This action requires your explicit confirmation and could not be completed automatically.',
            $aToolResult['error'] ?? null,
            'contracts/capability-agent-call.md\'s own verbatim worked example',
        );

        // The underlying Delegation row records the pause distinctly and
        // is reconstructible afterward via the existing DelegationQuery
        // surface (FR-020) -- a run id/delegation id alone must resolve
        // it for its real owner.
        $delegationRow = Delegation::where('parent_conversation_id', $conversationA->id)->first();
        $this->assertNotNull($delegationRow, 'a Delegation row must still exist underneath for FR-020 reconstruction');
        $this->assertSame('capability_offering', $delegationRow->origin);
        $this->assertSame('failed', $delegationRow->status);
        $this->assertSame('failure', $delegationRow->result_status);
        $this->assertSame('confirmation_required', $delegationRow->result_reason);
        $this->assertNull($delegationRow->result_output, 'a confirmation-required stop must never carry a genuine-looking output');

        $reconstructed = app(DelegationQuery::class)->findDelegation((string) $this->user->id, $delegationRow->id);
        $this->assertNotNull($reconstructed, 'the confirmation-required Delegation row must be reconstructible via DelegationQuery for its real owner (FR-020)');
        $this->assertSame('capability_offering', $reconstructed->origin);
        $this->assertSame('confirmation_required', $reconstructed->result_reason);
    }
}
