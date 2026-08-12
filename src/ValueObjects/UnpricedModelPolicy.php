<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * What to do when a not-yet-executed request targets a model with no
 * configured price (research.md D8, config('llm-client.budget.on_unpriced_model')).
 */
enum UnpricedModelPolicy: string
{
    case Stop = 'stop';
    case AdmitUntracked = 'admit_untracked';
    case ReserveFlatEstimate = 'reserve_flat_estimate';
}
