<?php

namespace ClarionApp\LlmClient\Events;

use ClarionApp\LlmClient\ValueObjects\TrimAudit;

/**
 * Dispatched when smart history trimming discards low-value messages.
 *
 * Carries a TrimAudit payload with per-decision details for debugging and metrics.
 */
class SmartHistoryTrimmed
{
    public function __construct(
        public readonly TrimAudit $audit,
    ) {}
}
