<?php

namespace Tests\Integration;

use Tests\TestCase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use ClarionApp\HttpQueue\Jobs\SendHttpStreamRequest;
use Tests\Integration\Harness\ResponseScript;
use Tests\Integration\Harness\ScriptedTransport;
use Tests\Integration\Harness\ScriptedStream;
use Tests\Integration\Harness\DegradationLedger;
use Tests\Integration\Harness\DeterministicEmbedder;
use Tests\Integration\Harness\ConversationFixture;

abstract class AssembledSystemTestCase extends TestCase
{
    protected ?ResponseScript $script = null;
    protected ?ScriptedTransport $transport = null;
    protected ?ScriptedStream $stream = null;
    protected DegradationLedger $ledger;
    protected ?ConversationFixture $fixture = null;
    protected string $scenario = 'unnamed';
    protected string $entryPath = 'sync';

    protected function setUp(): void
    {
        parent::setUp();

        // Guard: database must be SQLite :memory:
        $dbConfig = config('database.connections.' . config('database.default'));
        if (config('database.default') !== 'sqlite' || ($dbConfig['database'] ?? null) !== ':memory:') {
            $this->fail('Integration tests require SQLite :memory: database. Current: ' . config('database.default'));
        }

        // Flush cache
        Cache::flush();

        $this->createPackageMigratedTables();

        // Arm the ledger before anything can degrade, so no signal is missed.
        $this->ledger = new DegradationLedger();
        $this->ledger->arm();

        // Initialize script and transport. Embeddings are served by default
        // (contract C4 "available" mode) at the dimension the product is
        // configured for; scenarios opt into failure via the fixture's
        // withEmbeddingsDisabled().
        $this->script = new ResponseScript();
        $this->transport = new ScriptedTransport(
            $this->script,
            new DeterministicEmbedder((int) config('llm-client.memory.embedding.dimension', 1536))
        );

        // Bind the handler BEFORE any provider resolves
        $this->app->bind('llm-client.http_handler', fn () => $this->transport->handlerStack());

        // Fake queue for stream jobs
        Queue::fake([SendHttpStreamRequest::class]);

        $this->stream = new ScriptedStream();
    }

    protected function tearDown(): void
    {
        // Reconcile ledger
        if ($this->ledger) {
            $this->ledger->reconcile($this->scenario, $this->entryPath);
        }

        // Assert no unconsumed steps
        if ($this->script && $this->script->hasUnconsumedSteps()) {
            $unconsumedCount = $this->script->unconsumedSteps();
            $this->fail(sprintf(
                'Unconsumed script steps in [%s/%s]: %d steps remaining.',
                $this->scenario,
                $this->entryPath,
                $unconsumedCount
            ));
        }

        parent::tearDown();
    }

