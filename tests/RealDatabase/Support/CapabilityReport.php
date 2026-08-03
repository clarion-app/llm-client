<?php

namespace Tests\RealDatabase\Support;

/**
 * What the functional capability probe learned about the database.
 */
class CapabilityReport
{
    public function __construct(
        public readonly string $serverVersion,
        public readonly bool $vectorType,
        public readonly bool $vectorDistanceFunction,
        public readonly bool $fulltextIndex,
        public readonly int $minTokenSize,
        public readonly bool $stopwordsEnabled,
    ) {
    }

    /**
     * Check if all required capabilities are present.
     */
    public function isCapable(): bool
    {
        return $this->vectorType && $this->vectorDistanceFunction && $this->fulltextIndex;
    }

    /**
     * List the missing capabilities for diagnostic purposes.
     */
    public function missingCapabilities(): array
    {
        $missing = [];
        if (!$this->vectorType) $missing[] = 'VECTOR column type';
        if (!$this->vectorDistanceFunction) $missing[] = 'VEC_DISTANCE_COSINE function';
        if (!$this->fulltextIndex) $missing[] = 'FULLTEXT index / MATCH AGAINST';
        return $missing;
    }
}
