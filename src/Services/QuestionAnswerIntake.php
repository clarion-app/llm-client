<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\ValueObjects\AnswerMethod;
use ClarionApp\LlmClient\ValueObjects\IntakeResult;

/**
 * Decides whether an inbound message answers the conversation's pending
 * question, and records it as the answer if so.
 *
 * Called by the message endpoints before their usual handling. Intake only
 * records the answer; the calling endpoint resumes the paused run. A reply
 * that is not an answer — no question is pending, or the question was
 * resolved by something else a moment earlier — is left to the endpoint's
 * ordinary behavior.
 */
class QuestionAnswerIntake
{
    public function __construct(
        private readonly AgentQuestionService $questions,
        private readonly AnswerParser $parser,
    ) {
    }

    /**
     * @param  string  $rawContent  the reply as the endpoint received it
     */
    public function accept(Conversation $conversation, string $userId, string $rawContent): IntakeResult
    {
        /** @var AgentQuestion|null $question */
        $question = $conversation->pendingQuestion()->first();

        if ($question === null || (string) $question->user_id !== $userId) {
            return IntakeResult::notAnAnswer();
        }

        if (trim($rawContent) === '') {
            return IntakeResult::answerRequired($question);
        }

        if ($question->isCeilingPrompt()) {
            return app(CeilingPromptAnswers::class)->typed($question, $userId, $rawContent);
        }

        $message = $this->questions->answer(
            $question,
            $this->parser->parse($rawContent, $question),
            $userId,
            $rawContent,
            AnswerMethod::TypedReply,
        );

        if ($message === null) {
            return IntakeResult::notAnAnswer();
        }

        return IntakeResult::answered($question->fresh() ?? $question, $message);
    }
}
