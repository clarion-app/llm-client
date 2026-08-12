<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\AgentLoopStreamHandler;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Events\FinishOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\RateLimit;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\ConversationWorkCeilingService;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\Services\RunTraceRecorder;
use ClarionApp\LlmClient\ValueObjects\ConversationWorkScope;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use ClarionApp\LlmClient\ValueObjects\RunKind;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

/**
 * The feature's central scenario: a single LLM turn requests more tool
 * calls than a conversation's work ceiling allows, and the loop must stop
 * mid-batch, not merely between iterations. Left unanswered, a tool call
 * past the ceiling would leave the assistant message's tool_calls/
 * tool_results pairing incomplete — invalid for the next request against
 * either provider's chat-completions endpoint. Every not-yet-executed tool
 * call in that batch is therefore given a synthesized tool_result before
 * the loop performs its close-out.
 *
 * Proven independently at each of the four in-loop call sites, since a
 * fix wired into one does not imply it was wired into the others:
 *
 *  1. run()'s tool-call loop
 *  2. run()'s schema-validation retry branch (no tool calls to synthesize —
 *     this branch is reached only when the model's response carried none)
 *  3. resumeSync()'s continuation tool-call loop
 *  4. AgentLoopStreamHandler::handleToolCalls()'s tool-call loop
 */
class ConversationWorkMidBatchStopJourneyTest extends TestCase
{
    private const REFUSAL_TEXT = "This tool call was not executed: the conversation's per-response work ceiling was reached.";

