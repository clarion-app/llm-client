<?php

namespace ClarionApp\LlmClient\Models;

use ClarionApp\LlmClient\ValueObjects\AnswerMethod;
use ClarionApp\LlmClient\ValueObjects\ParsedAnswer;
use ClarionApp\LlmClient\ValueObjects\PresentationFallbackReason;
use ClarionApp\LlmClient\ValueObjects\PresentationMode;
use ClarionApp\LlmClient\ValueObjects\QuestionState;
use ClarionApp\LlmClient\ValueObjects\UnansweredPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A question an agent asked the user mid-task.
 *
 * Operational, frequently-changing state (like agent_runs), so it is
 * deliberately not replicated through the multichain bridge.
 *
 * `pending_conversation_id` equals `conversation_id` only while the
 * question is pending and is NULL otherwise; its UNIQUE index is what
 * allows at most one pending question per conversation. Every state
 * change goes through AgentQuestionService, never through this model
 * directly.
 */
class AgentQuestion extends Model
{
    protected $table = 'agent_questions';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'conversation_id',
        'user_id',
        'pending_conversation_id',
        'run_id',
        'step_id',
        'action_id',
        'paused_message_id',
        'display_message_id',
        'tool_call_id',
        'channel',
        'resume_mode',
        'question',
        'choices',
        'allow_multiple',
        'state',
        'asked_at',
        'expires_at',
        'delivered_at',
        'delivery_failure_reason',
        'resolved_at',
        'answer_raw',
        'answer_selected',
        'answered_by',
        'answer_message_id',
        'resumed_at',
        'preamble',
        'presentation',
        'presentation_fallback_reason',
        'answer_method',
        'unanswered_policy',
        'consequential',
        'assumed_answer_raw',
        'assumed_answer_selected',
        'requested_wait_minutes',
        'effective_wait_minutes',
        'stopped_at',
        'late_reply_noticed_at',
    ];

    protected $casts = [
        'state' => QuestionState::class,
        'choices' => 'array',
        'answer_selected' => 'array',
        'allow_multiple' => 'boolean',
        'asked_at' => 'datetime',
        'expires_at' => 'datetime',
        'delivered_at' => 'datetime',
        'resolved_at' => 'datetime',
        'resumed_at' => 'datetime',
        'presentation' => PresentationMode::class,
        'presentation_fallback_reason' => PresentationFallbackReason::class,
        'answer_method' => AnswerMethod::class,
        'unanswered_policy' => UnansweredPolicy::class,
        'consequential' => 'boolean',
        'assumed_answer_selected' => 'array',
        'requested_wait_minutes' => 'integer',
        'effective_wait_minutes' => 'integer',
        'stopped_at' => 'datetime',
        'late_reply_noticed_at' => 'datetime',
    ];

    protected $attributes = [
        'allow_multiple' => false,
        'state' => 'pending',
        'consequential' => false,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (!$model->id) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function pausedMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'paused_message_id');
    }

    public function displayMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'display_message_id');
    }

    /** @return list<string> */
    public function choiceList(): array
    {
        return array_values(array_map('strval', $this->choices ?? []));
    }

    /**
     * The answer the agent said it would assume if nobody answered, in the
     * shape a user's answer has; null unless the question proceeds on an
     * assumption.
     */
    public function assumedAnswer(): ?ParsedAnswer
    {
        if ($this->assumed_answer_raw === null) {
            return null;
        }

        $selected = array_values($this->assumed_answer_selected ?? []);

        return new ParsedAnswer((string) $this->assumed_answer_raw, $selected !== [], $selected);
    }

    /**
     * Whether the question's period passed under the stop policy. Stays
     * true after a late answer or a discard, which it then marks.
     */
    public function wasStopped(): bool
    {
        return $this->stopped_at !== null;
    }

    /**
     * What happened to the question, for the run record: the state, except
     * that an answer arriving after the work stopped reads `answered_late`
     * and a stopped question discarded with its conversation reads
     * `discarded`.
     */
    public function outcome(): string
    {
        $state = $this->stateValue();

        if ($this->wasStopped()) {
            if ($state === QuestionState::Answered) {
                return 'answered_late';
            }
            if ($state === QuestionState::Withdrawn) {
                return 'discarded';
            }
        }

        return $state->value;
    }

    /**
     * The JSON content of this question's `ask_user` run action: what was
     * asked, under which unanswered policy, when, how it was presented to the
     * user, and how (and whether yet) it was resolved and answered. Written
     * at the pause with outcome "pending" and no presentation, rewritten once
     * the question has been delivered, again when the work stops on it, and
     * again when the run resumes.
     *
     * The outcome, the stop and the policy come right after the id: the
     * stored content is capped, and a long question or assumption cuts the
     * end off, so these must stay readable in the cut text.
     */
    public function runRecordSnapshot(): string
    {
        $state = $this->stateValue();
        $waiting = !$state->isTerminal();
        $resolvedAt = $waiting ? null : $this->resolved_at;

        $answer = null;
        if ($state === QuestionState::Answered) {
            $answer = [
                'raw_reply' => (string) $this->answer_raw,
                'selected' => array_values($this->answer_selected ?? []),
                'method' => self::enumValue($this->answer_method),
            ];
        }

        $waitedMs = null;
        if ($resolvedAt !== null && $this->asked_at !== null) {
            $waitedMs = (int) $this->asked_at->diffInMilliseconds($resolvedAt, true);
        }

        $stoppedMs = null;
        if ($this->stopped_at !== null) {
            $stoppedMs = (int) $this->stopped_at->diffInMilliseconds($resolvedAt ?? now(), true);
        }

        return json_encode([
            'question_id' => $this->id,
            'outcome' => $this->outcome(),
            'stopped_at' => $this->stopped_at?->copy()->utc()->format('Y-m-d\\TH:i:s\\Z'),
            'stopped_ms' => $stoppedMs,
            'policy' => $this->policySnapshot(),
            'question' => $this->question,
            'choices' => $this->choiceList(),
            'allow_multiple' => (bool) $this->allow_multiple,
            'asked_at' => $this->asked_at?->copy()->utc()->format('Y-m-d\\TH:i:s\\Z'),
            'expires_at' => $this->expires_at?->copy()->utc()->format('Y-m-d\\TH:i:s\\Z'),
            'resolution' => $state->value,
            'resolved_at' => $resolvedAt?->copy()->utc()->format('Y-m-d\\TH:i:s\\Z'),
            'waited_ms' => $waitedMs,
            'answer' => $answer,
            'presentation' => [
                'mode' => self::enumValue($this->presentation),
                'fallback_reason' => self::enumValue($this->presentation_fallback_reason),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /**
     * The unanswered policy as recorded at ask time; null for a question
     * asked before policies existed. The scalars come before the (possibly
     * long) assumption so a cut record still shows them.
     *
     * @return array{if_unanswered: string, consequential: bool, requested_wait_minutes: ?int, effective_wait_minutes: ?int, assumed_answer: ?array}|null
     */
    private function policySnapshot(): ?array
    {
        $policy = self::enumValue($this->unanswered_policy);
        if ($policy === null) {
            return null;
        }

        return [
            'if_unanswered' => $policy,
            'consequential' => (bool) $this->consequential,
            'requested_wait_minutes' => $this->requested_wait_minutes === null ? null : (int) $this->requested_wait_minutes,
            'effective_wait_minutes' => $this->effective_wait_minutes === null ? null : (int) $this->effective_wait_minutes,
            'assumed_answer' => $this->assumedAnswer()?->toArray(),
        ];
    }

    private function stateValue(): QuestionState
    {
        return $this->state instanceof QuestionState ? $this->state : QuestionState::from((string) $this->state);
    }

    /** The stored string of an enum-cast column, or null when the row has none. */
    private static function enumValue(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return $value === null || $value === '' ? null : (string) $value;
    }
}
