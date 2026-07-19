<?php

namespace Tests\Integration\Harness;

use RuntimeException;

/**
 * Thrown when an embeddings request is made but no embedder is configured.
 *
 * This is wrapped in a RequestException by ScriptedTransport so the product's
 * real error handling path executes (e.g., EmbeddingService fallback).
 */
class EmbeddingsDisabledError extends RuntimeException
{
}
