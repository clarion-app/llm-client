<?php

namespace ClarionApp\LlmClient\ValueObjects;

enum RoleResolutionStatus: string
{
    case Resolved    = 'resolved';
    case Unassigned  = 'unassigned';
    case Broken      = 'broken';
}
