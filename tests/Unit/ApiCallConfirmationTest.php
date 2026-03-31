<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\Backend\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

    /** @test T024 — approval triggers execution of pending call */
    public function approval_executes_pending_call()
    {
        $pendingData = json_encode([
            '__pending_api_call' => true,
            'operationId' => 'updateContact',
            'method' => 'PUT',
            'path' => '/api/clarion-app/contacts/contact/123',
            'body' => ['name' => 'Updated'],
        ]);

        $message = Message::create([
            'conversation_id' => $this->conversation->id,
            'role' => 'system',
            'user' => 'System',
            'content' => $pendingData,
            'responseTime' => 0,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->postJson("/api/clarion-app/llm-client/conversation/{$this->conversation->id}/confirm-api-call", [
                'approved' => true,
                'message_id' => $message->id,
            ]);

        $response->assertStatus(200);
    }

    /** @test T024 — denial cancels pending call */
    public function denial_cancels_pending_call()
    {
        $pendingData = json_encode([
            '__pending_api_call' => true,
            'operationId' => 'deleteContact',
            'method' => 'DELETE',
            'path' => '/api/clarion-app/contacts/contact/123',
            'body' => null,
        ]);

        $message = Message::create([
            'conversation_id' => $this->conversation->id,
            'role' => 'system',
            'user' => 'System',
            'content' => $pendingData,
            'responseTime' => 0,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->postJson("/api/clarion-app/llm-client/conversation/{$this->conversation->id}/confirm-api-call", [
                'approved' => false,
                'message_id' => $message->id,
            ]);

        $response->assertStatus(200);

        // Verify the message is marked as cancelled
        $updatedMessage = Message::find($message->id);
        $content = json_decode($updatedMessage->content, true);
        $this->assertTrue($content['__cancelled'] ?? false);
    }

    /** @test T024 — non-owner cannot confirm */
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

    /** @test T024 — 404 for non-existent conversation */
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
