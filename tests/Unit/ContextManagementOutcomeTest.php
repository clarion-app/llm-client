<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use Tests\TestCase;
use ClarionApp\LlmClient\ValueObjects\ContextManagementOutcome;
use ClarionApp\LlmClient\ValueObjects\ContextManagementStep;
use PHPUnit\Framework\Attributes\Test;

class ContextManagementOutcomeTest extends TestCase
{
    #[Test]
    public function it_creates_an_empty_outcome_with_none()
    {
        $outcome = ContextManagementOutcome::none(
            contextCapacity: 128000,
            historyBudget: 100000,
            tokensBefore: 5000,
            model: 'gpt-4o',
            providerType: 'openai',
        );

        $this->assertTrue($outcome->isNone());
        $this->assertEquals(128000, $outcome->contextCapacity);
        $this->assertEquals(100000, $outcome->historyBudget);
        $this->assertEquals(5000, $outcome->tokensBefore);
        $this->assertEquals(5000, $outcome->tokensAfter);
        $this->assertEquals('gpt-4o', $outcome->model);
        $this->assertEquals('openai', $outcome->providerType);
        $this->assertEmpty($outcome->getSteps());
    }

    #[Test]
    public function it_creates_a_none_outcome_with_null_model()
    {
        $outcome = ContextManagementOutcome::none(
            contextCapacity: 8192,
            historyBudget: 6000,
            tokensBefore: 1000,
        );

        $this->assertTrue($outcome->isNone());
        $this->assertNull($outcome->model);
        $this->assertNull($outcome->providerType);
    }

    #[Test]
    public function it_accumulates_steps_via_addStep()
    {
        $outcome = new ContextManagementOutcome(
            contextCapacity: 128000,
            historyBudget: 100000,
            tokensBefore: 110000,
            tokensAfter: 0,
            model: 'gpt-4o',
            providerType: 'openai',
        );

        $smartTrimStep = ContextManagementStep::smartTrim(110000, 95000);
        $outcome->addStep($smartTrimStep);

        $trimStep = ContextManagementStep::trim(95000, 90000);
        $outcome->addStep($trimStep);

        $this->assertFalse($outcome->isNone());
        $steps = $outcome->getSteps();
        $this->assertCount(2, $steps);
        $this->assertEquals('smart_trim', $steps[0]->mechanism);
        $this->assertEquals('trim', $steps[1]->mechanism);
    }

    #[Test]
    public function step_trim_calculates_tokens_saved()
    {
        $step = ContextManagementStep::trim(10000, 7000);

        $this->assertEquals('trim', $step->mechanism);
        $this->assertEquals(10000, $step->tokensBefore);
        $this->assertEquals(7000, $step->tokensAfter);
        $this->assertEquals(3000, $step->tokensSaved);
        $this->assertNull($step->error);
    }

    #[Test]
    public function step_smart_trim_calculates_tokens_saved()
    {
        $step = ContextManagementStep::smartTrim(10000, 6000);

        $this->assertEquals('smart_trim', $step->mechanism);
        $this->assertEquals(10000, $step->tokensBefore);
        $this->assertEquals(6000, $step->tokensAfter);
        $this->assertEquals(4000, $step->tokensSaved);
        $this->assertNull($step->error);
    }

    #[Test]
    public function step_condense_calculates_tokens_saved_from_source_chunks()
    {
        $step = ContextManagementStep::condense(5000, 1000);

        $this->assertEquals('condense', $step->mechanism);
        $this->assertEquals(5000, $step->tokensBefore);
        $this->assertEquals(1000, $step->tokensAfter);
        $this->assertEquals(4000, $step->tokensSaved);
        $this->assertNull($step->error);
    }

    #[Test]
    public function step_condense_cached_replay_has_zero_tokens_saved()
    {
        // When replayed from cache, sourceChunkTokens = 0, so tokensSaved = 0.
        $step = ContextManagementStep::condense(0, 500);

        $this->assertEquals('condense', $step->mechanism);
        $this->assertEquals(0, $step->tokensBefore);
        $this->assertEquals(500, $step->tokensAfter);
        $this->assertEquals(0, $step->tokensSaved);
    }

    #[Test]
    public function step_condense_error_records_error_message()
    {
        $step = ContextManagementStep::condenseError('Connection timeout');

        $this->assertEquals('condense', $step->mechanism);
        $this->assertEquals(0, $step->tokensBefore);
        $this->assertEquals(0, $step->tokensAfter);
        $this->assertEquals(0, $step->tokensSaved);
        $this->assertEquals('Connection timeout', $step->error);
    }

    #[Test]
    public function step_trim_error_records_error_message()
    {
        $step = ContextManagementStep::trimError('Unexpected error');

        $this->assertEquals('trim', $step->mechanism);
        $this->assertEquals('Unexpected error', $step->error);
    }

    #[Test]
    public function step_smart_trim_error_records_error_message()
    {
        $step = ContextManagementStep::smartTrimError('Scoring failed');

        $this->assertEquals('smart_trim', $step->mechanism);
        $this->assertEquals('Scoring failed', $step->error);
    }

    #[Test]
    public function outcome_with_steps_is_not_none()
    {
        $outcome = new ContextManagementOutcome(
            contextCapacity: 128000,
            historyBudget: 100000,
            tokensBefore: 110000,
            tokensAfter: 90000,
            model: 'gpt-4o',
            providerType: 'openai',
        );

        $this->assertTrue($outcome->isNone());

        $outcome->addStep(ContextManagementStep::trim(110000, 90000));
        $this->assertFalse($outcome->isNone());
    }

    #[Test]
    public function condense_tokens_saved_is_decoupled_from_before_after()
    {
        // Condensation tokens_saved = sourceChunkTokens - summaryTokens
        // This is NOT tokens_before - tokens_after on the outcome level.
        $step = ContextManagementStep::condense(8000, 2000);

        $this->assertEquals(6000, $step->tokensSaved);
        $this->assertEquals(8000, $step->tokensBefore);
        $this->assertEquals(2000, $step->tokensAfter);
    }
}
