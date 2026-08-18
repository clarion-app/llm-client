<?php

/**
 * Servable entry point for the reference MCP server fixture's HTTP form.
 * Run under PHP's own built-in web server (never in production, never
 * outside a test process) by ReferenceMcpServer::startHttp():
 *
 *   php -S 127.0.0.1:{port} bin/http_server.php
 *
 * Reads one JSON-RPC request per POST body, decodes it, and writes back
 * whatever Protocol::rawResponseBody() produces for the configured mode
 * -- unmodified, so a caller sees exactly the same bytes a real
 * Streamable HTTP MCP server would send.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

use Tests\Fixtures\ReferenceMcpServer\Protocol;

$mode = getenv('REFERENCE_MCP_MODE') ?: Protocol::MODE_HAPPY_PATH;
$expectedToken = getenv('REFERENCE_MCP_EXPECTED_TOKEN');
$expectedToken = $expectedToken !== false ? $expectedToken : null;
$delayRaw = getenv('REFERENCE_MCP_DELAY_SECONDS');
$delaySeconds = $delayRaw !== false ? (float) $delayRaw : null;

header('Content-Type: application/json');

$body = file_get_contents('php://input') ?: '{}';
$request = json_decode($body, true);
$request = is_array($request) ? $request : [];
$method = $request['method'] ?? '';
$params = $request['params'] ?? [];
$id = $request['id'] ?? 1;

if ($expectedToken !== null) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($authHeader !== "Bearer {$expectedToken}") {
        http_response_code(401);
        echo json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => -32001, 'message' => 'Unauthorized'],
        ]);
        exit;
    }
}

echo Protocol::rawResponseBody($method, $params, $mode, $delaySeconds, $id);
