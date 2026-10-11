<?php

namespace ClarionApp\LlmClient\Tests\Feature;

use ClarionApp\LlmClient\Contracts\ConversationChannelDelivery;
use ClarionApp\LlmClient\Events\NewConversationMessageEvent;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Services\WebChannelDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The built-in `web` adapter. llm-client has already persisted the message
 * it is asked to deliver, so "delivery" on the web is only telling the open
 * conversation screen to refetch: it broadcasts NewConversationMessageEvent
 * for the conversation's latest message and never writes a message itself.
 */
class WebChannelDeliveryTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::table('messages')->delete();
        DB::table('conversations')->delete();

        parent::tearDown();
    }

    private function message(Conversation $conversation, string $content, string $createdAt): Message
    {
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'user' => 'Clarion',
            'content' => $content,
            'responseTime' => 0,
        ]);
        DB::table('messages')->where('id', $message->id)->update(['created_at' => $createdAt, 'updated_at' => $createdAt]);

        return $message;
    }

    #[Test]
    public function it_serves_the_web_channel(): void
    {
        $adapter = app(WebChannelDelivery::class);

        $this->assertInstanceOf(ConversationChannelDelivery::class, $adapter);
        $this->assertSame('web', $adapter->channel());
    }

    #[Test]
    public function deliver_broadcasts_the_latest_message_and_reports_success(): void
    {
        Event::fake([NewConversationMessageEvent::class]);

        $conversation = Conversation::factory()->create(['channel' => 'web']);
        $other = Conversation::factory()->create(['channel' => 'web']);
        $this->message($conversation, 'Earlier reply', '2026-10-11 09:00:00');
        $latest = $this->message($conversation, "Which part should I change first?\n\n1. Backend", '2026-10-11 09:05:00');
        $this->message($other, 'Unrelated, and newer', '2026-10-11 09:10:00');

        $result = app(WebChannelDelivery::class)->deliver($conversation, "Which part should I change first?\n\n1. Backend");

        $this->assertTrue($result->delivered);
        $this->assertNull($result->failureReason);

        Event::assertDispatchedTimes(NewConversationMessageEvent::class, 1);
        Event::assertDispatched(
            NewConversationMessageEvent::class,
            fn (NewConversationMessageEvent $event) => $event->conversation_id === $conversation->id
                && $event->message_id === $latest->id,
        );
    }

    #[Test]
    public function deliver_never_persists_a_message(): void
    {
        Event::fake([NewConversationMessageEvent::class]);

        $conversation = Conversation::factory()->create(['channel' => 'web']);
        $this->message($conversation, 'Already stored', '2026-10-11 09:00:00');
        $before = DB::table('messages')->count();

        app(WebChannelDelivery::class)->deliver($conversation, 'Already stored');

        $this->assertSame($before, DB::table('messages')->count());
    }
}
