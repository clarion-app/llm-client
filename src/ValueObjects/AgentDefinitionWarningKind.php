<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * Discriminant for AgentDefinitionWarning::$kind.
 *
 * One case today, deliberately not pre-populated with placeholder
 * cases for hypothetical future warnings (spec's Assumptions section).
 */
enum AgentDefinitionWarningKind
{
    /**
     * The document's own tools.allow/tools.deny permit an operation
     * whose HTTP method is in config('llm-client.confirm_methods'),
     * and the document's own safety.confirmation_required does not
     * cover it (research.md D2).
     */
    case DestructiveOperationWithoutConfirmation;
}
