<?php

namespace ClarionApp\LlmClient\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EpisodicMemoryGenerationFailed implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public readonly string $userId,
        public readonly string $conversationId,
        public readonly string $error,
        public readonly string $type = 'summarization_failed'
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<Channel>|Channel
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.'.$this->userId.'.episodic-memory-failed'),
        ];
    }

    /**
     * Get the data that should be broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'error' => $this->error,
            'type' => $this->type,
        ];
    }

    /**
     * Get the event name for broadcasting.
     */
    public function broadcastAs(): ?string
    {
        return 'episodic-memory-generation-failed';
    }
}
