<?php

namespace ClarionApp\LlmClient\Tests\Unit\ValueObjects;

use Carbon\CarbonImmutable;
use ClarionApp\LlmClient\Models\ReductionStep;
use ClarionApp\LlmClient\ValueObjects\DegradationDecision;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * DegradationDecision (data-model.md §2, research.md D10) is the read-only
 * result of DegradationGate::evaluate()/forRun(). This test covers its two
 * behaviors in isolation from DegradationGate itself: the `full()` named
 * constructor's "nothing applies" shape, and composeDisclosure()'s "one
 * sentence, built once, reused everywhere" discipline — never null except
 * on `full`, always naming the axis/what changed/resetsAt, levers always
 * joined in the same fixed order (substitute model, then withheld tools,
 * then history ratio) regardless of which order the constructor's named
 * arguments were supplied in, so two calls with the identical decision
 * produce byte-identical prose (mutation-checklist row 12).
 */
class DegradationDecisionTest extends TestCase
{
    #[Test]
    public function full_reports_no_reduction_and_composes_no_disclosure(): void
    {
        $decision = DegradationDecision::full();

        $this->assertSame('full', $decision->outcome);
        $this->assertNull($decision->governingStep);
        $this->assertSame([], $decision->withheldTools);
        $this->assertNull($decision->composeDisclosure());
    }

    #[Test]
    public function a_reduced_decision_naming_only_a_substitute_model_composes_the_axis_the_model_and_the_reset_time_verbatim(): void
    {
        $resetsAt = CarbonImmutable::parse('2026-08-13 00:00:00');

        $decision = new DegradationDecision(
            outcome: 'reduced',
            governingStep: $this->makeStep(['substitute_model' => 'llama3.2:3b']),
            axis: 'budget_user',
            ratio: '0.9250',
            effectiveModel: 'llama3.2:3b',
            resetsAt: $resetsAt,
        );

        $sentence = $decision->composeDisclosure();

        $this->assertNotNull($sentence);
        $this->assertStringContainsString('llama3.2:3b', $sentence);
        $this->assertStringContainsString('2026-08-13 00:00', $sentence);
        // Never recomputed — the exact CarbonImmutable given is what is
        // rendered, not a fresh now()-derived figure.
        $this->assertStringContainsString($resetsAt->format('Y-m-d H:i'), $sentence);
    }

    #[Test]
    public function a_reduced_decision_naming_only_withheld_tools_lists_the_tool_names_not_a_model(): void
    {
        $decision = new DegradationDecision(
            outcome: 'reduced',
            axis: 'conversation_work',
            ratio: '0.9000',
            withheldTools: ['memory_search', 'propose_declarative_memory'],
            resetsAt: CarbonImmutable::parse('2026-08-13 00:00:00'),
        );

        $sentence = $decision->composeDisclosure();

        $this->assertNotNull($sentence);
        $this->assertStringContainsString('memory_search', $sentence);
        $this->assertStringContainsString('propose_declarative_memory', $sentence);
        $this->assertStringNotContainsString('instead of the usual model', $sentence);
    }

    #[Test]
    public function a_reduced_decision_naming_only_a_history_budget_ratio_composes_the_reduced_history_fact(): void
    {
        $decision = new DegradationDecision(
            outcome: 'reduced',
            axis: 'rate_limit',
            ratio: '0.9500',
            historyBudgetRatio: '0.5000',
            resetsAt: CarbonImmutable::parse('2026-08-13 00:00:00'),
        );

        $sentence = $decision->composeDisclosure();

        $this->assertNotNull($sentence);
        $this->assertStringContainsString('0.5000', $sentence);
        $this->assertStringContainsString('history', $sentence);
    }

    #[Test]
    public function a_rung_with_every_lever_set_composes_all_of_them_in_a_fixed_deterministic_order_regardless_of_constructor_argument_order(): void
    {
        $resetsAt = CarbonImmutable::parse('2026-08-13 00:00:00');

        // Named arguments supplied in one order.
        $decisionA = new DegradationDecision(
            outcome: 'reduced',
            axis: 'budget_installation',
            ratio: '0.9900',
            effectiveModel: 'llama3.2:3b',
            withheldTools: ['memory_search'],
            historyBudgetRatio: '0.5000',
            resetsAt: $resetsAt,
        );

        // The identical decision, named arguments supplied in a different
        // order — construction order must never affect the composed prose.
        $decisionB = new DegradationDecision(
            historyBudgetRatio: '0.5000',
            withheldTools: ['memory_search'],
            resetsAt: $resetsAt,
            ratio: '0.9900',
            effectiveModel: 'llama3.2:3b',
            axis: 'budget_installation',
            outcome: 'reduced',
        );

        $sentenceA = $decisionA->composeDisclosure();
        $sentenceB = $decisionB->composeDisclosure();

        $this->assertSame($sentenceA, $sentenceB);

        // Fixed order: substitute model, then withheld tools, then history
        // ratio.
        $modelPos = strpos($sentenceA, 'llama3.2:3b');
        $toolsPos = strpos($sentenceA, 'memory_search');
        $historyPos = strpos($sentenceA, '0.5000');

        $this->assertNotFalse($modelPos);
        $this->assertNotFalse($toolsPos);
        $this->assertNotFalse($historyPos);
        $this->assertTrue($modelPos < $toolsPos);
        $this->assertTrue($toolsPos < $historyPos);

        // Calling composeDisclosure() twice on the same instance also
        // produces byte-identical prose.
        $this->assertSame($sentenceA, $decisionA->composeDisclosure());
    }

    #[Test]
    public function full_composes_no_disclosure_even_when_constructed_with_a_governing_step_by_mistake(): void
    {
        $decision = new DegradationDecision(
            outcome: 'full',
            governingStep: $this->makeStep(['substitute_model' => 'llama3.2:3b']),
            axis: 'budget_user',
            ratio: '0.9250',
            effectiveModel: 'llama3.2:3b',
        );

        $this->assertNull($decision->composeDisclosure());
    }

    #[Test]
    public function ratio_and_history_budget_ratio_are_always_plain_decimal_strings_never_floats(): void
    {
        $decision = new DegradationDecision(
            outcome: 'reduced',
            axis: 'budget_user',
            ratio: '0.92500',
            historyBudgetRatio: '0.50000',
            resetsAt: CarbonImmutable::parse('2026-08-13 00:00:00'),
        );

        $this->assertIsString($decision->ratio);
        $this->assertSame('0.92500', $decision->ratio);
        $this->assertIsString($decision->historyBudgetRatio);
        $this->assertSame('0.50000', $decision->historyBudgetRatio);

        // The exact string given is what appears in the composed sentence
        // too — never a float-rounded rendering.
        $this->assertStringContainsString('0.50000', $decision->composeDisclosure());
    }

    private function makeStep(array $overrides = []): ReductionStep
    {
        return new ReductionStep(array_merge([
            'axis' => 'budget_user',
            'threshold_ratio' => '0.9000',
            'enabled' => true,
        ], $overrides));
    }
}
