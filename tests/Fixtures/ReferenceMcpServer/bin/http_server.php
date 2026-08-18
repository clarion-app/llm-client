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
$toolNamesFile = getenv('REFERENCE_MCP_TOOL_NAMES_FILE');
$toolNamesFile = $toolNamesFile !== false ? $toolNamesFile : null;
$requestLogFile = getenv('REFERENCE_MCP_REQUEST_LOG_FILE');
$requestLogFile = $requestLogFile !== false ? $requestLogFile : null;

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

// Read fresh on every request (never cached in a variable set once at
// process start, unlike $mode above) -- this is what lets setTools()
// change what this same running process reports mid-test.
$toolNames = null;
if ($toolNamesFile !== null && is_file($toolNamesFile)) {
    $raw = file_get_contents($toolNamesFile) ?: '';
    $toolNames = array_values(array_filter(
        array_map('trim', explode(',', $raw)),
        fn (string $name) => $name !== '',
    ));
}

// Appended, never overwritten -- ReferenceMcpServer::loggedToolCalls()
// reads this back to confirm which physical process a given call
// actually reached, independent of what that call's own response
// content shows.
if ($requestLogFile !== null && $method === 'tools/call') {
    file_put_contents(
        $requestLogFile,
        json_encode(['tool' => $params['name'] ?? null, 'arguments' => $params['arguments'] ?? []]) . "\n",
        FILE_APPEND | LOCK_EX,
    );
}

echo Protocol::rawResponseBody($method, $params, $mode, $delaySeconds, $id, $toolNames);
