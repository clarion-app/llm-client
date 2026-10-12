<?php

namespace ClarionApp\LlmClient\Services;

use Carbon\CarbonInterface;
use ClarionApp\LlmClient\Models\AgentQuestion;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use ClarionApp\LlmClient\ValueObjects\UnansweredPolicy;

/**
 * The only renderer of a question and of its follow-up notices. The output
 * is plain text and channel-agnostic: every channel receives exactly these
 * strings, so a question reads the same on the web screen and in a chat.
 */
class QuestionRenderer
{
    public const SINGLE_SELECT_INSTRUCTION = 'Reply with the number of your choice, or answer in your own words.';

    public const MULTI_SELECT_INSTRUCTION = 'Reply with one or more numbers separated by commas or spaces, or answer in your own words.';

    public const FREE_TEXT_INSTRUCTION = 'Reply with your answer.';

    /** Instruction under a single-select question whose choices are shown as controls. */
    public const SINGLE_SELECT_OPTIONS_INSTRUCTION = 'Choose an option below, or reply in your own words.';

    /** Instruction under a multi-select question whose choices are shown as controls. */
    public const MULTI_SELECT_OPTIONS_INSTRUCTION = 'Select one or more options below, then tap Submit — or reply in your own words.';

    /**
     * The plain-text question, answered by typing.
     *
     * @param  string|null  $preamble  assistant text that accompanied the
     *   question; blank is the same as none
     */
    public function render(AgentQuestion $question, ?string $preamble = null): string
    {
        return $this->compose($question, $preamble, self::SINGLE_SELECT_INSTRUCTION, self::MULTI_SELECT_INSTRUCTION);
    }

    /**
     * The question as shown alongside its choices as controls. Identical to
     * render() except for the instruction line under a list of choices, so
     * the numbered list reads the same on every channel. A question without
     * choices has no controls and renders exactly as render() does.
     */
    public function renderWithOptions(AgentQuestion $question, ?string $preamble = null): string
    {
        return $this->compose($question, $preamble, self::SINGLE_SELECT_OPTIONS_INSTRUCTION, self::MULTI_SELECT_OPTIONS_INSTRUCTION);
    }

    /**
     * The label of one choice: "{n}. {text}". With $maxChars, a longer
     * label is cut to exactly $maxChars characters ending in "…"; the
     * "{n}. " prefix that identifies the choice is always kept.
     */
    public function optionLabel(int $number, string $text, ?int $maxChars): string
    {
        $prefix = $number.'. ';
        $label = $prefix.$text;

        if ($maxChars === null || mb_strlen($label) <= $maxChars) {
            return $label;
        }

        $keep = $maxChars - 1;

        if ($keep < mb_strlen($prefix)) {
            // Too small a limit for any text: the number alone still
            // identifies the choice.
            $bare = rtrim($prefix);

            return mb_strlen($bare) <= $maxChars ? $bare : $prefix;
        }

        return mb_substr($label, 0, $keep).'…';
    }

    /**
     * One line describing how a resolved question was resolved, e.g.
     * "Answered: 2. Use the cache". A selection names every chosen choice
     * by its full text; a free-text answer is not repeated.
     */
    public function renderOutcome(AgentQuestion $question): string
    {
        $state = $question->state instanceof QuestionState ? $question->state : QuestionState::tryFrom((string) $question->state);

        $stopped = $question->stopped_at !== null;

        if ($question->isCeilingPrompt()) {
            $text = app(TaskBudgetText::class);

            // A decided or stopped ceiling prompt names this task's budget;
            // its other states read as any question's.
            if ($state === QuestionState::Answered) {
                return str_starts_with((string) $question->answer_raw, 'accept') ? $text->outcomeAccepted() : $text->outcomeContinued();
            }
            if ($state === QuestionState::Stopped) {
                return $text->alreadyStopped();
            }
        }

        return match ($state) {
            QuestionState::Answered => $this->renderAnswered($question, $stopped),
            QuestionState::Expired => 'This question expired and is no longer awaiting a reply.',
            QuestionState::ExpiredAssumed => 'Expired — no answer arrived in time; the agent continued assuming: '
                .$this->assumptionText($question),
            QuestionState::Stopped => 'Expired — work stopped. Answer to resume.',
            QuestionState::Withdrawn => $stopped
                ? 'Discarded — the conversation was ended or deleted while work was stopped.'
                : 'This question was withdrawn and is no longer awaiting a reply.',
            QuestionState::Undeliverable => 'This question could not be delivered.',
            default => throw new \LogicException("No outcome exists for a question in state {$state?->value}."),
        };
    }

    private function renderAnswered(AgentQuestion $question, bool $late): string
    {
        $selected = array_values($question->answer_selected ?? []);

        if ($selected === []) {
            return $late ? 'Answered in your own words after work stopped.' : 'Answered in your own words.';
        }

        $labels = implode(', ', array_map(
            fn (array $choice) => $this->optionLabel((int) $choice['number'], (string) $choice['text'], null),
            $selected,
        ));

        return ($late ? 'Answered after work stopped: ' : 'Answered: ').$labels;
    }

    /**
     * What the question says, in advance, about what happens if nobody
     * answers it by its deadline. Null for a question asked before
     * unanswered policies existed, which renders exactly as it always did.
     */
    public function expiryStatement(AgentQuestion $question): ?string
    {
        $policy = $question->unanswered_policy instanceof UnansweredPolicy
            ? $question->unanswered_policy
            : UnansweredPolicy::tryFrom((string) $question->unanswered_policy);

        if ($policy === null) {
            return null;
        }

        $deadline = $this->deadlineText($question);

        if ($question->isCeilingPrompt()) {
            return app(TaskBudgetText::class)->promptExpiryStatement($deadline);
        }

        if ($policy === UnansweredPolicy::ProceedOnAssumption) {
            return "If there is no answer by {$deadline}, the agent will continue assuming: ".$this->assumptionText($question);
        }

        if ($question->consequential) {
            return 'Work on this task will not continue without your answer. '
                ."If there is no answer by {$deadline}, it will pause until you reply.";
        }

        return "If there is no answer by {$deadline}, work on this task will pause until you reply.";
    }

