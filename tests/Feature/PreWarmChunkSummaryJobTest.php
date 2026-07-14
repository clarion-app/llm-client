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
use ClarionApp\LlmClient\Services\CondensationSummaryStore;
use ClarionApp\LlmClient\Presets\CondensationPreset;
use ClarionApp\LlmClient\Events\ConversationCondensed;
use ClarionApp\LlmClient\Jobs\PreWarmChunkSummaryJob;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Mockery;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * Feature tests for PreWarmChunkSummaryJob.
 *
 * Exercises the queued pre-warm job path that condenses sealed chunks
 * asynchronously, writing the same cache row the synchronous path reads.
 */
class PreWarmChunkSummaryJobTest extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        // Create all required tables manually to avoid migration conflicts.
        // Parent defineDatabaseMigrations() creates users, llm_memory_entries, declarative_memories.

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
            $table->uuid('conversation_id');
            $table->integer('chunk_index');
            $table->string('source_hash');
            $table->integer('source_message_count')->default(0);
            $table->json('summary');
            $table->integer('summary_tokens')->nullable();
            $table->string('condensation_model')->nullable();
            $table->string('condensation_provider')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'chunk_index']);
        });

        // condensation_states table
        Schema::create('condensation_states', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id')->unique();
            $table->integer('consecutive_failures')->default(0);
            $table->timestamp('cooldown_until')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        // Clean up data
        ChunkSummary::query()->delete();

        // Clean up error/exception handlers to avoid risky test warnings
        $restored = restore_exception_handler();
        $restored = restore_error_handler();

        Mockery::close();
    }

    /**
     * Create a test user, server, and conversation with messages.
     */
    private function createConversation(int $messageCount = 12): array
    {
        $user = User::create([
            'id' => (string) Str::uuid(),
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
        ]);

        $server = Server::create([
            'id' => (string) Str::uuid(),
            'name' => 'Test Server',
            'server_url' => 'https://api.openai.com',
            'provider_type' => ProviderType::OpenAI->value,
            'token' => 'sk-test',
        ]);

        $conversation = Conversation::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'server_id' => $server->id,
            'title' => 'Test Conversation',
        ]);

        $messages = [];
        for ($i = 0; $i < $messageCount; $i++) {
            $role = ($i % 2 === 0) ? 'user' : 'assistant';
            $messages[] = Message::create([
                'id' => (string) Str::uuid(),
                'conversation_id' => $conversation->id,
                'role' => $role,
                'content' => "Message content number {$i} with some text to make it longer for token estimation.",
                'token_count' => 15,
            ]);
        }

        return [
            'user' => $user,
            'server' => $server,
            'conversation' => $conversation,
            'messages' => $messages,
        ];
    }

    /**
     * Create a mocked provider that returns a canned condensation summary.
     */
    private function createMockProvider(): Mockery\MockInterface
    {
        $provider = Mockery::mock(LlmProvider::class);
        $provider->shouldReceive('chat')
            ->andReturn([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'decisions' => ['Decision from chunk'],
                                'constraints' => ['Constraint from chunk'],
                                'open_questions' => [],
                                'facts' => ['Fact from chunk'],
                                'commitments' => [],
                            ]),
                        ],
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 50,
                    'completion_tokens' => 30,
                    'total_tokens' => 80,
                ],
            ]);
        $provider->shouldReceive('countTokens')->andReturnUsing(fn ($texts) => is_array($texts) ? count($texts) * 10 : 10);

        return $provider;
    }

    #[Test]
    function queued_job_writes_cache_row_for_chunk(): void
    {
        $data = $this->createConversation(12);
        $conversationId = $data['conversation']->id;

        $mockProvider = $this->createMockProvider();

        // Bind mock provider
        $this->app->bind(LlmProvider::class, fn () => $mockProvider);

        // Create provider registry with mock
        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($mockProvider);
        $this->app->instance(ProviderRegistry::class, $registry);

        // Configure condensation
        config(['llm-client.condensation' => [
            'enabled' => true,
            'chunk_size' => 4,
            'model' => 'gpt-4o-mini',
            'provider' => 'openai',
            'timeout_seconds' => 20,
            'failure_threshold' => 3,
            'cooldown_seconds' => 60,
            'prewarm' => true,
        ]]);

        // Execute job directly (sync)
        $job = new PreWarmChunkSummaryJob($conversationId, 0);
        $job->handle(
            $this->app[LlmProvider::class],
            $this->app[CondensationSummaryStore::class],
            $this->app[CondensationPreset::class]
        );

        // Assert ChunkSummary was created
        $summary = ChunkSummary::where('conversation_id', $conversationId)
            ->where('chunk_index', 0)
            ->first();

        self::assertNotNull($summary, 'ChunkSummary should be created by the job');
        self::assertNotNull($summary->source_hash, 'Source hash should be set');
        self::assertNotNull($summary->summary, 'Summary data should be persisted');
    }

    #[Test]
    function job_is_no_op_when_chunk_already_cached(): void
    {
        $data = $this->createConversation(12);
        $conversationId = $data['conversation']->id;

        $mockProvider = $this->createMockProvider();

        // Pre-populate cache
        ChunkSummary::create([
            'id' => (string) Str::uuid(),
            'conversation_id' => $conversationId,
            'chunk_index' => 0,
            'source_hash' => 'existing-hash',
            'source_message_count' => 4,
            'summary' => [
                'decisions' => ['Existing decision'],
                'constraints' => [],
                'open_questions' => [],
                'facts' => [],
                'commitments' => [],
            ],
            'summary_tokens' => 10,
            'condensation_model' => 'gpt-4o-mini',
            'condensation_provider' => 'openai',
        ]);

        // Bind mock provider - should NOT be called
        $mockProvider->shouldReceive('chat')->never();
        $this->app->bind(LlmProvider::class, fn () => $mockProvider);

        $registry = Mockery::mock(ProviderRegistry::class);
        $this->app->instance(ProviderRegistry::class, $registry);

        config(['llm-client.condensation' => [
            'enabled' => true,
            'chunk_size' => 4,
            'model' => 'gpt-4o-mini',
            'provider' => 'openai',
            'timeout_seconds' => 20,
            'failure_threshold' => 3,
            'cooldown_seconds' => 60,
            'prewarm' => true,
        ]]);

        // Execute job directly (sync)
        $job = new PreWarmChunkSummaryJob($conversationId, 0);
        $job->handle(
            $this->app[LlmProvider::class],
            $this->app[CondensationSummaryStore::class],
            $this->app[CondensationPreset::class]
        );

        // Assert only one ChunkSummary exists (the pre-populated one)
        $count = ChunkSummary::where('conversation_id', $conversationId)
            ->where('chunk_index', 0)
            ->count();

        self::assertSame(1, $count, 'Should not create duplicate ChunkSummary');
    }

    #[Test]
    function job_dispatches_conversation_condensed_event_with_synchronous_false(): void
    {
        $data = $this->createConversation(12);
        $conversationId = $data['conversation']->id;

        $mockProvider = $this->createMockProvider();

        $this->app->bind(LlmProvider::class, fn () => $mockProvider);

        $registry = Mockery::mock(ProviderRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($mockProvider);
        $this->app->instance(ProviderRegistry::class, $registry);

        config(['llm-client.condensation' => [
            'enabled' => true,
            'chunk_size' => 4,
            'model' => 'gpt-4o-mini',
            'provider' => 'openai',
            'timeout_seconds' => 20,
            'failure_threshold' => 3,
            'cooldown_seconds' => 60,
            'prewarm' => true,
        ]]);

        Event::fake(ConversationCondensed::class);

        $job = new PreWarmChunkSummaryJob($conversationId, 0);
        $job->handle(
            $this->app[LlmProvider::class],
            $this->app[CondensationSummaryStore::class],
            $this->app[CondensationPreset::class]
        );

        Event::assertDispatched(ConversationCondensed::class, function ($event) use ($conversationId) {
            return $event->conversationId === $conversationId
                && $event->chunkIndex === 0
                && $event->synchronous === false;
        });
    }
}
