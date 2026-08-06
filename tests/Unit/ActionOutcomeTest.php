<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use ClarionApp\LlmClient\ValueObjects\ActionOutcome;
use Tests\TestCase;

class ActionOutcomeTest extends TestCase
{
    /** @test */
    public function has_in_progress_case(): void
    {
        $this->assertEquals('in_progress', ActionOutcome::InProgress->value);
    }

    /** @test */
    public function has_awaiting_confirmation_case(): void
    {
        $this->assertEquals('awaiting_confirmation', ActionOutcome::AwaitingConfirmation->value);
    }

    /** @test */
    public function has_success_case(): void
    {
        $this->assertEquals('success', ActionOutcome::Success->value);
    }

    /** @test */
    public function has_failure_case(): void
    {
        $this->assertEquals('failure', ActionOutcome::Failure->value);
    }

    /** @test */
    public function has_unfinished_case(): void
    {
        $this->assertEquals('unfinished', ActionOutcome::Unfinished->value);
    }

    /** @test */
    public function has_exactly_five_cases(): void
    {
        $this->assertCount(5, ActionOutcome::cases());
    }

    // isTerminal()

    /** @test */
    public function is_terminal_true_for_success(): void
    {
        $this->assertTrue(ActionOutcome::Success->isTerminal());
    }

    /** @test */
    public function is_terminal_true_for_failure(): void
    {
        $this->assertTrue(ActionOutcome::Failure->isTerminal());
    }

    /** @test */
    public function is_terminal_true_for_unfinished(): void
    {
        $this->assertTrue(ActionOutcome::Unfinished->isTerminal());
    }

    /** @test */
    public function is_terminal_false_for_in_progress(): void
    {
        $this->assertFalse(ActionOutcome::InProgress->isTerminal());
    }

    /** @test */
    public function is_terminal_false_for_awaiting_confirmation(): void
    {
        // AwaitingConfirmation is suspended, not terminal — it may still
        // transition to Success or Failure on resume.
        $this->assertFalse(ActionOutcome::AwaitingConfirmation->isTerminal());
    }

    // requiresReason()

    /** @test */
    public function requires_reason_true_only_for_failure(): void
    {
        $this->assertTrue(ActionOutcome::Failure->requiresReason());
    }

    /** @test */
    public function requires_reason_false_for_success(): void
    {
        $this->assertFalse(ActionOutcome::Success->requiresReason());
    }

    /** @test */
    public function requires_reason_false_for_in_progress(): void
    {
        $this->assertFalse(ActionOutcome::InProgress->requiresReason());
    }

    /** @test */
    public function requires_reason_false_for_awaiting_confirmation(): void
    {
        $this->assertFalse(ActionOutcome::AwaitingConfirmation->requiresReason());
    }

    /** @test */
    public function requires_reason_false_for_unfinished(): void
    {
        $this->assertFalse(ActionOutcome::Unfinished->requiresReason());
    }

    // isOpen()

    /** @test */
    public function is_open_true_for_in_progress(): void
    {
        $this->assertTrue(ActionOutcome::InProgress->isOpen());
    }

    /** @test */
    public function is_open_true_for_awaiting_confirmation(): void
    {
        $this->assertTrue(ActionOutcome::AwaitingConfirmation->isOpen());
    }

    /** @test */
    public function is_open_false_for_terminal_outcomes(): void
    {
        $this->assertFalse(ActionOutcome::Success->isOpen());
        $this->assertFalse(ActionOutcome::Failure->isOpen());
        $this->assertFalse(ActionOutcome::Unfinished->isOpen());
    }

    // isExemptFromSweep()

    /** @test */
    public function is_exempt_from_sweep_true_only_for_awaiting_confirmation(): void
    {
        $this->assertTrue(ActionOutcome::AwaitingConfirmation->isExemptFromSweep());
    }

    /** @test */
    public function is_exempt_from_sweep_false_for_other_outcomes(): void
    {
        $this->assertFalse(ActionOutcome::InProgress->isExemptFromSweep());
        $this->assertFalse(ActionOutcome::Success->isExemptFromSweep());
        $this->assertFalse(ActionOutcome::Failure->isExemptFromSweep());
        $this->assertFalse(ActionOutcome::Unfinished->isExemptFromSweep());
    }
}
