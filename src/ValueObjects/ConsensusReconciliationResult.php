<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * The two-variant outcome of a single ConsensusReconciliationJudge::reconcile()
 * call. Never a thrown exception — every failure mode (unassigned judge role,
 * budget refusal, provider error, malformed response) converges on the same
 * `unreconciled` shape with a human-readable reason, directly mirroring
 * RubricJudgmentResult's own two-variant shape
 * (104-multi-agent-consensus, contracts/consensus-reconciliation-contract.md
 * §2).
 */
final class ConsensusReconciliationResult
{
    private function __construct(
        private readonly bool $reconciled,
        public readonly ?string $classification,
        public readonly ?string $reconciledAnswer,
        public readonly ?array $positions,
        public readonly ?string $judgeModel,
        public readonly ?string $judgeServerId,
        public readonly ?string $judgeConversationId,
        public readonly ?string $reason,
    ) {
    }

    public static function reconciled(
        string $classification,
        string $reconciledAnswer,
        array $positions,
        string $judgeModel,
        string $judgeServerId,
        string $judgeConversationId,
    ): self {
        return new self(
            reconciled: true,
            classification: $classification,
            reconciledAnswer: $reconciledAnswer,
            positions: $positions,
            judgeModel: $judgeModel,
            judgeServerId: $judgeServerId,
            judgeConversationId: $judgeConversationId,
            reason: null,
        );
    }

    public static function unreconciled(string $reason): self
    {
        return new self(
            reconciled: false,
            classification: null,
            reconciledAnswer: null,
            positions: null,
            judgeModel: null,
            judgeServerId: null,
            judgeConversationId: null,
            reason: $reason,
        );
    }

    public function isReconciled(): bool
    {
        return $this->reconciled;
    }
}
