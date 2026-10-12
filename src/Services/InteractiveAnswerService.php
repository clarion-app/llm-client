<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Contracts\InteractiveQuestionAnswers;
use ClarionApp\LlmClient\Jobs\ResumeAfterQuestionJob;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\ValueObjects\AnswerMethod;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswer;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswerOutcome;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswerStatus;
use ClarionApp\LlmClient\ValueObjects\ParsedAnswer;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The one place an interactive answer — a web form submission or a tapped
 * option — is applied to a question.
 *
 * The answer names its question by id. Checked in order, the first that
 * applies deciding: the question exists and belongs to the user (else
 * NotFound, identical for both); it still takes an answer — pending, or
 * stopped at its deadline (else NotPending, nothing written); the answer
 * is not empty (AnswerRequired); a selection
 * fits the question's choices (InvalidAnswer). Only then is the answer
 * turned into the reply the user could have typed for the same meaning and
 * recorded through the same parser and compare-and-set as a typed reply,
 * so an interactive answer can never mean something a typed one would not.
 */
class InteractiveAnswerService implements InteractiveQuestionAnswers
{
    public function __construct(
        private readonly AgentQuestionService $questions,
        private readonly AnswerParser $parser,
        private readonly QuestionRenderer $renderer,
    ) {
    }

    /**
     * Apply $answer to the question. Never resumes the paused run; on
     * Answered the caller does (or submit() queues it).
     */
    public function record(string $questionId, string $userId, InteractiveAnswer $answer, AnswerMethod $method): InteractiveAnswerOutcome
    {
        $question = AgentQuestion::find($questionId);

        if ($question === null || (string) $question->user_id !== $userId) {
            return InteractiveAnswerOutcome::notFound();
        }

        $state = $this->stateOf($question);

        // A stopped question still takes an answer: it resumes the work.
        if (!$state->acceptsAnswers()) {
            return InteractiveAnswerOutcome::notPending($state, $question);
        }

        if ($answer->isEmpty()) {
            return InteractiveAnswerOutcome::answerRequired($state);
        }

        if (!$this->fits($answer, $question)) {
            return InteractiveAnswerOutcome::invalidAnswer($state);
        }

        $text = $answer->toReplyText();
        $parsed = $this->parser->parse($text, $question);
        $message = $this->questions->answer($question, $parsed, $userId, $text, $method);

        if ($message === null) {
            // Resolved by something else between loading and recording.
            $winner = $question->fresh() ?? $question;

            return InteractiveAnswerOutcome::notPending($this->stateOf($winner), $winner);
        }

        return InteractiveAnswerOutcome::answered($question->fresh() ?? $question, $message, $this->summary($parsed, $text));
    }

    public function submit(string $questionId, string $userId, InteractiveAnswer $answer, AnswerMethod $method): InteractiveAnswerOutcome
    {
        $outcome = $this->record($questionId, $userId, $answer, $method);

        if ($outcome->status === InteractiveAnswerStatus::Answered) {
            try {
                ResumeAfterQuestionJob::dispatch($questionId);
            } catch (\Throwable $e) {
                // The answer stands; the re-drive sweep resumes any answered
                // question that never resumed.
                Log::warning('InteractiveAnswerService: the resume of an answered question could not be queued', [
                    'question_id' => $questionId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $outcome;
    }

    public function stateFor(string $questionId, string $userId): ?QuestionState
    {
        $row = DB::table('agent_questions')->where('id', $questionId)->first(['user_id', 'state']);

        if ($row === null || (string) $row->user_id !== $userId) {
            return null;
        }

        return QuestionState::from((string) $row->state);
    }

    /**
     * Whether a selection can be an answer to this question: it has
     * choices, every number names one, no number repeats, and a
     * single-select question gets exactly one. Free text always fits.
     */
    private function fits(InteractiveAnswer $answer, AgentQuestion $question): bool
    {
        if ($answer->selected === null) {
            return true;
        }

        $count = count($question->choiceList());

        if ($count === 0) {
            return false;
        }

        if (count($answer->selected) !== count(array_unique($answer->selected))) {
            return false;
        }

        if (!$question->allow_multiple && count($answer->selected) !== 1) {
            return false;
        }

        foreach ($answer->selected as $number) {
            if (!is_int($number) || $number < 1 || $number > $count) {
                return false;
            }
        }

        return true;
    }

    /** "2. Use the cache", "1. Backend, 3. Docs", or the free text verbatim. */
    private function summary(ParsedAnswer $parsed, string $text): string
    {
        if ($parsed->selected === []) {
            return $text;
        }

        return implode(', ', array_map(
            fn (array $choice) => $this->renderer->optionLabel((int) $choice['number'], (string) $choice['text'], null),
            $parsed->selected,
        ));
    }

    private function stateOf(AgentQuestion $question): QuestionState
    {
        return $question->state instanceof QuestionState ? $question->state : QuestionState::from((string) $question->state);
    }
}
