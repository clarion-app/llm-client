<?php

namespace ClarionApp\LlmClient\Exceptions;

use Exception;

/**
 * Raised by EvalSuiteImporter when the effective (agent_identifier, name)
 * pair of an import — the override if given, else the document's own pair
 * — is already held by a live suite (contracts/eval-suites-api.md §5, C9).
 *
 * Extends \Exception rather than \InvalidArgumentException deliberately:
 * the controller must be able to tell "your file is malformed" (422) apart
 * from "your file is fine, but that name is taken" (409), and the only
 * contract requirement (C9) is that this class be distinct from
 * \InvalidArgumentException so the two can never be caught by the same
 * catch block.
 */
final class NameConflictException extends Exception
{
    public function __construct(
        public readonly string $name,
        public readonly string $agentIdentifier,
    ) {
        parent::__construct(
            sprintf(
                "A suite named '%s' already exists for agent '%s'.",
                $this->name,
                $this->agentIdentifier,
            )
        );
    }
}
