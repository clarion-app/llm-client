<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\LanguageModel;
use ClarionApp\Backend\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use PHPUnit\Framework\Attributes\Test;

class ConversationChannelTest extends TestCase
{
    use RefreshDatabase;

    private function createConversation(array $overrides = []): Conversation
    {
        $user = User::factory()->create();
        $server = Server::create([
            'name' => 'TestServer',
            'server_url' => 'http://localhost:11434/v1/chat/completions',
            'token' => 'test-token',
        ]);

        return Conversation::create(array_merge([
            'user_id' => $user->id,
            'server_id' => $server->id,
            'title' => 'Test Conversation',
            'model' => 'test-model',
            'character' => 'Clarion',
        ], $overrides));
    }

    // T004 — channel accessor returns 'web' for null

    #[Test]
    public function channel_accessor_returns_web_for_null()
    {
        $conversation = $this->createConversation();
        $this->assertNull($conversation->getAttributes()['channel'] ?? null);
        $this->assertEquals('web', $conversation->channel);
    }

    // T004 — stores explicit channel value

    #[Test]
    public function stores_explicit_channel_value()
    {
        $conversation = $this->createConversation(['channel' => 'telegram']);
        $this->assertEquals('telegram', $conversation->channel);
    }

    // T004 — validates channel format allows valid values

    #[Test]
    public function validates_channel_format_allows_valid_values()
    {
        $validChannels = ['web', 'telegram', 'discord', 'whats-app', 'custom_channel', 'my-channel-123'];

        foreach ($validChannels as $channel) {
            $conversation = $this->createConversation(['channel' => $channel]);
            $this->assertEquals($channel, $conversation->channel, "Channel '{$channel}' should be valid");
        }
    }

    // T004 — channel is in fillable

    #[Test]
    public function channel_is_in_fillable()
    {
        $conversation = new Conversation();
        $this->assertContains('channel', $conversation->getFillable());
    }

    // T004 — channel max length is 50 characters

    #[Test]
    public function channel_max_length_is_50_characters()
    {
        $conversation = $this->createConversation(['channel' => str_repeat('a', 50)]);
        $this->assertEquals(str_repeat('a', 50), $conversation->channel);
    }
}
