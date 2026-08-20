<?php

namespace Tests\RealSpecKit\Support;

use InvalidArgumentException;
use RuntimeException;

/**
 * Bidirectional declare/observe/reconcile ledger for per-(aiTarget,
 * commandName) command-pack outcomes (data-model.md §3,
 * contracts/speckit-outcome-ledger.md), mirroring
 * tests/Integration/Harness/DegradationLedger.php's existing shape
 * (research.md D8) applied to a new domain.
 *
 * Every journey test declares, before exercising anything, the outcome it
 * expects per pair (self-derived from the real fixture's own scan --
 * research.md D9, never a hardcoded snapshot), then observes what actually
 * happened. reconcile() fails loudly in both directions: an observation
 * with no matching declaration means the test observed something it never
 * asserted on; a declaration with no matching observation means the test
 * is no longer exercising what it claims to.
 */
final class SpecKitOutcomeLedger
{
    /** @var array<string, array{aiTarget: string, commandName: string, outcome: SpecKitCommandOutcome, detail: ?string}> */
    private array $declared = [];

    /** @var array<string, SpecKitLedgerEntry> */
    private array $observed = [];

    public function expectInvoked(string $aiTarget, string $commandName): self
    {
        return $this->declare($aiTarget, $commandName, SpecKitCommandOutcome::Invoked, null);
    }

    public function expectDiscoveredOnly(string $aiTarget, string $commandName): self
    {
        return $this->declare($aiTarget, $commandName, SpecKitCommandOutcome::DiscoveredOnly, null);
    }

    public function expectGap(string $aiTarget, string $commandName, string $shapePattern): self
    {
        return $this->declare($aiTarget, $commandName, SpecKitCommandOutcome::NotDiscovered, $shapePattern);
    }

    public function observeInvoked(string $aiTarget, string $commandName, ?string $failureDetail = null): self
    {
        return $this->observe($aiTarget, $commandName, SpecKitCommandOutcome::Invoked, $failureDetail);
    }

    public function observeDiscoveredOnly(string $aiTarget, string $commandName): self
    {
        return $this->observe($aiTarget, $commandName, SpecKitCommandOutcome::DiscoveredOnly, null);
    }

    /**
     * FR-009's "specific, named gap" requirement is enforced here, at the
     * call site -- an empty/whitespace-only $shapeDetail throws
     * immediately, never deferred to reconcile().
     */
    public function observeGap(string $aiTarget, string $commandName, string $shapeDetail): self
    {
        if (trim($shapeDetail) === '') {
            throw new InvalidArgumentException(
                "SpecKitOutcomeLedger::observeGap() requires a non-empty \$shapeDetail naming the "
                . "specific gap for ({$aiTarget}, {$commandName}) -- an empty or whitespace-only "
                . "detail would silently violate FR-009's 'specific, named gap' requirement."
            );
        }

        return $this->observe($aiTarget, $commandName, SpecKitCommandOutcome::NotDiscovered, $shapeDetail);
    }

    /**
     * Bidirectional: throws (fails the test) on any undeclared observation
     * OR declared-but-unobserved entry, mirroring
     * DegradationLedger::reconcile().
     */
    public function reconcile(): void
    {
        $errors = [];

        foreach ($this->observed as $key => $entry) {
            if (!isset($this->declared[$key])) {
                $errors[] = $this->formatUndeclaredError($entry);
            }
        }

        foreach ($this->declared as $key => $declaration) {
            if (!isset($this->observed[$key])) {
                $errors[] = $this->formatUnfulfilledError($declaration);
            }
        }

        if ($errors !== []) {
            throw new RuntimeException(implode("\n\n", $errors));
        }
    }

    /**
     * Human-readable table, one row per (aiTarget, commandName), grouped by
     * aiTarget then commandName -- callable at any point, even before
     * reconcile(). Rendered from keys sorted deterministically (not
     * insertion order) so two independently-built ledgers given the same
     * declare/observe call sequence always produce byte-identical output,
     * regardless of any incidental ordering difference between the two
     * builds.
     */
    public function describe(): string
    {
        $keys = array_unique(array_merge(array_keys($this->declared), array_keys($this->observed)));

        $byTarget = [];
        foreach ($keys as $key) {
            [$aiTarget, $commandName] = explode('::', $key, 2);
            $byTarget[$aiTarget][$commandName] = $key;
        }

        ksort($byTarget);

        $lines = [];
        foreach ($byTarget as $aiTarget => $commands) {
            ksort($commands);
            $lines[] = "[{$aiTarget}]";

            foreach ($commands as $commandName => $key) {
                $declaration = $this->declared[$key] ?? null;
                $entry = $this->observed[$key] ?? null;

                $declaredText = $declaration !== null
                    ? 'expected '.$declaration['outcome']->name.($declaration['detail'] !== null ? " ({$declaration['detail']})" : '')
                    : 'expected: (none declared)';

                $observedText = $entry !== null
                    ? 'observed '.$entry->outcome->name.($entry->detail !== null ? " ({$entry->detail})" : '')
                    : 'observed: (none observed)';

                $lines[] = "  {$commandName}: {$declaredText}; {$observedText}";
            }
        }

        return implode("\n", $lines);
    }

    private function declare(string $aiTarget, string $commandName, SpecKitCommandOutcome $outcome, ?string $detail): self
    {
        $this->declared[$this->key($aiTarget, $commandName)] = [
            'aiTarget' => $aiTarget,
            'commandName' => $commandName,
            'outcome' => $outcome,
            'detail' => $detail,
        ];

        return $this;
    }

    private function observe(string $aiTarget, string $commandName, SpecKitCommandOutcome $outcome, ?string $detail): self
    {
        $key = $this->key($aiTarget, $commandName);

        if (isset($this->observed[$key])) {
            throw new RuntimeException(
                "SpecKitOutcomeLedger: ({$aiTarget}, {$commandName}) was already observed as "
                . "{$this->observed[$key]->outcome->name} -- observe*() is write-once per "
                . '(aiTarget, commandName) pair.'
            );
        }

        $this->observed[$key] = new SpecKitLedgerEntry($aiTarget, $commandName, $outcome, $detail);

        return $this;
    }

    private function key(string $aiTarget, string $commandName): string
    {
        return "{$aiTarget}::{$commandName}";
    }

    private function formatUndeclaredError(SpecKitLedgerEntry $entry): string
    {
        $detail = $entry->detail !== null ? " ('{$entry->detail}')" : '';

        return sprintf(
            "Undeclared observation in SpecKitOutcomeLedger:\n\n"
            . "  (%s, %s) was observed as %s%s but no matching declaration exists.\n\n"
            . "  This means the test observed an outcome it never asserted on. Either the real\n"
            . "  CLI's command set changed (add a matching expect*() call for this pair) or a\n"
            . '  real regression occurred.',
            $entry->aiTarget,
            $entry->commandName,
            $entry->outcome->name,
            $detail
        );
    }

    /**
     * @param  array{aiTarget: string, commandName: string, outcome: SpecKitCommandOutcome, detail: ?string}  $declaration
     */
    private function formatUnfulfilledError(array $declaration): string
    {
        return sprintf(
            "Unfulfilled declaration in SpecKitOutcomeLedger:\n\n"
            . "  (%s, %s) was declared %s but no observation was ever recorded.\n\n"
            . "  This means the test declared an expectation it never checked for. The test is\n"
            . '  not exercising what it claims to.',
            $declaration['aiTarget'],
            $declaration['commandName'],
            $declaration['outcome']->name
        );
    }
}
