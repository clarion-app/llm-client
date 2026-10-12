<?php

namespace ClarionApp\LlmClient\Exceptions;

use ClarionApp\LlmClient\Services\TaskBudgetText;

/**
 * A consensus request could not open the task budget its contributors would
 * work under, so it is refused before anything is recorded or any
 * contributor is asked — never run on some other task's budget. Raised by
 * ConsensusService::dispatch() and caught by ConsensusController.
 */
final class ConsensusTaskBudgetUnavailableException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct((new TaskBudgetText())->unavailable());
    }
}
