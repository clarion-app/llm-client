<?php

namespace ClarionApp\LlmClient\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ApiCallConfirmationRequiredEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $conversation_id;
    public string $message_id;
    public string $method;
    public string $path;
    public $body;

    public function __construct(string $conversation_id, string $message_id, string $method, string $path, $body = null)
    {
        $this->conversation_id = $conversation_id;
        $this->message_id = $message_id;
        $this->method = $method;
        $this->path = $path;
        $this->body = $body;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("Conversation.{$this->conversation_id}")
        ];
    }
}
