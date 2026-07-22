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
}
