<?php

namespace Tests\Integration\Harness;

/**
 * Degradation ledger for integration tests.
 *
 * Tracks declared expectations and observed degradation events, then reconciles
 * them bidirectionally. Used by AssembledSystemTestCase to ensure scenarios
 * neither silently degrade nor pass when expected degradations don't occur.
 */
class DegradationLedger
{
    /** @var list<string> Expected degradation patterns (stable-prefix form). */
    public array $declared = [];

    /** @var list<string> Observed structured degradation events. */
    public array $observedEvents = [];

    /** @var list<array{level:string,message:string,context:array}> Observed log entries (warning/error only). */
    public array $observedLogs = [];

    /**
     * Declare an expected degradation pattern.
     *
     * @param string $pattern Stable-prefix form like 'embedding_generation_failed:*' or exact match 'episodic_skipped_budget_exhausted'.
     */
    public function expect(string $pattern): self
    {
        $this->declared[] = $pattern;
        return $this;
    }

    /**
     * Observe a structured degradation event (from MemoryRetrievalResult::degradationEvents, etc).
     */
    public function observeEvent(string $event): self
    {
        $this->observedEvents[] = $event;
        return $this;
    }

    /**
     * Install the log spy that turns the product's log-and-continue behavior
     * into observations (contract C1). Observation only — no production catch
     * site is modified and nothing here changes what the product does.
     *
     * Two sources, in the contract's priority order:
     *  1. Structured degradation events, carried in the log context of
     *     MetricsRecorder::recordMemoryRetrieval(). Prefix-matchable, preferred.
     *  2. warning/error log text, for context-management catch sites that have
     *     no structured equivalent. Fallback only.
     */
    public function arm(): void
    {
        \Illuminate\Support\Facades\Log::listen(function ($event): void {
            $context = (array) ($event->context ?? []);

            // Source 1: structured events (preferred).
            foreach ((array) ($context['degradation_events'] ?? []) as $degradationEvent) {
                if (is_string($degradationEvent) && $degradationEvent !== '') {
                    $this->observeEvent($degradationEvent);
                }
            }

            // Source 2: warning/error text (fallback). observeLog() filters level.
            $this->observeLog((string) $event->level, (string) $event->message, $context);
        });
    }

    /**
     * Observe a log entry. Only warning/error levels are tracked.
     */
    public function observeLog(string $level, string $message, array $context = []): self
    {
        // PHP deprecation notices arrive on the warning channel but describe the
        // engine, not a degraded product path. They are a lint concern, not a
        // fault-tolerance signal, and would otherwise drown the real ones.
        if (str_contains($message, 'is deprecated')) {
            return $this;
        }

        // Only capture warning and error logs - info/debug are not degradation signals
        if (in_array(strtolower($level), ['warning', 'error', 'warn'])) {
            $this->observedLogs[] = [
                'level' => $level,
                'message' => $message,
                'context' => $context,
            ];
        }
        return $this;
    }

    /**
     * Reconcile declarations against observations.
     *
     * Bidirectional:
     * - Undeclared observation => fail
     * - Declared-but-unobserved => fail
     *
     * @param string $scenario Scenario name for error messages.
     * @param string $entryPath Entry path ('sync' or 'stream') for error messages.
     * @throws \RuntimeException If reconciliation fails.
     */
    public function reconcile(string $scenario, string $entryPath): void
    {
        $errors = [];

        // Direction 1: Check for undeclared observations
        $undeclaredEvents = $this->findUndeclaredEvents();
        foreach ($undeclaredEvents as $event) {
            $errors[] = $this->formatUndeclaredError($event, $scenario, $entryPath);
        }

        // Direction 2: Check for declared-but-unobserved patterns
        $unobservedDeclarations = $this->findUnobservedDeclarations();
        foreach ($unobservedDeclarations as $pattern) {
            $errors[] = $this->formatUnobservedError($pattern, $scenario, $entryPath);
        }

        if (!empty($errors)) {
            throw new \RuntimeException(implode("\n\n", $errors));
        }
    }

    /**
     * Find observed events that match no declaration.
     *
     * @return list<string>
     */
    private function findUndeclaredEvents(): array
    {
        $undeclared = [];

        // Check structured events first (preferred over log text)
        foreach ($this->observedEvents as $event) {
            if (!$this->isDeclared($event)) {
                $undeclared[] = $event;
            }
        }

        // Check log entries. Fallback only: a log line that merely echoes a
        // structured event is not a second degradation (contract C1).
        foreach ($this->observedLogs as $log) {
            $message = $log['message'];
            if ($this->isDeclared($message) || $this->isEchoOfStructuredEvent($message)) {
                continue;
            }
            $undeclared[] = $message;
        }

        return $undeclared;
    }

