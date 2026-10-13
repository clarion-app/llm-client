<?php

namespace Tests\Unit;

use ClarionApp\LlmClient\Services\TaskBudgetDirectiveParser;
use ClarionApp\LlmClient\Services\TaskBudgetText;
use ClarionApp\LlmClient\ValueObjects\StatedCeilings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The `/budget` directive: a message whose first line is
 * `/budget steps=<int> spend=<decimal>` (either key, any order, any case)
 * states this task's budget ceilings; the rest of the message is the
 * request. The values are ceilings — totals for the task — never additions.
 *
 * Only the first line is read: a `/budget` further down is ordinary text.
 * A directive with nothing after it either changes the current task's
 * ceilings (with pairs) or asks to see its standing (a bare `/budget`).
 * A directive that cannot be read — an unknown or repeated key, a value
 * that is not a usable ceiling, no pairs before a request — is malformed:
 * the message is left exactly as sent and nothing is applied, and the reply
 * names the accepted form.
 *
 * parse() returns array{stated: ?StatedCeilings, remaining: string,
 * directiveOnly: bool, viewOnly: bool, malformed: bool}.
 */
class TaskBudgetDirectiveParserTest extends TestCase
{
    private function parse(string $content): array
    {
        return (new TaskBudgetDirectiveParser())->parse($content);
    }

    private function assertResult(array $result, ?int $steps, ?string $spend, string $remaining, bool $directiveOnly = false, bool $viewOnly = false, bool $malformed = false): void
    {
        $this->assertSame(['stated', 'remaining', 'directiveOnly', 'viewOnly', 'malformed'], array_keys($result));
        if ($steps === null && $spend === null) {
            $this->assertNull($result['stated']);
        } else {
            $this->assertInstanceOf(StatedCeilings::class, $result['stated']);
            $this->assertSame($steps, $result['stated']->maxSteps);
            $this->assertSame($spend, $result['stated']->maxSpend);
        }
        $this->assertSame($remaining, $result['remaining']);
        $this->assertSame($directiveOnly, $result['directiveOnly'], 'directiveOnly');
        $this->assertSame($viewOnly, $result['viewOnly'], 'viewOnly');
        $this->assertSame($malformed, $result['malformed'], 'malformed');
    }

    #[Test]
    public function both_ceilings_are_stated_and_the_rest_is_the_request(): void
    {
        $this->assertResult($this->parse("/budget steps=50 spend=2.00\nDo X"), 50, '2.00', 'Do X');
    }

    #[Test]
    public function the_request_keeps_its_own_lines(): void
    {
        $this->assertResult($this->parse("/budget steps=50\nFirst line.\nSecond line."), 50, null, "First line.\nSecond line.");
        $this->assertResult($this->parse("/budget steps=50\r\nDo X"), 50, null, 'Do X');
    }

    public static function singleKeys(): array
    {
        return [
            'steps alone' => ["/budget steps=7\nDo X", 7, null],
            'spend alone' => ["/budget spend=0.50\nDo X", null, '0.50'],
            'whole spend' => ["/budget spend=3\nDo X", null, '3'],
            'spend first' => ["/budget spend=2.00 steps=50\nDo X", 50, '2.00'],
            'any case' => ["/BUDGET Steps=50 SPEND=2.00\nDo X", 50, '2.00'],
            'mixed case' => ["/Budget STEPS=12\nDo X", 12, null],
            'extra spaces' => ["/budget   steps=50    spend=2.00  \nDo X", 50, '2.00'],
            'a ceiling above any maximum is still only stated' => ["/budget steps=1000 spend=100.00\nDo X", 1000, '100.00'],
        ];
    }

    #[Test]
    #[DataProvider('singleKeys')]
    public function either_key_in_any_order_and_any_case(string $content, ?int $steps, ?string $spend): void
    {
        $this->assertResult($this->parse($content), $steps, $spend, 'Do X');
    }

    #[Test]
    public function a_directive_with_nothing_after_it_is_directive_only(): void
    {
        $this->assertResult($this->parse('/budget steps=40'), 40, null, '', directiveOnly: true);
        $this->assertResult($this->parse('/budget spend=2.00 steps=40'), 40, '2.00', '', directiveOnly: true);
        $this->assertResult($this->parse("/budget steps=40\n\n  \n"), 40, null, '', directiveOnly: true);
    }

    #[Test]
    public function a_bare_budget_is_a_request_to_see_the_standing(): void
    {
        $this->assertResult($this->parse('/budget'), null, null, '', viewOnly: true);
        $this->assertResult($this->parse('/BUDGET  '), null, null, '', viewOnly: true);
        $this->assertResult($this->parse("/budget\n"), null, null, '', viewOnly: true);
    }

    public static function ordinaryText(): array
    {
        return [
            'no directive' => ['Store three notes.'],
            'a directive on the second line' => ["Do X\n/budget steps=50"],
            'a longer word' => ['/budgets steps=50'],
            'glued to its pair' => ['/budgetsteps=50'],
            'mentioned mid-line' => ['Please use /budget steps=50 next time.'],
            'empty' => [''],
        ];
    }

    #[Test]
    #[DataProvider('ordinaryText')]
    public function anything_else_is_ordinary_text_left_as_it_is(string $content): void
    {
        $this->assertResult($this->parse($content), null, null, $content);
    }

    public static function malformed(): array
    {
        return [
            'repeated key' => ['/budget steps=5 steps=6'],
            'repeated spend' => ['/budget spend=1 spend=2'],
            'unknown key' => ['/budget minutes=5'],
            'unknown key beside a good one' => ['/budget steps=5 tokens=100'],
            'zero steps' => ['/budget steps=0'],
            'negative steps' => ['/budget steps=-3'],
            'fractional steps' => ['/budget steps=1.5'],
            'steps not a number' => ['/budget steps=abc'],
            'empty steps' => ['/budget steps='],
            'zero spend' => ['/budget spend=0'],
            'zero spend with places' => ['/budget spend=0.00'],
            'negative spend' => ['/budget spend=-1'],
            'spend not a number' => ['/budget spend=abc'],
            'spend in scientific notation' => ['/budget spend=1e3'],
            'a word, not a pair' => ['/budget please'],
            'a separator other than =' => ['/budget steps:5'],
            'a comma-joined list' => ['/budget steps=5,spend=2'],
        ];
    }

    #[Test]
    #[DataProvider('malformed')]
    public function a_directive_that_cannot_be_read_is_malformed_and_left_untouched(string $line): void
    {
        $this->assertResult($this->parse($line), null, null, $line, malformed: true);

        $content = $line."\nDo X";
        $this->assertResult($this->parse($content), null, null, $content, malformed: true);
    }

    #[Test]
    public function a_bare_budget_before_a_request_is_malformed(): void
    {
        // No ceilings stated, yet a request follows: the user meant to set
        // something, so they are shown the form rather than ignored.
        $content = "/budget\nDo X";
        $this->assertResult($this->parse($content), null, null, $content, malformed: true);
    }

    #[Test]
    public function the_reply_to_a_malformed_directive_names_the_accepted_form(): void
    {
        $this->assertStringContainsString("'/budget steps=50 spend=2.00'", (new TaskBudgetText())->directiveMalformed());
    }
}
