<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * Why AgentVersionComparer::compare() refused to compare two version ids
 * (data-model.md §5). Mirrors AgentDefinitionResolutionErrorKind's own
 * "one enum, one exception class, one match for the message" shape
 * (087/086) rather than inventing two separate exception classes.
 */
enum InvalidAgentVersionComparisonKind
{
    /** The caller named the identical version id on both sides. */
    case SameVersion;

    /** The two version ids resolve to different Agent identities. */
    case DifferentAgents;
}
