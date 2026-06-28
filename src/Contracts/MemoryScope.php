<?php

namespace ClarionApp\LlmClient\Contracts;

enum MemoryScope: string
{
    case SCRATCH = 'scratch';
    case SHORT_TERM = 'short_term';
    case LONG_TERM = 'long_term';
}
