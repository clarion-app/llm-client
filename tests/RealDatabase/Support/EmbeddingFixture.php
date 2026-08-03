<?php

namespace Tests\RealDatabase\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * T034: Known set of literal embeddings for memory vector search.
 *
 * Dimension 8, unit vectors chosen so expected similarities are exact
 * (or near-exact for the diagonal vector). No embedding service is called.
 *
 * Query [1,0,0,0,0,0,0,0] against four stored vectors:
 * - entry_a: [1,0,0,0,0,0,0,0]       → cosine = 1.0  → normalised = 1.0
 * - entry_b: [0.7071,0.7071,0,...]   → cosine = 0.7071 → normalised ≈ 0.85355
 * - entry_c: [0,1,0,0,0,0,0,0]       → cosine = 0.0  → normalised = 0.5
 * - entry_d: [0,0,1,0,0,0,0,0]       → cosine = 0.0  → normalised = 0.5
 *
 * expectedOrder is written down, not computed at assert time.
 */
class EmbeddingFixture
{
    /** The query vector for all scenarios. */
    public static function queryVector(): array
    {
        return [1.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0];
    }

    /**
     * Memory entry rows to seed. Each row has the columns that
     * llm_memory_entries expects. Embeddings are literal arrays.
     */
    public static function entries(string $agentId, string $userId): array
    {
        return [
            [
                'id' => 'a0000000-0000-0000-0000-000000000001',
                'scope' => 'long_term',
                'agent_id' => $agentId,
                'user_id' => $userId,
                'conversation_id' => null,
                'turn_id' => null,
                'key' => 'entry_a',
                'content' => 'This is the exact match memory entry.',
                'embedding' => [1.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0],
                'last_accessed_at' => Carbon::now(),
            ],
            [
                'id' => 'b0000000-0000-0000-0000-000000000002',
                'scope' => 'long_term',
                'agent_id' => $agentId,
                'user_id' => $userId,
                'conversation_id' => null,
                'turn_id' => null,
                'key' => 'entry_b',
                'content' => 'This is the partial match memory entry.',
                'embedding' => [0.7071, 0.7071, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0],
                'last_accessed_at' => Carbon::now(),
            ],
            [
                'id' => 'c0000000-0000-0000-0000-000000000003',
                'scope' => 'long_term',
                'agent_id' => $agentId,
                'user_id' => $userId,
                'conversation_id' => null,
                'turn_id' => null,
                'key' => 'entry_c',
                'content' => 'This is the orthogonal memory entry c.',
                'embedding' => [0.0, 1.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0],
                'last_accessed_at' => Carbon::now(),
            ],
            [
                'id' => 'd0000000-0000-0000-0000-000000000004',
                'scope' => 'long_term',
                'agent_id' => $agentId,
                'user_id' => $userId,
                'conversation_id' => null,
                'turn_id' => null,
                'key' => 'entry_d',
                'content' => 'This is the orthogonal memory entry d.',
                'embedding' => [0.0, 0.0, 1.0, 0.0, 0.0, 0.0, 0.0, 0.0],
                'last_accessed_at' => Carbon::now(),
            ],
        ];
    }

    /**
     * Rows for a different user (FR-013 cross-user isolation).
     */
    public static function foreignUserRows(string $agentId, string $foreignUserId): array
    {
        return [
            [
                'id' => 'f0000000-0000-0000-0000-000000000005',
                'scope' => 'long_term',
                'agent_id' => $agentId,
                'user_id' => $foreignUserId,
                'conversation_id' => null,
                'turn_id' => null,
                'key' => 'foreign_entry',
                'content' => 'This entry belongs to another user.',
                'embedding' => [1.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0],
                'last_accessed_at' => Carbon::now(),
            ],
        ];
    }

    /**
     * Expected order of keys by similarity (best first), written down.
     * entry_a (1.0) > entry_b (~0.8536) > entry_c (0.5) = entry_d (0.5)
     * For ties (c and d), the engine may return either order.
     */
    public static function expectedOrder(): array
    {
        return ['entry_a', 'entry_b'];
    }

    /**
     * Expected normalised similarity scores (within float32 tolerance).
     * These are computed once and written down, not at assert time.
     */
    public static function expectedScores(): array
    {
        return [
            'entry_a' => 1.0,
            'entry_b' => 0.85355,  // (0.7071 + 1) / 2 ≈ 0.85355
            'entry_c' => 0.5,
            'entry_d' => 0.5,
        ];
    }

