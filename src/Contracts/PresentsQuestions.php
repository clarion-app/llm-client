<?php

namespace ClarionApp\LlmClient\Contracts;

use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\ValueObjects\ChannelQuestionCapabilities;
use ClarionApp\LlmClient\ValueObjects\DeliveryResult;
use ClarionApp\LlmClient\ValueObjects\QuestionOutcomeView;
use ClarionApp\LlmClient\ValueObjects\QuestionPresentation;

/**
 * Implemented, alongside ConversationChannelDelivery, by a channel adapter
 * that can present a question's choices as controls. An adapter that does
 * not implement it receives every question as plain text.
 */
interface PresentsQuestions
{
    /** What this channel can present. Read at each delivery (so configuration changes apply). */
    public function questionCapabilities(): ChannelQuestionCapabilities;

    /**
     * Present a question richly. Same rules as ConversationChannelDelivery::deliver(): MUST NOT persist
     * conversation messages, MUST NOT throw, MUST deliver the full text (all choices, full text, in order).
     * A failure here is not final: llm-client falls back to deliver($conversation, $presentation->plainText).
     */
    public function presentQuestion(Conversation $conversation, QuestionPresentation $presentation): DeliveryResult;

    /**
     * Show a question's outcome wherever this channel presented it. Called once when the question stops, if it
     * does, and once when it is finally resolved, each after the committing transaction. When
     * `$outcome->answerable` is true (the question stopped and still takes an answer) keep offering its options
     * and show `$outcome->summary`; otherwise show the summary and stop offering them.
     * Best effort: MUST NOT throw; failure MUST NOT be reported back (the outcome already stands).
     */
    public function presentResolution(Conversation $conversation, QuestionOutcomeView $outcome): void;
}
