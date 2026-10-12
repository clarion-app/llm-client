<?php

namespace ClarionApp\LlmClient\Services;

use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\ValueObjects\AnswerMethod;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use Illuminate\Support\Collection;

/**
 * The question view the conversation screen renders a question from: what
 * was asked, its numbered choices, whether several may be chosen, its
 * current state and, once answered, the answer and how it was given.
 *
 * Built from the stored question on every request, so a screen that loads
 * the conversation always shows the question's current state. It carries
 * nothing about the run, the paused turn or delivery.
 */
class QuestionViewBuilder
{
    /**
     * @return array{id: string, question: string, preamble: ?string, choices: list<array{number: int, text: string}>, allow_multiple: bool, state: string, expires_at: ?string, answer: ?array{is_selection: bool, selected: list<array{number: int, text: string}>, raw_reply: string, method: ?string}}
     */
    public function forQuestion(AgentQuestion $question): array
    {
        $choices = [];
        foreach ($question->choiceList() as $index => $text) {
            $choices[] = ['number' => $index + 1, 'text' => $text];
        }

        $state = $question->state instanceof QuestionState ? $question->state : QuestionState::from((string) $question->state);

        return [
            'id' => (string) $question->id,
            'question' => (string) $question->question,
            'preamble' => $question->preamble,
            'choices' => $choices,
            'allow_multiple' => (bool) $question->allow_multiple,
            'state' => $state->value,
            'expires_at' => $question->expires_at?->copy()->utc()->format('Y-m-d\\TH:i:s\\Z'),
            'answer' => $state === QuestionState::Answered ? $this->answer($question) : null,
        ];
    }

    /**
     * The view for each question display message among $messages, keyed by
     * message id, read with one query however many questions there are (and
     * none when there are none). A message naming a question asked in
     * another conversation gets no view.
     *
     * @param  Collection<int, Message>  $messages
     * @return array<string, array<string, mixed>>
     */
    public function forMessages(Collection $messages): array
    {
        $questionIdByMessage = [];
        $conversationByMessage = [];
        foreach ($messages as $message) {
            $toolData = $message->tool_data;
            if (is_string($toolData)) {
                $toolData = json_decode($toolData, true);
            }
            if (!is_array($toolData) || ($toolData['kind'] ?? null) !== AgentQuestionService::KIND_QUESTION) {
                continue;
            }
            $questionId = $toolData['question_id'] ?? null;
            if (is_string($questionId) && $questionId !== '') {
                $questionIdByMessage[(string) $message->id] = $questionId;
                $conversationByMessage[(string) $message->id] = (string) $message->conversation_id;
            }
        }

        if ($questionIdByMessage === []) {
            return [];
        }

        $questions = AgentQuestion::whereIn('id', array_values(array_unique($questionIdByMessage)))->get()->keyBy('id');

        $views = [];
        foreach ($questionIdByMessage as $messageId => $questionId) {
            $question = $questions->get($questionId);
            // Only a question asked in the message's own conversation: a
            // message can never show the state or answer of one asked
            // elsewhere.
            if ($question !== null && (string) $question->conversation_id === $conversationByMessage[$messageId]) {
                $views[$messageId] = $this->forQuestion($question);
            }
        }

        return $views;
    }

    /** @return array{is_selection: bool, selected: list<array{number: int, text: string}>, raw_reply: string, method: ?string} */
    private function answer(AgentQuestion $question): array
    {
        $selected = array_values(array_map(
            fn (array $choice) => ['number' => (int) $choice['number'], 'text' => (string) $choice['text']],
            array_filter($question->answer_selected ?? [], 'is_array'),
        ));

        $method = $question->answer_method;

        return [
            'is_selection' => $selected !== [],
            'selected' => $selected,
            'raw_reply' => (string) $question->answer_raw,
            'method' => $method instanceof AnswerMethod ? $method->value : ($method === null ? null : (string) $method),
        ];
    }
}
