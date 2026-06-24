<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\AgentLoopService;
use ClarionApp\Backend\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use PHPUnit\Framework\Attributes\Test;
use Mockery;

class ApiCallConfirmationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->conversation = Conversation::create([
            'user_id' => $this->user->id,
            'title' => 'Command Conversation',
            'model' => 'test-model',
            'character' => 'Clarion',
            'server_id' => null,
        ]);
    }

    // T024 — approval triggers execution of pending call

    #[Test]
    public function approval_executes_pending_call()
    {
        $this->mock(AgentLoopService::class, function ($mock) {
            $mock->shouldReceive('resume')->once();
        });

        $message = Message::create([
            'conversation_id' => $this->conversation->id,
            'role' => 'system',
            'user' => 'System',
            'content' => '',
            'responseTime' => 0,
            'tool_data' => [
                'pending_confirmation' => [
                    'operationId' => 'updateContact',
                    'method' => 'PUT',
                    'path' => '/api/clarion-app/contacts/contact/123',
                    'body' => ['name' => 'Updated'],
                    'expires_at' => now()->addMinutes(5)->toDateTimeString(),
                ],
            ],
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->postJson("/api/clarion-app/llm-client/conversation/{$this->conversation->id}/confirm-api-call", [
                'approved' => true,
                'message_id' => $message->id,
            ]);

        $response->assertStatus(200);
    }

    #[Test]
    public function denial_cancels_pending_call()
    {
        $this->mock(AgentLoopService::class, function ($mock) {
            $mock->shouldReceive('resume')->once();
        });

        $message = Message::create([
            'conversation_id' => $this->conversation->id,
            'role' => 'system',
            'user' => 'System',
            'content' => '',
            'responseTime' => 0,
            'tool_data' => [
                'pending_confirmation' => [
                    'operationId' => 'deleteContact',
                    'method' => 'DELETE',
                    'path' => '/api/clarion-app/contacts/contact/123',
                    'body' => null,
                    'expires_at' => now()->addMinutes(5)->toDateTimeString(),
                ],
            ],
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->postJson("/api/clarion-app/llm-client/conversation/{$this->conversation->id}/confirm-api-call", [
                'approved' => false,
                'message_id' => $message->id,
            ]);

        $response->assertStatus(200);
    }

    // T024 — non-owner cannot confirm

    #[Test]
    public function non_owner_cannot_confirm_api_call()
    {
        $otherUser = User::factory()->create();

        $response = $this->actingAs($otherUser, 'api')
            ->postJson("/api/clarion-app/llm-client/conversation/{$this->conversation->id}/confirm-api-call", [
                'approved' => true,
                'message_id' => 'some-id',
            ]);

        $response->assertStatus(403);
    }

    // T024 — 404 for non-existent conversation

    #[Test]
    public function returns_404_for_non_existent_conversation()
    {
        $fakeId = '00000000-0000-0000-0000-000000000000';

        $response = $this->actingAs($this->user, 'api')
            ->postJson("/api/clarion-app/llm-client/conversation/{$fakeId}/confirm-api-call", [
                'approved' => true,
                'message_id' => 'some-id',
            ]);

        $response->assertStatus(404);
    }
}
