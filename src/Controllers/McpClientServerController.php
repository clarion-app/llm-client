<?php

namespace ClarionApp\LlmClient\Controllers;

use App\Http\Controllers\Controller;
use ClarionApp\LlmClient\Jobs\RefreshMcpClientServerToolsJob;
use ClarionApp\LlmClient\Jobs\TestMcpClientConnectionJob;
use ClarionApp\LlmClient\Models\McpClientConnectionTest;
use ClarionApp\LlmClient\Models\McpClientServer;
use ClarionApp\LlmClient\Models\McpClientServerStatus;
use ClarionApp\LlmClient\Models\McpClientTool;
use ClarionApp\LlmClient\ValueObjects\McpTransportKind;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * McpClientServerController -- the server-configuration API for a user's
 * (or a project's) connections to third-party MCP servers: create, list,
 * inspect, on-demand refresh, and soft-delete. Every method resolves the
 * caller via Auth::user()->id and re-derives visibility through
 * McpClientServer::eligibleFor() -- the same personal-or-installation-
 * scope predicate RoleAssignment resolution already applies for role
 * scoping -- so a server outside the caller's scope is indistinguishable
 * from one that does not exist at all, mirroring RunController's own
 * uniform-404 shape (never a distinguishing 403, so existence is never
 * leaked to an ineligible user).
 */
class McpClientServerController extends Controller
{
    /**
     * GET /mcp-client-server -- servers eligible to the caller (own +
     * installation scope).
     */
    public function index(Request $request): JsonResponse
    {
        $callerUserId = Auth::user()->id;

        $servers = McpClientServer::query()
            ->eligibleFor($callerUserId)
            ->get();

        // One query for every relevant status row, keyed by server_id, so
        // this stays O(1) regardless of how many servers the caller has
        // eligible -- never one status query per server (plan.md
        // Performance Goals; Grounding note 1).
        $statuses = McpClientServerStatus::query()
            ->whereIn('server_id', $servers->pluck('id'))
            ->get()
            ->keyBy('server_id');

        $summaries = $servers
            ->map(fn (McpClientServer $server) => $this->serverSummaryWithStatus($server, $statuses->get($server->id)))
            ->values();

        return response()->json($summaries);
    }

    /**
     * POST /mcp-client-server -- create a server at the caller-chosen
     * scope and immediately queue a tool refresh, so its currently
     * offered tools become visible without any further user action, the
     * same "queue a refresh job right on create" shape
     * ServerController::store() already establishes for RefreshServerModelsJob.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request);

        $userId = $validated['scope'] === 'project'
            ? McpClientServer::INSTALLATION_SCOPE_ID
            : Auth::user()->id;

        $server = McpClientServer::create([
            'name' => $validated['name'],
            'transport' => $validated['transport'],
            'url' => $validated['url'] ?? null,
            'command' => $validated['command'] ?? null,
            'args' => $validated['args'] ?? null,
            'credential' => $validated['credential'] ?? null,
            'user_id' => $userId,
        ]);

        RefreshMcpClientServerToolsJob::dispatch($server->id, 'create');

        return response()->json([
            'id' => $server->id,
            'name' => $server->name,
            'transport' => $server->transport->value,
            'scope' => $server->scope,
            'status' => 'pending',
        ], 201);
    }

    /**
     * GET /mcp-client-server/{id} -- one server plus its current cached
     * tools and reachability status (US1 Acceptance Scenario 1).
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $server = $this->findEligible($id);
        if ($server === null) {
            return $this->notFoundResponse();
        }

        return response()->json($this->serverDetail($server));
    }

    /**
     * DELETE /mcp-client-server/{id} -- soft-delete.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $server = $this->findEligible($id);
        if ($server === null) {
            return $this->notFoundResponse();
        }

        $server->delete();

        return response()->json([], 204);
    }

    /**
     * POST /mcp-client-server/{id}/refresh -- on-demand tool-list
     * refresh, dispatched the same way store() dispatches its own
     * initial refresh.
     */
    public function refresh(Request $request, string $id): JsonResponse
    {
        $server = $this->findEligible($id);
        if ($server === null) {
            return $this->notFoundResponse();
        }

        RefreshMcpClientServerToolsJob::dispatch($server->id, 'manual');

        return response()->json($this->serverDetail($server->fresh()));
    }

    /**
     * POST /mcp-client-server/test-connection -- start a connection test
     * without creating or touching any mcp_client_servers row (D3/D4).
     * Runs on a queue worker (D2): this endpoint only creates the
     * tracking row and dispatches the job; it never itself calls
     * McpTransportFactory.
     */
    public function testConnection(Request $request): JsonResponse
    {
        $validated = $this->validatedConnectionOnly($request);

        $test = McpClientConnectionTest::create([
            'user_id' => Auth::user()->id,
            'transport' => $validated['transport'],
            'url' => $validated['url'] ?? null,
            'command' => $validated['command'] ?? null,
            'args' => $validated['args'] ?? null,
            'credential' => $validated['credential'] ?? null,
            'status' => 'pending',
        ]);

        TestMcpClientConnectionJob::dispatch($test->id);

        return response()->json([
            'id' => $test->id,
            'status' => 'pending',
        ], 202);
    }

    /**
     * GET /mcp-client-server/test-connection/{id} -- polled by the
     * frontend until status leaves pending. Scoped to the caller the
     * same way every other read in this controller is (findEligible()'s
     * pattern) -- an absent or foreign-owned test id is a uniform 404,
     * never a distinguishing 403.
     */
    public function showTestConnection(Request $request, string $id): JsonResponse
    {
        $callerUserId = Auth::user()->id;

        $test = McpClientConnectionTest::where('id', $id)
            ->where('user_id', $callerUserId)
            ->first();

        if ($test === null) {
            return $this->notFoundResponse();
        }

        return response()->json([
            'id' => $test->id,
            'status' => $test->status,
            'failure_category' => $test->failure_category,
            'message' => $test->message,
            'tool_count' => $test->tool_count,
        ]);
    }

    /**
     * The same connection-shape fields validated() already validates,
     * minus name/scope -- a test has no identity or ownership scope of
     * its own beyond the caller (contracts/connection-test-api.md).
     *
     * @return array<string, mixed>
     */
    private function validatedConnectionOnly(Request $request): array
    {
        return $request->validate([
            'transport' => ['required', 'string', Rule::in(array_map(fn (McpTransportKind $t) => $t->value, McpTransportKind::cases()))],
            'url' => [
                'required_if:transport,streamable_http',
                'nullable',
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null) {
                        return;
                    }
                    $scheme = parse_url($value, PHP_URL_SCHEME);
                    if (!$scheme || !in_array(strtolower($scheme), ['http', 'https'], true)) {
                        $fail('The url must use the http or https scheme.');
                    }
                },
            ],
            'command' => ['required_if:transport,stdio', 'nullable', 'string'],
            'args' => ['sometimes', 'nullable', 'array'],
            'args.*' => ['string'],
            'credential' => ['nullable', 'string'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'transport' => ['required', 'string', Rule::in(array_map(fn (McpTransportKind $t) => $t->value, McpTransportKind::cases()))],
            'url' => [
                'required_if:transport,streamable_http',
                'nullable',
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null) {
                        return;
                    }
                    $scheme = parse_url($value, PHP_URL_SCHEME);
                    if (!$scheme || !in_array(strtolower($scheme), ['http', 'https'], true)) {
                        $fail('The url must use the http or https scheme.');
                    }
                },
            ],
            'command' => ['required_if:transport,stdio', 'nullable', 'string'],
            'args' => ['sometimes', 'nullable', 'array'],
            'args.*' => ['string'],
            'credential' => ['nullable', 'string'],
            'scope' => ['required', 'string', Rule::in(['personal', 'project'])],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serverSummary(McpClientServer $server): array
    {
        return [
            'id' => $server->id,
            'name' => $server->name,
            'transport' => $server->transport->value,
            'scope' => $server->scope,
        ];
    }

    /**
     * index()'s own shape: serverSummary()'s 4 fields plus each server's
     * own connection_status/last_reachable_at/tool_count -- a distinct
     * helper (rather than changing serverSummary() itself) because
     * serverSummary()'s current 4-field shape is also the documented
     * response for replaceCredential(), which must stay unchanged.
     *
     * @return array<string, mixed>
     */
    private function serverSummaryWithStatus(McpClientServer $server, ?McpClientServerStatus $status): array
    {
        return $this->serverSummary($server) + [
            'connection_status' => $status->connection_status ?? 'unknown',
            'last_reachable_at' => $status->last_reachable_at ?? null,
            'tool_count' => $status->tool_count ?? 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serverDetail(McpClientServer $server): array
    {
        $status = McpClientServerStatus::where('server_id', $server->id)->first();

        $tools = McpClientTool::where('server_id', $server->id)
            ->active()
            ->get()
            ->map(fn (McpClientTool $tool) => [
                'name' => $tool->name,
                'description' => $tool->description,
                'input_schema' => $tool->input_schema,
                'synthetic_operation_id' => $tool->synthetic_operation_id,
            ])
            ->values();

        return [
            'id' => $server->id,
            'name' => $server->name,
            'transport' => $server->transport->value,
            'scope' => $server->scope,
            'status' => [
                'connection_status' => $status->connection_status ?? 'unknown',
                'last_error' => $status->last_error ?? null,
                'tool_count' => $status->tool_count ?? 0,
                'refresh_finished_at' => $status->refresh_finished_at ?? null,
                'last_reachable_at' => $status->last_reachable_at ?? null,
            ],
            'tools' => $tools,
        ];
    }

    private function findEligible(string $id): ?McpClientServer
    {
        $callerUserId = Auth::user()->id;

        return McpClientServer::query()
            ->eligibleFor($callerUserId)
            ->find($id);
    }

    /**
     * The uniform "not found" body for an absent-or-foreign-owned server
     * id, matching RunController::notFoundResponse()'s own established
     * shape and status code (404, never 403).
     */
    private function notFoundResponse(): JsonResponse
    {
        return response()->json([
            'error' => 'MCP client server not found',
            'code' => 'mcp_client_server_not_found',
        ], 404);
    }
}
