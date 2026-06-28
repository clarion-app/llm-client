<?php

namespace Tests\Unit\Commands;

use Tests\TestCase;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Contracts\MemoryScope;
use ClarionApp\LlmClient\Contracts\ProviderType;
use ClarionApp\LlmClient\Models\MemoryEntry;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\EmbeddingService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use RuntimeException;

class EmbedMemoryCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Create llm_servers table if not exists
        if (!\Illuminate\Support\Facades\Schema::hasTable('llm_servers')) {
            \Illuminate\Support\Facades\Schema::create('llm_servers', function ($table) {
                $table->uuid('id')->primary();
                $table->string('name')->nullable();
                $table->text('server_url')->nullable();
                $table->longText('token')->nullable();
                $table->string('provider_type')->default('openai');
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // Register embedding service
        $registry = app(ProviderRegistry::class);
        $embeddingService = new EmbeddingService($registry);
        app()->instance(EmbeddingService::class, $embeddingService);
    }

    public function test_dry_run_shows_count(): void
    {
        $mockProvider = $this->createMock(LlmProvider::class);
        $mockProvider->method('embed')
            ->willReturn(['embeddings' => [[0.1, 0.2, 0.3]]]);

        app(ProviderRegistry::class)->register(
            ProviderType::OpenAI,
            fn () => $mockProvider
        );

        $server = Server::create([
            'id' => (string) Str::uuid(),
            'name' => 'Embedding Server',
            'server_url' => 'https://api.example.com',
            'token' => 'test-token',
            'provider_type' => ProviderType::OpenAI,
        ]);

        config(['llm-client.memory.embedding.enabled' => true]);
        config(['llm-client.memory.embedding.server_id' => $server->id]);

        $agentId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        MemoryEntry::create([
            'id' => (string) Str::uuid(),
            'scope' => MemoryScope::LONG_TERM,
            'agent_id' => $agentId,
            'user_id' => $userId,
            'key' => 'entry1',
            'content' => 'Content 1',
            'embedding' => null,
        ]);

        MemoryEntry::create([
            'id' => (string) Str::uuid(),
            'scope' => MemoryScope::LONG_TERM,
            'agent_id' => $agentId,
            'user_id' => $userId,
            'key' => 'entry2',
            'content' => 'Content 2',
            'embedding' => null,
        ]);

        $exitCode = Artisan::call('llm-client:embed-memory', ['--dry-run' => true]);

        $this->assertEquals(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('2 entries would be processed', $output);
    }

    public function test_dry_run_with_agent_id_filter(): void
    {
        $mockProvider = $this->createMock(LlmProvider::class);
        app(ProviderRegistry::class)->register(
            ProviderType::OpenAI,
            fn () => $mockProvider
        );

        $server = Server::create([
            'id' => (string) Str::uuid(),
            'name' => 'Embedding Server',
            'server_url' => 'https://api.example.com',
            'token' => 'test-token',
            'provider_type' => ProviderType::OpenAI,
        ]);

        config(['llm-client.memory.embedding.enabled' => true]);
        config(['llm-client.memory.embedding.server_id' => $server->id]);

        $agentId1 = (string) Str::uuid();
        $agentId2 = (string) Str::uuid();
        $userId = (string) Str::uuid();

        MemoryEntry::create([
            'id' => (string) Str::uuid(),
            'scope' => MemoryScope::LONG_TERM,
            'agent_id' => $agentId1,
            'user_id' => $userId,
            'key' => 'entry1',
            'content' => 'Content 1',
            'embedding' => null,
        ]);

        MemoryEntry::create([
            'id' => (string) Str::uuid(),
            'scope' => MemoryScope::LONG_TERM,
            'agent_id' => $agentId2,
            'user_id' => $userId,
            'key' => 'entry2',
            'content' => 'Content 2',
            'embedding' => null,
        ]);

        $exitCode = Artisan::call('llm-client:embed-memory', [
            '--dry-run' => true,
            '--agent-id' => $agentId1,
        ]);

        $this->assertEquals(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('1 entries would be processed', $output);
        $this->assertStringContainsString("agent_id = {$agentId1}", $output);
    }

    public function test_batch_processing_success(): void
    {
        $mockProvider = $this->createMock(LlmProvider::class);
        $mockProvider->method('embed')
            ->willReturn(['embeddings' => [[0.1, 0.2, 0.3]]]);

        app(ProviderRegistry::class)->register(
            ProviderType::OpenAI,
            fn () => $mockProvider
        );

        $server = Server::create([
            'id' => (string) Str::uuid(),
            'name' => 'Embedding Server',
            'server_url' => 'https://api.example.com',
            'token' => 'test-token',
            'provider_type' => ProviderType::OpenAI,
        ]);

        config(['llm-client.memory.embedding.enabled' => true]);
        config(['llm-client.memory.embedding.server_id' => $server->id]);

        $agentId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        MemoryEntry::create([
            'id' => (string) Str::uuid(),
            'scope' => MemoryScope::LONG_TERM,
            'agent_id' => $agentId,
            'user_id' => $userId,
            'key' => 'entry1',
            'content' => 'Content 1',
            'embedding' => null,
        ]);

        MemoryEntry::create([
            'id' => (string) Str::uuid(),
            'scope' => MemoryScope::LONG_TERM,
            'agent_id' => $agentId,
            'user_id' => $userId,
            'key' => 'entry2',
            'content' => 'Content 2',
            'embedding' => null,
        ]);

        $exitCode = Artisan::call('llm-client:embed-memory', ['--batch-size' => 10]);

        $this->assertEquals(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('2 succeeded', $output);

        $entries = MemoryEntry::where('agent_id', $agentId)->get();
        foreach ($entries as $entry) {
            $this->assertNotNull($entry->embedding);
        }
    }

    public function test_batch_processing_handles_failures(): void
    {
        $mockProvider = $this->createMock(LlmProvider::class);
        $mockProvider->method('embed')
            ->willThrowException(new RuntimeException('Provider error'));

        app(ProviderRegistry::class)->register(
            ProviderType::OpenAI,
            fn () => $mockProvider
        );

        $server = Server::create([
            'id' => (string) Str::uuid(),
            'name' => 'Embedding Server',
            'server_url' => 'https://api.example.com',
            'token' => 'test-token',
            'provider_type' => ProviderType::OpenAI,
        ]);

        config(['llm-client.memory.embedding.enabled' => true]);
        config(['llm-client.memory.embedding.server_id' => $server->id]);

        $agentId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        MemoryEntry::create([
            'id' => (string) Str::uuid(),
            'scope' => MemoryScope::LONG_TERM,
            'agent_id' => $agentId,
            'user_id' => $userId,
            'key' => 'entry1',
            'content' => 'Content 1',
            'embedding' => null,
        ]);

        $exitCode = Artisan::call('llm-client:embed-memory', ['--batch-size' => 10]);

        $this->assertEquals(1, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('1 failed', $output);

        $entry = MemoryEntry::where('key', 'entry1')->first();
        $this->assertNull($entry->embedding);
    }

    public function test_skips_entries_with_existing_embeddings(): void
    {
        $mockProvider = $this->createMock(LlmProvider::class);
        $mockProvider->expects($this->never())
            ->method('embed');

        app(ProviderRegistry::class)->register(
            ProviderType::OpenAI,
            fn () => $mockProvider
        );

        $server = Server::create([
            'id' => (string) Str::uuid(),
            'name' => 'Embedding Server',
            'server_url' => 'https://api.example.com',
            'token' => 'test-token',
            'provider_type' => ProviderType::OpenAI,
        ]);

        config(['llm-client.memory.embedding.enabled' => true]);
        config(['llm-client.memory.embedding.server_id' => $server->id]);

        $agentId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        MemoryEntry::create([
            'id' => (string) Str::uuid(),
            'scope' => MemoryScope::LONG_TERM,
            'agent_id' => $agentId,
            'user_id' => $userId,
            'key' => 'entry1',
            'content' => 'Content 1',
            'embedding' => [0.1, 0.2, 0.3],
        ]);

        $exitCode = Artisan::call('llm-client:embed-memory');

        $this->assertEquals(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('No entries need embedding backfill', $output);
    }

    public function test_skips_non_long_term_entries(): void
    {
        $mockProvider = $this->createMock(LlmProvider::class);
        $mockProvider->expects($this->never())
            ->method('embed');

        app(ProviderRegistry::class)->register(
            ProviderType::OpenAI,
            fn () => $mockProvider
        );

        $server = Server::create([
            'id' => (string) Str::uuid(),
            'name' => 'Embedding Server',
            'server_url' => 'https://api.example.com',
            'token' => 'test-token',
            'provider_type' => ProviderType::OpenAI,
        ]);

        config(['llm-client.memory.embedding.enabled' => true]);
        config(['llm-client.memory.embedding.server_id' => $server->id]);

        $agentId = (string) Str::uuid();
        $userId = (string) Str::uuid();

        MemoryEntry::create([
            'id' => (string) Str::uuid(),
            'scope' => MemoryScope::SCRATCH,
            'agent_id' => $agentId,
            'user_id' => $userId,
            'key' => 'scratch_entry',
            'content' => 'Scratch content',
            'embedding' => null,
        ]);

        MemoryEntry::create([
            'id' => (string) Str::uuid(),
            'scope' => MemoryScope::SHORT_TERM,
            'agent_id' => $agentId,
            'user_id' => $userId,
            'key' => 'short_entry',
            'content' => 'Short term content',
            'embedding' => null,
        ]);

        $exitCode = Artisan::call('llm-client:embed-memory');

        $this->assertEquals(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('No entries need embedding backfill', $output);
    }

    public function test_fails_when_embedding_disabled(): void
    {
        config(['llm-client.memory.embedding.enabled' => false]);

        $exitCode = Artisan::call('llm-client:embed-memory');

        $this->assertEquals(1, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('disabled', $output);
    }

    public function test_fails_when_no_provider_available(): void
    {
        config(['llm-client.memory.embedding.enabled' => true]);
        config(['llm-client.memory.embedding.server_id' => null]);

        $exitCode = Artisan::call('llm-client:embed-memory');

        $this->assertEquals(1, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('No embedding provider available', $output);
    }
}
