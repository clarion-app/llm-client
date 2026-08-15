<?php

namespace ClarionApp\LlmClient\Exceptions;

/**
 * 105-stage-pipeline (contracts/stage-pipeline-api.md §1). Thrown by
 * SequenceService::defineSequence() for any of its four named 422s
 * (empty_stages / unknown_coordinator_agent / unknown_helper_agent /
 * invalid_schema) -- SequenceController::store() catches this and maps it
 * directly to the contract's 422 body, including stage_position when the
 * offending stage is known (unknown_helper_agent / invalid_schema; the
 * other two are checked before any per-stage loop runs, so they never
 * carry one).
 *
 * The FIRST validation failure encountered wins (stages are checked in
 * array order, contracts §1's own "the FIRST... is reported" convention
 * mirrored from FR-016's run-invocation check) -- a caller fixing that one
 * error and retrying surfaces the next, rather than every failure being
 * enumerated in one response.
 */
final class SequenceDefinitionValidationException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?int $stagePosition = null,
    ) {
        parent::__construct($message);
    }
}
