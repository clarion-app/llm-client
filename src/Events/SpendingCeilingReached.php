<?php

namespace ClarionApp\LlmClient\Events;

use ClarionApp\LlmClient\Support\OperatorAccess;
use ClarionApp\LlmClient\ValueObjects\EnforcementDecision;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * A scope's consumption has reached a ceiling for the first time in the
 * current period.
 *
 * Fired in *either* enforcement mode. A stop-mode crossing is announced
 * because somebody has just been refused; a warn-mode crossing is announced
 * because nobody has, and an operator who is told nothing has no way to
 * discover that a limit they set is being exceeded.
 *
 * This is a separate kind from SpendingThresholdWarned rather than a flag on
 * it, because the two are latched separately: the approach warning already
 * having fired in a period must never suppress the more important news that
 * the ceiling itself was reached in that same period.
 *
 * Channels and payload are resolved exactly as SpendingThresholdWarned's
 * are, which in turn is exactly how Events/RunUpdated.php resolves its own —
 * look up the owner and hand the id to the already-hardened
 * PrivateChannel('User.{id}'), with zero identifier comparisons and zero new
 * channel-authorization predicates.
 */
class SpendingCeilingReached implements ShouldBroadcastNow
{
    use SerializesModels;

    /** How this payload identifies itself on the wire. */
    public const KIND = 'reached';

    /**
     * @param  string|null  $userId  the user this concerns, or null when the
     *   ceiling reached is the installation's and the audience is its operators
     * @param  EnforcementDecision  $decision  the ceiling, the figure, the
     *   period, and the one sentence describing all three
     */
    public function __construct(
        public readonly ?string $userId,
        public readonly EnforcementDecision $decision,
    ) {
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        if ($this->userId !== null) {
            return [new PrivateChannel('User.'.$this->userId)];
        }

        $channels = [];

        foreach ((array) config('llm-client.cost.operator_user_ids', []) as $operatorId) {
            if (!is_string($operatorId) || !OperatorAccess::isOperator($operatorId)) {
                continue;
            }

            $channels[] = new PrivateChannel('User.'.$operatorId);
        }

        return $channels;
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'kind' => self::KIND,
            'ceiling' => $this->decision->ceilingArray(),
            'period' => $this->decision->periodArray(),
            'consumption' => $this->decision->snapshot?->toArray(),
            'remaining' => $this->decision->remaining(),
            'message' => $this->decision->reason,
        ];
    }
}
