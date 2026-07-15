<?php

namespace ClarionApp\LlmClient\Services;

/**
 * Validates coherence of trimmed conversation history.
 *
 * Detects messages that reference dropped content (dangling references)
 * and cascade-drops them to maintain coherent context. Bounded to max 3 levels deep.
 */
class CoherenceValidator
{
    /** Patterns that indicate a message references earlier content. */
    private const REFERENCE_PATTERNS = [
        '/\bas\s+shown\s+(above|below|before|earlier)\b/i',
        '/\bthe\s+(output|result|response|answer)\s+(above|below|before|earlier)\b/i',
        '/\b(as\s+mentioned|as\s+noted|as\s+stated)\s+(above|before|earlier)/i',
        '/\b(see|refer\s+to|check)\s+(above|below|before|earlier)/i',
        '/\bthe\s+(previous|prior|last)\s+(message|response|answer|result)/i',
        '/\bas\s+(discussed|explained|described)\s+(above|before|earlier)/i',
        '/\b(looking\s+at|based\s+on)\s+the\s+(output|result|response)/i',
        '/\bthat\s+(output|result|response|answer)\s+(you|we|it)\s+(got|received|found)/i',
    ];

    /** Maximum cascade depth to prevent O(n²) behavior. */
    private const MAX_CASCADE_DEPTH = 3;

    /**
     * Validate trimmed history and return indices of messages that should be cascade-dropped.
     *
     * @param list<array{role: string, content: string|null, tool_calls?: array, tool_call_id?: string}> $messages
     *        Full history messages (no system).
     * @param list<array{messages: list<array>, messageIndices: list<int>, estimatedTokens: int}> $units
     *        Turn units.
     * @param list<int> $evictedIndices Unit indices already evicted.
     * @param list<int> $preservedIndices Unit indices that are preserved (recent pairs).
     *
     * @return list<int> Additional unit indices to drop due to dangling references.
     */
    public function validate(
        array $messages,
        array $units,
        array $evictedIndices,
        array $preservedIndices,
    ): array {
        if (empty($evictedIndices)) {
            return [];
        }

        // Build set of evicted message indices
        $evictedMessageIndices = [];
        foreach ($evictedIndices as $unitIdx) {
            foreach ($units[$unitIdx]['messageIndices'] ?? [] as $msgIdx) {
                $evictedMessageIndices[$msgIdx] = true;
            }
        }

        // Build preserved message indices set
        $preservedMessageIndices = [];
        foreach ($preservedIndices as $unitIdx) {
            foreach ($units[$unitIdx]['messageIndices'] ?? [] as $msgIdx) {
                $preservedMessageIndices[$msgIdx] = true;
            }
        }

        // Cascade drop: find messages referencing evicted content
        $cascadeDropped = [];
        $currentEvicted = $evictedMessageIndices;

        for ($depth = 0; $depth < self::MAX_CASCADE_DEPTH; $depth++) {
            $newlyDropped = [];

            foreach ($units as $unitIdx => $unit) {
                // Skip already evicted or already cascade-dropped units
                if (in_array($unitIdx, $evictedIndices, true) || isset($cascadeDropped[$unitIdx])) {
                    continue;
                }

                // Check if any message in this unit references evicted content
                foreach ($unit['messages'] as $msgIdx => $msg) {
                    $absoluteIdx = $unit['messageIndices'][$msgIdx] ?? 0;
                    $content = $msg['content'] ?? '';

                    if ($this->hasDanglingReference($content, $absoluteIdx, $currentEvicted, $messages)) {
                        $newlyDropped[$unitIdx] = true;
                        $cascadeDropped[$unitIdx] = true;

                        // Add this unit's message indices to evicted set for next cascade level
                        foreach ($unit['messageIndices'] as $mIdx) {
                            $currentEvicted[$mIdx] = true;
                        }
                        break;
                    }
                }
            }

            // Stop if no new messages were dropped
            if (empty($newlyDropped)) {
                break;
            }
        }

        return array_keys($cascadeDropped);
    }

    /**
     * Check if a message content has a dangling reference to evicted content.
     */
    private function hasDanglingReference(
        string $content,
        int $messageIndex,
        array $evictedMessageIndices,
        array $messages,
    ): bool {
        $trimmed = trim($content);
        if (strlen($trimmed) === 0) {
            return false;
        }

        // Check if content matches reference patterns
        $hasReference = false;
        foreach (self::REFERENCE_PATTERNS as $pattern) {
            if (preg_match($pattern, $trimmed)) {
                $hasReference = true;
                break;
            }
        }

        if (!$hasReference) {
            return false;
        }

        // Check if the reference is likely pointing to evicted content
        // (simplified: if there are evicted messages before this one, it's a potential dangling ref)
        $hasEvictedBefore = false;
        for ($i = 0; $i < $messageIndex; $i++) {
            if (isset($evictedMessageIndices[$i])) {
                $hasEvictedBefore = true;
                break;
            }
        }

        return $hasEvictedBefore;
    }
}
