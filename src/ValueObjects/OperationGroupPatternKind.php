<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * How an OperationGroupPattern's raw string is matched against a candidate
 * operation (research.md D8): a bare uppercase HTTP verb denotes "every
 * operation with this method"; anything else is a glob matched against the
 * operation's operationId.
 */
enum OperationGroupPatternKind
{
    case Glob;
    case HttpVerb;
}
