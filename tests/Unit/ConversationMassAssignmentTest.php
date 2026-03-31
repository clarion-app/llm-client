<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\LanguageModel;
use ClarionApp\Backend\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ConversationMassAssignmentTest extends TestCase
{
    use RefreshDatabase;

    /** @test T033 — update with extra user_id field does not change owner */
    public function update_with_extra_user_id_does_not_change_owner()
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $server = Server::create([
            'name' => 'TestServer',
            'server_url' => 'http://localhost:11434/v1/chat/completions',
            'token' => 'test-token',
        ]);

        $conversation = Conversation::create([
            'user_id' => $owner->id,
            'title' => 'Original Title',
            'model' => 'test-model',
            'character' => 'Clarion',
            'server_id' => $server->id,
        ]);

        // Try to change owner via mass assignment
        $response = $this->actingAs($owner, 'api')
            ->putJson("/api/clarion-app/llm-client/conversation/{$conversation->id}", [
                'title' => 'Updated Title',
                'model' => 'test-model',
                'server_id' => $server->id,
                'user_id' => $attacker->id, // This should be ignored
            ]);

        $response->assertStatus(200);

        $conversation->refresh();
        $this->assertEquals($owner->id, $conversation->user_id);
        $this->assertNotEquals($attacker->id, $conversation->user_id);
        $this->assertEquals('Updated Title', $conversation->title);
    }
}
