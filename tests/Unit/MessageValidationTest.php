<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\Backend\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use PHPUnit\Framework\Attributes\Test;

class MessageValidationTest extends TestCase
{
    use RefreshDatabase;

    // T041 — message with invalid role is rejected

    #[Test]
    public function rejects_invalid_role()
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Test',
            'model' => 'test-model',
            'character' => 'Clarion',
            'server_id' => null,
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'user' => $user->name,
            'content' => 'Hello',
            'responseTime' => 0,
        ]);

        $response = $this->actingAs($user, 'api')
            ->putJson("/api/clarion-app/llm-client/message/{$message->id}", [
                'content' => 'Updated',
                'role' => 'hacker',
                'user' => $user->name,
                'conversation_id' => $conversation->id,
            ]);

        $response->assertStatus(422);
    }

    // T041 — valid roles are accepted

    #[Test]
    public function accepts_valid_roles()
    {
        $user = User::factory()->create();
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'title' => 'Test',
            'model' => 'test-model',
            'character' => 'Clarion',
            'server_id' => null,
        ]);

        foreach (['assistant', 'user', 'system'] as $role) {
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'role' => 'user',
                'user' => $user->name,
                'content' => 'Hello',
                'responseTime' => 0,
            ]);

            $response = $this->actingAs($user, 'api')
                ->putJson("/api/clarion-app/llm-client/message/{$message->id}", [
                    'content' => 'Updated',
                    'role' => $role,
                    'user' => $user->name,
                    'conversation_id' => $conversation->id,
                ]);

            $response->assertStatus(200);
        }
    }
}
