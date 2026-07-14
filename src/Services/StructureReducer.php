<?php

namespace ClarionApp\LlmClient\Services;

/**
 * Deterministic structure-aware reduction for JSON/structured data.
 *
 * Preserves shape, counts, identifiers, and representative samples while
 * reducing token footprint. Zero-latency (no external calls).
 */
class StructureReducer
{
    /** @var array<string, mixed> */
    private array $config;

    /** Field names that are always preserved as identifiers. */
    private const IDENTIFIER_FIELDS = [
        'id', 'uuid', 'name', 'path', 'url', 'href', 'email', 'phone',
        'code', 'token', 'key', 'hash', 'secret', 'title', 'slug',
        'username', 'user_id', 'file_name', 'filename', 'type', 'status',
    ];

    /** @var array<int, bool> Tracks whether a field name is an identifier field. */
    private static ?array $identifierFieldCache = null;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? [];
    }

    /**
     * Main entry point for structure reduction.
     *
     * @param array|object $data Decoded JSON data.
     * @param int $maxTokens Maximum target tokens for reduced output.
     * @param int $sampleItems Number of sample items to keep in arrays.
     * @return array Reduced structure.
     */
    public function reduce($data, int $maxTokens = 500, int $sampleItems = 5): array
    {
        if (is_object($data)) {
            $data = (array) $data;
        }

        if (!is_array($data)) {
            return ['value' => $data];
        }

        // Detect if this is a list (sequential integer keys) or a mapping (string keys).
        $reduced = $this->reduceValue($data, $sampleItems, 0, $maxTokens);

        // If reduced output still exceeds budget, apply aggressive truncation.
        $reducedTokens = ToolResultCondenser::estimateTokens(json_encode($reduced));
        if ($reducedTokens > $maxTokens) {
            $reduced = $this->aggressiveReduce($data, $sampleItems, $maxTokens);
        }

        return $reduced;
    }

    /**
     * Recursively reduce a value based on its type.
     *
     * @param mixed $value Value to reduce.
     * @param int $sampleItems Sample items count for arrays.
     * @param int $depth Current recursion depth.
     * @param int $maxTokens Target token budget.
     * @return mixed Reduced value.
     */
    private function reduceValue($value, int $sampleItems, int $depth, int $maxTokens)
    {
        // Max depth limit to prevent excessive recursion.
        if ($depth > 3) {
            if (is_array($value)) {
                $count = count($value);
                return ["[truncated nested structure: {$count} items]"];
            }
            if (is_object($value)) {
                return '[truncated nested object]';
            }
            return $value;
        }

        if (is_null($value)) {
            return null;
        }

        if (is_bool($value) || is_int($value) || is_float($value)) {
            // Scalars always preserved.
            return $value;
        }

        if (is_string($value)) {
            // Check if value looks like an identifier.
            if ($this->looksLikeIdentifier($value)) {
                return $value;
            }

            // Truncate long string values at 200 chars.
            if (strlen($value) > 200) {
                return mb_strimwidth($value, 0, 200, '...');
            }

            return $value;
        }

        if (is_object($value)) {
            $value = (array) $value;
        }

        if (is_array($value)) {
            return $this->reduceArray($value, $sampleItems, $depth, $maxTokens);
        }

        return $value;
    }

    /**
     * Reduce an array value (handles both lists and objects).
     */
    private function reduceArray(array $value, int $sampleItems, int $depth, int $maxTokens): array
    {
        // Detect list vs mapping.
        $keys = array_keys($value);
        $isList = count($keys) > 0 && array_reduce(
            $keys,
            fn($carry, $k) => $carry && is_int($k),
            true
        );

        if ($isList) {
            return $this->reduceList($value, $sampleItems, $depth, $maxTokens);
        }

        return $this->reduceMapping($value, $sampleItems, $depth, $maxTokens);
    }

    /**
     * Reduce a list (sequential integer-keyed array).
     */
    private function reduceList(array $items, int $sampleItems, int $depth, int $maxTokens): array
    {
        $total = count($items);

        if ($total <= $sampleItems) {
            // Small enough — keep all items but still reduce nested structures.
            $reduced = [];
            foreach ($items as $item) {
                $reduced[] = $this->reduceValue($item, $sampleItems, $depth + 1, $maxTokens);
            }
            return $reduced;
        }

        // Keep first N sample items.
        $samples = [];
        foreach (array_slice($items, 0, $sampleItems) as $item) {
            $samples[] = $this->reduceValue($item, $sampleItems, $depth + 1, $maxTokens);
        }

        // Collect representative field names from first item.
        $fields = [];
        if (!empty($items[0]) && is_array($items[0])) {
            $fields = array_keys($items[0]);
        }

        $remaining = $total - count($samples);
        $result = [
            '_meta' => [
                'type' => 'array',
                'total_count' => $total,
                'sample_count' => count($samples),
                'fields' => !empty($fields) ? $fields : null,
            ],
        ];
        $result = array_merge($result, $samples);
        $result['_truncated'] = '... ' . $remaining . ' more items (use reference to retrieve full results)';

        return $result;
    }

    /**
     * Reduce a mapping (string-keyed array / object).
     */
    private function reduceMapping(array $mapping, int $sampleItems, int $depth, int $maxTokens): array
    {
        $result = [];

        foreach ($mapping as $key => $value) {
            // Always preserve identifier fields.
            $isIdentifierField = $this->isIdentifierField($key);

            if (is_array($value) || is_object($value)) {
                $result[$key] = $this->reduceValue($value, $sampleItems, $depth + 1, $maxTokens);
            } elseif (is_string($value)) {
                if ($isIdentifierField || $this->looksLikeIdentifier($value)) {
                    $result[$key] = $value;
                } elseif (strlen($value) > 200) {
                    $result[$key] = mb_strimwidth($value, 0, 200, '...');
                } else {
                    $result[$key] = $value;
                }
            } else {
                // Scalars (int, float, bool, null) always preserved.
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Aggressive reduction when even normal reduction exceeds budget.
     */
    private function aggressiveReduce(array $data, int $sampleItems, int $maxTokens): array
    {
        $keys = array_keys($data);
        $isList = count($keys) > 0 && array_reduce(
            $keys,
            fn($carry, $k) => $carry && is_int($k),
            true
        );

        if ($isList) {
            $total = count($data);
            return [
                '_meta' => [
                    'type' => 'array',
                    'total_count' => $total,
                ],
                '_truncated' => "[aggressively truncated: {$total} items total (use reference to retrieve full results)]",
            ];
        }

        // For mappings, just keep keys and types.
        $summary = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $summary[$key] = "[array: " . count($value) . " items]";
            } elseif (is_object($value)) {
                $summary[$key] = '[object]';
            } elseif (is_string($value) && strlen($value) > 100) {
                $summary[$key] = mb_strimwidth($value, 0, 100, '...');
            } else {
                $summary[$key] = $value;
            }
        }

        return $summary;
    }

    /**
     * Check if a field name matches identifier patterns.
     */
    private function isIdentifierField(string $fieldName): bool
    {
        // Normalize to lowercase.
        $lower = strtolower($fieldName);

        // Direct match against known identifier field names.
        if (in_array($lower, self::IDENTIFIER_FIELDS, true)) {
            return true;
        }

        // Check for *_id or *_uuid patterns.
        if (str_ends_with($lower, '_id') || str_ends_with($lower, '_uuid')) {
            return true;
        }

        return false;
    }

    /**
     * Check if a value looks like an identifier (UUID, URI, hex string, monetary amount).
     */
    public function looksLikeIdentifier(string $value): bool
    {
        // UUID pattern.
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)) {
            return true;
        }

        // URI pattern.
        if (preg_match('/^https?:\/\/|^[a-z]+:\/\//', $value)) {
            return true;
        }

        // Hex string > 16 chars.
        if (preg_match('/^[0-9a-f]{16,}$/i', $value)) {
            return true;
        }

        // Monetary amount pattern.
        if (preg_match('/^\$?\d+(\.\d{2})?$/', $value)) {
            return true;
        }

        // Email pattern.
        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return true;
        }

        return false;
    }
}
