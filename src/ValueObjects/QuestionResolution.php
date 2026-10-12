<?php

namespace ClarionApp\LlmClient\ValueObjects;

use Carbon\Carbon;
use ClarionApp\LlmClient\Models\AgentQuestion;

/**
 * How a question was resolved, as handed to the resumed agent loop.
 * toToolResult() is the `ask_user` call's result the agent receives.
 */
final readonly class QuestionResolution
{
    public function __construct(
        public QuestionState $state,
        public ?ParsedAnswer $parsedAnswer = null,
        public ?string $reason = null,
    ) {
    }

    public function toToolResult(AgentQuestion $question): string
    {
        $base = ['outcome' => $this->state->value, 'question_id' => $question->id];

        $payload = match ($this->state) {
            QuestionState::Answered => $question->stopped_at !== null
                ? $this->answeredLate($question)
                : $base + ['answer' => $this->answer($question)],
            QuestionState::Expired => $base + [
                'message' => sprintf(
                    'The question expired without an answer after %d minutes. No answer will arrive. Decide how to proceed.',
                    self::periodMinutes($question),
                ),
            ],
            QuestionState::Undeliverable => $base + [
                'message' => sprintf(
                    'The question could not be delivered to the user: %s. The user has not seen it.',
                    $this->reason ?? $question->delivery_failure_reason ?? 'unknown reason',
                ),
            ],
            QuestionState::Withdrawn => $base + [
                'message' => 'The user stopped this task while the question was pending.',
            ],
            QuestionState::Pending => throw new \LogicException('A pending question has no resolution.'),
            QuestionState::Stopped => throw new \LogicException('A stopped question has no resolution.'),
        };

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * An answer that arrived after the question's work had stopped at its
     * deadline: when it stopped, when it was answered, and the answer.
     *
     * @return array<string, mixed>
     */
    private function answeredLate(AgentQuestion $question): array
    {
        $period = (int) ($question->effective_wait_minutes ?? self::periodMinutes($question));

        return [
            'outcome' => 'answered_late',
            'question_id' => $question->id,
            'waiting_period_minutes' => $period,
            'stopped_at' => self::utc($question->stopped_at),
            'answered_at' => self::utc($question->resolved_at),
            'answer' => $this->answer($question),
            'message' => sprintf(
                'The question expired without an answer after %d minutes and work stopped. '
                .'The user answered later; continue with this answer. Nothing was done while the work was stopped.',
                $period,
            ),
        ];
    }

    /** @return array{raw_reply: string, is_selection: bool, selected: list<array{number: int, text: string}>} */
    private function answer(AgentQuestion $question): array
    {
        return ($this->parsedAnswer ?? new ParsedAnswer((string) $question->answer_raw, false))->toArray();
    }

    private static function utc(?\DateTimeInterface $time): ?string
    {
        return $time === null ? null : Carbon::instance($time)->utc()->format('Y-m-d\\TH:i:s\\Z');
    }

    /** The question's own answerable period in whole minutes, fixed at ask time. */
    private static function periodMinutes(AgentQuestion $question): int
    {
        if ($question->asked_at === null || $question->expires_at === null) {
            return 0;
        }

        return intdiv(max(0, $question->expires_at->getTimestamp() - $question->asked_at->getTimestamp()), 60);
    }
}
