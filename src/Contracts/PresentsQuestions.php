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
     * Show that a question is resolved wherever this channel presented it, and stop offering its options.
     * Best effort: MUST NOT throw; failure MUST NOT be reported back (the resolution already stands).
     * Called at most once per question, after the resolving transaction commits.
     */
    public function presentResolution(Conversation $conversation, QuestionOutcomeView $outcome): void;
}
