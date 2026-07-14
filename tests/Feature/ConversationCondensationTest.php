<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\ChunkSummary;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Contracts\ProviderType;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\LlmClient\Services\ConversationCondenser;
use ClarionApp\LlmClient\Services\ChunkPartitioner;
use ClarionApp\LlmClient\Services\CondensationSummaryStore;
use ClarionApp\LlmClient\Services\ContextWindowBudgeter;
use ClarionApp\LlmClient\Presets\CondensationPreset;
use ClarionApp\LlmClient\Events\ConversationCondensed;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;

use PHPUnit\Framework\Attributes\Test;

/**
 * Integration tests for Conversation Condensation.
 *
 * Tests end-to-end condensation through AgentLoopService with actual
 * database interactions, provider resolution, and event dispatch.
 */
class ConversationCondensationTest extends TestCase
{
    private User $user;
    private Server $server;
    private Conversation $conversation;

    protected function defineDatabaseMigrations(): void
    {
        // Create all required tables manually to avoid migration conflicts.
        // Parent defineDatabaseMigrations() creates users, llm_memory_entries, declarative_memories.
        // We need to also create the core tables and condensation tables here.

        // users table
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        // llm_servers table
        Schema::create('llm_servers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('server_url');
            $table->string('provider_type')->nullable();
            $table->text('token')->nullable();
            $table->timestamps();
        });

