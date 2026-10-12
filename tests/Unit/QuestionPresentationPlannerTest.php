<?php

namespace ClarionApp\LlmClient\Tests\Unit;

use ClarionApp\LlmClient\Contracts\ConversationChannelDelivery;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Services\QuestionPresentationPlanner;
use ClarionApp\LlmClient\Services\QuestionRenderer;
use ClarionApp\LlmClient\ValueObjects\ChannelQuestionCapabilities;
use ClarionApp\LlmClient\ValueObjects\DeliveryResult;
use ClarionApp\LlmClient\ValueObjects\PresentationFallbackReason;
use ClarionApp\LlmClient\ValueObjects\PresentationMode;
use ClarionApp\LlmClient\ValueObjects\PresentationPlan;
use ClarionApp\LlmClient\ValueObjects\QuestionOption;
use ClarionApp\LlmClient\ValueObjects\QuestionPresentation;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\QuestionChannels\RecordingPresentingDelivery;
use Tests\TestCase;

/**
 * The planner decides once, for the whole question, whether its channel
 * gets the options as controls or the plain-text question — and why. A
 * channel that declares nothing, cannot show options, or cannot show this
 * many gets plain text with every choice numbered; never a subset.
 */
class QuestionPresentationPlannerTest extends TestCase
{
    private const QUESTION_ID = '6f1c2a40-0000-4000-8000-000000000002';

    private const CHOICES = ['Backend', 'Frontend', 'Docs'];

    private function question(array $choices = self::CHOICES, bool $allowMultiple = false): AgentQuestion
    {
        return (new AgentQuestion())->forceFill([
            'id' => self::QUESTION_ID,
            'question' => 'Which part should I change first?',
            'choices' => $choices,
            'allow_multiple' => $allowMultiple,
            'state' => 'pending',
        ]);
    }

    private function plan(AgentQuestion $question, ?ConversationChannelDelivery $adapter, ?string $preamble = null): PresentationPlan
    {
        return app(QuestionPresentationPlanner::class)->plan($question, $adapter, $preamble);
    }

    private function presenting(?int $maxOptions = null, ?int $maxLabelChars = null, bool $supportsOptions = true): RecordingPresentingDelivery
    {
        return new RecordingPresentingDelivery('telegram', new ChannelQuestionCapabilities(
            supportsOptions: $supportsOptions,
            maxOptions: $maxOptions,
            maxOptionLabelChars: $maxLabelChars,
        ));
    }

    private function plainOnly(): ConversationChannelDelivery
    {
        return new class implements ConversationChannelDelivery {
            public function channel(): string
            {
                return 'sms';
            }

            public function deliver(Conversation $conversation, string $text): DeliveryResult
            {
                return DeliveryResult::ok();
            }
        };
    }

    private function assertPlainText(PresentationPlan $plan, PresentationFallbackReason $reason): void
    {
        $this->assertSame(PresentationMode::PlainText, $plan->mode);
        $this->assertSame($reason, $plan->fallbackReason);
        $this->assertNull($plan->presentation, 'a plain-text plan carries no rich presentation');
    }

    private function assertRich(PresentationPlan $plan): QuestionPresentation
    {
        $this->assertSame(PresentationMode::Rich, $plan->mode);
        $this->assertNull($plan->fallbackReason, 'a rich plan has no fallback reason');
        $this->assertInstanceOf(QuestionPresentation::class, $plan->presentation);

        return $plan->presentation;
    }

    // ---------------------------------------------------------------
    // Plain text, and why
    // ---------------------------------------------------------------

    #[Test]
    public function an_adapter_that_declares_nothing_gets_plain_text(): void
    {
        $this->assertPlainText($this->plan($this->question(), $this->plainOnly()), PresentationFallbackReason::ChannelLacksOptions);
    }

    #[Test]
    public function no_adapter_at_all_is_planned_as_plain_text_for_lack_of_options(): void
    {
        $this->assertPlainText($this->plan($this->question(), null), PresentationFallbackReason::ChannelLacksOptions);
    }

    #[Test]
    public function a_presenting_adapter_that_cannot_show_options_gets_plain_text(): void
    {
        $this->assertPlainText(
            $this->plan($this->question(), $this->presenting(supportsOptions: false)),
            PresentationFallbackReason::ChannelLacksOptions,
        );
        $this->assertPlainText(
            $this->plan($this->question(), new RecordingPresentingDelivery('telegram', ChannelQuestionCapabilities::none())),
            PresentationFallbackReason::ChannelLacksOptions,
        );
    }

