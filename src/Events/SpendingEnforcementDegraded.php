<?php

namespace ClarionApp\LlmClient\Events;

use ClarionApp\LlmClient\Support\OperatorAccess;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * Enforcement is running without a consumption figure it can trust.
 *
 * Operators only: this is a statement about the installation's plumbing,
 * not about anybody's allowance, and a user cannot act on it.
 *
 * broadcastOn() resolves its channels exactly as Events/RunUpdated.php
 * does — each operator's id is looked up and handed to the already-hardened
 * PrivateChannel('User.{id}'). Zero new Broadcast::channel() predicates and
 * zero new identifier-comparison code: that is a standing rule in this
 * package, adopted after an (int)-cast UUID collision on this very channel
 * let one user's payload reach another.
 *
 * Throttled by the caller (BudgetGate) rather than here, because the
 * throttle is about how often the *condition* is announced, not about how
 * an announcement is delivered.
 */
class SpendingEnforcementDegraded implements ShouldBroadcastNow
{
    use SerializesModels;

    /**
     * @param  string  $scopeType  the scope whose figure could not be read
     * @param  string|null  $scopeId  the user UUID, or null for the installation
     * @param  string  $periodType  day | week | month
     * @param  string  $policy  what the gate did about it: 'stop' or 'allow'
     */
    public function __construct(
        public readonly string $scopeType,
        public readonly ?string $scopeId,
        public readonly string $periodType,
        public readonly string $policy,
        public readonly ?string $detail = null,
    ) {
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
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
            'kind' => 'enforcement_degraded',
            'scope_type' => $this->scopeType,
            'scope_id' => $this->scopeId,
            'period_type' => $this->periodType,
            'policy' => $this->policy,
            'detail' => $this->detail,
            'message' => 'Spending consumption could not be read, so enforcement is running '
                .($this->policy === 'stop' ? 'fail-closed and refusing new work.' : 'fail-open and allowing work.'),
        ];
    }
}
