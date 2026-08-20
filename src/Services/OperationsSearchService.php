<?php

namespace ClarionApp\LlmClient\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OperationsSearchService
{
    private ConnectionInterface $db;
    private int $defaultLimit;

    /**
     * Accepts optional database connection and default limit for testability.
     * If not provided, uses Laravel's DB facade and config.
     *
     * @param ConnectionInterface|null $db Database connection
     * @param int|null $defaultLimit Default result limit (defaults to config value)
     */
    public function __construct(?ConnectionInterface $db = null, ?int $defaultLimit = null)
    {
        $this->db = $db ?: DB::connection();
        $this->defaultLimit = $defaultLimit ?? (int) config('llm-client.operations_search.default_limit', 10);
    }

    /**
     * @param string $query
     * @param string|null $codingProjectId When non-null, scopes the result set to rows
     *   that are either global (coding_project_id IS NULL) or belong to this workspace
     *   (coding_project_id = $codingProjectId) -- never another workspace's rows
     *   (128-project-command-indexing, contracts/operations-search-service.md). When
     *   null (the default), the query is byte-for-byte identical to this method's
     *   pre-feature behavior: no type = 'project_command' row is ever returned.
     * @param int|null $limit
     */
    public function search(string $query, ?string $codingProjectId = null, ?int $limit = null): array
    {
        if ($limit === null) {
            $limit = $this->defaultLimit;
        }

        $queryBuilder = $this->db->table('operation_search_index')
            ->select(
                'operation_id as operationId',
                'package_name',
                'type',
                'summary',
                'method',
                'path',
                'param_schema as paramSchema',
                'prompt_content as promptContent'
            )
            ->whereRaw('MATCH(type, searchable_text) AGAINST(? IN NATURAL LANGUAGE MODE)', [$query])
            ->orderByRaw('MATCH(type, searchable_text) AGAINST(? IN NATURAL LANGUAGE MODE) DESC', [$query])
            ->limit($limit);

        if ($codingProjectId !== null) {
            $queryBuilder->where(function ($q) use ($codingProjectId) {
                $q->whereNull('coding_project_id')->orWhere('coding_project_id', $codingProjectId);
            });
        }

        $results = $queryBuilder
            ->get()
            ->toArray();

        if ($codingProjectId === null) {
            // 128-project-command-indexing (Phase 4/US2, contracts/
            // operations-search-service.md postcondition 1): an unscoped
            // search must build the exact same select/whereRaw/orderByRaw/
            // limit query chain as before this feature -- no additional
            // where() call of any kind (OperationsSearchServiceScopingTest
            // asserts this at the query-builder level). A type =
            // 'project_command' row can still be present in the raw result
            // set (nothing in the query restricts it), so it is filtered
            // out here, in PHP, after fetch -- never returned to a caller
            // with no workspace in scope (FR-003, US2 Acceptance Scenario
            // 3).
            $results = array_values(array_filter(
                $results,
                fn ($row) => ($row->type ?? null) !== 'project_command'
            ));
        }

        return $results;
    }

    /**
     * Check if the operation_search_index table exists in the database.
     *
     * @return bool True if the table exists, false otherwise.
     */
    public function tableExists(): bool
    {
        try {
            return $this->db->getSchemaBuilder()->hasTable('operation_search_index');
        } catch (\Throwable $e) {
            // Fallback: query information_schema directly if Schema builder fails
            try {
                $result = $this->db->select(
                    "SELECT COUNT(*) as cnt FROM information_schema.tables 
                     WHERE table_schema = DATABASE() AND table_name = ?",
                    ['operation_search_index']
                );
                return ($result[0]->cnt ?? 0) > 0;
            } catch (\Throwable $fallbackException) {
                Log::warning('OperationsSearchService: could not determine table existence', [
                    'error' => $fallbackException->getMessage(),
                ]);
                return false;
            }
        }
    }

    /**
     * Safely decode a JSON paramSchema value, returning null on failure.
     * Logs a warning if the JSON is malformed.
     *
     * @param mixed $value The JSON string to decode.
     * @return array|null Decoded array or null on failure.
     */
    public static function safeDecodeParamSchema($value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Use error_log instead of Log facade for testability in unit context
            @error_log('OperationsSearchService: malformed paramSchema - ' . json_last_error_msg());
            return null;
        }

        return $decoded;
    }
}
