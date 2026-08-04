<?php

namespace ClarionApp\LlmClient\Events;

use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\ServerStatusProjector;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast event dispatched after a model refresh completes.
 *
 * Sent on PrivateChannel('User.{triggeredBy}') so the initiating user
 * receives a real-time push matching the REST projection.
 * Not dispatched when triggered_by is null (system-initiated refresh).
 */
class ServerModelsRefreshed implements ShouldBroadcastNow
{
    use SerializesModels;

    public function __construct(
        public readonly string $serverId,
        public readonly ?string $triggeredBy,
    ) {}

    /**
     * The channel(s) this event should broadcast on.
     *
     * Only broadcasts when triggered_by is set (user-initiated refresh).
     */
    public function broadcastOn(): array
    {
        if ($this->triggeredBy === null) {
            return [];
        }

        return [
            new PrivateChannel('User.' . $this->triggeredBy),
        ];
    }

    /**
     * The data to broadcast.
     *
     * Returns the same ServerStatusProjector output as GET /server-status
     * so a pushed status can never disagree with a fetched one.
     */
    public function broadcastWith(): array
    {
        $server = Server::find($this->serverId);
        if (!$server) {
            return [
                'server_id' => $this->serverId,
            ];
        }

        $projector = app(ServerStatusProjector::class);
        $projected = $projector->project($server);

        return array_merge(
            ['server_id' => $this->serverId],
            $projected,
        );
    }
}