    #[Test]
    public function more_choices_than_the_channel_can_show_degrades_the_whole_question(): void
    {
        $this->assertPlainText(
            $this->plan($this->question(), $this->presenting(maxOptions: 2)),
            PresentationFallbackReason::TooManyOptions,
        );
    }

    #[Test]
    public function lacking_options_is_decided_before_counting_them(): void
    {
        $this->assertPlainText(
            $this->plan($this->question(), $this->presenting(maxOptions: 2, supportsOptions: false)),
            PresentationFallbackReason::ChannelLacksOptions,
        );
    }

    // ---------------------------------------------------------------
    // Rich
    // ---------------------------------------------------------------

    #[Test]
    public function exactly_as_many_choices_as_the_channel_allows_is_rich(): void
    {
        $presentation = $this->assertRich($this->plan($this->question(), $this->presenting(maxOptions: 3)));

        $this->assertCount(3, $presentation->options);
    }

    #[Test]
    public function an_unlimited_channel_is_rich(): void
    {
        $this->assertRich($this->plan($this->question(), $this->presenting()));
        $this->assertRich($this->plan($this->question(), new RecordingPresentingDelivery('telegram', ChannelQuestionCapabilities::unlimited())));
    }

    #[Test]
    public function a_question_without_choices_is_rich_with_no_options(): void
    {
        $presentation = $this->assertRich($this->plan($this->question(choices: []), $this->presenting(maxOptions: 2)));

        $this->assertSame([], $presentation->options);
    }

    #[Test]
    public function every_choice_is_an_option_in_order_with_its_full_text_and_number(): void
    {
        $choices = ['Backend', 'Frontend', 'Docs', 'Tests', 'Release notes'];
        $presentation = $this->assertRich($this->plan($this->question(choices: $choices), $this->presenting()));

        $this->assertCount(5, $presentation->options);
        foreach ($presentation->options as $index => $option) {
            $this->assertInstanceOf(QuestionOption::class, $option);
            $this->assertSame($index + 1, $option->number);
            $this->assertSame($choices[$index], $option->text);
            $this->assertSame(($index + 1).'. '.$choices[$index], $option->label);
        }
    }

    #[Test]
    public function long_labels_are_shortened_to_the_channel_limit_but_the_text_stays_whole(): void
    {
        $long = 'Rewrite the persistence layer to use event sourcing throughout';
        $presentation = $this->assertRich($this->plan(
            $this->question(choices: ['Keep it', $long]),
            $this->presenting(maxLabelChars: 20),
        ));

        [$short, $shortened] = $presentation->options;

        $this->assertSame('1. Keep it', $short->label, 'a label within the limit is untouched');
        $this->assertSame($long, $shortened->text, 'the full text is always carried');
        $this->assertSame(app(QuestionRenderer::class)->optionLabel(2, $long, 20), $shortened->label);
        $this->assertStringStartsWith('2. ', $shortened->label);
        $this->assertLessThanOrEqual(20, mb_strlen($shortened->label));
        $this->assertStringEndsWith('…', $shortened->label);
    }

    #[Test]
    public function the_presentation_carries_the_question_and_both_renderings(): void
    {
        $renderer = app(QuestionRenderer::class);
        $preamble = 'I found three areas that need work.';

        foreach ([false, true] as $allowMultiple) {
            $question = $this->question(allowMultiple: $allowMultiple);
            $presentation = $this->assertRich($this->plan($question, $this->presenting(), $preamble));

            $this->assertSame(self::QUESTION_ID, $presentation->questionId);
            $this->assertSame('Which part should I change first?', $presentation->question);
            $this->assertSame($preamble, $presentation->preamble);
            $this->assertSame($allowMultiple, $presentation->allowMultiple);
            $this->assertSame($renderer->renderWithOptions($question, $preamble), $presentation->bodyText);
            $this->assertSame($renderer->render($question, $preamble), $presentation->plainText);
        }
    }

    #[Test]
    public function without_a_preamble_none_is_passed_on(): void
    {
        $question = $this->question();
        $presentation = $this->assertRich($this->plan($question, $this->presenting(), null));

        $this->assertNull($presentation->preamble);
        $this->assertSame(app(QuestionRenderer::class)->render($question, null), $presentation->plainText);
    }
}
