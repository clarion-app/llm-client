<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Contracts\ConversationChannelDelivery;
use ClarionApp\LlmClient\Contracts\PresentsQuestions;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\ValueObjects\PresentationFallbackReason;
use ClarionApp\LlmClient\ValueObjects\PresentationPlan;
use ClarionApp\LlmClient\ValueObjects\QuestionOption;
use ClarionApp\LlmClient\ValueObjects\QuestionPresentation;

/**
 * Decides, once for the whole question, whether its channel presents the
 * choices as controls or receives the plain-text question — and why.
 *
 * Plain text, in this order of precedence: the channel has no adapter, its
 * adapter cannot present questions, or it declares it cannot show options
 * (channel_lacks_options); the question has more choices than the channel
 * can show (too_many_options). The whole question degrades; a subset of
 * the choices is never presented. Otherwise every choice becomes an option,
 * in order, with its full text and a label shortened to the channel's
 * limit. Pure: reads nothing but its arguments.
 */
class QuestionPresentationPlanner
{
    public function __construct(private readonly QuestionRenderer $renderer)
    {
    }

    public function plan(AgentQuestion $question, ?ConversationChannelDelivery $adapter, ?string $preamble): PresentationPlan
    {
        if (!$adapter instanceof PresentsQuestions) {
            return PresentationPlan::plainText(PresentationFallbackReason::ChannelLacksOptions);
        }

        $capabilities = $adapter->questionCapabilities();

        if (!$capabilities->supportsOptions) {
            return PresentationPlan::plainText(PresentationFallbackReason::ChannelLacksOptions);
        }

        $choices = $question->choiceList();

        if ($capabilities->maxOptions !== null && count($choices) > $capabilities->maxOptions) {
            return PresentationPlan::plainText(PresentationFallbackReason::TooManyOptions);
        }

        $options = [];
        foreach ($choices as $index => $text) {
            $number = $index + 1;
            $options[] = new QuestionOption(
                $number,
                $text,
                $this->renderer->optionLabel($number, $text, $capabilities->maxOptionLabelChars),
            );
        }

        return PresentationPlan::rich(new QuestionPresentation(
            questionId: (string) $question->id,
            question: (string) $question->question,
            preamble: $preamble,
            options: $options,
            allowMultiple: (bool) $question->allow_multiple,
            bodyText: $this->renderer->renderWithOptions($question, $preamble),
            plainText: $this->renderer->render($question, $preamble),
        ));
    }
}
