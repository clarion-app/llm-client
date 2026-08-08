<?php

namespace ClarionApp\LlmClient\Events;

use ClarionApp\LlmClient\Support\OperatorAccess;
use ClarionApp\LlmClient\ValueObjects\EnforcementDecision;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * A scope's consumption has crossed a ceiling's approach threshold for the
 * first time in the current period.
 *
 * Fired at most once per scope per period, by BudgetThresholdNotifier, and
 * only after that notifier has won the durable once-per-period latch — so
 * this event existing at all already means "this is the first time".
 *
 * broadcastOn() resolves its channels exactly as Events/RunUpdated.php
 * does: the owner's id is looked up and handed to the already-hardened
 * PrivateChannel('User.{id}'). Zero new channel-authorization predicates and
 * zero identifier comparisons — a standing rule in this package, adopted
 * after an integer-cast UUID collision on this very channel let one user's
 * payload reach another. The operator fan-out is spelled out here rather
 * than shared with SpendingCeilingReached because the channel name has to
 * be constructed in the file that broadcasts it for that rule to be
 * checkable by reading the file.
 *
 * broadcastWith() carries the same shapes the 402 refusal body uses, so an
 * interface renders a warning and a refusal with one code path — including
 * the message itself, which is composed inside EnforcementDecision so the
 * three surfaces cannot drift into three plausible half-truths about the
 * same ceiling.
 */
class SpendingThresholdWarned implements ShouldBroadcastNow
{
    use SerializesModels;

    /** How this payload identifies itself on the wire. */
    public const KIND = 'approach';

    /**
     * @param  string|null  $userId  the user this concerns, or null when the
     *   crossing is the installation's and the audience is its operators
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
