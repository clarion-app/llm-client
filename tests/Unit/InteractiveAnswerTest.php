<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use ClarionApp\LlmClient\ValueObjects\InteractiveAnswer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An interactive answer — a form submission or a tapped option — is either
 * a selection of choice numbers or free text, never both. It is turned into
 * the reply text a user could have typed for the same meaning, so it goes
 * through the same parser as a typed reply.
 */
class InteractiveAnswerTest extends TestCase
{
    #[Test]
    public function a_selection_becomes_its_sorted_unique_numbers_joined_by_comma_space(): void
    {
        $this->assertSame('1, 3', InteractiveAnswer::selection([3, 1, 3])->toReplyText());
        $this->assertSame('1, 2, 10', InteractiveAnswer::selection([10, 2, 1])->toReplyText());
    }

    #[Test]
    public function a_single_selection_is_just_its_number(): void
    {
        $this->assertSame('2', InteractiveAnswer::selection([2])->toReplyText());
    }

    #[Test]
    public function free_text_is_passed_through_verbatim_without_trimming(): void
    {
        $this->assertSame('  x ', InteractiveAnswer::freeText('  x ')->toReplyText());
        $this->assertSame("line one\nline two", InteractiveAnswer::freeText("line one\nline two")->toReplyText());
    }

    #[Test]
    public function a_selection_carries_its_numbers_as_given_and_no_text(): void
    {
        $answer = InteractiveAnswer::selection([3, 1, 3]);

        // Kept exactly as given, so a duplicate can still be refused as an
        // invalid answer before it is ever turned into reply text.
        $this->assertSame([3, 1, 3], $answer->selected);
        $this->assertNull($answer->text);
    }

    #[Test]
    public function free_text_carries_its_text_and_no_selection(): void
    {
        $answer = InteractiveAnswer::freeText('use a fourth approach');

        $this->assertSame('use a fourth approach', $answer->text);
        $this->assertNull($answer->selected);
    }

    #[Test]
    public function an_empty_selection_is_still_a_selection_not_text(): void
    {
        $answer = InteractiveAnswer::selection([]);

        $this->assertSame([], $answer->selected);
        $this->assertNull($answer->text);
    }

    #[Test]
    public function both_or_neither_cannot_be_constructed_because_only_the_named_constructors_are_public(): void
    {
        $class = new \ReflectionClass(InteractiveAnswer::class);
        $constructor = $class->getConstructor();

        $this->assertNotNull($constructor);
        $this->assertTrue($constructor->isPrivate(), 'only selection() and freeText() may build an answer');
        $this->assertTrue($class->isReadOnly() || $this->allPropertiesReadOnly($class), 'an answer cannot be altered after it is built');
    }

    #[Test]
    public function empty_selections_and_blank_text_are_empty(): void
    {
        $this->assertTrue(InteractiveAnswer::selection([])->isEmpty());
        $this->assertTrue(InteractiveAnswer::freeText('')->isEmpty());
        $this->assertTrue(InteractiveAnswer::freeText("  \n")->isEmpty());
        $this->assertTrue(InteractiveAnswer::freeText("\t")->isEmpty());
    }

    #[Test]
    public function non_empty_selections_and_text_are_not_empty(): void
    {
        $this->assertFalse(InteractiveAnswer::selection([2])->isEmpty());
        $this->assertFalse(InteractiveAnswer::selection([1, 3])->isEmpty());
        $this->assertFalse(InteractiveAnswer::freeText('x')->isEmpty());
        $this->assertFalse(InteractiveAnswer::freeText('  x ')->isEmpty());
        $this->assertFalse(InteractiveAnswer::freeText('0')->isEmpty(), '"0" is an answer, not blank');
    }

    private function allPropertiesReadOnly(\ReflectionClass $class): bool
    {
        foreach (['selected', 'text'] as $name) {
            if (!$class->hasProperty($name) || !$class->getProperty($name)->isReadOnly()) {
                return false;
            }
        }

        return true;
    }
}
