<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Contracts\LlmProvider;
use ClarionApp\LlmClient\Contracts\MemoryScope;
use ClarionApp\LlmClient\Models\MemoryEntry;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\LlmClient\Providers\ProviderRegistry;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Service for generating and managing memory entry embeddings.
 *
 * Handles provider resolution (dedicated embedding server vs chat provider fallback),
 * embedding generation with error handling, and content truncation.
 */
class EmbeddingService
{
    /**
     * Maximum input length for embedding generation (characters).
     * Truncate content exceeding this limit before embedding.
     */
    private const MAX_INPUT_LENGTH = 8000;

    public function __construct(
        private readonly ProviderRegistry $providerRegistry
    ) {}

    /**
     * Check if embedding generation is enabled.
     */
    public function isEnabled(): bool
    {
        return config('llm-client.memory.embedding.enabled', true) === true;
    }

    /**
     * Resolve the embedding provider.
     *
     * Priority:
     * 1. Dedicated embedding server (configured via memory.embedding.server_id)
     * 2. Chat provider (fallback, if it supports embeddings)
     * 3. Null (no provider available)
     */
    public function getProvider(): ?LlmProvider
    {
        if (!$this->isEnabled()) {
            return null;
        }

        // Try dedicated embedding server first
        $serverId = config('llm-client.memory.embedding.server_id', null);
        if ($serverId !== null) {
            $server = Server::find($serverId);
            if ($server !== null) {
                try {
                    return $this->providerRegistry->resolve($server);
                } catch (RuntimeException $e) {
                    Log::warning('Failed to resolve dedicated embedding provider', [
                        'server_id' => $serverId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // No dedicated embedding provider available.
        // Return null — callers should handle this gracefully.
        // We do NOT fall back to chat provider here as that would require
        // knowing the agent's chat server context which EmbeddingService doesn't have.
        return null;
    }

    /**
     * Generate an embedding for the given text.
     *
     * @return float[] Embedding vector
     * @throws RuntimeException If embedding generation fails
     */
    public function generate(string $content): array
    {
        $provider = $this->getProvider();
        if ($provider === null) {
            throw new RuntimeException(
                'No embedding provider available. Configure memory.embedding.server_id or disable semantic search.'
            );
        }

        // Truncate content if too long
        $input = $this->truncateForEmbedding($content);

        $model = config('llm-client.memory.embedding.model', null);
        $options = [];
        if ($model !== null && $model !== '') {
            $options['model'] = $model;
        }

        $result = $provider->embed([$input], $options);
        $embeddings = $result['embeddings'] ?? [];

        if (empty($embeddings) || !is_array($embeddings[0] ?? null)) {
            throw new RuntimeException('Embedding provider returned invalid result (empty or non-array embeddings).');
        }

        return $embeddings[0];
    }

    /**
     * Generate and save embedding for a memory entry.
     *
     * Returns true if embedding was successfully generated and saved,
     * false if embedding generation was skipped or failed.
     *
     * This is non-blocking — failures are logged but don't throw.
     */
    public function generateForEntry(MemoryEntry $entry): bool
    {
        // Only generate embeddings for long-term entries
        if ($entry->scope !== MemoryScope::LONG_TERM) {
            return false;
        }

        // Skip if embedding is disabled
        if (!$this->isEnabled()) {
            return false;
        }

        // Skip if no provider available
        if ($this->getProvider() === null) {
            Log::warning('Skipping embedding generation: no embedding provider available', [
                'entry_id' => $entry->id,
                'key' => $entry->key,
            ]);
            return false;
        }

        try {
            $embedding = $this->generate($entry->content);
            $entry->embedding = $embedding;
            $entry->save();
            return true;
        } catch (RuntimeException $e) {
            Log::warning('Failed to generate embedding for memory entry', [
                'entry_id' => $entry->id,
                'key' => $entry->key,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Truncate content to maximum embedding input length.
     */
    private function truncateForEmbedding(string $content): string
    {
        if (mb_strlen($content) <= self::MAX_INPUT_LENGTH) {
            return $content;
        }

        return mb_substr($content, 0, self::MAX_INPUT_LENGTH);
    }

    /**
     * Compute cosine similarity between two embedding vectors.
     *
     * @param float[] $a First vector
     * @param float[] $b Second vector
     * @return float Cosine similarity in range [-1.0, 1.0]
     */
    public static function cosineSimilarity(array $a, array $b): float
    {
        $lenA = count($a);
        $lenB = count($b);

        if ($lenA === 0 || $lenA !== $lenB) {
            return 0.0;
        }

        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < $lenA; $i++) {
            $dotProduct += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        if ($normA == 0.0 || $normB == 0.0) {
            return 0.0;
        }

        return $dotProduct / (sqrt($normA) * sqrt($normB));
    }

    /**
     * Normalize cosine similarity from [-1.0, 1.0] to [0.0, 1.0].
     */
    public static function normalizeSimilarity(float $cosineSimilarity): float
    {
        return ($cosineSimilarity + 1.0) / 2.0;
    }
}
