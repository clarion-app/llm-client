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

    public function search(string $query, ?int $limit = null): array
    {
        if ($limit === null) {
            $limit = $this->defaultLimit;
        }

        $results = $this->db->table('operation_search_index')
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
            ->whereRaw('MATCH(searchable_text) AGAINST(? IN NATURAL LANGUAGE MODE)', [$query])
            ->orderByRaw('MATCH(searchable_text) AGAINST(? IN NATURAL LANGUAGE MODE) DESC', [$query])
            ->limit($limit)
            ->get()
            ->toArray();

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
