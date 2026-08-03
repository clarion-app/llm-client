<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * The relationship of a message to a run.
 */
enum RunRelation: string
{
    case Trigger = 'trigger';
    case Reply = 'reply';
}
