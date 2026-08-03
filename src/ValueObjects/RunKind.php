<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * Whether the run was started by user interaction or by a system job.
 */
enum RunKind: string
{
    case Interactive = 'interactive';
    case SystemInitiated = 'system_initiated';
}
