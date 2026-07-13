<?php

namespace ClarionApp\LlmClient\Tests\Unit\Jobs;

use Tests\TestCase;
use ClarionApp\LlmClient\Jobs\GenerateEpisodicMemoryJob;
use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\EpisodicMemory;
use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use ClarionApp\LlmClient\Services\EmbeddingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Bus;
use Mockery;

use PHPUnit\Framework\Attributes\Test;

class GenerateEpisodicMemoryJobTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Server $server;
    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        // Note: Event::fake() is NOT called here because EloquentMultiChainBridge
        // relies on the 'creating' event to generate UUIDs. We only fake events
        // in specific tests that need to verify event broadcasting.

        $this->user = \ClarionApp\Backend\Models\User::factory()->create();

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
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function job_skips_when_episodic_memory_already_exists()
    {
        // Create an existing EpisodicMemory for the conversation
        EpisodicMemory::create([
            'user_id' => $this->user->id,
            'conversation_id' => $this->conversation->id,
            'summary' => 'Already exists',
            'topics' => ['test'],
            'word_count' => 100,
            'summary_word_count' => 10,
        ]);

        // Create messages for the conversation
        $this->createMessages(['user', 'assistant', 'user']);

        $providerMock = Mockery::mock(LlmProvider::class);
        // Provider should NOT be called since memory already exists
        $providerMock->shouldNotReceive('chat');

        $registryMock = Mockery::mock(ProviderRegistry::class);
        $registryMock->shouldReceive('resolve')->andReturn($providerMock);

        $embeddingMock = Mockery::mock(EmbeddingService::class);
        $embeddingMock->shouldNotReceive('generate');

        $job = new GenerateEpisodicMemoryJob(
            $this->conversation->id,
            'test-agent-id'
        );

        $job->handle($registryMock, $embeddingMock);

        // Should still have only 1 memory (the original one)
        $this->assertEquals(1, EpisodicMemory::withoutGlobalScope('user')->count());
    }

    #[Test]
    public function job_skips_conversation_with_fewer_than_three_meaningful_exchanges()
    {
        // Create only 2 messages (less than 3)
        $this->createMessages(['user', 'assistant']);

        $providerMock = Mockery::mock(LlmProvider::class);
        $providerMock->shouldNotReceive('chat');

        $registryMock = Mockery::mock(ProviderRegistry::class);
        $registryMock->shouldNotReceive('resolve');

        $embeddingMock = Mockery::mock(EmbeddingService::class);

        $job = new GenerateEpisodicMemoryJob(
            $this->conversation->id,
            'test-agent-id'
        );

        $job->handle($registryMock, $embeddingMock);

        // No EpisodicMemory should be created
        $this->assertEquals(0, EpisodicMemory::withoutGlobalScope('user')->count());
    }

    #[Test]
    public function job_skips_conversation_with_empty_transcript()
    {
        // Create messages with empty content
        Message::create([
            'conversation_id' => $this->conversation->id,
            'role' => 'user',
            'content' => '',
            'user' => json_encode(['name' => 'User']),
        ]);
        Message::create([
            'conversation_id' => $this->conversation->id,
            'role' => 'assistant',
            'content' => '   ',
            'user' => json_encode(['name' => 'Assistant']),
        ]);
        Message::create([
            'conversation_id' => $this->conversation->id,
            'role' => 'user',
            'content' => '',
            'user' => json_encode(['name' => 'User']),
        ]);

        $providerMock = Mockery::mock(LlmProvider::class);
        $providerMock->shouldNotReceive('chat');

        $registryMock = Mockery::mock(ProviderRegistry::class);
        $registryMock->shouldNotReceive('resolve');

        $embeddingMock = Mockery::mock(EmbeddingService::class);

        $job = new GenerateEpisodicMemoryJob(
            $this->conversation->id,
            'test-agent-id'
        );

        $job->handle($registryMock, $embeddingMock);

        // No EpisodicMemory should be created
        $this->assertEquals(0, EpisodicMemory::withoutGlobalScope('user')->count());
    }

    #[Test]
    public function job_stores_placeholder_when_provider_resolution_fails()
    {
        $this->createMessages(['user', 'assistant', 'user']);

        $registryMock = Mockery::mock(ProviderRegistry::class);
        $registryMock->shouldReceive('resolve')->andThrow(new \RuntimeException('Provider not found'));

        $embeddingMock = Mockery::mock(EmbeddingService::class);

        $job = new GenerateEpisodicMemoryJob(
            $this->conversation->id,
            'test-agent-id'
        );

        $job->handle($registryMock, $embeddingMock);

        // Should create a placeholder memory
        $this->assertEquals(1, EpisodicMemory::withoutGlobalScope('user')->count());

        $memory = EpisodicMemory::withoutGlobalScope('user')->first();
        $this->assertStringContainsString('Memory capture skipped', $memory->summary);
        $this->assertStringContainsString('LLM provider unavailable', $memory->summary);
        $this->assertEquals(['skipped'], $memory->topics);
    }

    #[Test]
    public function job_broadcasts_failure_when_summarization_returns_null()
    {
        $this->createMessages(['user', 'assistant', 'user']);

        $providerMock = Mockery::mock(LlmProvider::class);
        $providerMock->shouldReceive('chat')->andReturn([
            'choices' => [['message' => ['content' => '']]]
        ]);

        $registryMock = Mockery::mock(ProviderRegistry::class);
        $registryMock->shouldReceive('resolve')->andReturn($providerMock);

        $embeddingMock = Mockery::mock(EmbeddingService::class);
        $embeddingMock->shouldNotReceive('generate');

        // Listen for the failure event
        $failureFired = false;
        Event::listen(\ClarionApp\LlmClient\Events\EpisodicMemoryGenerationFailed::class, function ($e) use (&$failureFired) {
            $failureFired = true;
            $this->assertEquals($this->conversation->id, $e->conversationId);
        });

        $job = new GenerateEpisodicMemoryJob(
            $this->conversation->id,
            'test-agent-id'
        );

        $job->handle($registryMock, $embeddingMock);

        // No EpisodicMemory should be created when summarization returns null
        $this->assertEquals(0, EpisodicMemory::withoutGlobalScope('user')->count());
        $this->assertTrue($failureFired, 'EpisodicMemoryGenerationFailed event should be broadcast on null summarization');
    }

    #[Test]
    public function job_stores_placeholder_when_summary_ratio_exceeds_fifty_percent()
    {
        $this->createMessages(['user', 'assistant', 'user']);

        // Create a summary that's longer than 50% of the original
        // The messages have very few words, so a long summary will exceed 50%
        $longSummary = '{"summary":"This is a very long summary that exceeds fifty percent of the original word count because it contains far more words than the original transcript which was very short Adding even more words to ensure we exceed the threshold significantly","topics":["test"]}';

        $providerMock = Mockery::mock(LlmProvider::class);
        $providerMock->shouldReceive('chat')->andReturn([
            'choices' => [['message' => ['content' => $longSummary]]],
        ]);

        $registryMock = Mockery::mock(ProviderRegistry::class);
        $registryMock->shouldReceive('resolve')->andReturn($providerMock);

        $embeddingMock = Mockery::mock(EmbeddingService::class);
        $embeddingMock->shouldNotReceive('generate');

        $job = new GenerateEpisodicMemoryJob(
            $this->conversation->id,
            'test-agent-id'
        );

        $job->handle($registryMock, $embeddingMock);

        // Should create a placeholder due to high ratio
        $this->assertEquals(1, EpisodicMemory::withoutGlobalScope('user')->count());

        $memory = EpisodicMemory::withoutGlobalScope('user')->first();
        $this->assertStringContainsString('Memory capture skipped', $memory->summary);
        $this->assertStringContainsString('ratio threshold', $memory->summary);
    }

    #[Test]
    public function job_successfully_creates_episodic_memory_with_valid_summary()
    {
        // Create a conversation with enough words for the summary ratio to pass
        Message::create([
            'conversation_id' => $this->conversation->id,
            'role' => 'user',
            'content' => 'I need to plan the deployment strategy for our microservices architecture. We have five services that need to be deployed to Kubernetes. The team has been discussing whether to use canary deployments or blue-green deployments. We also need to consider database migration strategies and rollback procedures.',
            'user' => json_encode(['name' => 'User']),
        ]);
        Message::create([
            'conversation_id' => $this->conversation->id,
            'role' => 'assistant',
            'content' => "Let's discuss the deployment options. Canary deployments allow gradual rollout and early detection of issues. Blue-green deployments provide instant rollback capability but require double the infrastructure. For database migrations, we can use expand-and-contract pattern.",
            'user' => json_encode(['name' => 'Assistant']),
        ]);
        Message::create([
            'conversation_id' => $this->conversation->id,
            'role' => 'user',
            'content' => 'I think we should go with canary deployments for the web-facing services and blue-green for the internal services. Let me document this decision so the team knows the plan going forward.',
            'user' => json_encode(['name' => 'User']),
        ]);

        // Summary is short enough (< 50% of original)
        $summaryJson = '{"summary":"Team agreed on canary deployments for web services and blue-green for internal services.","topics":["deployment","kubernetes","microservices"]}';

        $providerMock = Mockery::mock(LlmProvider::class);
        $providerMock->shouldReceive('chat')->andReturn([
            'choices' => [['message' => ['content' => $summaryJson]]],
        ]);

        $registryMock = Mockery::mock(ProviderRegistry::class);
        $registryMock->shouldReceive('resolve')->andReturn($providerMock);

        $embeddingMock = Mockery::mock(EmbeddingService::class);
        $embeddingMock->shouldReceive('isEnabled')->andReturn(true);
        $embeddingMock->shouldReceive('generate')->once()->with('Team agreed on canary deployments for web services and blue-green for internal services.')->andReturn([0.1, 0.2, 0.3]);

        $job = new GenerateEpisodicMemoryJob(
            $this->conversation->id,
            'test-agent-id'
        );

        $job->handle($registryMock, $embeddingMock);

        // Should create a valid EpisodicMemory
        $this->assertEquals(1, EpisodicMemory::withoutGlobalScope('user')->count());

        $memory = EpisodicMemory::withoutGlobalScope('user')->first();
        $this->assertEquals($this->user->id, $memory->user_id);
        $this->assertEquals($this->conversation->id, $memory->conversation_id);
        $this->assertEquals('Team agreed on canary deployments for web services and blue-green for internal services.', $memory->summary);
        $this->assertEquals(['deployment', 'kubernetes', 'microservices'], $memory->topics);
        $this->assertGreaterThan(0, $memory->word_count);
        $this->assertGreaterThan(0, $memory->summary_word_count);
    }

    #[Test]
    public function job_limits_topics_to_max_topics_per_entry()
    {
        $this->createMessages(['user', 'assistant', 'user']);

        $summaryJson = '{"summary":"Short summary.","topics":["a","b","c","d","e","f","g","h","i","j","k","l"]}';

        $providerMock = Mockery::mock(LlmProvider::class);
        $providerMock->shouldReceive('chat')->andReturn([
            'choices' => [['message' => ['content' => $summaryJson]]],
        ]);

        $registryMock = Mockery::mock(ProviderRegistry::class);
        $registryMock->shouldReceive('resolve')->andReturn($providerMock);

        $embeddingMock = Mockery::mock(EmbeddingService::class);
        $embeddingMock->shouldReceive('isEnabled')->andReturn(false);

        $job = new GenerateEpisodicMemoryJob(
            $this->conversation->id,
            'test-agent-id'
        );

        $job->handle($registryMock, $embeddingMock);

        $memory = EpisodicMemory::withoutGlobalScope('user')->first();
        // Default max_topics_per_entry is 10
        $this->assertLessThanOrEqual(10, count($memory->topics));
    }

    #[Test]
    public function job_broadcasts_failure_event_when_summarization_throws_exception()
    {
        $this->createMessages(['user', 'assistant', 'user']);

        $providerMock = Mockery::mock(LlmProvider::class);
        $providerMock->shouldReceive('chat')->andThrow(new \RuntimeException('LLM API error'));

        $registryMock = Mockery::mock(ProviderRegistry::class);
        $registryMock->shouldReceive('resolve')->andReturn($providerMock);

        $embeddingMock = Mockery::mock(EmbeddingService::class);

        // Listen for the failure event
        $failureFired = false;
        Event::listen(\ClarionApp\LlmClient\Events\EpisodicMemoryGenerationFailed::class, function ($e) use (&$failureFired) {
            $failureFired = true;
            $this->assertEquals($this->conversation->id, $e->conversationId);
        });

        $job = new GenerateEpisodicMemoryJob(
            $this->conversation->id,
            'test-agent-id'
        );

        $job->handle($registryMock, $embeddingMock);

        $this->assertTrue($failureFired, 'EpisodicMemoryGenerationFailed event should be broadcast on LLM error');
    }

    #[Test]
    public function job_skips_when_conversation_not_found()
    {
        $providerMock = Mockery::mock(LlmProvider::class);
        $providerMock->shouldNotReceive('chat');

        $registryMock = Mockery::mock(ProviderRegistry::class);
        $registryMock->shouldNotReceive('resolve');

        $embeddingMock = Mockery::mock(EmbeddingService::class);

        $job = new GenerateEpisodicMemoryJob(
            '00000000-0000-0000-0000-000000000000',
            'test-agent-id'
        );

        $job->handle($registryMock, $embeddingMock);

        // No EpisodicMemory should be created
        $this->assertEquals(0, EpisodicMemory::withoutGlobalScope('user')->count());
    }

    /**
     * Helper to create test messages with the given roles.
     */
    private function createMessages(array $roles): void
    {
        $contentMap = [
            'user' => 'This is a user message with enough words to count as meaningful content.',
            'assistant' => 'This is an assistant response with enough words to count as meaningful content.',
        ];

        foreach ($roles as $role) {
            Message::create([
                'conversation_id' => $this->conversation->id,
                'role' => $role,
                'content' => $contentMap[$role] ?? 'Some content.',
                'user' => json_encode(['name' => $role === 'user' ? 'User' : 'Assistant']),
            ]);
        }
    }
}
