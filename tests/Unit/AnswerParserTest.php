<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Services\AnswerParser;
use ClarionApp\LlmClient\ValueObjects\ParsedAnswer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AnswerParser is deliberately strict: a reply is a selection only when it
 * consists solely of valid choice numbers (exactly one on a single-select
 * question; one or more, separated by commas and/or spaces, on a
 * multi-select question). Everything else is free text and is never
 * rejected. The raw reply always travels with the result, unmodified.
 */
class AnswerParserTest extends TestCase
{
    private function question(array $choices = ['Backend', 'Frontend', 'Docs'], bool $allowMultiple = false): AgentQuestion
    {
        return (new AgentQuestion())->forceFill([
            'question' => 'Which part should I change first?',
            'choices' => $choices,
            'allow_multiple' => $allowMultiple,
        ]);
    }

    private function parse(string $raw, ?AgentQuestion $question = null): ParsedAnswer
    {
        return (new AnswerParser())->parse($raw, $question ?? $this->question());
    }

    private function assertFreeText(ParsedAnswer $answer, string $raw): void
    {
        $this->assertFalse($answer->isSelection, "'{$raw}' must be free text");
        $this->assertSame([], $answer->selected);
        $this->assertSame($raw, $answer->rawReply);
        $this->assertFalse($answer->isBlank());
    }

    #[Test]
    public function a_single_valid_number_selects_that_choice(): void
    {
        $answer = $this->parse('2');

        $this->assertTrue($answer->isSelection);
        $this->assertSame([['number' => 2, 'text' => 'Frontend']], $answer->selected);
        $this->assertSame('2', $answer->rawReply);
        $this->assertFalse($answer->isBlank());
    }

    #[Test]
    public function surrounding_whitespace_does_not_prevent_a_selection_and_is_kept_in_the_raw_reply(): void
    {
        $answer = $this->parse(" 3\n");

        $this->assertTrue($answer->isSelection);
        $this->assertSame([['number' => 3, 'text' => 'Docs']], $answer->selected);
        $this->assertSame(" 3\n", $answer->rawReply, 'the parser never trims or rewrites the raw reply');
    }

    #[Test]
    public function multi_select_comma_list_selects_each_choice_ascending(): void
    {
        $answer = $this->parse('1, 3', $this->question(allowMultiple: true));

        $this->assertTrue($answer->isSelection);
        $this->assertSame(
            [['number' => 1, 'text' => 'Backend'], ['number' => 3, 'text' => 'Docs']],
            $answer->selected,
        );
        $this->assertSame('1, 3', $answer->rawReply);
    }

    #[Test]
    public function multi_select_collapses_duplicates_and_sorts_ascending(): void
    {
        $answer = $this->parse('3 1 3', $this->question(allowMultiple: true));

        $this->assertTrue($answer->isSelection);
        $this->assertSame(
            [['number' => 1, 'text' => 'Backend'], ['number' => 3, 'text' => 'Docs']],
            $answer->selected,
        );
        $this->assertSame('3 1 3', $answer->rawReply);
    }

    #[Test]
    public function multi_select_accepts_a_single_number(): void
    {
        $answer = $this->parse('2', $this->question(allowMultiple: true));

        $this->assertTrue($answer->isSelection);
        $this->assertSame([['number' => 2, 'text' => 'Frontend']], $answer->selected);
    }

    #[Test]
    public function multi_select_with_any_out_of_range_number_is_free_text(): void
    {
        $this->assertFreeText($this->parse('1, 7', $this->question(allowMultiple: true)), '1, 7');
    }

    #[Test]
    public function multiple_numbers_on_single_select_is_free_text(): void
    {
        $this->assertFreeText($this->parse('1, 2'), '1, 2');
        $this->assertFreeText($this->parse('1 2'), '1 2');
    }