    /**
     * Find declared patterns that match no observation.
     *
     * @return list<string>
     */
    private function findUnobservedDeclarations(): array
    {
        $unobserved = [];

        foreach ($this->declared as $pattern) {
            $matched = false;

            // Check against structured events first
            foreach ($this->observedEvents as $event) {
                if ($this->patternMatches($pattern, $event)) {
                    $matched = true;
                    break;
                }
            }

            // If not matched by events, check against log messages
            if (!$matched) {
                foreach ($this->observedLogs as $log) {
                    if ($this->patternMatches($pattern, $log['message'])) {
                        $matched = true;
                        break;
                    }
                }
            }

            if (!$matched) {
                $unobserved[] = $pattern;
            }
        }

        return $unobserved;
    }

    /**
     * Check if an observation is covered by any declaration.
     */
    private function isDeclared(string $observation): bool
    {
        foreach ($this->declared as $pattern) {
            if ($this->patternMatches($pattern, $observation)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if a pattern matches an observation.
     *
     * Supports wildcard suffix (e.g., 'embedding_generation_failed:*' matches 'embedding_generation_failed:timeout').
     */
    private function patternMatches(string $pattern, string $observation): bool
    {
        // Wildcard pattern: 'prefix:*' matches 'prefix:anything'
        if (str_ends_with($pattern, ':*')) {
            $prefix = substr($pattern, 0, -2); // Remove ':*'
            return str_starts_with($observation, $prefix . ':');
        }

        // Exact match
        return $pattern === $observation;
    }

    /**
     * Whether a log message restates a degradation already captured as a
     * structured event.
     *
     * Catch sites log prose ("AutoMemoryRetriever: embedding generation
     * failed") alongside the structured event ("embedding_generation_failed:
     * ..."). Normalizing the message to the event's snake_case shape lets the
     * event absorb its own log echo, so one degradation is reported once.
     */
    private function isEchoOfStructuredEvent(string $message): bool
    {
        $normalized = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $message) ?? '');

        foreach ($this->observedEvents as $event) {
            $eventName = explode(':', $event, 2)[0];
            if ($eventName !== '' && str_contains($normalized, strtolower($eventName))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Format an error message for an undeclared observation.
     */
    private function formatUndeclaredError(string $signal, string $scenario, string $entryPath): string
    {
        // Extract component from signal if possible
        $component = $this->extractComponent($signal);

        return sprintf(
            "Undeclared degradation in [%s / %s] (scenario: %s):\n\n" .
            "  %s recorded:\n" .
            "    %s\n\n" .
            "  This scenario declared no expected degradations, so the %s path\n" .
            "  was required to complete on its primary route.\n\n" .
            "  Fix the component, or — if this degradation is correct for this scenario —\n" .
            "  declare it: \$this->ledger->expect('%s:*');",
            $component ?? 'unknown',
            $entryPath,
            $scenario,
            $component ?? 'Component',
            $signal,
            $component ?? 'component',
            explode(':', $signal, 2)[0]
        );
    }

    /**
     * Format an error message for a declared-but-unobserved pattern.
     */
    private function formatUnobservedError(string $pattern, string $scenario, string $entryPath): string
    {
        return sprintf(
            "Expected degradation did not occur in [%s / %s]:\n\n" .
            "  This scenario expected: %s\n" .
            "  But no matching degradation was observed.\n\n" .
            "  This means the scenario may no longer be testing what it claims.\n" .
            "  If the component is now working correctly, remove the expectation.",
            $scenario,
            $entryPath,
            $pattern
        );
    }

    /**
     * Extract component name from a degradation signal.
     */
    private function extractComponent(string $signal): ?string
    {
        // Map common signal prefixes to component names
        $componentMap = [
            'embedding_generation_failed' => 'AutoMemoryRetriever',
            'declarative_retrieval_failed' => 'AutoMemoryRetriever',
            'episodic_retrieval_failed' => 'AutoMemoryRetriever',
            'long_term_retrieval_failed' => 'AutoMemoryRetriever',
            'episodic_skipped_budget_exhausted' => 'AutoMemoryRetriever',
            'long_term_skipped_budget_exhausted' => 'AutoMemoryRetriever',
            'declarative_scoring_skipped_budget_exhausted' => 'AutoMemoryRetriever',
            'context_management_failed' => 'ContextWindowBudgeter',
        ];

        $prefix = explode(':', $signal, 2)[0];
        return $componentMap[$prefix] ?? null;
    }
}
