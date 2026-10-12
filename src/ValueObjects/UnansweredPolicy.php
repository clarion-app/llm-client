<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * What happens when nobody answers a question within its answerable
 * period, chosen by the agent when it asks and fixed from then on.
 *
 * `Stop` (the default) pauses the task until the user answers late or ends
 * the conversation; `ProceedOnAssumption` continues the task with the
 * answer the agent said it would assume.
 */
enum UnansweredPolicy: string
{
    case ProceedOnAssumption = 'proceed_on_assumption';
    case Stop = 'stop';
}