    /**
     * Mirror the package migrations this suite depends on.
     *
     * These stores are part of the assembled system — episodic retrieval is one
     * of the fan-out routes, and condensation is one of the context-management
     * mechanisms — so without their tables every scenario degrades on those
     * routes instead of exercising them.
     *
     * They live here rather than in the shared TestCase deliberately: this suite
     * bootstraps schema instead of migrating (constitution §V), whereas suites
     * using RefreshDatabase do run the package migrations, and a table created
     * in the shared bootstrap collides with the migration that creates it.
     */
    private function createPackageMigratedTables(): void
    {
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
                // Mirrors 2026_06_28_000001_add_embedding_to_episodic_memories.php
                // (SQLite fallback branch: json). Without this column,
                // EpisodicMemory::update(['embedding' => ...]) — which
                // GenerateEpisodicMemoryJob::generateEmbedding() performs for
                // real once a summary is captured — fails at the SQL layer
                // ("no such column: embedding"), a genuine schema-bootstrap gap
                // this suite hadn't exercised before Story 4 (060) started
                // driving conversations all the way through a real end().
                $table->json('embedding')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index('user_id');
                $table->index('conversation_id');
                $table->index(['user_id', 'created_at']);
                $table->index(['user_id', 'protected']);
                $table->index('deleted_at');
            });
        }

        if (!Schema::hasTable('chunk_summaries')) {
            Schema::create('chunk_summaries', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('conversation_id')->index();
                $table->unsignedInteger('chunk_index');
                $table->string('source_hash', 64);
                $table->unsignedInteger('source_message_count');
                $table->json('summary');
                $table->unsignedInteger('summary_tokens')->nullable();
                $table->string('condensation_model')->nullable();
                $table->string('condensation_provider')->nullable();
                $table->timestamps();

                $table->unique(['conversation_id', 'chunk_index']);
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

        // mcp_sessions table (for MCP session tracking during tool execution).
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
                $table->index('deleted_at');
            });
        }

        // agent_runs table (for agent run trace).
        if (!Schema::hasTable('agent_runs')) {
            Schema::create('agent_runs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->enum('kind', ['interactive', 'system_initiated']);
                $table->uuid('user_id');
                $table->uuid('conversation_id')->nullable();
                $table->string('source', 64)->nullable();
                $table->enum('end_state', ['in_progress', 'completed', 'failed', 'stopped_early', 'abandoned'])->default('in_progress');
                $table->string('end_reason', 256)->nullable();
                $table->timestamp('started_at', 6);
                $table->timestamp('ended_at', 6)->nullable();
                $table->unsignedBigInteger('duration_ms')->nullable();
                $table->unsignedInteger('step_count')->default(0);
                $table->timestamp('created_at')->useCurrent();
                $table->boolean('is_streamed')->default(false);
                $table->unsignedBigInteger('first_output_ms')->nullable();
                $table->string('model', 128)->nullable();
                $table->string('agent_id', 255)->nullable();
                $table->unsignedBigInteger('model_wait_ms')->nullable();
                $table->unsignedBigInteger('tool_exec_ms')->nullable();
                $table->unsignedBigInteger('confirm_wait_ms')->nullable();
                $table->unsignedBigInteger('product_ms')->nullable();

                $table->index('conversation_id');
                $table->index(['user_id', 'started_at']);
                $table->index(['end_state', 'started_at']);
                $table->index(['model', 'started_at']);
                $table->index(['agent_id', 'started_at']);
            });
        }

        // agent_run_steps table (for agent run trace).
        if (!Schema::hasTable('agent_run_steps')) {
            Schema::create('agent_run_steps', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('run_id');
                $table->unsignedInteger('position');
                $table->uuid('attempt_group_id')->nullable();
                $table->enum('end_state', ['in_progress', 'completed', 'failed', 'stopped_early', 'abandoned'])->default('in_progress');
                $table->string('end_reason', 256)->nullable();
                $table->timestamp('started_at', 6);
                $table->timestamp('ended_at', 6)->nullable();
                $table->unsignedBigInteger('duration_ms')->nullable();
                $table->unsignedBigInteger('wait_ms')->nullable();
                $table->unsignedSmallInteger('attempt_count')->default(1);

                $table->unique(['run_id', 'position']);
                $table->index('attempt_group_id');
                $table->index(['run_id', 'started_at']);
            });
        }

        // agent_run_messages table (for agent run trace).
        if (!Schema::hasTable('agent_run_messages')) {
            Schema::create('agent_run_messages', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('run_id');
                $table->uuid('message_id');
                $table->enum('relation', ['trigger', 'reply']);
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['run_id', 'relation']);
                $table->index('message_id');
                $table->index('run_id');
            });
        }

        // agent_run_actions table (for agent step actions).
        if (!Schema::hasTable('agent_run_actions')) {
            Schema::create('agent_run_actions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('run_id');
                $table->uuid('step_id');
                $table->enum('action_type', ['llm_request', 'tool_invocation', 'context_reshape']);
                $table->string('target', 256)->nullable();
                $table->uuid('attempt_group_id')->nullable();
                $table->uuid('parent_action_id')->nullable();
                $table->enum('outcome', ['in_progress', 'awaiting_confirmation', 'success', 'failure', 'unfinished'])->default('in_progress');
                $table->string('failure_reason', 512)->nullable();
                $table->timestamp('paused_at', 6)->nullable();
                $table->timestamp('started_at', 6);
                $table->timestamp('ended_at', 6)->nullable();
                $table->unsignedBigInteger('duration_ms')->nullable();
                $table->text('content')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['run_id', 'started_at']);
                $table->index(['step_id', 'started_at']);
                $table->index('attempt_group_id');
                $table->index('parent_action_id');
                $table->index(['run_id', 'outcome']);
                $table->index(['action_type', 'started_at']);
                $table->index(['attempt_group_id', 'target']);
            });
        }
    }

    protected function fixture(): ConversationFixture
    {
        return $this->fixture ??= new ConversationFixture($this);
    }

    protected function script(): ResponseScript
    {
        return $this->script;
    }

    public function transport(): ScriptedTransport
    {
        return $this->transport;
    }

    public function ledger(): DegradationLedger
    {
        return $this->ledger;
    }

    protected function stream(): ScriptedStream
    {
        return $this->stream ??= new ScriptedStream();
    }

    protected function firstCapturedChatPayload()
    {
        return $this->capturedChatPayloads()[0] ?? false;
    }

    /**
     * Chat payloads from whichever entry path the scenario drove.
     *
     * The sync path reaches the boundary over Guzzle; the streaming path never
     * does — it dispatches a job instead. Merging both here is what lets a
     * payload assertion be written once and reused across the two (FR-007a).
     * Only one source is populated in a single-path scenario.
     *
     * @return \Tests\Integration\Harness\CapturedPayload[]
     */
    protected function capturedChatPayloads(): array
    {
        return array_merge(
            $this->transport->capturedChatPayloads(),
            $this->stream->extractDispatchedJobs()->capturedPayloads()
        );
    }

    /**
     * Extract the system prompt from a CapturedPayload.
     *
     * For Anthropic providers, the system prompt is in $payload->system.
     * For OpenAI providers, it's in the first message with role 'system'.
     * For streaming paths, it may be in $payload->system (set by dispatchStreamRequest).
     */
    protected function extractSystemPrompt(object $payload): string
    {
        // Check dedicated system field first (Anthropic / streaming)
        if (!empty($payload->system)) {
            return $payload->system;
        }

        // Fall back to first system message in messages array (OpenAI sync)
        foreach ($payload->messages as $message) {
            if (is_array($message) && ($message['role'] ?? '') === 'system') {
                return $message['content'] ?? '';
            }
        }

        return '';
    }

    /**
     * Build SSE chunks for a tool-call response.
     */
    protected function buildToolCallSseChunks(array $response): array
    {
        $toolCalls = $response['choices'][0]['message']['tool_calls'] ?? [];
        $chunks = [];

        foreach ($toolCalls as $tc) {
            // Tool call ID chunk
            $data = json_encode([
                'choices' => [
                    [
                        'delta' => [
                            'tool_calls' => [
                                [
                                    'index' => 0,
                                    'id' => $tc['id'],
                                    'type' => $tc['type'],
                                ],
                            ],
                        ],
                        'finish_reason' => null,
                    ],
                ],
            ]);
            $chunks[] = "data: {$data}\n\n";

            // Function name chunk
            $data = json_encode([
                'choices' => [
                    [
                        'delta' => [
                            'tool_calls' => [
                                [
                                    'index' => 0,
                                    'function' => [
                                        'name' => $tc['function']['name'],
                                    ],
                                ],
                            ],
                        ],
                        'finish_reason' => null,
                    ],
                ],
            ]);
            $chunks[] = "data: {$data}\n\n";

            // Function arguments chunk
            $data = json_encode([
                'choices' => [
                    [
                        'delta' => [
                            'tool_calls' => [
                                [
                                    'index' => 0,
                                    'function' => [
                                        'arguments' => $tc['function']['arguments'],
                                    ],
                                ],
                            ],
                        ],
                        'finish_reason' => null,
                    ],
                ],
            ]);
            $chunks[] = "data: {$data}\n\n";
        }

        // Final chunk with finish_reason
        $finishReason = $response['choices'][0]['finish_reason'] ?? 'tool_calls';
        $finalData = json_encode([
            'choices' => [
                [
                    'delta' => [],
                    'finish_reason' => $finishReason,
                ],
            ],
        ]);
        $chunks[] = "data: {$finalData}\n\n";

        return $chunks;
    }

    /**
     * Build SSE chunks from a scripted response (text content).
     */
    protected function buildSseChunks(array $response): array
    {
        $content = $response['choices'][0]['message']['content'] ?? '';
        $finishReason = $response['choices'][0]['finish_reason'] ?? 'stop';

        $chunks = [];
        $chunkSize = 10;
        for ($i = 0; $i < strlen($content); $i += $chunkSize) {
            $piece = substr($content, $i, $chunkSize);
            $data = json_encode([
                'choices' => [
                    [
                        'delta' => ['content' => $piece],
                        'finish_reason' => null,
                    ],
                ],
            ]);
            $chunks[] = "data: {$data}\n\n";
        }

        $finalData = json_encode([
            'choices' => [
                [
                    'delta' => [],
                    'finish_reason' => $finishReason,
                ],
            ],
        ]);
        $chunks[] = "data: {$finalData}\n\n";

        return $chunks;
    }
}