        // conversations table
        Schema::create('conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('server_id');
            $table->string('title')->nullable();
            $table->string('model')->nullable();
            $table->string('character')->nullable();
            $table->string('provider_override')->nullable();
            $table->boolean('is_processing')->default(false);
            $table->string('channel')->nullable();
            $table->timestamps();
        });

        // messages table
        Schema::create('messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id');
            $table->string('role');
            $table->text('content');
            $table->unsignedInteger('token_count')->nullable();
            $table->json('tool_data')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // chunk_summaries table
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

        // condensation_states table
        Schema::create('condensation_states', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id')->unique();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('cooldown_until')->nullable();
            $table->timestamps();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Use array cache for tests
        $this->app->singleton('cache', function () {
            return new \Illuminate\Cache\CacheManager($this->app);
        });
        $this->app->singleton('cache.store', function ($app) {
            return $app['cache']->store('array');
        });

        // Enable condensation
        $this->app['config']->set('llm-client.condensation', [
            'enabled' => true,
            'chunk_size' => 4,
            'model' => 'gpt-4o-mini',
            'provider' => 'openai',
            'timeout_seconds' => 30,
            'failure_threshold' => 3,
            'cooldown_seconds' => 300,
            'prewarm' => false,
        ]);

        $this->user = User::factory()->create();

        $this->server = Server::create([
            'name' => 'Test Server',
            'server_url' => 'https://api.openai.com',
            'provider_type' => 'openai',
            'token' => 'test-token',
        ]);

        $this->conversation = Conversation::create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'title' => 'Test Conversation',
            'model' => 'gpt-4',
            'character' => 'Clarion',
        ]);
    }

    protected function tearDown(): void
    {
        // Clean up test data
        \Illuminate\Support\Facades\DB::table('messages')->delete();
        \Illuminate\Support\Facades\DB::table('chunk_summaries')->delete();
        \Illuminate\Support\Facades\DB::table('condensation_states')->delete();
        \Illuminate\Support\Facades\DB::table('conversations')->delete();
        \Illuminate\Support\Facades\DB::table('llm_servers')->delete();

        Mockery::close();

        parent::tearDown();
    }

    #[Test]
    public function synchronous_condensation_fires_event_with_correct_data()
    {
        Event::fake(ConversationCondensed::class);

        // Create enough messages to trigger condensation (8 messages, chunk_size=4)
        $messages = [];
        for ($i = 0; $i < 8; $i++) {
            $messages[] = Message::create([
                'conversation_id' => $this->conversation->id,
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'content' => "Message number {$i} with some content",
                'token_count' => 10,
            ]);
        }

        // Mock the condensation provider
        $summaryJson = '{"decisions":["Use canary deployments"],"constraints":["Must be zero-downtime"],"open_questions":["Rollback strategy?"],"facts":["8 messages exchanged"],"commitments":["Deploy by Friday"],"context":"Discussion about deployment strategies"}';

        $condensationProvider = \Mockery::mock(LlmProvider::class);
        $condensationProvider->shouldReceive('chat')
            ->once()
            ->andReturn([
                'choices' => [['message' => ['content' => $summaryJson]]],
                'usage' => [
                    'prompt_tokens' => 50,
                    'completion_tokens' => 30,
                    'total_tokens' => 80,
                ],
            ]);

        $condensationProvider->shouldReceive('countTokens')
            ->andReturnUsing(fn ($text) => strlen($text) / 4);

        // Mock the main provider
        $mainProvider = \Mockery::mock(LlmProvider::class);
        $mainProvider->shouldReceive('countTokens')
            ->andReturnUsing(fn ($text) => strlen($text) / 4);

        $registryMock = \Mockery::mock(ProviderRegistry::class);
        $registryMock->shouldReceive('resolveByType')
            ->andReturn($mainProvider);
        $registryMock->shouldReceive('resolve')
            ->andReturn($condensationProvider);

        // Create condenser with mocked provider
        $cacheStore = $this->app['cache.store'];
        $condenser = new ConversationCondenser(
            new ChunkPartitioner(),
            new CondensationSummaryStore($cacheStore),
            new ContextWindowBudgeter(),
            new CondensationPreset(),
            $condensationProvider,
            $registryMock
        );

        // Build messages payload
        $messageArray = [];
        foreach ($messages as $m) {
            $messageArray[] = [
                'role' => $m->role,
                'content' => $m->content,
            ];
        }

        // Call condenseOrTrim with small historyBudget to force condensation
        $estimator = fn (string $text) => strlen($text) / 4;
        $result = $condenser->condenseOrTrim(
            $messageArray,
            'gpt-4',
            ProviderType::OpenAI,
            $estimator,
            $this->conversation->id,
            20  // Small budget to force condensation
        );

        // Verify event was dispatched with correct data
        Event::assertDispatched(ConversationCondensed::class, function ($event) {
            return $event->conversationId === $this->conversation->id
                && $event->synchronous === true
                && $event->chunkIndex === 0;
        });

        // Verify ChunkSummary was persisted
        $this->assertEquals(1, ChunkSummary::count());

        $summary = ChunkSummary::first();
        $this->assertEquals($this->conversation->id, $summary->conversation_id);
        $this->assertEquals(0, $summary->chunk_index);

        \Mockery::close();
    }

    #[Test]
    public function transcript_remains_unchanged_after_condensation()
    {
        // Create messages
        $originalMessages = [];
        for ($i = 0; $i < 8; $i++) {
            $msg = Message::create([
                'conversation_id' => $this->conversation->id,
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'content' => "Original message {$i}",
                'token_count' => 10,
            ]);
            $originalMessages[] = $msg->fresh();
        }

        // Mock condensation provider
        $summaryJson = '{"decisions":["Test decision"],"constraints":[],"open_questions":[],"facts":[],"commitments":[],"context":"Test"}';

        $condensationProvider = \Mockery::mock(LlmProvider::class);
        $condensationProvider->shouldReceive('chat')
            ->once()
            ->andReturn([
                'choices' => [['message' => ['content' => $summaryJson]]],
                'usage' => [
                    'prompt_tokens' => 50,
                    'completion_tokens' => 30,
                    'total_tokens' => 80,
                ],
            ]);

        $condensationProvider->shouldReceive('countTokens')
            ->andReturnUsing(fn ($text) => strlen($text) / 4);

        $registryMock = \Mockery::mock(ProviderRegistry::class);
        $registryMock->shouldReceive('resolve')
            ->andReturn($condensationProvider);

        // Create condenser
        $cacheStore = $this->app['cache.store'];
        $condenser = new ConversationCondenser(
            new ChunkPartitioner(),
            new CondensationSummaryStore($cacheStore),
            new ContextWindowBudgeter(),
            new CondensationPreset(),
            $condensationProvider,
            $registryMock
        );

        // Build messages payload
        $messageArray = [];
        foreach ($originalMessages as $m) {
            $messageArray[] = [
                'role' => $m->role,
                'content' => $m->content,
            ];
        }

        // Call condenseOrTrim with small historyBudget to force condensation
        $estimator = fn (string $text) => strlen($text) / 4;
        $condenser->condenseOrTrim(
            $messageArray,
            'gpt-4',
            ProviderType::OpenAI,
            $estimator,
            $this->conversation->id,
            20  // Small budget to force condensation
        );

        // Verify all messages are unchanged in database
        $dbMessages = Message::where('conversation_id', $this->conversation->id)
            ->orderBy('created_at')
            ->get();

        $this->assertEquals(8, $dbMessages->count());

        foreach ($dbMessages as $index => $dbMsg) {
            $this->assertEquals($originalMessages[$index]->content, $dbMsg->content);
            $this->assertEquals($originalMessages[$index]->role, $dbMsg->role);
        }

        // Verify no summary content leaked into Message rows
        foreach ($dbMessages as $dbMsg) {
            $this->assertStringNotContainsString('Condensed', $dbMsg->content);
            $this->assertStringNotContainsString('Test decision', $dbMsg->content);
        }

        \Mockery::close();
    }

    #[Test]
    public function condensation_enabled_flag_controls_behavior()
    {
        // Disable condensation
        $this->app['config']->set('llm-client.condensation.enabled', false);

        $messages = [];
        for ($i = 0; $i < 8; $i++) {
            $messages[] = [
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'content' => "Message {$i}",
            ];
        }

        $mainProvider = \Mockery::mock(LlmProvider::class);
        $mainProvider->shouldReceive('countTokens')
            ->andReturnUsing(fn ($text) => strlen($text) / 4);

        $registryMock = \Mockery::mock(ProviderRegistry::class);
        $registryMock->shouldReceive('resolveByType')
            ->andReturn($mainProvider);

        $cacheStore = $this->app['cache.store'];
        $condenser = new ConversationCondenser(
            new ChunkPartitioner(),
            new CondensationSummaryStore($cacheStore),
            new ContextWindowBudgeter(),
            new CondensationPreset(),
            null,
            $registryMock
        );

        $estimator = fn (string $text) => strlen($text) / 4;
        $result = $condenser->condenseOrTrim(
            $messages,
            'gpt-4',
            ProviderType::OpenAI,
            $estimator,
            $this->conversation->id,
            20 // Small budget
        );

        // When disabled, no ChunkSummary should be created
        $this->assertEquals(0, ChunkSummary::count());

        \Mockery::close();
    }

    #[Test]
    public function multiple_condensation_passes_keep_summary_byte_identical()
    {
        $messages = [];
        for ($i = 0; $i < 8; $i++) {
            $messages[] = [
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'content' => "Message {$i} with enough content to count",
            ];
        }

        // Compute source hash for chunk 0 (chunk_size=4, messages 0-3)
        // With 8 messages and budget=20, verbatim keeps last ~4 msgs, so only chunk 0 needs condensation
        $partitioner = new ChunkPartitioner();
        $sourceHash = $partitioner->computeSourceHash($messages, 0, 4);

        // Pre-populate cache with known summary
        $summaryJson = '{"decisions":["Decision A"],"constraints":[],"open_questions":[],"facts":[],"commitments":[],"context":"Test"}';
        ChunkSummary::create([
            'id' => Str::uuid(),
            'conversation_id' => $this->conversation->id,
            'chunk_index' => 0,
            'source_hash' => $sourceHash,
            'source_message_count' => 4,
            'summary' => json_decode($summaryJson),
        ]);

        $condensationProvider = \Mockery::mock(LlmProvider::class);
        $condensationProvider->shouldReceive('chat')
            ->never();

        $condensationProvider->shouldReceive('countTokens')
            ->andReturnUsing(fn ($text) => strlen($text) / 4);

        $registryMock = \Mockery::mock(ProviderRegistry::class);
        $registryMock->shouldReceive('resolve')
            ->andReturn($condensationProvider);

        $cacheStore = $this->app['cache.store'];
        $condenser = new ConversationCondenser(
            new ChunkPartitioner(),
            new CondensationSummaryStore($cacheStore),
            new ContextWindowBudgeter(),
            new CondensationPreset(),
            $condensationProvider,
            $registryMock
        );

        $estimator = fn (string $text) => strlen($text) / 4;

        // First pass
        $result1 = $condenser->condenseOrTrim(
            $messages,
            'gpt-4',
            ProviderType::OpenAI,
            $estimator,
            $this->conversation->id,
            20 // Small budget to force condensation
        );

        // Second pass
        $result2 = $condenser->condenseOrTrim(
            $messages,
            'gpt-4',
            ProviderType::OpenAI,
            $estimator,
            $this->conversation->id,
            20  // Small budget to force condensation
        );

        // Results should be identical (cache hit)
        $this->assertEquals($result1, $result2);

        \Mockery::close();
    }
}
