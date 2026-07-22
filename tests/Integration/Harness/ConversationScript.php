<?php

namespace Tests\Integration\Harness;

use Closure;

/**
 * T010: ConversationScript — declarative description of a conversation to play.
 *
 * Builder pattern with construction rules:
 * - maxTurns must be > 0
 * - stopWhen without continuation is a construction error
 * - a script with neither stopWhen nor turns is a construction error
 */
class ConversationScript
{
    /** @var list<TurnPlan> */
    public array $turns = [];

    public ?TurnPlan $continuation = null;

    /** @var list<LaneRule> */
    public array $rules = [];

    /** @var ?Closure(PlayedConversation): bool */
    public ?Closure $stopWhenCondition = null;

    public int $maxTurns = 0;

    public string $entryPath = 'sync';

    /** @var list<string> */
    public array $requiredRules = [];

    private function __construct()
    {
    }

    public static function make(): self
    {
        return new self();
    }

    /**
     * Add an explicitly planned turn.
     *
     * @param string|callable(int $turn): string $userMessage
     * @param callable(ResponseScript): void $responses
     */
    public function turn(string|callable $userMessage, callable $responses, ?string $marker = null, array $expectDegradations = []): self
    {
        $this->turns[] = new TurnPlan($userMessage, $responses, $marker, $expectDegradations);
        return $this;
    }

    /**
     * Set a filler template for turns beyond the explicit plan.
     *
     * @param callable(int $turn): string $userMessage
     */
    public function filler(callable $userMessage, ?callable $responses = null, ?string $marker = null, array $expectDegradations = []): self
    {
        $res = $responses ?? fn (ResponseScript $r) => $r->finalAnswer('Ok.');
        $this->continuation = new TurnPlan($userMessage, $res, $marker, $expectDegradations);
        return $this;
    }

    /**
     * Add a lane rule.
     *
     * @param Closure(CapturedPayload, int $turn): bool $predicate Optional predicate (defaults to always true)
     * @param Closure(CapturedPayload, int $turn): array $respond Wire-shaped response
     */
    public function rule(RequestLane $lane, Closure $respond, ?Closure $predicate = null, ?string $label = null): self
    {
        $pred = $predicate ?? fn (CapturedPayload $p, int $t) => true;
        $lbl = $label ?? sprintf('%s_rule_%d', $lane->value, count($this->rules));
        $this->rules[] = new LaneRule($lane, $pred, $respond, $lbl);
        return $this;
    }

    /**
     * Record a rule label that must fire at least once (S6).
     */
    public function requireRule(string $label): self
    {
        $this->requiredRules[] = $label;
        return $this;
    }

    /**
     * Set the stopping condition (FR-003).
     *
     * @param Closure(PlayedConversation): bool $condition
     */
    public function stopWhen(Closure $condition): self
    {
        $this->stopWhenCondition = $condition;
        return $this;
    }

    /**
     * Convenience: stop when context management has acted at least once.
     */
    public function untilContextManagementActed(): self
    {
        $this->stopWhenCondition = function (PlayedConversation $played) {
            $convId = $played->conversationId;
            $count = \ClarionApp\LlmClient\Models\ContextManagementRecord::query()
                ->where('conversation_id', $convId)
                ->whereColumn('tokens_after', '<', 'tokens_before')
                ->count();
            return $count >= 1;
        };
        return $this;
    }

    /**
     * Convenience: stop when context management has acted at least n times.
     */
    public function untilContextManagementActedAtLeast(int $n): self
    {
        $this->stopWhenCondition = function (PlayedConversation $played) use ($n) {
            $convId = $played->conversationId;
            $count = \ClarionApp\LlmClient\Models\ContextManagementRecord::query()
                ->where('conversation_id', $convId)
                ->whereColumn('tokens_after', '<', 'tokens_before')
                ->distinct('attempt_group_id')
                ->count('attempt_group_id');
            return $count >= $n;
        };
        return $this;
    }

    /**
     * Set the hard turn bound (FR-018).
     */
    public function maxTurns(int $n): self
    {
        if ($n <= 0) {
            throw new \InvalidArgumentException(
                "maxTurns must be > 0, got {$n}. The driver would have no bound."
            );
        }

        // stopWhen without continuation is an error — the driver would have nothing to play
        if ($this->stopWhenCondition !== null && $this->continuation === null) {
            throw new \InvalidArgumentException(
                'stopWhen requires a continuation (filler). Without it, the driver has nothing to play beyond the explicit turns.'
            );
        }

        // A script with neither stopWhen nor turns is an error
        if ($this->stopWhenCondition === null && $this->turns === []) {
            throw new \InvalidArgumentException(
                'Script must have at least one turn or a stopWhen condition.'
            );
        }

        $this->maxTurns = $n;
        return $this;
    }

    /**
     * Switch to streaming entry path.
     */
    public function stream(): self
    {
        $this->entryPath = 'stream';
        return $this;
    }
}
