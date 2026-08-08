<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * What a ceiling does once its amount is reached: warn and carry on, or
 * refuse new work.
 */
enum EnforcementMode: string
{
    case Warn = 'warn';
    case Stop = 'stop';
}
