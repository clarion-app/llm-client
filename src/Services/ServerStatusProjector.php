<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Models\ServerStatus;

/**
 * Derives the "current state" projection for a server's status on every READ.
 *
 * No timers, no background processes — the projection is computed from the raw
 * ServerStatus row (or the absence of one) at the moment of the request.
 *
 * Projection rules:
 * - Missing row → never_checked with everything else null/0.
 * - in_flight is true only while refresh_started_at != null,
 *   refresh_finished_at == null, and (now - started) < 60s.
 * - At >= 60s the projection flips last_outcome to "did_not_complete"
 *   but leaves connection_status unchanged.
 */
class ServerStatusProjector
{
    /**
     * Seconds before an in-flight refresh is considered stale.
     */
    public const STALE_THRESHOLD_SECONDS = 60;

    /**
     * Project the current status for a server.
     *
     * @return array<string, mixed> Projection array compatible with the REST API response.
     */
    public function project(Server $server): array
    {
        $status = ServerStatus::where('server_id', $server->id)->first();

        if ($status === null) {
            return $this->neverCheckedProjection();
        }

        $connectionStatus = $status->connection_status;
        $lastOutcome = $status->last_outcome;
        $lastError = $status->last_error;
        $modelCount = $status->model_count;
        $refreshStartedAt = $status->refresh_started_at;
        $refreshFinishedAt = $status->refresh_finished_at;

        // Determine in_flight and apply stale-refresh override.
        $inFlight = false;
        if ($refreshStartedAt !== null && $refreshFinishedAt === null) {
            $elapsedSeconds = $refreshStartedAt->diffInSeconds(now(), false);
            if ($elapsedSeconds < self::STALE_THRESHOLD_SECONDS) {
                $inFlight = true;
            } else {
                // Stale: refresh started but never finished within the threshold.
                $lastOutcome = 'did_not_complete';
            }
        }

        return [
            'server_id' => $server->id,
            'server_name' => $server->name,
            'connection_status' => $connectionStatus,
            'last_outcome' => $lastOutcome,
            'last_error' => $lastError,
            'model_count' => $modelCount,
            'in_flight' => $inFlight,
            'refresh_started_at' => $refreshStartedAt?->toIso8601String(),
            'refresh_finished_at' => $refreshFinishedAt?->toIso8601String(),
        ];
    }

    /**
     * Default projection when no status row exists for the server.
     *
     * @return array<string, mixed>
     */
    private function neverCheckedProjection(): array
    {
        return [
            'connection_status' => 'never_checked',
            'last_outcome' => null,
            'last_error' => null,
            'model_count' => 0,
            'in_flight' => false,
            'refresh_started_at' => null,
            'refresh_finished_at' => null,
        ];
    }

    /**
     * Project the status for ALL servers, including those with no status row.
     *
     * @return array<int, array<string, mixed>>
     */
    public function projectAll(): array
    {
        $servers = Server::orderBy('name')->get();
        $results = [];

        foreach ($servers as $server) {
            $projection = $this->project($server);
            $projection['server_id'] = $server->id;
            $projection['server_name'] = $server->name;
            $results[] = $projection;
        }

        return $results;
    }
}
