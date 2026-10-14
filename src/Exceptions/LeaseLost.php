<?php

namespace ClarionApp\LlmClient\Exceptions;

/**
 * The run's lease was lost to another worker (checkpoint-ledger contract
 * §1): our lease expired while we were slow, and another process took
 * the run over. The current process must stop before its next action —
 * the funnel and the loop head catch this, close nothing, record nothing
 * further, and return `['status' => 'superseded']`.
 */
class LeaseLost extends CheckpointWriteFailed
{
    public function __construct(
        string $message = 'The run lease was lost to another worker.',
        string $reason = 'lease_lost',
    ) {
        parent::__construct($message, $reason);
    }
}
