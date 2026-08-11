<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * The two-variant outcome of a single RubricJudge::judge() call. Never a
 * thrown exception — every failure mode (unassigned role, budget refusal,
 * provider error, malformed response) converges on the same `unjudged`
 * shape with a human-readable reason, mirroring the status-carrying value
 * object pattern already used elsewhere in this package (RoleResolution,
 * EnforcementDecision) rather than a thrown exception.
 */
final class RubricJudgmentResult
{
    private function __construct(
        public readonly string $status,
        public readonly ?int $score,
        public readonly ?string $justification,
        public readonly ?string $unjudgedReason,
        public readonly ?string $model,
        public readonly ?string $serverId,
        public readonly ?string $conversationId,
    ) {
    }

    public static function judged(
        int $score,
        string $justification,
        string $model,
        string $serverId,
        string $conversationId,
    ): self {
        return new self(
            status: 'judged',
            score: $score,
            justification: $justification,
            unjudgedReason: null,
            model: $model,
            serverId: $serverId,
            conversationId: $conversationId,
        );
    }

    public static function unjudged(string $reason): self
    {
        return new self(
            status: 'unjudged',
            score: null,
            justification: null,
            unjudgedReason: $reason,
            model: null,
            serverId: null,
            conversationId: null,
        );
    }
}
