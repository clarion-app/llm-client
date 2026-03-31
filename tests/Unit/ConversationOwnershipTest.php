<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\Backend\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ConversationOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected User $ownerUser;
    protected User $otherUser;
    protected Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ownerUser = User::factory()->create();
        $this->otherUser = User::factory()->create();

        $this->conversation = Conversation::create([
            'user_id' => $this->ownerUser->id,
            'title' => 'Test Conversation',
            'model' => 'test-model',
            'character' => 'Clarion',
            'server_id' => null,
        ]);
    }

    /** @test T010 */
    public function show_returns_403_for_non_owner()
    {
        $response = $this->actingAs($this->otherUser, 'api')
            ->getJson("/api/clarion-app/llm-client/conversation/{$this->conversation->id}");

        $response->assertStatus(403);
    }

    /** @test T011 */
    public function update_returns_403_for_non_owner()
    {
        $response = $this->actingAs($this->otherUser, 'api')
            ->putJson("/api/clarion-app/llm-client/conversation/{$this->conversation->id}", [
                'title' => 'Hacked Title',
                'model' => 'test-model',
                'server_id' => 'some-id',
            ]);

        $response->assertStatus(403);
    }

    /** @test T012 */
    public function destroy_returns_403_for_non_owner()
    {
        $response = $this->actingAs($this->otherUser, 'api')
            ->deleteJson("/api/clarion-app/llm-client/conversation/{$this->conversation->id}");

        $response->assertStatus(403);
    }

    /** @test T013 */
    public function generate_title_returns_403_for_non_owner()
    {
        $response = $this->actingAs($this->otherUser, 'api')
            ->postJson("/api/clarion-app/llm-client/conversation/{$this->conversation->id}/generate-title");

        $response->assertStatus(403);
    }

    /** @test T014 */
    public function show_returns_404_for_non_existent_conversation()
    {
        $fakeId = '00000000-0000-0000-0000-000000000000';

        $response = $this->actingAs($this->ownerUser, 'api')
            ->getJson("/api/clarion-app/llm-client/conversation/{$fakeId}");

        $response->assertStatus(404);
    }

    /** @test T014 */
    public function update_returns_404_for_non_existent_conversation()
    {
        $fakeId = '00000000-0000-0000-0000-000000000000';

        $response = $this->actingAs($this->ownerUser, 'api')
            ->putJson("/api/clarion-app/llm-client/conversation/{$fakeId}", [
                'title' => 'Test',
                'model' => 'test-model',
                'server_id' => 'some-id',
            ]);

        $response->assertStatus(404);
    }

    /** @test T014 */
    public function destroy_returns_404_for_non_existent_conversation()
    {
        $fakeId = '00000000-0000-0000-0000-000000000000';

        $response = $this->actingAs($this->ownerUser, 'api')
            ->deleteJson("/api/clarion-app/llm-client/conversation/{$fakeId}");

        $response->assertStatus(404);
    }

    /** @test T014a */
    public function show_returns_404_for_soft_deleted_conversation()
    {
        $this->conversation->delete();

        $response = $this->actingAs($this->ownerUser, 'api')
            ->getJson("/api/clarion-app/llm-client/conversation/{$this->conversation->id}");

        $response->assertStatus(404);
    }

    /** @test Owner can access their own conversation */
    public function show_returns_200_for_owner()
    {
        $response = $this->actingAs($this->ownerUser, 'api')
            ->getJson("/api/clarion-app/llm-client/conversation/{$this->conversation->id}");

        $response->assertStatus(200);
    }
}
