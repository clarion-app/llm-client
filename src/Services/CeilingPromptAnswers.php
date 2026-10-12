<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Models\TaskBudget;
use ClarionApp\LlmClient\ValueObjects\AnswerMethod;
use ClarionApp\LlmClient\ValueObjects\CeilingDecision;
use ClarionApp\LlmClient\ValueObjects\IntakeResult;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswer;
use ClarionApp\LlmClient\ValueObjects\InteractiveAnswerOutcome;
use ClarionApp\LlmClient\ValueObjects\ParsedAnswer;
use ClarionApp\LlmClient\ValueObjects\QuestionState;

/**
 * Applies a reply to a task-budget ceiling prompt, typed or chosen from the
 * prompt's options. Continue and accept answer the prompt (its resumption
 * then applies them); stop parks the prompt, keeping it answerable; anything
 * else changes nothing and returns guidance. Never starts any work itself.
 */
class CeilingPromptAnswers
{
    public function __construct(
        private readonly AgentQuestionService $questions,
        private readonly CeilingDecisionParser $parser,
        private readonly TaskBudgetText $text,
        private readonly QuestionRenderer $renderer,
    ) {
    }

    public function typed(AgentQuestion $question, string $userId, string $raw): IntakeResult
    {
        [$decision, $state] = $this->decision($question, $raw);

        if ($decision->isInvalid()) {
            return IntakeResult::decisionRequired($question, (string) $decision->guidance);
        }
        if ($decision->kind === CeilingDecision::STOP) {
            if ($state === QuestionState::Stopped) {
                return IntakeResult::decisionRequired($question, $this->text->alreadyStopped());
            }
            $reply = $this->questions->stopCeilingPrompt($question, $userId, $raw);
            if ($reply === null) {
                return IntakeResult::notAnAnswer();
            }

            return IntakeResult::decisionRecorded($question->fresh() ?? $question, $reply, $this->text->stoppedByChoice());
        }

        $message = $this->answer($question, $decision, $userId, $raw, AnswerMethod::TypedReply);

        return $message === null ? IntakeResult::notAnAnswer() : IntakeResult::answered($question->fresh() ?? $question, $message);
    }

    public function interactive(AgentQuestion $question, string $userId, InteractiveAnswer $answer, AnswerMethod $method): InteractiveAnswerOutcome
    {
        $raw = $answer->toReplyText();
        [$decision, $state] = $this->decision($question, $raw);

        if ($decision->isInvalid()) {
            return InteractiveAnswerOutcome::invalidAnswer($state, (string) $decision->guidance);
        }
        if ($decision->kind === CeilingDecision::STOP) {
            if ($state === QuestionState::Stopped) {
                return InteractiveAnswerOutcome::invalidAnswer($state, $this->text->alreadyStopped());
            }
            $reply = $this->questions->stopCeilingPrompt($question, $userId, $raw);
            if ($reply === null) {
                $winner = $question->fresh() ?? $question;

                return InteractiveAnswerOutcome::notPending($winner->state, $winner);
            }

            return InteractiveAnswerOutcome::answered($question->fresh() ?? $question, $reply, 'Stop');
        }

        $message = $this->answer($question, $decision, $userId, $raw, $method);
        if ($message === null) {
            $winner = $question->fresh() ?? $question;

            return InteractiveAnswerOutcome::notPending($winner->state, $winner);
        }
        $number = $decision->kind === CeilingDecision::CONTINUE ? 1 : 2;
        $summary = $answer->selected !== null
            ? $this->renderer->optionLabel($number, $question->choiceList()[$number - 1], null)
            : $raw;

        return InteractiveAnswerOutcome::answered($question->fresh() ?? $question, $message, $summary);
    }

    /** @return array{0: CeilingDecision, 1: QuestionState} */
    private function decision(AgentQuestion $question, string $raw): array
    {
        $state = $question->state instanceof QuestionState ? $question->state : QuestionState::from((string) $question->state);
        $task = $question->task_budget_id === null ? null : TaskBudget::find($question->task_budget_id);
        if ($task === null) {
            return [$this->parser->parse($raw), $state];
        }
        $tasks = app(TaskBudgetService::class);
        $standing = $tasks->standing($task);
        $offered = $tasks->offeredAllowance($task, $standing);

        return [$this->parser->parse($raw, $standing, $offered['steps'], $offered['spend']), $state];
    }

    private function answer(AgentQuestion $question, CeilingDecision $decision, string $userId, string $raw, AnswerMethod $method): ?Message
    {
        $number = $decision->kind === CeilingDecision::CONTINUE ? 1 : 2;
        $selected = [['number' => $number, 'text' => $question->choiceList()[$number - 1] ?? '']];
        $normalized = $decision->toReply();

        return $this->questions->answer($question, new ParsedAnswer($normalized, true, $selected), $userId, $raw, $method, $normalized);
    }
}
