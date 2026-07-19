<?php

namespace Tests\Unit\Integration;

use PHPUnit\Framework\TestCase;
use Tests\Integration\Harness\DeterministicEmbedder;

class DeterministicEmbedderTest extends TestCase
{
    /* ------------------------------------------------------------------ */
    /*  T009: DeterministicEmbedder unit tests                             */
    /* ------------------------------------------------------------------ */

    public function test_same_input_produces_identical_vector()
    {
        $embedder = new DeterministicEmbedder(768);
        $input = 'The quick brown fox jumps over the lazy dog';

        $vector1 = $embedder->embed($input);
        $vector2 = $embedder->embed($input);

        $this->assertEquals($vector1, $vector2, 'Same input should produce identical vectors');
    }

    public function test_same_input_across_instances()
    {
        $embedder1 = new DeterministicEmbedder(1536);
        $embedder2 = new DeterministicEmbedder(1536);
        $input = 'Deterministic embedding test string';

        $vector1 = $embedder1->embed($input);
        $vector2 = $embedder2->embed($input);

        $this->assertEquals($vector1, $vector2, 'Different instances should produce identical vectors for same input');
    }

    public function test_vector_has_correct_dimensions()
    {
        $dim = 768;
        $embedder = new DeterministicEmbedder($dim);

        $vector = $embedder->embed('test content');

        $this->assertCount($dim, $vector);
    }

    public function test_vector_has_correct_dimensions_large()
    {
        $dim = 1536;
        $embedder = new DeterministicEmbedder($dim);

        $vector = $embedder->embed('test content');

        $this->assertCount($dim, $vector);
    }

    public function test_vector_values_are_floats()
    {
        $embedder = new DeterministicEmbedder(768);
        $vector = $embedder->embed('test');

        foreach ($vector as $value) {
            $this->assertIsFloat($value);
        }
    }

    public function test_distinct_inputs_produce_distinct_vectors()
    {
        $embedder = new DeterministicEmbedder(768);

        $vector1 = $embedder->embed('The capital of France is Paris');
        $vector2 = $embedder->embed('The capital of Japan is Tokyo');

        $this->assertNotEquals($vector1, $vector2, 'Distinct inputs should produce distinct vectors');
    }

    public function test_cosine_similarity_is_stable_and_non_degenerate()
    {
        $embedder = new DeterministicEmbedder(768);

        $similar1 = $embedder->embed('I love programming in PHP');
        $similar2 = $embedder->embed('I enjoy coding in PHP');
        $dissimilar = $embedder->embed('The weather is nice today');

        $similarCos = $this->cosineSimilarity($similar1, $similar2);
        $dissimilarCos = $this->cosineSimilarity($similar1, $dissimilar);

        // Both similarities should be in [-1, 1]
        $this->assertGreaterThanOrEqual(-1.0, $similarCos);
        $this->assertLessThanOrEqual(1.0, $similarCos);
        $this->assertGreaterThanOrEqual(-1.0, $dissimilarCos);
        $this->assertLessThanOrEqual(1.0, $dissimilarCos);

        // Self-similarity should be ~1.0 (floating point tolerance)
        $selfCos = $this->cosineSimilarity($similar1, $similar1);
        $this->assertLessThan(1e-4, abs($selfCos - 1.0), 'Self-similarity should be ~1.0');
    }

    public function test_cosine_ordering_is_stable()
    {
        $embedder = new DeterministicEmbedder(768);

        $a = $embedder->embed('apple');
        $b = $embedder->embed('banana');
        $c = $embedder->embed('cherry');

        $firstAb = $this->cosineSimilarity($a, $b);
        $firstAc = $this->cosineSimilarity($a, $c);

        // Re-deriving the same comparison must give bit-identical results, or
        // scenarios asserting relative order would be flaky by construction.
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(
                $firstAb,
                $this->cosineSimilarity($embedder->embed('apple'), $embedder->embed('banana')),
                'Cosine similarity must be identical across repeated derivations'
            );
            $this->assertSame(
                $firstAc,
                $this->cosineSimilarity($embedder->embed('apple'), $embedder->embed('cherry')),
                'Cosine similarity must be identical across repeated derivations'
            );
        }
    }

    /**
     * The property SC-001a depends on: shared vocabulary must rank above
     * unrelated text. Without this the harness cannot assert that retrieval
     * ordered by relevance rather than by insertion.
     */
    public function test_shared_vocabulary_scores_above_unrelated_text()
    {
        $embedder = new DeterministicEmbedder(768);

        $query = $embedder->embed('Help me with billing service settings');
        $relevant = $embedder->embed('User prefers billing service notifications via email');
        $unrelated = $embedder->embed('User timezone is UTC-5');

        $this->assertGreaterThan(
            $this->cosineSimilarity($query, $unrelated),
            $this->cosineSimilarity($query, $relevant),
            'Text sharing vocabulary with the query must score above unrelated text'
        );
    }

    public function test_collision_resistance()
    {
        $embedder = new DeterministicEmbedder(768);

        // Generate vectors for many distinct inputs
        $vectors = [];
        $inputs = [];
        for ($i = 0; $i < 100; $i++) {
            $input = "Unique input string number {$i} with some additional text";
            $inputs[] = $input;
            $vectors[] = $embedder->embed($input);
        }

        // All vectors should be unique (no collisions)
        $uniqueVectors = array_unique($vectors, SORT_REGULAR);
        $this->assertCount(count($vectors), $uniqueVectors, 'No vectors should collide for distinct inputs');
    }

    public function test_embed_batch_returns_correct_count()
    {
        $embedder = new DeterministicEmbedder(768);
        $contents = ['first', 'second', 'third'];

        $batch = $embedder->embedBatch($contents);

        $this->assertCount(3, $batch);
    }

    public function test_embed_batch_matches_individual_embeds()
    {
        $embedder = new DeterministicEmbedder(768);
        $contents = ['hello world', 'goodbye world'];

        $batch = $embedder->embedBatch($contents);

        $this->assertEquals($embedder->embed('hello world'), $batch[0]);
        $this->assertEquals($embedder->embed('goodbye world'), $batch[1]);
    }

    public function test_embed_batch_empty()
    {
        $embedder = new DeterministicEmbedder(768);
        $batch = $embedder->embedBatch([]);

        $this->assertIsArray($batch);
        $this->assertCount(0, $batch);
    }

    public function test_vectors_are_normalized()
    {
        $embedder = new DeterministicEmbedder(768);
        $vector = $embedder->embed('test content for normalization');

        // Calculate magnitude
        $magnitude = sqrt(array_sum(array_map(fn($v) => $v * $v, $vector)));

        // Should be approximately 1.0 (normalized, floating point tolerance)
        $this->assertLessThan(1e-4, abs($magnitude - 1.0), 'Vectors should be unit-normalized');
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers                                                            */
    /* ------------------------------------------------------------------ */

    private function cosineSimilarity(array $a, array $b): float
    {
        $dotProduct = 0.0;
        $magA = 0.0;
        $magB = 0.0;

        for ($i = 0; $i < count($a); $i++) {
            $dotProduct += $a[$i] * $b[$i];
            $magA += $a[$i] * $a[$i];
            $magB += $b[$i] * $b[$i];
        }

        $magA = sqrt($magA);
        $magB = sqrt($magB);

        if ($magA === 0.0 || $magB === 0.0) {
            return 0.0;
        }

        return $dotProduct / ($magA * $magB);
    }
}
