<?php

namespace ClarionApp\LlmClient\Exceptions;

use ClarionApp\LlmClient\ValueObjects\ModelRole;
use RuntimeException;
use Throwable;

/**
 * A model picked *because* a role pointed at it could not be used.
 *
 * Extends RuntimeException deliberately (contracts/role-assignment.md §3.4):
 * every consumer of an embedding — AutoMemoryRetriever, MemoryService,
 * EpisodicMemorySearchService, EmbeddingService::generateForEntry — already
 * catches \RuntimeException to degrade gracefully. Extending \Exception here
 * would sail straight through those catches and surface as an unhandled error,
 * which is exactly what SC-006 forbids.
 */
final class RoleAssignmentFailedException extends RuntimeException
{
    public function __construct(
        public readonly ModelRole $role,
        public readonly string $model,
        public readonly ?string $reason = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                'The %s role\'s assigned model "%s" could not be used%s. Configure a different model in LLM settings.',
                $role->value,
                $model,
                $reason ? sprintf(' (%s)', $reason) : ''
            ),
            0,
            $previous
        );
    }
}
