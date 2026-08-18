<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Exceptions\McpAuthenticationException;
use ClarionApp\LlmClient\Models\McpClientServer;
use ClarionApp\LlmClient\Models\McpClientServerStatus;
use ClarionApp\LlmClient\Models\McpClientTool;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Runs the initialize -> tools/list handshake against one McpClientServer
 * and reconciles the result into two local tables: mcp_client_tools (one
 * row per currently-offered tool, stamped with the moment this refresh
 * ran) and mcp_client_server_statuses (one row per server, upserted in
 * place) -- mirroring RefreshServerModelsJob's own create/reconcile/
 * soft-delete shape for LanguageModel rows.
 *
 * "Soft removal" here works differently from that job's outright
 * ->delete(), because mcp_client_tools has no deleted_at column at all: a
 * tool the server no longer offers simply isn't touched by this refresh,
 * so its last_seen_at falls behind the new refresh_finished_at and
 * McpClientTool::scopeActive() excludes it from then on, while the row
 * itself -- and any invocation history attributed to it -- stays intact.
 *
 * Every outcome lands on exactly one of the four connection_status values
 * a status row can hold (reachable/unreachable/auth_failed/unknown) --
 * never an uncaught exception escaping to the caller, the same failure-
 * isolation discipline McpClientToolExecutor applies one layer down for a
 * single tool invocation.
 */
class McpClientToolDiscoveryService
{
    public function __construct(
        private readonly McpTransportFactory $transportFactory,
        private readonly McpClientTextSanitizer $sanitizer,
    ) {
    }

    public function discover(McpClientServer $server): McpClientServerStatus
    {
        $existingStatus = McpClientServerStatus::where('server_id', $server->id)->first();

        $status = McpClientServerStatus::updateOrCreate(
            ['server_id' => $server->id],
            [
                'connection_status' => $existingStatus?->connection_status ?? 'unknown',
                'refresh_started_at' => now(),
                'refresh_finished_at' => null,
            ]
        );

        try {
            $transport = $this->transportFactory->for($server);
            $transport->initialize();
            $tools = $transport->listTools();
        } catch (McpAuthenticationException $e) {
            $status->update([
                'connection_status' => 'auth_failed',
                'last_error' => $e->getMessage(),
                'tool_count' => 0,
                'refresh_finished_at' => now(),
            ]);

            return $status->fresh();
        } catch (\Throwable $e) {
            // Every other transport-level failure -- unreachable, timed
            // out, or a malformed/misbehaving response -- is reported as
            // "unreachable": the status row's own connection_status
            // vocabulary has no separate timeout/protocol-error value,
            // and the property it exists to preserve is "could not be
            // used at all" vs. "has no tools", not a full taxonomy of
            // every possible transport failure.
            $status->update([
                'connection_status' => 'unreachable',
                'last_error' => $e->getMessage(),
                'tool_count' => 0,
                'refresh_finished_at' => now(),
            ]);

            return $status->fresh();
        }

        // One shared instant, stamped onto every tool this refresh finds
        // *and* onto the status row's own refresh_finished_at, so
        // McpClientTool::scopeActive()'s "last_seen_at >= refresh_finished_at"
        // check holds for every tool this refresh actually touched and
        // fails for any it did not -- the entire soft-removal mechanism
        // rests on both values being identical, not merely close.
        $refreshTimestamp = now();
        $toolCount = $this->reconcileTools($server, $tools, $refreshTimestamp);

        $status->update([
            'connection_status' => 'reachable',
            'last_error' => null,
            'tool_count' => $toolCount,
            'refresh_finished_at' => $refreshTimestamp,
        ]);

        return $status->fresh();
    }

