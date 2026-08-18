<?php

/**
 * Servable entry point for the reference MCP server fixture's stdio
 * form. Spawned per call by ReferenceMcpServer::stdioCommand() (never in
 * production, never outside a test process):
 *
 *   php bin/stdio_server.php
 *
 * Reads one JSON-RPC request per line from STDIN and writes one
 * JSON-RPC response per line to STDOUT, so a caller can send an
 * initialize followed by one tools/list or tools/call within the same
 * process lifetime -- matching a stdio transport's one-subprocess-per-
 * call shape, where that one subprocess still performs both the
 * handshake and the actual operation before it is torn down.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

use Tests\Fixtures\ReferenceMcpServer\Protocol;

$mode = getenv('REFERENCE_MCP_MODE') ?: Protocol::MODE_HAPPY_PATH;
$expectedCredential = getenv('REFERENCE_MCP_EXPECTED_CREDENTIAL');
$expectedCredential = $expectedCredential !== false ? $expectedCredential : null;
$actualCredential = getenv('REFERENCE_MCP_CREDENTIAL');
$actualCredential = $actualCredential !== false ? $actualCredential : null;
$delayRaw = getenv('REFERENCE_MCP_DELAY_SECONDS');
$delaySeconds = $delayRaw !== false ? (float) $delayRaw : null;

while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }

    $request = json_decode($line, true);
    $request = is_array($request) ? $request : [];
    $method = $request['method'] ?? '';
    $params = $request['params'] ?? [];
    $id = $request['id'] ?? 1;

    if ($expectedCredential !== null && $actualCredential !== $expectedCredential) {
        echo json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => -32001, 'message' => 'Unauthorized'],
        ]) . "\n";
        flush();
        continue;
    }

    echo Protocol::rawResponseBody($method, $params, $mode, $delaySeconds, $id) . "\n";
    flush();
}
