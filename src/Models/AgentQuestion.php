<?php

namespace ClarionApp\LlmClient\Models;

use ClarionApp\LlmClient\ValueObjects\QuestionState;
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
    ];

    protected $attributes = [
        'allow_multiple' => false,
        'state' => 'pending',
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
     * The JSON content of this question's `ask_user` run action: what was
     * asked, when, and how (and whether yet) it was resolved. Written at the
     * pause with resolution "pending" and rewritten when the run resumes.
     */
    public function runRecordSnapshot(): string
    {
        $state = $this->state instanceof QuestionState ? $this->state : QuestionState::from((string) $this->state);
        $pending = $state === QuestionState::Pending;
        $resolvedAt = $pending ? null : $this->resolved_at;

        $answer = null;
        if ($state === QuestionState::Answered) {
            $answer = [
                'raw_reply' => (string) $this->answer_raw,
                'selected' => array_values($this->answer_selected ?? []),
            ];
        }

        $waitedMs = null;
        if ($resolvedAt !== null && $this->asked_at !== null) {
            $waitedMs = (int) $this->asked_at->diffInMilliseconds($resolvedAt, true);
        }

        return json_encode([
            'question_id' => $this->id,
            'question' => $this->question,
            'choices' => $this->choiceList(),
            'allow_multiple' => (bool) $this->allow_multiple,
            'asked_at' => $this->asked_at?->copy()->utc()->format('Y-m-d\\TH:i:s\\Z'),
            'expires_at' => $this->expires_at?->copy()->utc()->format('Y-m-d\\TH:i:s\\Z'),
            'resolution' => $state->value,
            'resolved_at' => $resolvedAt?->copy()->utc()->format('Y-m-d\\TH:i:s\\Z'),
            'waited_ms' => $waitedMs,
            'answer' => $answer,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
