<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\ToolInvocationRecord;
use ClarionApp\LlmClient\Models\UsageRecord;
use ClarionApp\LlmClient\Models\UsageSummary;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\MetricsRecorder;
use ClarionApp\LlmClient\Services\OperationCache;
use ClarionApp\LlmClient\ValueObjects\ToolFailureCategory;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

/**
 * Drives the real AgentLoopService loop (not the MetricsRecorder in isolation)
 * to prove usage and tool-outcome metrics are actually recorded end-to-end,
 * including tool *failures* — which the loop previously always recorded as success.
 */
class AgentLoopMetricsIntegrationTest extends TestCase
{
    /**
     * Build a fake LlmProvider that returns scripted chat() responses in order.
     *
     * @param array<int, array> $responses
     */
    private function fakeProvider(array $responses): LlmProvider
    {
        return new class($responses) implements LlmProvider {
            private int $i = 0;

            public function __construct(private array $responses) {}

            public function chat(array $messages, array $tools = [], array $options = []): array
            {
                $r = $this->responses[$this->i] ?? end($this->responses);
                $this->i++;
                return $r;
            }

            public function stream(array $messages, array $tools = [], array $options = []): \Generator
            {
                yield [];
            }

            public function embed(array $inputs, array $options = []): array
            {
                return ['embeddings' => []];
            }

            public function countTokens(string $text, ?string $model = null): int
            {
                return 0;
            }

            public function listModels(): array
            {
                return ['models' => []];
            }
        };
    }

    private function makeService(LlmProvider $provider, ?McpToolExecutor $executor = null): AgentLoopService
    {
        $registry = app(ProviderRegistry::class);
        $registry->register('openai', fn ($server) => $provider);

        return new AgentLoopService(
            Mockery::mock(McpToolRegistry::class),
            $executor ?? Mockery::mock(McpToolExecutor::class),
            new OperationCache(),
            $registry,
            metricsRecorder: new MetricsRecorder(),
        );
    }

    private function makeConversation(): Conversation
    {
        $server = Server::create([
            'name' => 'test',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'sk-test',
        ]);

        // title set so the loop skips the (HTTP) title-generation call.
        return Conversation::factory()->create([
            'is_processing' => false,
            'server_id' => $server->id,
            'title' => 'Test conversation',
        ]);
    }

    private function textResponse(string $content, array $usage = ['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30]): array
    {
        return [
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]],
            'usage' => $usage,
        ];
    }

    private function toolCallResponse(string $name, array $arguments): array
    {
        return [
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => [[
                        'id' => 'call_' . Str::random(6),
                        'type' => 'function',
                        'function' => ['name' => $name, 'arguments' => json_encode($arguments)],
                    ]],
                ],
            ]],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 5, 'total_tokens' => 10],
        ];
    }

    #[Test]
    public function run_records_usage_for_a_plain_text_turn()
    {
        $conversation = $this->makeConversation();
        $service = $this->makeService($this->fakeProvider([
            $this->textResponse('Hello there.'),
        ]));

        $result = $service->run($conversation, 'Hi');

        $this->assertEquals('completed', $result['status']);

        $usage = UsageRecord::forConversation($conversation->id)->first();
        $this->assertNotNull($usage, 'A usage record should be created by the real loop');
        $this->assertEquals(10, $usage->input_tokens);
        $this->assertEquals(20, $usage->output_tokens);
        $this->assertFalse((bool) $usage->input_estimated);

        $summary = UsageSummary::getConversationTotals($conversation->id);
        $this->assertNotNull($summary);
        $this->assertEquals(30, $summary->total_tokens);
        $this->assertEquals(1, $summary->request_count);
    }

    #[Test]
    public function run_records_tool_failure_with_category_not_hardcoded_success()
    {
        $conversation = $this->makeConversation();

        // Turn 1: model calls execute_operation with no operationId -> tool returns
        //         {"error":"operationId is required"} (a failure).
        // Turn 2: model produces a final text answer so the loop terminates.
        $service = $this->makeService($this->fakeProvider([
            $this->toolCallResponse('execute_operation', ['parameters' => []]),
            $this->textResponse('All done.'),
        ]));

        $result = $service->run($conversation, 'Do the thing');

        $this->assertEquals('completed', $result['status']);

        $tool = ToolInvocationRecord::forConversation($conversation->id)->first();
        $this->assertNotNull($tool, 'The loop must record the tool invocation');
        $this->assertEquals('failure', $tool->outcome, 'A failing tool must NOT be recorded as success');
        $this->assertEquals(ToolFailureCategory::InvalidInput, $tool->failure_category);
        $this->assertEquals('execute_operation', $tool->tool_name);

        // Usage should be recorded for both LLM calls in the turn, sharing intent.
        $this->assertEquals(2, UsageRecord::forConversation($conversation->id)->count());
    }

    #[Test]
    public function resume_sync_records_the_confirmed_operation()
    {
        // The curated test schema omits mcp_sessions; the confirmed-operation
        // path resolves an MCP session, so create the table it needs.
        if (!\Illuminate\Support\Facades\Schema::hasTable('mcp_sessions')) {
            \Illuminate\Support\Facades\Schema::create('mcp_sessions', function ($table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id');
                $table->string('protocol_version');
                $table->string('client_name')->nullable();
                $table->string('client_version')->nullable();
                $table->json('capabilities')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        $conversation = $this->makeConversation();

        $executor = Mockery::mock(McpToolExecutor::class);
        $executor->shouldReceive('extractArguments')
            ->andReturn(['path' => '/widgets', 'query' => [], 'body' => []]);
        $executor->shouldReceive('executeHttpCall')
            ->andReturn(['content' => [['text' => '{"ok":true}']]]);

        // After the confirmed call, the continuation loop asks the LLM again;
        // return a plain text answer to terminate.
        $service = $this->makeService($this->fakeProvider([
            $this->textResponse('Created the widget.'),
        ]), $executor);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => $conversation->character,
            'content' => '',
            'responseTime' => 0,
            'tool_data' => [
                'tool_calls' => [['id' => 'call_1', 'function' => ['name' => 'execute_operation', 'arguments' => '{}']]],
                'tool_results' => null,
                'iteration' => 1,
                'pending_confirmation' => [
                    'tool_name' => 'execute_operation',
                    'confirmation_type' => 'api_call',
                    'operationId' => 'createWidget',
                    'method' => 'POST',
                    'path' => '/widgets',
                    'arguments' => [],
                    'expires_at' => now()->addMinutes(5)->toIso8601String(),
                ],
            ],
        ]);

        $result = $service->resumeSync($conversation, $message, true);

        $this->assertEquals('completed', $result['status']);

        $tool = ToolInvocationRecord::forConversation($conversation->id)
            ->forToolName('execute_operation')
            ->first();
        $this->assertNotNull($tool, 'The confirmed operation must be recorded as a tool invocation');
        $this->assertEquals('success', $tool->outcome);
    }
}