    public static function freeTextReplies(): array
    {
        return [
            'out of range high' => ['7'],
            'zero' => ['0'],
            'mixed with text' => ['2, but only for the backend'],
            'trailing period' => ['2.'],
            'hash prefix' => ['#2'],
            'negative' => ['-1'],
            'a word' => ['Frontend'],
            'a sentence' => ['none of these — use a fourth approach'],
        ];
    }

    #[Test]
    #[DataProvider('freeTextReplies')]
    public function non_selection_replies_are_free_text(string $raw): void
    {
        $this->assertFreeText($this->parse($raw), $raw);
    }

    #[Test]
    public function a_number_on_a_question_without_choices_is_free_text(): void
    {
        $this->assertFreeText($this->parse('2', $this->question(choices: [])), '2');
    }

    public static function blankReplies(): array
    {
        return [
            'empty' => [''],
            'spaces' => ['   '],
            'newlines and tabs' => ["\n\t \n"],
        ];
    }

    #[Test]
    #[DataProvider('blankReplies')]
    public function blank_replies_are_blank(string $raw): void
    {
        $answer = $this->parse($raw);

        $this->assertTrue($answer->isBlank());
        $this->assertFalse($answer->isSelection);
        $this->assertSame([], $answer->selected);
        $this->assertSame($raw, $answer->rawReply);
    }
    // ---------------------------------------------------------------
    // parseAgainst(): the same rules, before a question row exists
    // ---------------------------------------------------------------

    /** @return array<string, array{0: string, 1: list<string>, 2: bool}> */
    public static function equivalenceCases(): array
    {
        $three = ['Backend', 'Frontend', 'Docs'];
        $replies = [
            'single number' => '2',
            'padded number' => " 3\n",
            'comma list' => '1, 3',
            'space list' => '3 1 3',
            'out of range' => '7',
            'zero' => '0',
            'mixed list' => '1, 7',
            'trailing period' => '2.',
            'hash prefix' => '#2',
            'negative' => '-1',
            'a word' => 'Frontend',
            'a sentence' => '2, but only for the backend',
            'empty' => '',
            'blank' => "  \n\t ",
        ];

        $cases = [];
        foreach ($replies as $name => $raw) {
            $cases["{$name}, single-select"] = [$raw, $three, false];
            $cases["{$name}, multi-select"] = [$raw, $three, true];
            $cases["{$name}, no choices"] = [$raw, [], false];
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('equivalenceCases')]
    public function parse_against_reads_a_reply_exactly_as_parse_does_for_the_same_question(string $raw, array $choices, bool $allowMultiple): void
    {
        $parser = new AnswerParser();

        $this->assertEquals(
            $parser->parse($raw, $this->question($choices, $allowMultiple)),
            $parser->parseAgainst($raw, $choices, $allowMultiple),
        );
    }

    #[Test]
    public function parse_against_selects_by_the_choices_it_is_given(): void
    {
        $answer = (new AnswerParser())->parseAgainst('3, 1', ['PHPUnit', 'Pest', 'Codeception'], true);

        $this->assertEquals(
            new ParsedAnswer('3, 1', true, [
                ['number' => 1, 'text' => 'PHPUnit'],
                ['number' => 3, 'text' => 'Codeception'],
            ]),
            $answer,
        );
    }

    #[Test]
    public function parse_delegates_to_parse_against(): void
    {
        $sentinel = new ParsedAnswer('delegated', false);
        $parser = new class($sentinel) extends AnswerParser {
            /** @var list<array{0: string, 1: array, 2: bool}> */
            public array $calls = [];

            public function __construct(private ParsedAnswer $sentinel)
            {
            }

            public function parseAgainst(string $raw, array $choices, bool $allowMultiple): ParsedAnswer
            {
                $this->calls[] = [$raw, $choices, $allowMultiple];

                return $this->sentinel;
            }
        };

        $result = $parser->parse('2', $this->question(['Backend', 'Frontend'], true));

        $this->assertSame($sentinel, $result, 'parse() returns what parseAgainst() returns: one copy of the rules');
        $this->assertSame([['2', ['Backend', 'Frontend'], true]], $parser->calls);
    }
}
