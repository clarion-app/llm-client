<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Contracts\FaultInjector;

/**
 * The production fault-injection seam (research D21): a no-op. Each named
 * point costs one method call; nothing is recorded or thrown.
 */
class NullFaultInjector implements FaultInjector
{
    public function reach(string $point): void
    {
        // no-op
    }
}
