<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\LanguageModel;
use ClarionApp\LlmClient\Models\UserSetting;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\Backend\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

use PHPUnit\Framework\Attributes\Test;

class AgentControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->server = Server::create([
            'name' => 'TestServer',
            'server_url' => 'http://localhost:11434/v1/chat/completions',
            'token' => 'test-token',
        ]);
        LanguageModel::create([
            'name' => 'test-model',
            'server_id' => $this->server->id,
        ]);
        UserSetting::create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'model' => 'test-model',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // T005 — auth:api rejects unauthenticated requests

    #[Test]
    public function agent_endpoint_rejects_unauthenticated_requests()
    {
        $response = $this->postJson('/api/clarion-app/llm-client/agent', [
            'message' => 'Hello',
        ]);

        $response->assertStatus(401);
    }

    // T005 — valid request returns 200 with response

    #[Test]
    public function agent_endpoint_returns_200_with_response()
    {
        $mockService = Mockery::mock(AgentLoopService::class);
        $mockService->shouldReceive('run')
            ->once()
            ->andReturn([
                'status' => 'completed',
                'content' => 'Hello! How can I help you?',
                'message_id' => 'mock-message-id',
            ]);
        $this->app->instance(AgentLoopService::class, $mockService);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/clarion-app/llm-client/agent', [
                'message' => 'Hello',
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['conversation_id', 'message_id', 'content', 'status']);
    }

    // T005 — conversation lookup by user+channel+inactivity threshold

    #[Test]
    public function agent_endpoint_reuses_recent_conversation_by_channel()
    {
        $conversation = Conversation::create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'title' => 'Existing Telegram Convo',
            'model' => 'test-model',
            'character' => 'Clarion',
            'channel' => 'telegram',
            'updated_at' => now()->subHours(1),
        ]);

        $mockService = Mockery::mock(AgentLoopService::class);
        $mockService->shouldReceive('run')
            ->once()
            ->withArgs(function ($conv) use ($conversation) {
                return $conv->id === $conversation->id;
            })
            ->andReturn([
                'status' => 'completed',
                'content' => 'Response',
                'message_id' => 'mock-id',
            ]);
        $this->app->instance(AgentLoopService::class, $mockService);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/clarion-app/llm-client/agent', [
                'message' => 'Hello',
                'channel' => 'telegram',
            ]);

        $response->assertStatus(200)
            ->assertJson(['conversation_id' => $conversation->id]);
    }

    // T005 — confirmation_required returns 202

    #[Test]
    public function agent_endpoint_returns_202_for_confirmation_required()
    {
        $mockService = Mockery::mock(AgentLoopService::class);
        $mockService->shouldReceive('run')
            ->once()
            ->andReturn([
                'status' => 'confirmation_required',
                'content' => '',
                'message_id' => 'mock-message-id',
                'confirmation' => [
                    'operationId' => 'contacts.destroy',
                    'method' => 'DELETE',
                    'path' => '/api/contacts/1',
                    'arguments' => [],
                    'expires_at' => now()->addMinutes(5)->toIso8601String(),
                ],
            ]);
        $this->app->instance(AgentLoopService::class, $mockService);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/clarion-app/llm-client/agent', [
                'message' => 'Delete contact 1',
            ]);

        $response->assertStatus(202)
            ->assertJson(['status' => 'confirmation_required'])
            ->assertJsonStructure(['confirmation']);
    }

    // T005 — error code for missing server

    #[Test]
    public function agent_endpoint_returns_422_when_no_server_configured()
    {
        $userNoServer = User::factory()->create();

        $response = $this->actingAs($userNoServer, 'api')
            ->postJson('/api/clarion-app/llm-client/agent', [
                'message' => 'Hello',
            ]);

        $response->assertStatus(422)
            ->assertJson(['code' => 'no_server']);
    }

    // T005 — error code for processing conflict

    #[Test]
    public function agent_endpoint_returns_409_when_conversation_is_processing()
    {
        $conversation = Conversation::create([
            'user_id' => $this->user->id,
            'server_id' => $this->server->id,
            'title' => 'Processing Convo',
            'model' => 'test-model',
            'character' => 'Clarion',
            'channel' => 'web',
            'is_processing' => true,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/clarion-app/llm-client/agent', [
                'message' => 'Hello',
                'conversation_id' => $conversation->id,
            ]);

        $response->assertStatus(409)
            ->assertJson(['code' => 'processing']);
    }
}
