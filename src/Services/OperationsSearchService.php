<?php

namespace ClarionApp\LlmClient\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

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
                'summary',
                'method',
                'path',
                'param_schema as paramSchema'
            )
            ->whereRaw('MATCH(searchable_text) AGAINST(? IN NATURAL LANGUAGE MODE)', [$query])
            ->orderByRaw('MATCH(searchable_text) AGAINST(? IN NATURAL LANGUAGE MODE) DESC', [$query])
            ->limit($limit)
            ->get()
            ->toArray();

        return $results;
    }
}
