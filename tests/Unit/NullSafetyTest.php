<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\Backend\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class NullSafetyTest extends TestCase
{
    use RefreshDatabase;

    /** @test T038 — ServerController update returns 404 for non-existent server */
    public function server_update_returns_404_for_non_existent()
    {
        $user = User::factory()->create();
        $fakeId = '00000000-0000-0000-0000-000000000000';

        $response = $this->actingAs($user, 'api')
            ->putJson("/api/clarion-app/llm-client/server/{$fakeId}", [
                'name' => 'Test',
                'server_url' => 'http://localhost',
                'token' => 'tok',
            ]);

        $response->assertStatus(404);
    }

    /** @test T038 — ServerController destroy returns 404 for non-existent server */
    public function server_destroy_returns_404_for_non_existent()
    {
        $user = User::factory()->create();
        $fakeId = '00000000-0000-0000-0000-000000000000';

        $response = $this->actingAs($user, 'api')
            ->deleteJson("/api/clarion-app/llm-client/server/{$fakeId}");

        $response->assertStatus(404);
    }

    /** @test T039 — ConversationController store returns 422 when no server/model available */
    public function conversation_store_returns_422_when_no_server_available()
    {
        $user = User::factory()->create();

        // No servers or models exist
        $response = $this->actingAs($user, 'api')
            ->postJson('/api/clarion-app/llm-client/conversation', [
                'title' => 'Test',
            ]);

        $response->assertStatus(422);
    }
}
