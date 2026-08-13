<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\Backend\Models\User;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\Server;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * research.md D5, quickstart.md step 8 — Phase 3/T016
 * (090-agent-version-binding).
 *
 * A conversation created without naming an agent_id — the existing, still
 * far more common path — must be completely unaffected by this feature:
 * agent_id/agent_version_id both written null, and every other existing
 * behavior (201, the initial-greeting Message, server_id/model resolution)
 * unchanged. A locked-in regression guard, not a test that is expected to
 * be red before implementation — omitting agent_id already produces null
 * columns with no code change needed, so this test MAY already pass today.
 * It exists to prove a future change never accidentally breaks this path.
 */
class ConversationWithoutAgentUnaffectedRegressionTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        DB::table('messages')->delete();
        DB::table('conversations')->delete();
        DB::table('llm_servers')->delete();
        DB::table('users')->delete();

        parent::tearDown();
    }

    #[Test]
    public function a_conversation_created_without_an_agent_id_writes_null_binding_columns(): void
    {
        $server = Server::create([
            'name' => 'TestServer',
            'server_url' => 'https://api.openai.com/v1/chat/completions',
            'token' => 'test-token',
        ]);

        $response = $this->actingAs($this->user, 'api')->postJson('/api/clarion-app/llm-client/conversation', [
            'title' => 'No agent here',
            'server_id' => $server->id,
            'model' => 'gpt-4o',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('agent_id', null);
        $response->assertJsonPath('agent_version_id', null);

        $conversation = Conversation::find($response->json('id'));
        $this->assertNotNull($conversation);
        $this->assertNull($conversation->agent_id);
        $this->assertNull($conversation->agent_version_id);

        // Existing behavior around the no-agent path is otherwise unchanged.
        $this->assertSame($this->user->id, $conversation->user_id);
        $this->assertSame($server->id, $conversation->server_id);
        $this->assertSame('gpt-4o', $conversation->model);
        $this->assertSame(
            1,
            Message::where('conversation_id', $conversation->id)->count(),
            'the initial-greeting Message must still be created, unaffected by this feature',
        );
    }
}
