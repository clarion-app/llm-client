<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Services\ServerStatusProjector;
use Illuminate\Http\JsonResponse;

/**
 * Controller for server status endpoints.
 *
 * GET /server-status returns one entry for every server,
 * including those with no status row (projected as never_checked).
 */
class ServerStatusController extends Controller
{
    /**
     * Get the status projection for all servers.
     *
     * Returns one entry per server, computed on every read via
     * ServerStatusProjector (SC-008 — no queue worker required).
     */
    public function index(ServerStatusProjector $projector): JsonResponse
    {
        $servers = Server::all();
        $statuses = [];

        foreach ($servers as $server) {
            $projected = $projector->project($server);
            $statuses[] = array_merge(
                ['server_id' => $server->id],
                $projected,
            );
        }

        return response()->json($statuses, 200);
    }
}
