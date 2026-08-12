<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * The semantic problems AgentDefinitionParser::parse() can find in an
 * otherwise structurally valid document — a named model, capability, or
 * operation-group pattern that does not resolve against this
 * installation's current state. See contracts/agent-definition-parser.md
 * §3 for the exact per-kind message each produces.
 */
enum AgentDefinitionResolutionErrorKind
{
    case UnknownModel;
    case UnknownCapability;
    case EmptyOperationPattern;
}
