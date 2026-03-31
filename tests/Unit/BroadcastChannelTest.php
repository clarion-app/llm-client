<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\Events\FinishOpenAIConversationResponseEvent;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Events\UpdateOpenAIConversationResponseEvent;
use Illuminate\Broadcasting\PrivateChannel;

class BroadcastChannelTest extends TestCase
{
    /** @test T016 */
    public function finish_event_broadcasts_on_private_channel()
    {
        $event = new FinishOpenAIConversationResponseEvent('test-conv-id', 'test reply');
        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
    }

    /** @test T016 */
    public function new_message_event_broadcasts_on_private_channel()
    {
        $event = new NewConversationMessageEvent('test-conv-id', 'test-msg-id');
        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
    }

    /** @test T016 */
    public function update_event_broadcasts_on_private_channel()
    {
        $event = new UpdateOpenAIConversationResponseEvent('test-conv-id', 'test-msg-id', 'test reply');
        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
    }
}
