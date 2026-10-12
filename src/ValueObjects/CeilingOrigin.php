<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * Where a task's ceiling came from: the installation default, the user, or
 * the user's stated value reduced to the installation maximum.
 */
enum CeilingOrigin: string
{
    case Default = 'default';
    case User = 'user';
    case UserReducedToMaximum = 'user_reduced_to_maximum';
}
