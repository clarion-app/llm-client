<?php

namespace Tests\Integration\Harness;

/**
 * Deterministic embedder for integration tests.
 *
 * Produces stable content-hash → fixed-dimension vectors that are identical
 * across processes and runs. Uses SHA-256 with a salt derived from the input
 * to generate normalized unit vectors.
 */
class DeterministicEmbedder
{
    private int $dimensions;

    public function __construct(int $dimensions = 768)
    {
        $this->dimensions = $dimensions;
    }

    /**
     * Generate a deterministic embedding vector for the given content.
     *
     * @param string $content The text to embed.
     * @return float[] Fixed-dimension normalized embedding vector.
     */
    public function embed(string $content): array
    {
        $vector = $this->generateVector($content);
        return $this->normalize($vector);
    }

    /**
     * Generate embeddings for a batch of content strings.
     *
     * @param string[] $contents Array of text strings to embed.
     * @return float[][] Array of embedding vectors, one per input.
     */
    public function embedBatch(array $contents): array
    {
        $results = [];
        foreach ($contents as $content) {
            $results[] = $this->embed($content);
        }
        return $results;
    }

    /**
     * Generate a raw (unnormalized) vector by hashing tokens into dimensions.
     *
     * Hashing the whole string would be deterministic but semantically inert:
     * two texts about the same subject would land as far apart as two unrelated
     * ones, so cosine ordering would be arbitrary and SC-001a's relative-order
     * assertions could not be written at all.
     *
     * Hashing per token (the "hashing trick") keeps determinism while making
     * similarity track shared vocabulary — texts overlapping in words land
     * closer together. That is enough structure for scenarios to assert which
     * of two seeded memories is the more relevant one, which is all they claim.
     *
     * @param string $content The input text.
     * @return float[] Raw vector values.
     */
    private function generateVector(string $content): array
    {
        $vector = array_fill(0, $this->dimensions, 0.0);

        foreach ($this->tokenize($content) as $token) {
            // Two independent digests per token: one picks the dimension, the
            // other its sign, so unrelated tokens cancel rather than accumulate.
            $indexDigest = crc32('idx:' . $token);
            $signDigest = crc32('sgn:' . $token);

            $index = $indexDigest % $this->dimensions;
            $sign = ($signDigest % 2 === 0) ? 1.0 : -1.0;

            $vector[$index] += $sign;
        }

        return $vector;
    }

    /**
     * Split text into lowercase alphanumeric tokens.
     *
     * @return string[]
     */
    private function tokenize(string $content): array
    {
        $tokens = preg_split('/[^a-z0-9]+/i', strtolower($content), -1, PREG_SPLIT_NO_EMPTY);

        return $tokens === false ? [] : $tokens;
    }

    /**
     * Normalize a vector to unit length.
     *
     * @param float[] $vector The vector to normalize.
     * @return float[] Unit-normalized vector.
     */
    private function normalize(array $vector): array
    {
        // Calculate magnitude
        $magnitude = sqrt(array_sum(array_map(fn($v) => $v * $v, $vector)));

        if ($magnitude === 0.0) {
            // Edge case: zero vector (shouldn't happen with our hash-based approach)
            return $vector;
        }

        // Normalize
        return array_map(fn($v) => $v / $magnitude, $vector);
    }
}
