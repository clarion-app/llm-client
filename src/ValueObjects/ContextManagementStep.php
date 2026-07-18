<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * A single mechanism step within a ContextManagementOutcome.
 *
 * One step is recorded per mechanism that actually acted in a request.
 */
final class ContextManagementStep
{
    public function __construct(
        public readonly string $mechanism,
        public readonly int $tokensBefore,
        public readonly int $tokensAfter,
        public readonly int $tokensSaved,
        public readonly ?string $error = null,
    ) {}

    /**
     * Create a trim step (budgeter dropped units or truncated).
     */
    public static function trim(int $tokensBefore, int $tokensAfter): self
    {
        return new self(
            mechanism: 'trim',
            tokensBefore: $tokensBefore,
            tokensAfter: $tokensAfter,
            tokensSaved: $tokensBefore - $tokensAfter,
        );
    }

    /**
     * Create a smart_trim step (smart trimmer evicted messages).
     */
    public static function smartTrim(int $tokensBefore, int $tokensAfter): self
    {
        return new self(
            mechanism: 'smart_trim',
            tokensBefore: $tokensBefore,
            tokensAfter: $tokensAfter,
            tokensSaved: $tokensBefore - $tokensAfter,
        );
    }

    /**
     * Create a condense step.
     *
     * @param int $sourceChunkTokens Tokens in the source chunk (0 if replayed from cache).
     * @param int $summaryTokens Tokens in the generated summary.
     */
    public static function condense(int $sourceChunkTokens, int $summaryTokens): self
    {
        // tokens_saved = sourceChunkTokens - summaryTokens; 0 when replayed from cache.
        $tokensSaved = $sourceChunkTokens > 0 ? $sourceChunkTokens - $summaryTokens : 0;

        return new self(
            mechanism: 'condense',
            tokensBefore: $sourceChunkTokens,
            tokensAfter: $summaryTokens,
            tokensSaved: $tokensSaved,
        );
    }

    /**
     * Create a condense step that failed during execution.
     */
    public static function condenseError(string $errorMessage): self
    {
        return new self(
            mechanism: 'condense',
            tokensBefore: 0,
            tokensAfter: 0,
            tokensSaved: 0,
            error: $errorMessage,
        );
    }

    /**
     * Create a trim step that failed during execution.
     */
    public static function trimError(string $errorMessage): self
    {
        return new self(
            mechanism: 'trim',
            tokensBefore: 0,
            tokensAfter: 0,
            tokensSaved: 0,
            error: $errorMessage,
        );
    }

    /**
     * Create a smart_trim step that failed during execution.
     */
    public static function smartTrimError(string $errorMessage): self
    {
        return new self(
            mechanism: 'smart_trim',
            tokensBefore: 0,
            tokensAfter: 0,
            tokensSaved: 0,
            error: $errorMessage,
        );
    }
}
