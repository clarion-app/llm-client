<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * The planner's verdict that a run cannot be resumed, carrying a reason
 * code from data-model.md §5 `stopped_unresumable` (e.g.
 * `repeated_interruption`, `checkpoint_incoherent:<rule>`,
 * `legacy_insufficient`, `model_unavailable`, `tool_unavailable`,
 * `superseded`, `ended_without_outcome`) and an optional detail.
 */
final class Unresumable
{
    public function __construct(
        public readonly string $reasonCode,
        public readonly ?string $detail = null,
    ) {
    }
}