    /**
     * Seed entries directly into the database.
     * Uses raw INSERT so the embedding column gets the right format.
     *
     * @param string[] $embeddings Raw embedding values as JSON or VECTOR text
     */
    public static function seedRaw(string $agentId, string $userId, array $rawEmbeddings): void
    {
        $entries = self::baseEntries($agentId, $userId);
        $isMysql = DB::getDriverName() === 'mysql';
        foreach ($entries as $i => $entry) {
            $embedding = $rawEmbeddings[$i] ?? null;
            // On MySQL/MariaDB, wrap with VEC_FromText() for VECTOR columns.
            if ($isMysql && $embedding !== null) {
                $embedding = DB::raw("VEC_FromText('{$embedding}')");
            }
            DB::table('llm_memory_entries')->insert([
                'id' => $entry['id'],
                'scope' => $entry['scope'],
                'agent_id' => $entry['agent_id'],
                'user_id' => $entry['user_id'],
                'conversation_id' => $entry['conversation_id'],
                'turn_id' => $entry['turn_id'],
                'key' => $entry['key'],
                'content' => $entry['content'],
                'embedding' => $embedding,
                'last_accessed_at' => $entry['last_accessed_at'],
            ]);
        }
    }

    /**
     * Seed foreign user rows directly into the database.
     */
    public static function seedForeignRaw(string $agentId, string $foreignUserId, ?string $rawEmbedding): void
    {
        $rows = self::foreignUserRowsBase($agentId, $foreignUserId);
        $isMysql = DB::getDriverName() === 'mysql';
        if ($isMysql && $rawEmbedding !== null) {
            $rawEmbedding = DB::raw("VEC_FromText('{$rawEmbedding}')");
        }
        foreach ($rows as $row) {
            DB::table('llm_memory_entries')->insert([
                'id' => $row['id'],
                'scope' => $row['scope'],
                'agent_id' => $row['agent_id'],
                'user_id' => $row['user_id'],
                'conversation_id' => $row['conversation_id'],
                'turn_id' => $row['turn_id'],
                'key' => $row['key'],
                'content' => $row['content'],
                'embedding' => $rawEmbedding,
                'last_accessed_at' => $row['last_accessed_at'],
            ]);
        }
    }

    /**
     * Base entry data (without embedding).
     */
    private static function baseEntries(string $agentId, string $userId): array
    {
        return [
            [
                'id' => 'a0000000-0000-0000-0000-000000000001',
                'scope' => 'long_term',
                'agent_id' => $agentId,
                'user_id' => $userId,
                'conversation_id' => null,
                'turn_id' => null,
                'key' => 'entry_a',
                'content' => 'This is the exact match memory entry.',
                'last_accessed_at' => Carbon::now(),
            ],
            [
                'id' => 'b0000000-0000-0000-0000-000000000002',
                'scope' => 'long_term',
                'agent_id' => $agentId,
                'user_id' => $userId,
                'conversation_id' => null,
                'turn_id' => null,
                'key' => 'entry_b',
                'content' => 'This is the partial match memory entry.',
                'last_accessed_at' => Carbon::now(),
            ],
            [
                'id' => 'c0000000-0000-0000-0000-000000000003',
                'scope' => 'long_term',
                'agent_id' => $agentId,
                'user_id' => $userId,
                'conversation_id' => null,
                'turn_id' => null,
                'key' => 'entry_c',
                'content' => 'This is the orthogonal memory entry c.',
                'last_accessed_at' => Carbon::now(),
            ],
            [
                'id' => 'd0000000-0000-0000-0000-000000000004',
                'scope' => 'long_term',
                'agent_id' => $agentId,
                'user_id' => $userId,
                'conversation_id' => null,
                'turn_id' => null,
                'key' => 'entry_d',
                'content' => 'This is the orthogonal memory entry d.',
                'last_accessed_at' => Carbon::now(),
            ],
        ];
    }

    /**
     * Foreign user entry data (without embedding).
     */
    private static function foreignUserRowsBase(string $agentId, string $foreignUserId): array
    {
        return [
            [
                'id' => 'f0000000-0000-0000-0000-000000000005',
                'scope' => 'long_term',
                'agent_id' => $agentId,
                'user_id' => $foreignUserId,
                'conversation_id' => null,
                'turn_id' => null,
                'key' => 'foreign_entry',
                'content' => 'This entry belongs to another user.',
                'last_accessed_at' => Carbon::now(),
            ],
        ];
    }
}
