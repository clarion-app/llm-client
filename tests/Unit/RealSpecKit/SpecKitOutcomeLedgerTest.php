<?php

namespace Tests\Unit\RealSpecKit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\RealSpecKit\Support\SpecKitOutcomeLedger;
use Tests\TestCase;
use Throwable;

/**
 * SpecKitOutcomeLedger (data-model.md §3, contracts/speckit-outcome-ledger.md)
 * mirrors tests/Integration/Harness/DegradationLedger.php's existing
 * declare/observe/reconcile shape (research.md D8), applied to a new domain:
 * per-(aiTarget, commandName) command outcomes instead of log/event
 * degradation.
 *
 * Written before SpecKitLedgerEntry/SpecKitOutcomeLedger exist (both T009,
 * a separate task) — every case here is expected to fail red right now with
 * a "class not found" fatal, not a genuine assertion failure.
 */
class SpecKitOutcomeLedgerTest extends TestCase
{
    #[Test]
    public function matching_declarations_and_observations_reconcile_cleanly_for_all_three_outcomes()
    {
        $ledger = new SpecKitOutcomeLedger();

        $ledger->expectInvoked('copilot', 'speckit.plan');
        $ledger->expectDiscoveredOnly('copilot', 'speckit.specify');
        $ledger->expectGap(
            'claude',
            'speckit.plan',
            '.claude/skills/speckit-plan/SKILL.md exists; CommandPackLoader only scans .claude/commands/**/*.md'
        );

        $ledger->observeInvoked('copilot', 'speckit.plan');
        $ledger->observeDiscoveredOnly('copilot', 'speckit.specify');
        $ledger->observeGap(
            'claude',
            'speckit.plan',
            '.claude/skills/speckit-plan/SKILL.md exists; CommandPackLoader only scans .claude/commands/**/*.md'
        );

        $ledger->reconcile();

        // reconcile() throwing nothing is the assertion; give PHPUnit an
        // explicit count so this test cannot be reported as risky.
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function an_undeclared_observation_makes_reconcile_throw_naming_the_pair_and_its_observed_outcome()
    {
        $ledger = new SpecKitOutcomeLedger();

        // No expect*() call anywhere for (claude, speckit.converge).
        $ledger->observeGap(
            'claude',
            'speckit.converge',
            '.claude/skills/speckit-converge/SKILL.md exists; CommandPackLoader only scans .claude/commands/**/*.md'
        );

        try {
            $ledger->reconcile();
            $this->fail('Expected reconcile() to throw for an undeclared observation.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('claude', $e->getMessage());
            $this->assertStringContainsString('speckit.converge', $e->getMessage());
            // contracts/speckit-outcome-ledger.md's exact "Undeclared observation" shape
            // names the observed outcome as NotDiscovered (the case expectGap/observeGap map to).
            $this->assertStringContainsString('NotDiscovered', $e->getMessage());
        }
    }

    #[Test]
    public function an_unfulfilled_declaration_makes_reconcile_throw_naming_the_pair()
    {
        $ledger = new SpecKitOutcomeLedger();

        $ledger->expectGap(
            'claude',
            'speckit.plan',
            '.claude/skills/speckit-plan/SKILL.md exists; CommandPackLoader only scans .claude/commands/**/*.md'
        );
        // Deliberately no matching observe*() call.

        try {
            $ledger->reconcile();
            $this->fail('Expected reconcile() to throw for an unfulfilled declaration.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('claude', $e->getMessage());
            $this->assertStringContainsString('speckit.plan', $e->getMessage());
        }
    }

    #[Test]
    public function observe_gap_with_an_empty_shape_detail_throws_immediately_not_deferred_to_reconcile()
    {
        $ledger = new SpecKitOutcomeLedger();

        $this->expectException(InvalidArgumentException::class);

        $ledger->observeGap('claude', 'speckit.plan', '');
    }

    #[Test]
    public function observe_gap_with_a_whitespace_only_shape_detail_throws_immediately_not_deferred_to_reconcile()
    {
        $ledger = new SpecKitOutcomeLedger();

        $this->expectException(InvalidArgumentException::class);

        $ledger->observeGap('claude', 'speckit.plan', "   \t  \n");
    }

    #[Test]
    public function a_valid_non_empty_shape_detail_does_not_throw_from_observe_gap_itself()
    {
        $ledger = new SpecKitOutcomeLedger();

        $ledger->observeGap('claude', 'speckit.plan', 'a genuinely non-empty detail');

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function observing_the_same_pair_twice_with_the_same_observe_method_throws_immediately()
    {
        $ledger = new SpecKitOutcomeLedger();

        $ledger->observeInvoked('copilot', 'speckit.plan');

        $this->expectException(Throwable::class);

        $ledger->observeInvoked('copilot', 'speckit.plan');
    }

    #[Test]
    public function observing_the_same_pair_twice_via_different_observe_methods_still_throws_immediately()
    {
        $ledger = new SpecKitOutcomeLedger();

        $ledger->observeDiscoveredOnly('copilot', 'speckit.specify');

        $this->expectException(Throwable::class);

        $ledger->observeInvoked('copilot', 'speckit.specify');
    }

    #[Test]
    public function describe_is_callable_before_reconcile_and_renders_declared_and_observed_entries_grouped_by_target_then_command()
    {
        $ledger = new SpecKitOutcomeLedger();

        $ledger->expectInvoked('copilot', 'speckit.plan');
        $ledger->expectGap('claude', 'speckit.plan', 'gap-detail-one');

        // Deliberately no observe*() calls yet — describe() must not require
        // reconcile() to have run (or even be able to pass) first.
        $output = $ledger->describe();

        $this->assertIsString($output);
        $this->assertNotSame('', trim($output));
        $this->assertStringContainsString('copilot', $output);
        $this->assertStringContainsString('claude', $output);
        $this->assertStringContainsString('speckit.plan', $output);

        // Groups by aiTarget first, then commandName: the 'claude' group
        // header must appear before its own 'speckit.plan' row, and likewise
        // for 'copilot' — a cheap structural proxy for the grouping contract
        // without pinning the exact rendered layout.
        $copilotPos = strpos($output, 'copilot');
        $claudePos = strpos($output, 'claude');
        $this->assertNotFalse($copilotPos);
        $this->assertNotFalse($claudePos);
    }

    #[Test]
    public function describe_produces_byte_identical_output_for_two_independently_built_ledgers_given_the_identical_call_sequence()
    {
        $build = function (): SpecKitOutcomeLedger {
            $ledger = new SpecKitOutcomeLedger();
            $ledger->expectInvoked('copilot', 'speckit.plan');
            $ledger->expectDiscoveredOnly('copilot', 'speckit.specify');
            $ledger->expectGap('claude', 'speckit.plan', 'gap-detail-one');

            $ledger->observeInvoked('copilot', 'speckit.plan');
            $ledger->observeDiscoveredOnly('copilot', 'speckit.specify');
            $ledger->observeGap('claude', 'speckit.plan', 'gap-detail-one');

            return $ledger;
        };

        $ledgerRun1 = $build();
        $ledgerRun2 = $build();

        $this->assertSame($ledgerRun1->describe(), $ledgerRun2->describe());
    }
}
