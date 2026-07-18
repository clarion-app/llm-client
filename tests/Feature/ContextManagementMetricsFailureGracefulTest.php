<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\McpToolExecutor;
use ClarionApp\LlmClient\Services\McpToolRegistry;
use ClarionApp\LlmClient\Services\MetricsRecorder;
use ClarionApp\LlmClient\Services\OperationCache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

/**
 * T022: A metrics storage failure must never reach the conversation.
 *
 * The unit tests cover that MetricsRecorder swallows and logs its own exceptions. This
 * covers the property operators actually depend on (FR-006, SC-005): with the metrics
 * table gone, a real request driven through AgentLoopService still returns a normal
 * response, and the miss is logged.
 */
class ContextManagementMetricsFailureGracefulTest extends TestCase
{
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
                return (int) ceil(strlen($text));
            }

            public function listModels(): array
            {
                return ['models' => []];
            }
        };
    }

    private function makeService(LlmProvider $provider): AgentLoopService
    {
        $registry = app(ProviderRegistry::class);
        $registry->register('openai', fn ($server) => $provider);

        return new AgentLoopService(
            Mockery::mock(McpToolRegistry::class),
            Mockery::mock(McpToolExecutor::class),
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

        return Conversation::factory()->create([
            'is_processing' => false,
            'server_id' => $server->id,
            'title' => 'Test conversation',
        ]);
    }

    private function textResponse(string $content): array
    {
        return [
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30],
        ];
    }

    /** Metrics tables renamed aside for the duration of a test, restored in tearDown. */
    private array $hiddenTables = [];

    protected function tearDown(): void
    {
        // Restore anything hidden by makeMetricsStorageUnavailable(). The test schema is
        // built once and guarded by hasTable(), so leaving these renamed would silently
        // break every later test in the process.
        foreach ($this->hiddenTables as $original) {
            if (Schema::hasTable("{$original}__hidden") && !Schema::hasTable($original)) {
                Schema::rename("{$original}__hidden", $original);
            }
        }
        $this->hiddenTables = [];

        parent::tearDown();
    }

    /**
     * Simulate metrics storage being unavailable by renaming the tables aside, so every
     * write the recorder attempts throws. Reversible, unlike dropping them.
     */
    private function makeMetricsStorageUnavailable(): void
    {
        foreach (['context_management_records', 'context_management_summaries'] as $table) {
            if (Schema::hasTable($table)) {
                Schema::rename($table, "{$table}__hidden");
                $this->hiddenTables[] = $table;
            }
        }
    }

    #[Test]
    public function request_succeeds_and_logs_when_metrics_storage_is_unavailable()
    {
        config([
            'llm-client.condensation.enabled' => false,
            'llm-client.context_window.providers.openai.context' => 128000,
        ]);

        $this->makeMetricsStorageUnavailable();

        $warnings = [];
        Log::listen(function ($log) use (&$warnings) {
            if ($log->level === 'warning') {
                $warnings[] = $log->message;
            }
        });

        $conversation = $this->makeConversation();
        $service = $this->makeService($this->fakeProvider([
            $this->textResponse('Hello there.'),
        ]));

        // The request must complete normally despite metrics being unwritable.
        $result = $service->run($conversation, 'Hi');

        $this->assertNotNull($result, 'Request must still return a result when metrics storage fails');

        $conversation->refresh();
        $assistantReplies = $conversation->messages()
            ->where('role', 'assistant')
            ->pluck('content')
            ->all();
        $this->assertNotEmpty(
            array_filter($assistantReplies, fn ($c) => str_contains((string) $c, 'Hello there.')),
            'The conversation response must be unaffected by the metrics failure'
        );

        // FR-007: the miss is visible to operators.
        $this->assertNotEmpty(
            array_filter($warnings, fn ($m) => str_contains($m, 'failed to record context management')),
            'A failed metric recording must be logged as a warning'
        );
    }
}