    /**
     * Reconciles one refresh's reported tools against this server's
     * existing rows and returns the number of tools successfully
     * accounted for (matched-by-name, matched-by-schema, or newly
     * inserted). Mirrors RefreshServerModelsJob's own create/reconcile/
     * soft-delete shape, but with an extra middle step this table's own
     * synthetic_operation_id durability requires: before falling back to
     * "remove one, add one" (today's only behavior), an orphaned row and
     * an unclaimed report sharing the same parameter schema -- and only
     * they -- are recognized as the same capability under a new name, so
     * the row's own id (and anything anchored to it) survives the
     * rename.
     *
     * @param  list<array{name: string, description: ?string, inputSchema: array, annotations: ?array}>  $tools
     */
    private function reconcileTools(McpClientServer $server, array $tools, Carbon $refreshTimestamp): int
    {
        $reportedByName = [];
        foreach ($tools as $tool) {
            $name = is_string($tool['name'] ?? null) ? $tool['name'] : null;
            if ($name === null || $name === '') {
                continue;
            }
            $reportedByName[$name] = $tool;
        }

        // The pool a name/schema match is drawn from is every row for
        // this server sharing its own current maximum last_seen_at --
        // "the tools this server offered as of its last successful
        // refresh." McpClientTool::maxLastSeenAtFor() is this exact
        // computation, now shared with McpClientTool::scopeActive() (which
        // builds the same MAX(last_seen_at)-per-server comparison as a
        // correlated subquery rather than N calls to this helper) so both
        // agree on one definition of "this server's own current pool."
        $maxLastSeenAt = McpClientTool::maxLastSeenAtFor($server->id);

        $candidateRowsByName = [];
        if ($maxLastSeenAt !== null) {
            foreach (McpClientTool::where('server_id', $server->id)->where('last_seen_at', $maxLastSeenAt)->get() as $row) {
                $candidateRowsByName[$row->name] = $row;
            }
        }

        $toolCount = 0;
        $matchedNames = [];

        // Fast path, unchanged from before this method existed: a
        // reported name matching an existing row updates it in place,
        // identity untouched.
        foreach ($reportedByName as $name => $tool) {
            if (!array_key_exists($name, $candidateRowsByName)) {
                continue;
            }

            $this->applyToolFields($candidateRowsByName[$name], $tool, $server, $refreshTimestamp);
            $matchedNames[$name] = true;
            $toolCount++;
        }

        $orphaned = [];
        foreach ($candidateRowsByName as $name => $row) {
            if (!isset($matchedNames[$name])) {
                $orphaned[] = $row;
            }
        }

        $unclaimed = [];
        foreach ($reportedByName as $name => $tool) {
            if (!isset($matchedNames[$name])) {
                $unclaimed[] = $tool;
            }
        }

        $orphanedBySignature = [];
        foreach ($orphaned as $row) {
            $orphanedBySignature[self::schemaSignature($row->input_schema ?? [])][] = $row;
        }

        $unclaimedIndexesBySignature = [];
        foreach ($unclaimed as $index => $tool) {
            $schema = is_array($tool['inputSchema'] ?? null) ? $tool['inputSchema'] : [];
            $unclaimedIndexesBySignature[self::schemaSignature($schema)][] = $index;
        }

        // A signature shared by exactly one orphaned row and exactly one
        // unclaimed report is the only case treated as a rename/
        // redescribe of the same capability -- any ambiguity on either
        // side (zero or multiple candidates) is left for the fallback
        // below rather than guessed at.
        $claimedUnclaimedIndexes = [];
        foreach ($orphanedBySignature as $signature => $rows) {
            if (count($rows) !== 1) {
                continue;
            }

            $candidateIndexes = $unclaimedIndexesBySignature[$signature] ?? [];
            if (count($candidateIndexes) !== 1) {
                continue;
            }

            $index = $candidateIndexes[0];
            $this->applyToolFields($rows[0], $unclaimed[$index], $server, $refreshTimestamp);
            $claimedUnclaimedIndexes[$index] = true;
            $toolCount++;
        }

        // Everything left unclaimed -- no schema match, or an ambiguous
        // one -- is a genuinely new row, with a freshly generated id
        // this installation alone controls.
        foreach ($unclaimed as $index => $tool) {
            if (isset($claimedUnclaimedIndexes[$index])) {
                continue;
            }

            $id = (string) Str::uuid();
            $inputSchema = is_array($tool['inputSchema'] ?? null) ? $tool['inputSchema'] : [];
            $annotations = is_array($tool['annotations'] ?? null) ? $tool['annotations'] : null;
            $description = $this->sanitizer->sanitize($tool['description'] ?? null, $server->name);

            McpClientTool::create([
                'id' => $id,
                'server_id' => $server->id,
                'synthetic_operation_id' => "mcp:{$server->id}:{$id}",
                'name' => $tool['name'],
                'description' => $description,
                'input_schema' => $inputSchema,
                'annotations' => $annotations,
                'last_seen_at' => $refreshTimestamp,
            ]);
            $toolCount++;
        }

        // Every remaining orphaned row (schema didn't match, or matched
        // ambiguously) is left untouched this refresh -- it ages out via
        // the pre-existing last_seen_at/scopeActive() mechanism once some
        // other row for this server advances the server's own maximum
        // last_seen_at past it.

        return $toolCount;
    }

    /**
     * Updates one existing row's mutable, server-reported fields in
     * place -- name, description, input_schema, annotations,
     * last_seen_at -- while leaving id and synthetic_operation_id
     * untouched, whether the row was matched by name (the ordinary case)
     * or recognized as a rename/redescribe by schema.
     *
     * @param  array{name: string, description: ?string, inputSchema: array, annotations: ?array}  $tool
     */
    private function applyToolFields(McpClientTool $row, array $tool, McpClientServer $server, Carbon $refreshTimestamp): void
    {
        $name = is_string($tool['name'] ?? null) ? $tool['name'] : $row->name;
        $inputSchema = is_array($tool['inputSchema'] ?? null) ? $tool['inputSchema'] : [];
        $annotations = is_array($tool['annotations'] ?? null) ? $tool['annotations'] : null;
        $description = $this->sanitizer->sanitize($tool['description'] ?? null, $server->name);

        $row->update([
            'name' => $name,
            'description' => $description,
            'input_schema' => $inputSchema,
            'annotations' => $annotations,
            'last_seen_at' => $refreshTimestamp,
        ]);
    }

    /**
     * A canonical, order-independent-on-object-keys form of a tool's
     * inputSchema, used only to decide whether two schemas are
     * structurally identical for rename/redescribe matching -- recursive
     * key-sort of every associative sub-array (list arrays keep their own
     * order, since element order is itself part of a list's meaning),
     * then json_encode.
     */
    private static function schemaSignature(array $schema): string
    {
        return json_encode(self::canonicalizeForSignature($schema));
    }

    private static function canonicalizeForSignature(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $canonicalized = [];
        foreach ($value as $key => $item) {
            $canonicalized[$key] = self::canonicalizeForSignature($item);
        }

        if (!array_is_list($canonicalized)) {
            ksort($canonicalized);
        }

        return $canonicalized;
    }
}
