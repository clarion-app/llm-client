<?php

namespace ClarionApp\LlmClient\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ToolExecutionEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $conversation_id;
    public string $tool_name;
    public string $status;

    public function __construct(string $conversation_id, string $tool_name, string $status)
    {
        $this->conversation_id = $conversation_id;
        $this->tool_name = $tool_name;
        $this->status = $status;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("Conversation.{$this->conversation_id}")
        ];
    }
}
