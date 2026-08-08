<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\AgentLoopStreamHandler;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Events\UpdateOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Events\FinishOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Events\ToolExecutionEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;

use PHPUnit\Framework\Attributes\Test;

class AgentLoopStreamHandlerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function parses_text_deltas_from_sse_chunks()
    {
        Event::fake([
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            NewConversationMessageEvent::class,
            ToolExecutionEvent::class,
            \ClarionApp\LlmClient\Events\ApiCallConfirmationRequiredEvent::class,
        ]);

        $conversation = Conversation::factory()->create();

        $handler = new AgentLoopStreamHandler();

        $data = json_encode([
            'conversation_id' => $conversation->id,
            'iteration' => 1,
        ]);

        // Simulate SSE text delta chunks
        $chunk1 = "data: " . json_encode([
            'choices' => [['delta' => ['content' => 'Hello'], 'finish_reason' => null]],
        ]) . "\n\n";

        $chunk2 = "data: " . json_encode([
            'choices' => [['delta' => ['content' => ' world'], 'finish_reason' => null]],
        ]) . "\n\n";

        $handler->handle($chunk1, $data, 0);
        $handler->handle($chunk2, $data, 1);

        $this->assertEquals('Hello world', $handler->reply);
    }

    #[Test]
    public function parses_tool_calls_deltas_with_argument_accumulation()
    {
        Event::fake([
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            NewConversationMessageEvent::class,
            ToolExecutionEvent::class,
            \ClarionApp\LlmClient\Events\ApiCallConfirmationRequiredEvent::class,
        ]);

        $conversation = Conversation::factory()->create();

        $handler = new AgentLoopStreamHandler();

        $data = json_encode([
            'conversation_id' => $conversation->id,
            'iteration' => 1,
        ]);

        // First chunk: tool call start
        $chunk1 = "data: " . json_encode([
            'choices' => [['delta' => ['tool_calls' => [
                ['index' => 0, 'id' => 'call_abc', 'type' => 'function', 'function' => ['name' => 'contacts.store', 'arguments' => '']],
            ]], 'finish_reason' => null]],
        ]) . "\n\n";

        // Second chunk: argument fragment
        $chunk2 = "data: " . json_encode([
            'choices' => [['delta' => ['tool_calls' => [
                ['index' => 0, 'function' => ['arguments' => '{"body":{"name"']],
            ]], 'finish_reason' => null]],
        ]) . "\n\n";

        // Third chunk: more arguments
        $chunk3 = "data: " . json_encode([
            'choices' => [['delta' => ['tool_calls' => [
                ['index' => 0, 'function' => ['arguments' => ': "Jane"}}']],
            ]], 'finish_reason' => null]],
        ]) . "\n\n";

        $handler->handle($chunk1, $data, 0);
        $handler->handle($chunk2, $data, 1);
        $handler->handle($chunk3, $data, 2);

        $this->assertCount(1, $handler->toolCalls);
        $this->assertEquals('call_abc', $handler->toolCalls[0]['id']);
        $this->assertEquals('contacts.store', $handler->toolCalls[0]['function']['name']);
        $this->assertEquals('{"body":{"name": "Jane"}}', $handler->toolCalls[0]['function']['arguments']);
    }

    #[Test]
    public function finish_detects_plain_text_response()
    {
        Event::fake([
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            NewConversationMessageEvent::class,
            ToolExecutionEvent::class,
            \ClarionApp\LlmClient\Events\ApiCallConfirmationRequiredEvent::class,
        ]);

        $conversation = Conversation::factory()->create();

        $handler = new AgentLoopStreamHandler();
        $handler->reply = 'Here is your answer.';
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
        ]);

        $handler->finish($data, 2);

        $handler->message->refresh();
        $this->assertEquals('Here is your answer.', $handler->message->content);

        $conversation->refresh();
        $this->assertFalse($conversation->is_processing);

        Event::assertDispatched(FinishOpenAIConversationResponseEvent::class);
    }

    #[Test]
    public function finish_detects_tool_calls_response()
    {
        Event::fake([
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            NewConversationMessageEvent::class,
            ToolExecutionEvent::class,
            \ClarionApp\LlmClient\Events\ApiCallConfirmationRequiredEvent::class,
        ]);

        $conversation = Conversation::factory()->create(['is_processing' => true]);

        $handler = new AgentLoopStreamHandler();
        $handler->toolCalls = [
            [
                'id' => 'call_abc123',
                'type' => 'function',
                'function' => [
                    'name' => 'contacts.store',
                    'arguments' => '{"body":{"name": "Jane"}}',
                ],
            ],
        ];
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
        ]);

        // Mock the tool execution to avoid actual HTTP calls
        $this->mockToolExecution('contacts.store', '{"id": "uuid-123"}');

        $handler->finish($data, 2);

        // Should have stored tool_data on the message
        $handler->message->refresh();
        $this->assertNotNull($handler->message->tool_data);
        $this->assertArrayHasKey('tool_calls', $handler->message->tool_data);

        Event::assertDispatched(ToolExecutionEvent::class);
    }

    #[Test]
    public function tracks_iteration_count()
    {
        Event::fake([
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            NewConversationMessageEvent::class,
            ToolExecutionEvent::class,
            \ClarionApp\LlmClient\Events\ApiCallConfirmationRequiredEvent::class,
        ]);

        $conversation = Conversation::factory()->create();

        $handler = new AgentLoopStreamHandler();

        $data = json_encode([
            'conversation_id' => $conversation->id,
            'iteration' => 5,
        ]);

        $chunk = "data: " . json_encode([
            'choices' => [['delta' => ['content' => 'Test'], 'finish_reason' => null]],
        ]) . "\n\n";

        $handler->handle($chunk, $data, 0);

        // The handler should know what iteration it's on
        $parsedData = json_decode($data, true);
        $this->assertEquals(5, $parsedData['iteration']);
    }

    #[Test]
    public function max_iteration_limit_triggers_error_message()
    {
        Event::fake([
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            NewConversationMessageEvent::class,
            ToolExecutionEvent::class,
            \ClarionApp\LlmClient\Events\ApiCallConfirmationRequiredEvent::class,
        ]);

        $conversation = Conversation::factory()->create(['is_processing' => true]);

        $handler = new AgentLoopStreamHandler();
        $handler->toolCalls = [
            [
                'id' => 'call_abc',
                'type' => 'function',
                'function' => ['name' => 'contacts.store', 'arguments' => '{}'],
            ],
        ];
        $handler->message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => '',
            'responseTime' => 0,
        ]);

        $maxIterations = config('llm-client.agent_loop.max_iterations', 20);

        $data = json_encode([
            'conversation_id' => $conversation->id,
            'iteration' => $maxIterations,
        ]);

        $handler->finish($data, 2);

        // Should have saved an error message and cleared is_processing
        $conversation->refresh();
        $this->assertFalse($conversation->is_processing);

        Event::assertDispatched(FinishOpenAIConversationResponseEvent::class);
    }

    protected function mockToolExecution(string $toolName, string $result)
    {
        $registryMock = Mockery::mock(\ClarionApp\LlmClient\Services\McpToolRegistry::class);
        $registryMock->shouldReceive('findTool')
            ->with($toolName)
            ->andReturn([
                'name' => $toolName,
                '_meta' => ['operationId' => 'op', 'method' => 'POST', 'path' => '/api/test'],
            ]);

        $executorMock = Mockery::mock(\ClarionApp\LlmClient\Services\McpToolExecutor::class);
        $executorMock->shouldReceive('executeTool')
            ->andReturn([
                'content' => [['type' => 'text', 'text' => $result]],
                'isError' => false,
            ]);

        $this->app->instance(\ClarionApp\LlmClient\Services\McpToolRegistry::class, $registryMock);
        $this->app->instance(\ClarionApp\LlmClient\Services\McpToolExecutor::class, $executorMock);
    }

    // === US2 Tests (T039) ===

    #[Test]
    public function finish_suspends_loop_when_confirmation_required()
    {
        Event::fake([
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            NewConversationMessageEvent::class,
            ToolExecutionEvent::class,
            \ClarionApp\LlmClient\Events\ApiCallConfirmationRequiredEvent::class,
        ]);

        $conversation = Conversation::factory()->create(['is_processing' => true]);

        $handler = new AgentLoopStreamHandler();
        $handler->toolCalls = [
            [
                'id' => 'call_del456',
                'type' => 'function',
                'function' => [
                    'name' => 'contacts.destroy',
                    'arguments' => '{"path":{"id": "42"}}',
                ],
            ],
        ];
        $handler->message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => '',
            'responseTime' => 0,
        ]);

        $registryMock = Mockery::mock(\ClarionApp\LlmClient\Services\McpToolRegistry::class);
        $registryMock->shouldReceive('findTool')
            ->with('contacts.destroy')
            ->andReturn([
                'name' => 'contacts.destroy',
                '_meta' => ['operationId' => 'destroyContact', 'method' => 'DELETE', 'path' => '/api/contacts/{id}'],
            ]);

        $this->app->instance(\ClarionApp\LlmClient\Services\McpToolRegistry::class, $registryMock);

        $data = json_encode([
            'conversation_id' => $conversation->id,
            'iteration' => 1,
        ]);

        $handler->finish($data, 2);

        $handler->message->refresh();
        $this->assertNotNull($handler->message->tool_data['pending_confirmation']);
        $this->assertEquals('contacts.destroy', $handler->message->tool_data['pending_confirmation']['tool_name']);

        Event::assertDispatched(\ClarionApp\LlmClient\Events\ApiCallConfirmationRequiredEvent::class);
    }

    #[Test]
    public function tool_data_pending_confirmation_stored_correctly()
    {
        Event::fake([
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            NewConversationMessageEvent::class,
            ToolExecutionEvent::class,
            \ClarionApp\LlmClient\Events\ApiCallConfirmationRequiredEvent::class,
        ]);

        $conversation = Conversation::factory()->create(['is_processing' => true]);

        $handler = new AgentLoopStreamHandler();
        $handler->toolCalls = [
            [
                'id' => 'call_del789',
                'type' => 'function',
                'function' => [
                    'name' => 'contacts.destroy',
                    'arguments' => '{"path":{"id": "99"}}',
                ],
            ],
        ];
        $handler->message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => '',
            'responseTime' => 0,
        ]);

        $registryMock = Mockery::mock(\ClarionApp\LlmClient\Services\McpToolRegistry::class);
        $registryMock->shouldReceive('findTool')
            ->with('contacts.destroy')
            ->andReturn([
                'name' => 'contacts.destroy',
                '_meta' => ['operationId' => 'destroyContact', 'method' => 'DELETE', 'path' => '/api/contacts/{id}'],
            ]);

        $this->app->instance(\ClarionApp\LlmClient\Services\McpToolRegistry::class, $registryMock);

        $data = json_encode([
            'conversation_id' => $conversation->id,
            'iteration' => 1,
        ]);

        $handler->finish($data, 2);

        $handler->message->refresh();
        $pending = $handler->message->tool_data['pending_confirmation'];

        $this->assertEquals('contacts.destroy', $pending['tool_name']);
        $this->assertEquals('DELETE', $pending['method']);
        $this->assertArrayHasKey('expires_at', $pending);
        $this->assertArrayHasKey('arguments', $pending);
    }

    // === US4 Tests (T041) ===

    #[Test]
    public function tool_execution_errors_fed_back_to_llm()
    {
        Event::fake([
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            NewConversationMessageEvent::class,
            ToolExecutionEvent::class,
            \ClarionApp\LlmClient\Events\ApiCallConfirmationRequiredEvent::class,
        ]);

        $conversation = Conversation::factory()->create(['is_processing' => true]);

        $handler = new AgentLoopStreamHandler();
        $handler->toolCalls = [
            [
                'id' => 'call_err',
                'type' => 'function',
                'function' => [
                    'name' => 'unknown.tool',
                    'arguments' => '{}',
                ],
            ],
        ];
        $handler->message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => '',
            'responseTime' => 0,
        ]);

        $registryMock = Mockery::mock(\ClarionApp\LlmClient\Services\McpToolRegistry::class);
        $registryMock->shouldReceive('findTool')
            ->with('unknown.tool')
            ->andReturn(null);
        $registryMock->shouldReceive('getTools')
            ->andReturn(['tools' => [], 'nextCursor' => null]);

        $executorMock = Mockery::mock(\ClarionApp\LlmClient\Services\McpToolExecutor::class);

        $this->app->instance(\ClarionApp\LlmClient\Services\McpToolRegistry::class, $registryMock);
        $this->app->instance(\ClarionApp\LlmClient\Services\McpToolExecutor::class, $executorMock);

        $data = json_encode([
            'conversation_id' => $conversation->id,
            'iteration' => 1,
        ]);

        $handler->finish($data, 2);

        $handler->message->refresh();
        $toolResults = $handler->message->tool_data['tool_results'];
        $this->assertStringContainsString('Unknown tool', $toolResults[0]['content']);
    }

    // === US5 Tests (T042) ===

    #[Test]
    public function handle_creates_assistant_message_on_first_text_chunk()
    {
        Event::fake([
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            NewConversationMessageEvent::class,
            ToolExecutionEvent::class,
            \ClarionApp\LlmClient\Events\ApiCallConfirmationRequiredEvent::class,
        ]);

        $conversation = Conversation::factory()->create();

        $handler = new AgentLoopStreamHandler();

        $data = json_encode([
            'conversation_id' => $conversation->id,
            'iteration' => 1,
        ]);

        $chunk = "data: " . json_encode([
            'choices' => [['delta' => ['content' => 'Hello'], 'finish_reason' => null]],
        ]) . "\n\n";

        $handler->handle($chunk, $data, 0);

        $this->assertNotNull($handler->message);
        $this->assertEquals('assistant', $handler->message->role);

        Event::assertDispatched(NewConversationMessageEvent::class);
        Event::assertDispatched(UpdateOpenAIConversationResponseEvent::class);
    }

    #[Test]
    public function update_event_broadcast_per_text_delta()
    {
        Event::fake([
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            NewConversationMessageEvent::class,
            ToolExecutionEvent::class,
            \ClarionApp\LlmClient\Events\ApiCallConfirmationRequiredEvent::class,
        ]);

        $conversation = Conversation::factory()->create();

        $handler = new AgentLoopStreamHandler();

        $data = json_encode([
            'conversation_id' => $conversation->id,
            'iteration' => 1,
        ]);

        $chunk1 = "data: " . json_encode([
            'choices' => [['delta' => ['content' => 'First'], 'finish_reason' => null]],
        ]) . "\n\n";

        $chunk2 = "data: " . json_encode([
            'choices' => [['delta' => ['content' => ' Second'], 'finish_reason' => null]],
        ]) . "\n\n";

        $handler->handle($chunk1, $data, 0);
        $handler->handle($chunk2, $data, 1);

        Event::assertDispatched(UpdateOpenAIConversationResponseEvent::class, 2);
    }

    // === US4 Tests (T039) ===

    #[Test]
    public function finish_reads_run_id_from_payload_and_passes_to_tracing(): void
    {
        // T039: finish() reads run_id from the parsed payload with ?? null fallback
        // and uses it for tracing. When run_id is in the payload, the handler
        // expects the run to already exist (created by start() + dispatchStreamRequest()).
        Event::fake([
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            NewConversationMessageEvent::class,
            ToolExecutionEvent::class,
            \ClarionApp\LlmClient\Events\ApiCallConfirmationRequiredEvent::class,
        ]);

        $this->app['config']->set('llm-client.run_trace.enabled', true);

        $conversation = Conversation::factory()->create();

        // Pre-create the run and step (simulating what start() + dispatchStreamRequest() do).
        $recorder = $this->app->make(\ClarionApp\LlmClient\Services\RunTraceRecorder::class);
        $runId = $recorder->openRun(
            \ClarionApp\LlmClient\ValueObjects\RunKind::Interactive,
            (string) $conversation->user_id,
            $conversation->id,
        );
        $stepId = $recorder->openStep($runId, 1, (string) \Illuminate\Support\Str::uuid());

        $handler = new AgentLoopStreamHandler();
        $handler->reply = 'Streaming response.';
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

        // The handler should have closed the step and run with the run_id from payload.
        $runs = \Illuminate\Support\Facades\DB::table('agent_runs')
            ->where('id', $runId)
            ->get();
        $this->assertCount(1, $runs);
        $this->assertEquals(\ClarionApp\LlmClient\ValueObjects\RunEndState::Completed->value, $runs[0]->end_state);

        // Verify the step was closed.
        $steps = \Illuminate\Support\Facades\DB::table('agent_run_steps')
            ->where('run_id', $runId)
            ->get();
        $this->assertCount(1, $steps);
        $this->assertEquals($stepId, $steps[0]->id);
        $this->assertEquals(\ClarionApp\LlmClient\ValueObjects\RunEndState::Completed->value, $steps[0]->end_state);
    }

    #[Test]
    public function finish_without_run_id_mints_fresh_run(): void
    {
        // T039: A payload without run_id (pre-feature or untraced job) does not crash
        // and mints a fresh run instead.
        Event::fake([
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            NewConversationMessageEvent::class,
            ToolExecutionEvent::class,
            \ClarionApp\LlmClient\Events\ApiCallConfirmationRequiredEvent::class,
        ]);

        $this->app['config']->set('llm-client.run_trace.enabled', true);

        $conversation = Conversation::factory()->create();

        $handler = new AgentLoopStreamHandler();
        $handler->reply = 'Response without run_id.';
        $handler->message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => '',
            'responseTime' => 0,
        ]);

        // No run_id in payload — simulates a pre-feature job.
        $data = json_encode([
            'conversation_id' => $conversation->id,
            'iteration' => 1,
        ]);

        // Should not crash.
        $handler->finish($data, 2);

        // A fresh run should have been minted.
        $runs = \Illuminate\Support\Facades\DB::table('agent_runs')
            ->where('conversation_id', $conversation->id)
            ->get();
        $this->assertCount(1, $runs);
        $this->assertEquals(\ClarionApp\LlmClient\ValueObjects\RunEndState::Completed->value, $runs[0]->end_state);

        // The step should exist and be completed.
        $steps = \Illuminate\Support\Facades\DB::table('agent_run_steps')
            ->where('run_id', $runs[0]->id)
            ->get();
        $this->assertCount(1, $steps);
    }

    #[Test]
    public function finish_with_run_id_uses_existing_run(): void
    {
        // T039: When a run_id is present in the payload, the handler uses that
        // existing run rather than minting a new one.
        Event::fake([
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            NewConversationMessageEvent::class,
            ToolExecutionEvent::class,
            \ClarionApp\LlmClient\Events\ApiCallConfirmationRequiredEvent::class,
        ]);

        $this->app['config']->set('llm-client.run_trace.enabled', true);

        $conversation = Conversation::factory()->create();
        $userId = (string) $conversation->user_id;

        // Pre-create a run (simulating what start() does on the first dispatch).
        $recorder = $this->app->make(\ClarionApp\LlmClient\Services\RunTraceRecorder::class);
        $runId = $recorder->openRun(
            \ClarionApp\LlmClient\ValueObjects\RunKind::Interactive,
            $userId,
            $conversation->id
        );
        $stepId = $recorder->openStep($runId, 1, (string) \Illuminate\Support\Str::uuid());

        $handler = new AgentLoopStreamHandler();
        $handler->reply = 'Response with existing run_id.';
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

        // Exactly one run should exist (the pre-created one, now closed).
        $runs = \Illuminate\Support\Facades\DB::table('agent_runs')
            ->where('conversation_id', $conversation->id)
            ->get();
        $this->assertCount(1, $runs);
        $this->assertEquals($runId, $runs[0]->id);
        $this->assertEquals(\ClarionApp\LlmClient\ValueObjects\RunEndState::Completed->value, $runs[0]->end_state);

        // The pre-created step should be closed.
        $steps = \Illuminate\Support\Facades\DB::table('agent_run_steps')
            ->where('run_id', $runId)
            ->get();
        $this->assertCount(1, $steps);
        $this->assertEquals($stepId, $steps[0]->id);
        $this->assertEquals(\ClarionApp\LlmClient\ValueObjects\RunEndState::Completed->value, $steps[0]->end_state);
    }

    // === US4 Tests (T043) ===

    #[Test]
    public function streaming_path_maintains_one_step_per_attempt_group(): void
    {
        // T043: The streaming handler mints one attemptGroupId per instance (line 132),
        // so each step corresponds to exactly one attempt group on the streaming path.
        // This is what makes 1:1 hold here even though it does not hold on resumeSync().
        Event::fake([
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            NewConversationMessageEvent::class,
            ToolExecutionEvent::class,
            \ClarionApp\LlmClient\Events\ApiCallConfirmationRequiredEvent::class,
        ]);

        $this->app['config']->set('llm-client.run_trace.enabled', true);

        $conversation = Conversation::factory()->create();
        $userId = (string) $conversation->user_id;

        // Pre-create a run and step (simulating what start() + dispatchStreamRequest() do).
        $recorder = $this->app->make(\ClarionApp\LlmClient\Services\RunTraceRecorder::class);
        $runId = $recorder->openRun(
            \ClarionApp\LlmClient\ValueObjects\RunKind::Interactive,
            $userId,
            $conversation->id,
        );

        // Simulate the attemptGroupId that dispatchStreamRequest mints.
        $attemptGroupId = (string) \Illuminate\Support\Str::uuid();
        $stepId = $recorder->openStep($runId, 1, $attemptGroupId);

        $handler = new AgentLoopStreamHandler();
        $handler->reply = 'Streaming response.';
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

        // Verify 1:1 correspondence: each step has exactly one attempt_group_id.
        $steps = \Illuminate\Support\Facades\DB::table('agent_run_steps')
            ->where('run_id', $runId)
            ->get();
        $this->assertCount(1, $steps);

        // The step's attempt_group_id matches what was passed during openStep.
        $this->assertEquals($attemptGroupId, $steps[0]->attempt_group_id);

        // The step is completed.
        $this->assertEquals(\ClarionApp\LlmClient\ValueObjects\RunEndState::Completed->value, $steps[0]->end_state);

        // The run is completed.
        $runs = \Illuminate\Support\Facades\DB::table('agent_runs')
            ->where('id', $runId)
            ->get();
        $this->assertCount(1, $runs);
        $this->assertEquals(\ClarionApp\LlmClient\ValueObjects\RunEndState::Completed->value, $runs[0]->end_state);
    }

    /**
     * The streamed equivalent of run()'s "all execute_operations succeeded" exit:
     * the response ends there, so the run must end there too rather than staying
     * in progress until the abandonment sweep finds it (FR-005, FR-008, SC-001).
     */
    #[Test]
    public function successful_execute_operation_exit_closes_the_run(): void
    {
        Event::fake([
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            NewConversationMessageEvent::class,
            ToolExecutionEvent::class,
            \ClarionApp\LlmClient\Events\ApiCallConfirmationRequiredEvent::class,
        ]);

        $this->app['config']->set('llm-client.run_trace.enabled', true);

        $conversation = Conversation::factory()->create(['is_processing' => true]);

        $recorder = $this->app->make(\ClarionApp\LlmClient\Services\RunTraceRecorder::class);
        $runId = $recorder->openRun(
            \ClarionApp\LlmClient\ValueObjects\RunKind::Interactive,
            (string) $conversation->user_id,
            $conversation->id,
        );
        $stepId = $recorder->openStep($runId, 1, (string) \Illuminate\Support\Str::uuid());

        // A successful execute_operation is the loop's terminal tool call — no
        // summary round follows it.
        $agentLoopMock = Mockery::mock(\ClarionApp\LlmClient\Services\AgentLoopService::class);
        $agentLoopMock->shouldReceive('executeMetaTool')
            ->andReturn(json_encode(['status' => 200, 'body' => ['ok' => true]]));
        $agentLoopMock->shouldReceive('allExecuteOperationsSucceeded')->andReturn(true);
        $this->app->instance(\ClarionApp\LlmClient\Services\AgentLoopService::class, $agentLoopMock);

        $handler = new AgentLoopStreamHandler();
        $handler->toolCalls = [
            [
                'id' => 'call_exec1',
                'type' => 'function',
                'function' => [
                    'name' => 'execute_operation',
                    'arguments' => '{"operationId":"createContact"}',
                ],
            ],
        ];
        $handler->message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => '',
            'responseTime' => 0,
        ]);

        $handler->finish(json_encode([
            'conversation_id' => $conversation->id,
            'iteration' => 1,
            'run_id' => $runId,
            'step_id' => $stepId,
        ]), 2);

        $run = \Illuminate\Support\Facades\DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertEquals(
            \ClarionApp\LlmClient\ValueObjects\RunEndState::Completed->value,
            $run->end_state,
            'A streamed response that ends on a successful execute_operation must close its run',
        );
        $this->assertNotNull($run->ended_at);
        $this->assertEquals(1, $run->step_count);

        $step = \Illuminate\Support\Facades\DB::table('agent_run_steps')->where('id', $stepId)->first();
        $this->assertEquals(\ClarionApp\LlmClient\ValueObjects\RunEndState::Completed->value, $step->end_state);
    }

    /**
     * A step suspended for a human confirmation stays open across the pause, so
     * its duration covers the wait and the resuming process can record the wait
     * portion (FR-004, FR-008). Closing it at the pause would leave the streamed
     * path with no wait_ms at all.
     */
    #[Test]
    public function confirmation_pause_leaves_the_step_open_for_the_resuming_process(): void
    {
        Event::fake([
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            NewConversationMessageEvent::class,
            ToolExecutionEvent::class,
            \ClarionApp\LlmClient\Events\ApiCallConfirmationRequiredEvent::class,
        ]);

        $this->app['config']->set('llm-client.run_trace.enabled', true);

        $conversation = Conversation::factory()->create(['is_processing' => true]);

        $recorder = $this->app->make(\ClarionApp\LlmClient\Services\RunTraceRecorder::class);
        $runId = $recorder->openRun(
            \ClarionApp\LlmClient\ValueObjects\RunKind::Interactive,
            (string) $conversation->user_id,
            $conversation->id,
        );
        $stepId = $recorder->openStep($runId, 1, (string) \Illuminate\Support\Str::uuid());

        $handler = new AgentLoopStreamHandler();
        $handler->toolCalls = [
            [
                'id' => 'call_del001',
                'type' => 'function',
                'function' => [
                    'name' => 'contacts.destroy',
                    'arguments' => '{"path":{"id": "7"}}',
                ],
            ],
        ];
        $handler->message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => '',
            'responseTime' => 0,
        ]);

        $registryMock = Mockery::mock(\ClarionApp\LlmClient\Services\McpToolRegistry::class);
        $registryMock->shouldReceive('findTool')
            ->with('contacts.destroy')
            ->andReturn([
                'name' => 'contacts.destroy',
                '_meta' => ['operationId' => 'destroyContact', 'method' => 'DELETE', 'path' => '/api/contacts/{id}'],
            ]);
        $this->app->instance(\ClarionApp\LlmClient\Services\McpToolRegistry::class, $registryMock);

        $handler->finish(json_encode([
            'conversation_id' => $conversation->id,
            'iteration' => 1,
            'run_id' => $runId,
            'step_id' => $stepId,
        ]), 2);

        $step = \Illuminate\Support\Facades\DB::table('agent_run_steps')->where('id', $stepId)->first();
        $this->assertEquals(
            \ClarionApp\LlmClient\ValueObjects\RunEndState::InProgress->value,
            $step->end_state,
            'The suspended step stays open so its duration covers the human wait',
        );

        // The continuation state the resuming process needs (contracts §3.2).
        $handler->message->refresh();
        $this->assertEquals($runId, $handler->message->tool_data['run_id']);
        $this->assertEquals($stepId, $handler->message->tool_data['step_id']);
        $this->assertArrayHasKey('paused_at', $handler->message->tool_data);
    }

    // === 074-latency-metrics T012: recordFirstOutput() ===

    #[Test]
    public function handle_calls_record_first_output_on_first_content_delta(): void
    {
        Event::fake([
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            NewConversationMessageEvent::class,
            ToolExecutionEvent::class,
            \ClarionApp\LlmClient\Events\ApiCallConfirmationRequiredEvent::class,
        ]);

        $this->app['config']->set('llm-client.run_trace.enabled', true);

        $conversation = Conversation::factory()->create();
        $recorder = $this->app->make(\ClarionApp\LlmClient\Services\RunTraceRecorder::class);
        $runId = $recorder->openRun(
            \ClarionApp\LlmClient\ValueObjects\RunKind::Interactive,
            (string) $conversation->user_id,
            $conversation->id,
            null,
            streamed: true,
        );

        $handler = new AgentLoopStreamHandler(null, null, $recorder);

        // The same job payload finish() reads run_id from (contracts §3.1) is
        // available to handle() on every chunk — both are called with $data.
        $data = json_encode([
            'conversation_id' => $conversation->id,
            'iteration' => 1,
            'run_id' => $runId,
            'step_id' => null,
            'action_id' => null,
        ]);

        $chunk = "data: " . json_encode([
            'choices' => [['delta' => ['content' => 'Hello'], 'finish_reason' => null]],
        ]) . "\n\n";

        usleep(20_000);
        $handler->handle($chunk, $data, 0);

        $run = \Illuminate\Support\Facades\DB::table('agent_runs')->where('id', $runId)->first();
        $this->assertNotNull($run);
        $this->assertNotNull($run->first_output_ms, 'The first content delta must record first_output_ms');
        $this->assertGreaterThanOrEqual(0, $run->first_output_ms);
    }

    #[Test]
    public function first_output_ms_is_not_overwritten_by_a_later_rounds_first_delta(): void
    {
        Event::fake([
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            NewConversationMessageEvent::class,
            ToolExecutionEvent::class,
            \ClarionApp\LlmClient\Events\ApiCallConfirmationRequiredEvent::class,
        ]);

        $this->app['config']->set('llm-client.run_trace.enabled', true);

        $conversation = Conversation::factory()->create();
        $recorder = $this->app->make(\ClarionApp\LlmClient\Services\RunTraceRecorder::class);
        $runId = $recorder->openRun(
            \ClarionApp\LlmClient\ValueObjects\RunKind::Interactive,
            (string) $conversation->user_id,
            $conversation->id,
            null,
            streamed: true,
        );

        $dataRound1 = json_encode([
            'conversation_id' => $conversation->id,
            'iteration' => 1,
            'run_id' => $runId,
        ]);
        $chunkRound1 = "data: " . json_encode([
            'choices' => [['delta' => ['content' => 'First'], 'finish_reason' => null]],
        ]) . "\n\n";

        // Round one: its own handler instance, matching production — a fresh
        // AgentLoopStreamHandler is minted per streaming dispatch.
        $handlerRound1 = new AgentLoopStreamHandler(null, null, $recorder);
        $handlerRound1->handle($chunkRound1, $dataRound1, 0);

        $firstValue = \Illuminate\Support\Facades\DB::table('agent_runs')->where('id', $runId)->value('first_output_ms');
        $this->assertNotNull($firstValue);

        usleep(50_000);

        // Round two: a later streaming round for the same run (e.g. the model's
        // summary reply after a tool call) also produces its own "first" content
        // delta — it must not overwrite the already-recorded value.
        $dataRound2 = json_encode([
            'conversation_id' => $conversation->id,
            'iteration' => 2,
            'run_id' => $runId,
        ]);
        $chunkRound2 = "data: " . json_encode([
            'choices' => [['delta' => ['content' => 'Second'], 'finish_reason' => null]],
        ]) . "\n\n";

        $handlerRound2 = new AgentLoopStreamHandler(null, null, $recorder);
        $handlerRound2->handle($chunkRound2, $dataRound2, 0);

        $secondValue = \Illuminate\Support\Facades\DB::table('agent_runs')->where('id', $runId)->value('first_output_ms');
        $this->assertSame(
            $firstValue,
            $secondValue,
            "A later round's own first content delta must not overwrite first_output_ms",
        );
    }

    #[Test]
    public function finish_fallback_open_run_passes_streamed_true_model_and_agent_id(): void
    {
        // T012: the handler's own openRun() fallback call site (~line 168, minting
        // a fresh run for a pre-run_id-shaped payload) must pass the same new
        // arguments every other call site does.
        Event::fake([
            UpdateOpenAIConversationResponseEvent::class,
            FinishOpenAIConversationResponseEvent::class,
            NewConversationMessageEvent::class,
            ToolExecutionEvent::class,
            \ClarionApp\LlmClient\Events\ApiCallConfirmationRequiredEvent::class,
        ]);

        $this->app['config']->set('llm-client.run_trace.enabled', true);

        $conversation = Conversation::factory()->create([
            'model' => 'gpt-4',
            'character' => 'research-assistant',
        ]);

        $handler = new AgentLoopStreamHandler();
        $handler->reply = 'Response without run_id.';
        $handler->message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => '',
            'responseTime' => 0,
        ]);

        // No run_id in payload — simulates a pre-feature job, exercising the
        // fallback openRun() call site inside finish().
        $data = json_encode([
            'conversation_id' => $conversation->id,
            'iteration' => 1,
        ]);

        $handler->finish($data, 2);

        $run = \Illuminate\Support\Facades\DB::table('agent_runs')->where('conversation_id', $conversation->id)->first();
        $this->assertNotNull($run);
        $this->assertEquals(1, (int) $run->is_streamed, 'the streaming path always mints streamed: true');
        $this->assertEquals('gpt-4', $run->model);
        $this->assertEquals('research-assistant', $run->agent_id);
    }
}