    /**
     * The answer the question said it would assume if nobody answered it,
     * in full: the chosen choices by number and text ("1. Backend, 3. Docs"),
     * or the assumed free text in quotes. Null when it assumes nothing.
     */
    public function assumptionText(AgentQuestion $question): ?string
    {
        if ($question->assumed_answer_raw === null) {
            return null;
        }

        $selected = array_values($question->assumed_answer_selected ?? []);

        if ($selected === []) {
            return '"'.$question->assumed_answer_raw.'"';
        }

        return implode(', ', array_map(
            fn (array $choice) => $this->optionLabel((int) $choice['number'], (string) $choice['text'], null),
            $selected,
        ));
    }

    /**
     * The question's deadline, always in UTC, with the waiting period fixed
     * when it was asked: "14:00 UTC on 13 Oct 2026 (1 day after this was asked)".
     */
    public function deadlineText(AgentQuestion $question): string
    {
        $expiresAt = $question->expires_at instanceof CarbonInterface ? $question->expires_at->copy()->utc() : null;
        $when = $expiresAt === null ? 'the deadline' : $expiresAt->format('H:i').' UTC on '.$expiresAt->format('j M Y');

        return $when.' ('.self::periodWords($this->periodMinutes($question)).' after this was asked)';
    }

    /** The waiting period in whole minutes: the stored one, else the ask-to-deadline gap. */
    private function periodMinutes(AgentQuestion $question): int
    {
        if ($question->effective_wait_minutes !== null) {
            return (int) $question->effective_wait_minutes;
        }

        if ($question->asked_at === null || $question->expires_at === null) {
            return 0;
        }

        return intdiv(max(0, $question->expires_at->getTimestamp() - $question->asked_at->getTimestamp()), 60);
    }

    /** "1 minute", "2 hours", "1 day", "1 hour 30 minutes", "25 hours". */
    private static function periodWords(int $minutes): string
    {
        $unit = fn (int $count, string $name) => $count.' '.$name.($count === 1 ? '' : 's');

        if ($minutes < 60) {
            return $unit($minutes, 'minute');
        }

        if ($minutes % 1440 === 0) {
            return $unit(intdiv($minutes, 1440), 'day');
        }

        if ($minutes % 60 === 0) {
            return $unit(intdiv($minutes, 60), 'hour');
        }

        return $unit(intdiv($minutes, 60), 'hour').' '.$unit($minutes % 60, 'minute');
    }

    private function compose(AgentQuestion $question, ?string $preamble, string $singleInstruction, string $multiInstruction): string
    {
        $blocks = [];

        if ($preamble !== null && trim($preamble) !== '') {
            $blocks[] = trim($preamble);
        }

        $blocks[] = (string) $question->question;

        $choices = $question->choiceList();

        if ($choices !== []) {
            $lines = [];
            foreach ($choices as $index => $choice) {
                $lines[] = $this->optionLabel($index + 1, $choice, null);
            }
            $blocks[] = implode("\n", $lines);
            $blocks[] = $question->allow_multiple ? $multiInstruction : $singleInstruction;
        } else {
            $blocks[] = self::FREE_TEXT_INSTRUCTION;
        }

        $statement = $this->expiryStatement($question);
        if ($statement !== null) {
            $blocks[] = $statement;
        }

        return implode("\n\n", $blocks);
    }

    /**
     * The one-time notice for a reply typed after a question already
     * expired on the assumption it stated: the reply is not applied to the
     * question, and is handled as a new message instead.
     */
    public function renderLateReplyNotice(AgentQuestion $question): string
    {
        return 'Your reply arrived after the question "'.$question->question.'" had expired. '
            .'The agent had already continued assuming: '.$this->assumptionText($question).'. '
            .'Your reply is being handled as a new message.';
    }

    /** The notice shown when a question is resolved without an answer. */
    public function renderNotice(AgentQuestion $question): string
    {
        $quoted = '"'.$question->question.'"';

        return match ($question->state) {
            QuestionState::Expired => "The question {$quoted} expired without an answer. "
                .'The agent will continue without it; replies sent now are treated as new messages.',
            QuestionState::ExpiredAssumed => "No answer to {$quoted} was received by the deadline ({$this->deadlineText($question)}). "
                .'The agent is continuing on the assumption it stated: '.$this->assumptionText($question).'. '
                .'Replies sent now are treated as new messages and will not change that assumption.',
            QuestionState::Stopped => "No answer to {$quoted} was received by the deadline ({$this->deadlineText($question)}). "
                .'Work on this task has stopped. It will continue when you answer the question above.',
            QuestionState::Withdrawn => $question->stopped_at !== null
                ? "The stopped task waiting on {$quoted} was discarded because the conversation was ended or deleted. "
                    .'It no longer needs a reply.'
                : "The question {$quoted} was withdrawn because the task was stopped. "
                    .'It no longer needs a reply.',
            QuestionState::Undeliverable => "The question {$quoted} could not be delivered ("
                .($question->delivery_failure_reason ?? 'unknown reason').'). '
                .'The agent was told and will continue without an answer.',
            default => throw new \LogicException("No notice exists for a question in state {$question->state?->value}."),
        };
    }
}
