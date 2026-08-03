<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\ValueObjects\RunEndState;
use PHPUnit\Framework\Attributes\Test;

class RunEndStateTest extends TestCase
{
    #[Test]
    public function it_has_all_five_expected_states()
    {
        $cases = RunEndState::cases();
        $values = array_map(fn ($c) => $c->value, $cases);

        $expected = [
            'in_progress',
            'completed',
            'failed',
            'stopped_early',
            'abandoned',
        ];

        $this->assertEquals($expected, $values);
    }

    #[Test]
    public function it_classifies_in_progress_as_non_terminal()
    {
        $this->assertFalse(RunEndState::InProgress->isTerminal());
    }

    #[Test]
    public function it_classifies_completed_as_terminal()
    {
        $this->assertTrue(RunEndState::Completed->isTerminal());
    }

    #[Test]
    public function it_classifies_failed_as_terminal()
    {
        $this->assertTrue(RunEndState::Failed->isTerminal());
    }

    #[Test]
    public function it_classifies_stopped_early_as_terminal()
    {
        $this->assertTrue(RunEndState::StoppedEarly->isTerminal());
    }

    #[Test]
    public function it_classifies_abandoned_as_terminal()
    {
        $this->assertTrue(RunEndState::Abandoned->isTerminal());
    }

    #[Test]
    public function completed_does_not_require_reason()
    {
        $this->assertFalse(RunEndState::Completed->requiresReason());
    }

    #[Test]
    public function in_progress_does_not_require_reason()
    {
        $this->assertFalse(RunEndState::InProgress->requiresReason());
    }

    #[Test]
    public function failed_requires_reason()
    {
        $this->assertTrue(RunEndState::Failed->requiresReason());
    }

    #[Test]
    public function stopped_early_requires_reason()
    {
        $this->assertTrue(RunEndState::StoppedEarly->requiresReason());
    }

    #[Test]
    public function abandoned_requires_reason()
    {
        $this->assertTrue(RunEndState::Abandoned->requiresReason());
    }

    #[Test]
    public function from_and_tryFrom_work_for_valid_value()
    {
        $state = RunEndState::from('failed');
        $this->assertSame(RunEndState::Failed, $state);

        $state = RunEndState::tryFrom('stopped_early');
        $this->assertSame(RunEndState::StoppedEarly, $state);
    }

    #[Test]
    public function tryFrom_returns_null_for_invalid_value()
    {
        $this->assertNull(RunEndState::tryFrom('unknown'));
    }

    #[Test]
    public function from_throws_for_invalid_value()
    {
        $this->expectException(\ValueError::class);
        RunEndState::from('unknown');
    }
}
