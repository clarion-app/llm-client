<?php

namespace ClarionApp\LlmClient\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use ClarionApp\LlmClient\Services\McpProtocolHandler;

class McpServerController extends Controller
{
    private McpProtocolHandler $handler;

    public function __construct(McpProtocolHandler $handler)
    {
        $this->handler = $handler;
    }

    public function handle(Request $request): JsonResponse|\Illuminate\Http\Response
    {
        $userId = Auth::id();
        $result = $this->handler->dispatch($request, $userId);

        // Notification — return 204 No Content
        if (!empty($result['_noContent'])) {
            return response()->noContent();
        }

        // Extract session ID if present (set by initialize)
        $sessionId = $result['_sessionId'] ?? null;
        unset($result['_sessionId']);

        $response = response()->json($result, 200, [
            'Content-Type' => 'application/json',
        ]);

        if ($sessionId) {
            $response->header('Mcp-Session-Id', $sessionId);
        }

        return $response;
    }
}
