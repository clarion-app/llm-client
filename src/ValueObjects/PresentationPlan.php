<?php

namespace ClarionApp\LlmClient\ValueObjects;

/**
 * How a question will be presented on its channel, decided once for the
 * whole question. A rich plan carries the presentation and no fallback
 * reason; a plain-text plan carries the reason and no presentation.
 */
final readonly class PresentationPlan
{
    private function __construct(
        public PresentationMode $mode,
        public ?PresentationFallbackReason $fallbackReason,
        public ?QuestionPresentation $presentation,
    ) {
    }

    public static function rich(QuestionPresentation $presentation): self
    {
        return new self(PresentationMode::Rich, null, $presentation);
    }

    public static function plainText(PresentationFallbackReason $reason): self
    {
        return new self(PresentationMode::PlainText, $reason, null);
    }
}
