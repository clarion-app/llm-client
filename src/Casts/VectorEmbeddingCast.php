<?php

namespace ClarionApp\LlmClient\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Query\Expression;

/**
 * Driver-aware cast for embedding columns.
 *
 * On MariaDB (mysql driver), embeddings are stored in VECTOR columns.
 * The VECTOR column stores data in a binary format, but VEC_ToText() returns
 * a string like '[1.00000000,0.00000000,...]' which this cast parses.
 *
 * On SQLite and other drivers, embeddings are stored as JSON strings.
 * This cast handles both formats transparently.
 *
 * Write path: Models use saving events (see MemoryEntry::booted()) to wrap
 * the embedding value with VEC_FromText() on MySQL. This cast formats
 * the array as the vector text string '[f1,f2,...]' for that expression.
 *
 * Read path: When VEC_ToText(embedding) is used in SELECT, the cast parses
 * the returned string. For direct reads (without VEC_ToText), the PDO driver
 * may return the VECTOR as a string representation that this cast also handles.
 *
 * FR-030: The SQLite path is unchanged — existing fallback tests must pass.
 */
class VectorEmbeddingCast implements CastsAttributes
{
    /**
     * Transform the attribute from the underlying column value (read).
     *
     * Parses vector text format '[f1,f2,...]' returned by VEC_ToText()
     * or JSON format from SQLite.
     *
     * @param mixed $value Raw column value from database
     * @return float[]|null PHP array of floats, or null
     */
    public function get($model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        // Already an array (e.g., from JSON cast on SQLite)
        if (is_array($value)) {
            return array_map(fn ($v) => (float) $v, $value);
        }

        $str = trim((string) $value);
        if ($str === '' || strtolower($str) === 'null') {
            return null;
        }

        // Parse [f1,f2,...] format (works for both VEC_ToText output and JSON arrays)
        if (str_starts_with($str, '[') && str_ends_with($str, ']')) {
            $inner = trim(substr($str, 1, -1));
            if ($inner === '') {
                return [];
            }
            $parts = array_map('trim', explode(',', $inner));
            return array_map(fn ($v) => (float) $v, $parts);
        }

        // Try json_decode as fallback
        $decoded = json_decode($str, true);
        if (is_array($decoded)) {
            return array_map(fn ($v) => (float) $v, $decoded);
        }

        return null;
    }

    /**
     * Transform the attribute to the underlying column value (write).
     *
     * Formats the PHP array as a vector text string '[f1,f2,...]'.
     * The model's saving event wraps this with VEC_FromText() on MySQL.
     *
     * @param mixed $value PHP array of floats, or null
     * @return string|null Vector text format string, or null
     */
    public function set($model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }

        // Passthrough for raw expressions (e.g., DB::raw("VEC_FromText('...')"))
        if ($value instanceof Expression) {
            return $value;
        }

        // Already a string (passthrough for pre-formatted values)
        if (is_string($value)) {
            return $value;
        }

        // Convert array to vector text format [f1,f2,...]
        if (is_array($value)) {
            $values = array_map(function ($v) {
                return sprintf('%.8f', (float) $v);
            }, $value);
            return '[' . implode(',', $values) . ']';
        }

        return null;
    }
}
