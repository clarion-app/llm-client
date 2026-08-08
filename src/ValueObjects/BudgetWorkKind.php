<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * The kind of work being admitted, carried into a refusal record.
 *
 * This selects only the *surface* of a refusal — an HTTP 402 at a request
 * boundary versus a recorded run for background work — and NEVER a
 * different rule. Every kind is evaluated against the same ceilings by the
 * same comparison; no way of starting model-consuming work gets a weaker
 * check than another.
 */
enum BudgetWorkKind: string
{
    case Interactive = 'interactive';
    case Resumed = 'resumed';
    case Deferred = 'deferred';
    case SystemInitiated = 'system_initiated';
}
