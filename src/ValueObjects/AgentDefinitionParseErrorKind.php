<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * The structural problems AgentDefinitionParser::parse() can find in a
 * document — malformed YAML, an unsupported format_version, a missing
 * name, an unrecognized (or wrongly-shaped) key anywhere in the document,
 * or instructions exceeding the configured token bound. See
 * contracts/agent-definition-parser.md §3 for the exact per-kind message
 * each produces.
 */
enum AgentDefinitionParseErrorKind
{
    case MalformedYaml;
    case UnrecognizedFormatVersion;
    case MissingName;
    case UnknownKey;
    case InstructionsTooLong;
}
