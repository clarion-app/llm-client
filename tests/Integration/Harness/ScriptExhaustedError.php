<?php

namespace Tests\Integration\Harness;

use RuntimeException;

/**
 * Thrown when a ResponseScript is exhausted (no more steps to serve).
 *
 * This is a harness error (test misconfiguration), not a network error.
 */
class ScriptExhaustedError extends RuntimeException
{
}
