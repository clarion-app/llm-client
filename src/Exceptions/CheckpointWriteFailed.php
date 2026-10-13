<?php

namespace ClarionApp\LlmClient\Exceptions;

/**
 * A checkpoint write failed (checkpoint-ledger contract §1). Thrown
 * instead of swallowed: a checkpoint that cannot be written must stop the
 * run, not silently proceed on a stale one. The `reason` is a short code
 * (e.g. `inside_transaction`) the caller can branch on.
 */
class CheckpointWriteFailed extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason,
    ) {
        parent::__construct($message);
    }
}
