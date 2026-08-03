<?php

namespace ClarionApp\LlmClient\Exceptions;

use ClarionApp\LlmClient\ValueObjects\ModelRole;
use Exception;

class RoleAssignmentFailedException extends Exception
{
    public function __construct(
        public readonly ModelRole $role,
        public readonly string $model,
        public readonly ?string $reason = null,
    ) {
        $message = match ($role) {
            ModelRole::Inference => sprintf(
                'Inference model "%s" is unavailable%s. Configure a different model in LLM settings.',
                $model,
                $reason ? sprintf(' (%s)', $reason) : ''
            ),
            ModelRole::Embedding => sprintf(
                'Embedding model "%s" is unavailable%s. Configure a different model in LLM settings.',
                $model,
                $reason ? sprintf(' (%s)', $reason) : ''
            ),
            ModelRole::Image => sprintf(
                'Image model "%s" is unavailable%s. Configure a different model in LLM settings.',
                $model,
                $reason ? sprintf(' (%s)', $reason) : ''
            ),
        };

        parent::__construct($message);
    }
}
