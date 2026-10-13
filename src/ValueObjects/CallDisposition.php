<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * The disposition of one tool call in a resume plan (checkpoint-ledger
 * contract §4). Only `Reuse` carries its result entry (the exact entry
 * later stored in the step message's `tool_data.tool_results`); the other
 * kinds carry none.
 */
final class CallDisposition
{
    public function __construct(
        public readonly CallDispositionKind $kind,
        public readonly ?array $result = null,
    ) {
    }
}
