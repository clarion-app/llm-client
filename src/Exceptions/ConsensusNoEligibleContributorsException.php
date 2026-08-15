<?php

namespace ClarionApp\LlmClient\Exceptions;

/**
 * Multi-opinion mode was requested but too few active AgentHelperAssignment
 * rows exist to attempt it — either zero eligible helpers, or two-or-more
 * but still fewer than `consensus.min_contributor_count` (only reachable
 * when an installation raises that config above its default of 2; an
 * eligible count of exactly 1 never reaches this exception and instead
 * always routes to `single_contributor_fallback`, FR-016). Raised by
 * ConsensusService::dispatch() and caught by ConsensusController, which
 * composes contracts/consensus-api.md §1's exact 422 body from
 * $eligibleCount/$minRequired (104-multi-agent-consensus,
 * contracts/consensus-reconciliation-contract.md §1).
 */
final class ConsensusNoEligibleContributorsException extends \RuntimeException
{
    public function __construct(
        public readonly int $eligibleCount,
        public readonly int $minRequired,
    ) {
        parent::__construct(sprintf(
            'At least %d assigned helpers are required for multi-opinion mode; this agent has %d.',
            $minRequired,
            $eligibleCount,
        ));
    }
}
