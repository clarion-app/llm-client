<?php

namespace Tests\RealDatabase\Support;

/**
 * Three-way result of trying to obtain a database.
 *
 * Available   — a database was obtained; scenarios run.
 * Unavailable — no explicit details and no usable Docker; skip (or fail under strict).
 * Incapable   — reachable but missing required capabilities; always fail, never skip.
 */
class ProvisionOutcome
{
    private function __construct()
    {
    }

    public static function available(ConnectionSpec $spec): self
    {
        $outcome = new self();
        $outcome->variant = 'available';
        $outcome->spec = $spec;
        return $outcome;
    }

    public static function unavailable(string $reason): self
    {
        $outcome = new self();
        $outcome->variant = 'unavailable';
        $outcome->reason = $reason;
        return $outcome;
    }

    public static function incapable(string $reason, string $detail = ''): self
    {
        $outcome = new self();
        $outcome->variant = 'incapable';
        $outcome->reason = $reason;
        $outcome->detail = $detail;
        return $outcome;
    }

    public function isAvailable(): bool
    {
        return $this->variant === 'available';
    }

    public function isUnavailable(): bool
    {
        return $this->variant === 'unavailable';
    }

    public function isIncapable(): bool
    {
        return $this->variant === 'incapable';
    }

    public function spec(): ?ConnectionSpec
    {
        return $this->spec ?? null;
    }

    public function reason(): string
    {
        return $this->reason ?? '';
    }

    public function detail(): string
    {
        return $this->detail ?? '';
    }

    /**
     * Human-readable diagnostic for skip/fail messages.
     */
    public function diagnostic(): string
    {
        return match ($this->variant) {
            'available'   => 'Database available: ' . $this->spec->host . ':' . $this->spec->port . '/' . $this->spec->database,
            'unavailable' => 'Database unavailable: ' . $this->reason,
            'incapable'   => 'Database incapable: ' . $this->reason . ($this->detail ? ' — ' . $this->detail : ''),
        };
    }

    private string $variant = 'unavailable';
    private ?ConnectionSpec $spec = null;
    private string $reason = '';
    private string $detail = '';
}
