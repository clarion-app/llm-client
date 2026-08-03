<?php

namespace Tests\RealDatabase\Support;

/**
 * Result of the isolation guard check.
 *
 * Can only permit (Isolated) or refuse — no "continue anyway" path.
 */
class IsolationVerdict
{
    private function __construct()
    {
    }

    public static function isolated(): self
    {
        $verdict = new self();
        $verdict->isolated = true;
        return $verdict;
    }

    public static function refused(string $message, array $checks = []): self
    {
        $verdict = new self();
        $verdict->isolated = false;
        $verdict->refusalMessage = $message;
        $verdict->checks = $checks;
        return $verdict;
    }

    public bool $isolated = false;
    public array $checks = [];
    public string $refusalMessage = '';
}
