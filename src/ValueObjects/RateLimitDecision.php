<?php

namespace ClarionApp\LlmClient\ValueObjects;

use ClarionApp\LlmClient\Models\RateLimit;

/**
 * What RateLimitGate decided about one user's request, and why.
 *
 * `limit` and `reading` are both null only in the no-limit-configured case,
 * where nothing was read because nothing needed to be — evaluate() never
 * touches RateLimitCounter when RateLimitService::resolveForUser() returns
 * null.
 *
 * `reason` is built once, by composeReason(), naming the limit, the window,
 * and the retry time — and reused verbatim by the 429 body and the log
 * line, so the two can never drift into describing the same refusal
 * differently.
 *
 * There is no allow_with_warning outcome, unlike the spending-ceiling
 * decision this mirrors: a rate limit either admits a request or refuses
 * it, never degrades it.
 */
final readonly class RateLimitDecision
{
    public const ALLOW = 'allow';
    public const STOP = 'stop';

    public function __construct(
        public string $outcome,
        public ?RateLimit $limit = null,
        public ?RateLimitReading $reading = null,
        public ?string $reason = null,
    ) {
    }

    /**
     * Nothing is configured for this user at all — no user-scoped row and
     * no user_default row. Nothing was read because nothing needed to be.
     */
    public static function noLimitConfigured(): self
    {
        return new self(self::ALLOW);
    }

    public function isStop(): bool
    {
        return $this->outcome === self::STOP;
    }

    /**
     * Seconds until the window resets, floored at zero. Null only when
     * nothing was measured (the no-limit case) or the counter's own store
     * was unreadable.
     */
    public function retryAfterSeconds(): ?int
    {
        if ($this->reading === null || $this->reading->resetsAt === null) {
            return null;
        }

        $seconds = $this->reading->resetsAt->getTimestamp() - now()->getTimestamp();

        return max(0, $seconds);
    }

    /** The wire shape of the resolved limit, or null when none applies. */
    public function limitArray(): ?array
    {
        if ($this->limit === null) {
            return null;
        }

        return [
            'id' => $this->limit->id,
            'scope_type' => $this->limit->scope_type,
            'scope_id' => $this->limit->scope_id,
            'max_requests' => $this->limit->max_requests,
            'window_seconds' => $this->limit->window_seconds,
            'waived' => (bool) $this->limit->waived,
        ];
    }

    /**
     * The wire shape of the current usage, or null when nothing was
     * measured. `available: false` when the counter's own store could not
     * be read — every other field is then omitted entirely rather than
     * sent as zero or a fabricated figure.
     */
    public function usageArray(): ?array
    {
        if ($this->reading === null) {
            return null;
        }

        if (!$this->reading->available) {
            return ['available' => false];
        }

        return [
            'count' => $this->reading->count,
            'max_requests' => $this->limit?->max_requests,
            'window_seconds' => $this->reading->windowSeconds,
            'window_start' => $this->reading->windowStart?->toIso8601String(),
            'resets_at' => $this->reading->resetsAt?->toIso8601String(),
            'available' => true,
        ];
    }

    /**
     * The refusal body. `work_kind` is the caller's, because the decision
     * itself is identical for every kind of work — the kind selects only
     * the surface a refusal is delivered on, never the rule that produced
     * it.
     */
    public function toArray(BudgetWorkKind $kind): array
    {
        return [
            'code' => 'rate_limit_exceeded',
            'message' => $this->reason,
            'limit' => $this->limitArray(),
            'usage' => $this->usageArray(),
            'retry_after_seconds' => $this->retryAfterSeconds(),
            'resets_at' => $this->reading?->resetsAt?->toIso8601String(),
            'work_kind' => $kind->value,
        ];
    }

    /**
     * Compose the one sentence every surface reuses: the limit, the window,
     * and the retry time, in plain language.
     */
    public static function composeReason(RateLimit $limit, RateLimitReading $reading): string
    {
        $window = self::windowDescription((int) $limit->window_seconds);
        $resetsAt = $reading->resetsAt !== null
            ? $reading->resetsAt->format('H:i').' UTC'
            : 'the window resets';

        return sprintf(
            "You've reached your rate limit of %d requests per %s. You can try again at %s.",
            $limit->max_requests,
            $window,
            $resetsAt,
        );
    }

    private static function windowDescription(int $seconds): string
    {
        return match ($seconds) {
            60 => 'minute',
            3600 => 'hour',
            86400 => 'day',
            604800 => 'week',
            default => $seconds.' seconds',
        };
    }
}
