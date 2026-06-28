<?php

namespace ClarionApp\LlmClient\Exceptions;

use RuntimeException;

/**
 * Exception thrown when semantic search operations fail.
 *
 * Provides structured error information including a machine-readable reason
 * code and an optional suggestion for remediation.
 */
class SemanticSearchException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message = '',
        public readonly ?string $suggestion = null,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        $defaultMessage = match ($reason) {
            'semantic_search_long_term_only' => 'Semantic search is only available for the long_term scope. Use key_prefix or content mode for scratch and short_term scopes.',
            'embedding_provider_unavailable' => 'No embedding provider is available for semantic search. Configure a dedicated embedding server or use a chat provider that supports embeddings.',
            'embedding_generation_failed' => 'Failed to generate embedding for the search query. The embedding service returned an error.',
            default => 'Semantic search failed: ' . $reason,
        };

        parent::__construct($message ?: $defaultMessage, $code, $previous);
    }
}
