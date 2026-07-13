<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\EpisodicMemory;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\EmbeddingService;
use ClarionApp\LlmClient\Events\ConversationEnded;
use ClarionApp\LlmClient\Events\EpisodicMemoryGenerationFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

use PHPUnit\Framework\Attributes\Test;

/**
 * Feature tests for Episodic Memory Capture Integration.
 *
 * Tests the complete lifecycle from ConversationEnded event firing
 * through job dispatch to EpisodicMemory creation.
 */
class EpisodicMemoryCaptureIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Server $server;
    private Conversation $conversation;
    private string $agentId;

    protected function setUp(): void
    {
        parent::setUp();

        // Use synchronous queue driver so jobs run immediately during tests
        config(['queue.default' => 'sync']);

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
            'title' => 'Integration Test Conversation',
            'model' => 'gpt-4',
            'character' => 'Clarion',
        ]);

        $this->agentId = 'test-agent-' . uniqid();
    }

    #[Test]
    public function conversation_ended_event_dispatches_generate_episodic_memory_job()
    {
        // Create messages with enough content
        $this->createTestMessages();

        // Dispatch ConversationEnded event
        Queue::fake(\ClarionApp\LlmClient\Jobs\GenerateEpisodicMemoryJob::class);

        Event::dispatch(new ConversationEnded($this->conversation->id, $this->agentId));

        // Verify job was dispatched
        Queue::assertPushed(\ClarionApp\LlmClient\Jobs\GenerateEpisodicMemoryJob::class, function ($job) {
            return $job->conversationId === $this->conversation->id;
        });
    }

    #[Test]
    public function conversation_ended_event_creates_episodic_memory_with_valid_conversation()
    {
        // Create messages with enough content
        $this->createTestMessages();

        // Mock the provider to return a valid summary
        $summaryJson = '{"summary":"User discussed deployment strategies and agreed on canary deployments for web services.","topics":["deployment","kubernetes","canary"]}';

        $providerMock = \Mockery::mock(LlmProvider::class);
        $providerMock->shouldReceive('chat')->once()->andReturn([
            'choices' => [['message' => ['content' => $summaryJson]]],
        ]);

        $registryMock = \Mockery::mock(ProviderRegistry::class);
        $registryMock->shouldReceive('resolve')->once()->andReturn($providerMock);

        // Embedding service
        $embeddingMock = \Mockery::mock(EmbeddingService::class);
        $embeddingMock->shouldReceive('isEnabled')->andReturn(true);
        $embeddingMock->shouldReceive('generate')->once()->andReturn([0.1, 0.2, 0.3]);

        // Bind mocks to container for DI
        $this->app->instance(ProviderRegistry::class, $registryMock);
        $this->app->instance(EmbeddingService::class, $embeddingMock);

        // Dispatch ConversationEnded event (job runs synchronously)
        Event::dispatch(new ConversationEnded($this->conversation->id, $this->agentId));

        // Verify EpisodicMemory was created
        $this->assertEquals(1, EpisodicMemory::withoutGlobalScope('user')->count());

        $memory = EpisodicMemory::withoutGlobalScope('user')->first();
        $this->assertEquals($this->user->id, $memory->user_id);
        $this->assertEquals($this->conversation->id, $memory->conversation_id);
        $this->assertEquals('User discussed deployment strategies and agreed on canary deployments for web services.', $memory->summary);
        $this->assertEquals(['deployment', 'kubernetes', 'canary'], $memory->topics);
        $this->assertGreaterThan(0, $memory->word_count);
        $this->assertGreaterThan(0, $memory->summary_word_count);

        \Mockery::close();
    }

    #[Test]
    public function conversation_ended_event_broadcasts_failure_when_provider_errors()
    {
        // Create messages with enough content
        $this->createTestMessages();

        // Track failure events
        $failureEvent = null;
        Event::listen(EpisodicMemoryGenerationFailed::class, function ($e) use (&$failureEvent) {
            $failureEvent = $e;
        });

        // Mock provider that throws an error
        $providerMock = \Mockery::mock(LlmProvider::class);
        $providerMock->shouldReceive('chat')->once()->andThrow(new \RuntimeException('API connection failed'));

        $registryMock = \Mockery::mock(ProviderRegistry::class);
        $registryMock->shouldReceive('resolve')->once()->andReturn($providerMock);

        $embeddingMock = \Mockery::mock(EmbeddingService::class);

        $this->app->instance(ProviderRegistry::class, $registryMock);
        $this->app->instance(EmbeddingService::class, $embeddingMock);

        // Dispatch ConversationEnded event
        Event::dispatch(new ConversationEnded($this->conversation->id, $this->agentId));

        // Verify failure event was broadcast
        $this->assertNotNull($failureEvent);
        $this->assertEquals($this->conversation->id, $failureEvent->conversationId);
        $this->assertEquals($this->user->id, $failureEvent->userId);

        // No EpisodicMemory should be created on LLM error
        $this->assertEquals(0, EpisodicMemory::withoutGlobalScope('user')->count());

        \Mockery::close();
    }

    #[Test]
    public function conversation_ended_event_skips_short_conversations()
    {
        // Create only 2 messages (below threshold of 3)
        Message::create([
            'conversation_id' => $this->conversation->id,
            'role' => 'user',
            'content' => 'Hello',
            'user' => json_encode(['name' => 'User']),
        ]);
        Message::create([
            'conversation_id' => $this->conversation->id,
            'role' => 'assistant',
            'content' => 'Hi there!',
            'user' => json_encode(['name' => 'Assistant']),
        ]);

        $providerMock = \Mockery::mock(LlmProvider::class);
        $providerMock->shouldNotReceive('chat');

        $registryMock = \Mockery::mock(ProviderRegistry::class);
        $registryMock->shouldNotReceive('resolve');

        $embeddingMock = \Mockery::mock(EmbeddingService::class);

        $this->app->instance(ProviderRegistry::class, $registryMock);
        $this->app->instance(EmbeddingService::class, $embeddingMock);

        // Dispatch ConversationEnded event
        Event::dispatch(new ConversationEnded($this->conversation->id, $this->agentId));

        // No EpisodicMemory should be created for short conversations
        $this->assertEquals(0, EpisodicMemory::withoutGlobalScope('user')->count());

        \Mockery::close();
    }

    #[Test]
    public function conversation_ended_event_deduplicates_by_conversation_id()
    {
        // Create messages with enough content
        $this->createTestMessages();

        // Create an existing EpisodicMemory
        EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => $this->conversation->id,
            'summary' => 'Already exists',
            'topics' => ['test'],
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);

        $providerMock = \Mockery::mock(LlmProvider::class);
        $providerMock->shouldNotReceive('chat');

        $registryMock = \Mockery::mock(ProviderRegistry::class);
        $registryMock->shouldNotReceive('resolve');

        $embeddingMock = \Mockery::mock(EmbeddingService::class);

        $this->app->instance(ProviderRegistry::class, $registryMock);
        $this->app->instance(EmbeddingService::class, $embeddingMock);

        // Dispatch ConversationEnded event
        Event::dispatch(new ConversationEnded($this->conversation->id, $this->agentId));

        // Still only 1 EpisodicMemory (the original)
        $this->assertEquals(1, EpisodicMemory::withoutGlobalScope('user')->count());

        $memory = EpisodicMemory::withoutGlobalScope('user')->first();
        $this->assertEquals('Already exists', $memory->summary);

        \Mockery::close();
    }

    #[Test]
    public function conversation_ended_event_gracefully_skips_embedding_when_disabled()
    {
        // Create messages with enough content
        $this->createTestMessages();

        $summaryJson = '{"summary":"Short summary.","topics":["test"]}';

        $providerMock = \Mockery::mock(LlmProvider::class);
        $providerMock->shouldReceive('chat')->once()->andReturn([
            'choices' => [['message' => ['content' => $summaryJson]]],
        ]);

        $registryMock = \Mockery::mock(ProviderRegistry::class);
        $registryMock->shouldReceive('resolve')->once()->andReturn($providerMock);

        // Embedding disabled
        $embeddingMock = \Mockery::mock(EmbeddingService::class);
        $embeddingMock->shouldReceive('isEnabled')->andReturn(false);
        $embeddingMock->shouldNotReceive('generate');

        $this->app->instance(ProviderRegistry::class, $registryMock);
        $this->app->instance(EmbeddingService::class, $embeddingMock);

        // Dispatch ConversationEnded event
        Event::dispatch(new ConversationEnded($this->conversation->id, $this->agentId));

        // Memory should still be created
        $this->assertEquals(1, EpisodicMemory::withoutGlobalScope('user')->count());

        \Mockery::close();
    }

    /**
     * Create test messages with enough content for the job to process.
     */
    private function createTestMessages(): void
    {
        Message::create([
            'conversation_id' => $this->conversation->id,
            'role' => 'user',
            'content' => 'I need to plan the deployment strategy for our microservices architecture. We have five services that need to be deployed to Kubernetes.',
            'user' => json_encode(['name' => 'User']),
        ]);
        Message::create([
            'conversation_id' => $this->conversation->id,
            'role' => 'assistant',
            'content' => "Let's discuss the deployment options. Canary deployments allow gradual rollout and early detection of issues. Blue-green deployments provide instant rollback capability.",
            'user' => json_encode(['name' => 'Assistant']),
        ]);
        Message::create([
            'conversation_id' => $this->conversation->id,
            'role' => 'user',
            'content' => 'I think we should go with canary deployments for the web-facing services. Let me document this decision.',
            'user' => json_encode(['name' => 'User']),
        ]);
    }
}