    private User $user;
    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->server = Server::create([
            'name' => 'Test Server',
            'server_url' => 'http://localhost:11434',
            'provider_type' => 'llama_cpp',
        ]);
    }

    protected function tearDown(): void
    {
        DB::table('conversation_work_ceilings')->delete();
        DB::table('agent_runs')->delete();

        Mockery::close();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function newConversation(): Conversation
    {
        return Conversation::create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'character' => 'Clarion',
        ]);
    }

    private function declareConversationDefault(int $maxWorkUnits, int $windowSeconds): void
    {
        app(ConversationWorkCeilingService::class)->upsert(
            ConversationWorkScope::ConversationDefault,
            RateLimit::INSTALLATION_SCOPE_ID,
            ['max_work_units' => $maxWorkUnits, 'window_seconds' => $windowSeconds],
        );
    }

    private function toolCallBurst(int $count): array
    {
        $calls = [];
        for ($i = 1; $i <= $count; $i++) {
            $calls[] = [
                'id' => "call_{$i}",
                'type' => 'function',
                'function' => ['name' => 'list_applications', 'arguments' => '{}'],
            ];
        }

        return $calls;
    }

    private function serviceWithScriptedProvider(array $responses): AgentLoopService
    {
        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')->andReturn(...$responses);
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($t) => (int) ceil(strlen((string) $t) / 4));

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($provider);
        $registry->shouldReceive('resolveByType')->andReturn($provider);

        return new AgentLoopService(
            app(McpToolRegistry::class),
            app(McpToolExecutor::class),
            app(OperationCache::class),
            $registry,
            runTraceRecorder: config('llm-client.run_trace.enabled', false) ? app(RunTraceRecorder::class) : null,
        );
    }

    /**
     * A batch of $total tool calls against a ceiling of $maxWorkUnits: the
     * first $maxWorkUnits entries must be real tool_results, and every
     * entry after that must be the synthesized refusal.
     */
    private function assertCoherentMixedBatch(array $toolCalls, array $toolResults, int $maxWorkUnits): void
    {
        $this->assertCount(count($toolCalls), $toolResults, 'Every tool call must have a matching tool_result — none left unanswered');

        $toolCallIds = array_column($toolCalls, 'id');
        $resultIds = array_column($toolResults, 'tool_call_id');
        sort($toolCallIds);
        sort($resultIds);
        $this->assertSame($toolCallIds, $resultIds, 'Every tool_call_id must have exactly one matching tool_result');

        foreach ($toolResults as $index => $result) {
            if ($index < $maxWorkUnits) {
                $this->assertNotSame(
                    self::REFUSAL_TEXT,
                    $result['content'],
                    "Tool call at index {$index} is within the ceiling and must have actually executed"
                );
            } else {
                $this->assertSame(
                    self::REFUSAL_TEXT,
                    $result['content'],
                    "Tool call at index {$index} is past the ceiling and must be synthesized as not executed"
                );
            }
        }
    }

    // ---------------------------------------------------------------
    // Call site 1 — run()'s tool-call loop
    // ---------------------------------------------------------------

    #[Test]
    public function run_stops_mid_batch_and_synthesizes_results_for_every_unexecuted_call(): void
    {
        $this->declareConversationDefault(5, 60);
        $conversation = $this->newConversation();

        $service = $this->serviceWithScriptedProvider([
            ['choices' => [['message' => ['content' => '', 'tool_calls' => $this->toolCallBurst(7)]]]],
        ]);

        $result = $service->run($conversation, 'Do seven things in one go.');

        $this->assertSame('stopped', $result['status']);
        $this->assertSame('conversation_work_ceiling_reached', $result['code'] ?? null);
        $this->assertStringContainsString('work ceiling', $result['content']);

        $message = Message::where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->latest('created_at')
            ->first();

        $this->assertNotNull($message);
        $this->assertCount(7, $message->tool_data['tool_calls']);
        $this->assertCoherentMixedBatch($message->tool_data['tool_calls'], $message->tool_data['tool_results'], 5);

        $conversation->refresh();
        $this->assertFalse($conversation->is_processing, 'is_processing must be cleared on a work-ceiling stop');
    }

    /**
     * Unlike RateLimitGate, which has no user to charge and skips entirely
     * when a conversation has no owning user, ConversationWorkGate keys on
     * the conversation id alone and has no dependency on user_id being
     * present at all — a system-adjacent conversation with no owner is
     * still bound by whatever ceiling applies to it.
     */
    #[Test]
    public function a_conversation_with_no_owning_user_is_still_stopped_by_its_ceiling(): void
    {
        $this->declareConversationDefault(5, 60);

        $conversation = Conversation::create([
            'user_id' => null,
            'server_id' => $this->server->id,
            'model' => 'test-model',
            'character' => 'Clarion',
        ]);

        $service = $this->serviceWithScriptedProvider([
            ['choices' => [['message' => ['content' => '', 'tool_calls' => $this->toolCallBurst(7)]]]],
        ]);

        $result = $service->run($conversation, 'Do seven things in one go.');

        $this->assertSame('stopped', $result['status']);
        $this->assertSame('conversation_work_ceiling_reached', $result['code'] ?? null);

        $message = Message::where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->latest('created_at')
            ->first();

        $this->assertNotNull($message);
        $this->assertCoherentMixedBatch($message->tool_data['tool_calls'], $message->tool_data['tool_results'], 5);
    }

    /**
     * Call site 2 — run()'s schema-validation retry branch. This branch has
     * no tool calls in flight at all (T001's grounding: it is reached only
     * when the model's response carried none), so there is nothing to
     * synthesize a tool_result for — the stop is a plain content/status/code
     * refusal, exactly like the tool-call sites but without §3.3's mid-batch
     * mechanism.
     */
    #[Test]
    public function run_stops_the_schema_validation_retry_branch_once_the_ceiling_is_reached(): void
    {
        $this->declareConversationDefault(2, 60);
        $conversation = $this->newConversation();

        // Every scripted response is prose, never satisfying the schema, so
        // every iteration takes the retry branch — the third retry attempt
        // must be refused by the ceiling before max_schema_retries (5, set
        // deliberately larger than max_work_units) would ever exhaust it.
        $service = $this->serviceWithScriptedProvider([
            ['choices' => [['message' => ['content' => 'Prose, not JSON, attempt one.', 'tool_calls' => []]]]],
            ['choices' => [['message' => ['content' => 'Prose, not JSON, attempt two.', 'tool_calls' => []]]]],
            ['choices' => [['message' => ['content' => 'Prose, not JSON, attempt three.', 'tool_calls' => []]]]],
        ]);

        $schema = [
            'type' => 'object',
            'properties' => ['answer' => ['type' => 'string']],
            'required' => ['answer'],
        ];

        $result = $service->run($conversation, 'Answer in the required shape.', [
            'schema' => $schema,
            'retry_on_validation_failure' => true,
            'max_schema_retries' => 5,
        ]);

        $this->assertSame('stopped', $result['status']);
        $this->assertSame('conversation_work_ceiling_reached', $result['code'] ?? null);
        $this->assertStringContainsString('work ceiling', $result['content']);

        $conversation->refresh();
        $this->assertFalse($conversation->is_processing);
    }

    // ---------------------------------------------------------------
    // Call site 3 — resumeSync()'s continuation tool-call loop
    // ---------------------------------------------------------------

    #[Test]
    public function resume_sync_stops_mid_batch_and_synthesizes_results_for_every_unexecuted_call(): void
    {
        $this->declareConversationDefault(5, 60);
        $conversation = $this->newConversation();
        $conversation->update(['is_processing' => true]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => '',
            'user' => 'Clarion',
            'tool_data' => [
                'tool_calls' => [[
                    'id' => 'call_confirmed',
                    'function' => ['name' => 'execute_operation', 'arguments' => '{}'],
                ]],
                'iteration' => 1,
                'pending_confirmation' => [
                    'operationId' => 'contacts.destroy',
                    'method' => 'DELETE',
                    'path' => '/api/contacts/1',
                    'arguments' => [],
                    'expires_at' => now()->addMinutes(5)->toIso8601String(),
                ],
            ],
        ]);

        $service = $this->serviceWithScriptedProvider([
            ['choices' => [['message' => ['content' => '', 'tool_calls' => $this->toolCallBurst(7)]]]],
        ]);

        // Declined — no ApiManager/HTTP mocking needed to resolve the
        // confirmed operation itself; the interesting part is the
        // continuation loop's own tool-call burst.
        $result = $service->resumeSync($conversation, $message, false);

        $this->assertSame('stopped', $result['status']);
        $this->assertSame('conversation_work_ceiling_reached', $result['code'] ?? null);
        $this->assertStringContainsString('work ceiling', $result['content']);

        // Identified by the id the stop itself returned, not by "latest
        // created_at": the message being resumed is already a role
        // 'assistant' row (the paused confirmation turn), created in the
        // same test within the same created_at second as the new stop
        // message below it — an ambiguous tiebreak for ordering alone.
        $this->assertNotNull($result['message_id']);
        $lastMessage = Message::find($result['message_id']);

        $this->assertNotNull($lastMessage);
        $this->assertCount(7, $lastMessage->tool_data['tool_calls']);
        $this->assertCoherentMixedBatch($lastMessage->tool_data['tool_calls'], $lastMessage->tool_data['tool_results'], 5);

        $conversation->refresh();
        $this->assertFalse($conversation->is_processing);
    }

    // ---------------------------------------------------------------
    // Call site 4 — AgentLoopStreamHandler::handleToolCalls()
    // ---------------------------------------------------------------

    #[Test]
    public function the_streaming_handler_stops_mid_batch_and_synthesizes_results_for_every_unexecuted_call(): void
    {
        Event::fake([FinishOpenAIConversationResponseEvent::class]);

        $this->declareConversationDefault(5, 60);
        config(['llm-client.run_trace.enabled' => true]);

        $conversation = $this->newConversation();
        $conversation->update(['is_processing' => true]);

        $recorder = app(RunTraceRecorder::class);
        $runId = $recorder->openRun(RunKind::Interactive, (string) $conversation->user_id, $conversation->id);
        $stepId = $recorder->openStep($runId, 1, (string) \Illuminate\Support\Str::uuid());

        $handler = new AgentLoopStreamHandler(null, null, $recorder);
        $handler->toolCalls = $this->toolCallBurst(7);
        $handler->message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => '',
            'responseTime' => 0,
        ]);

        $data = json_encode([
            'conversation_id' => $conversation->id,
            'iteration' => 1,
            'run_id' => $runId,
            'step_id' => $stepId,
        ]);

        $handler->finish($data, 2);

        $handler->message->refresh();
        $toolResults = $handler->message->tool_data['tool_results'] ?? null;
        $this->assertNotNull($toolResults, 'The synthesized batch must be persisted on the message');
        $this->assertCoherentMixedBatch($handler->toolCalls, $toolResults, 5);

        $step = DB::table('agent_run_steps')->where('id', $stepId)->first();
        $this->assertSame(RunEndState::StoppedEarly->value, $step->end_state);

        $run = DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertSame(RunEndState::StoppedEarly->value, $run->end_state);

        Event::assertDispatched(FinishOpenAIConversationResponseEvent::class, function ($event) {
            return str_contains($event->reply, 'work ceiling');
        });

        $conversation->refresh();
        $this->assertFalse($conversation->is_processing);
    }
}
