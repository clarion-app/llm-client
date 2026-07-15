<?php

namespace ClarionApp\LlmClient\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when a learned preference reaches the promotion threshold
 * and is proposed to the user for confirmation.
 */
class PreferenceProposalEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $userId,
        public readonly string $patternKey,
        public readonly string $preferenceDescription,
        public readonly int $confidenceScore,
        public readonly int $signalsCount,
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<Channel>|Channel
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.'.$this->userId.'.preference-proposal'),
        ];
    }

    /**
     * Get the data that should be broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'pattern_key' => $this->patternKey,
            'preference_description' => $this->preferenceDescription,
            'confidence_score' => $this->confidenceScore,
            'signals_count' => $this->signalsCount,
        ];
    }

    /**
     * Get the event name for broadcasting.
     */
    public function broadcastAs(): ?string
    {
        return 'preference-proposal';
    }
}
