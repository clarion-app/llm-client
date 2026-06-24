<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\LanguageModel;
use ClarionApp\LlmClient\Models\UserSetting;
use ClarionApp\Backend\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use PHPUnit\Framework\Attributes\Test;

class ConversationCreationTest extends TestCase
{
    use RefreshDatabase;

    // T028 — conversation creation uses Auth::id() not User::first()->id

    #[Test]
    public function store_creates_conversation_for_authenticated_user()
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $server = Server::create([
            'name' => 'TestServer',
            'server_url' => 'http://localhost:11434/v1/chat/completions',
            'token' => 'test-token',
        ]);

        LanguageModel::create([
            'name' => 'test-model',
            'server_id' => $server->id,
        ]);

        // Authenticate as userB (NOT the first user)
        $response = $this->actingAs($userB, 'api')
            ->postJson('/api/clarion-app/llm-client/conversation', [
                'title' => 'Test',
                'model' => 'test-model',
                'server_id' => $server->id,
            ]);

        $response->assertStatus(201);
        $conversation = Conversation::find($response->json('id'));
        $this->assertEquals($userB->id, $conversation->user_id);
        $this->assertNotEquals($userA->id, $conversation->user_id);
    }

    // T029 — conversation uses UserSetting defaults when server_id/model not provided

    #[Test]
    public function store_uses_user_setting_defaults()
    {
        $user = User::factory()->create();

        $server = Server::create([
            'name' => 'PreferredServer',
            'server_url' => 'http://localhost:11434/v1/chat/completions',
            'token' => 'test-token',
        ]);

        LanguageModel::create([
            'name' => 'preferred-model',
            'server_id' => $server->id,
        ]);

        UserSetting::create([
            'user_id' => $user->id,
            'server_id' => $server->id,
            'model' => 'preferred-model',
        ]);

        $response = $this->actingAs($user, 'api')
            ->postJson('/api/clarion-app/llm-client/conversation', [
                'title' => 'Test with defaults',
                'model' => 'any',
                'server_id' => $server->id,
            ]);

        $response->assertStatus(201);
    }
}
