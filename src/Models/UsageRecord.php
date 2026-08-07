<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;

class UsageRecord extends Model
{
    protected $table = 'usage_records';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'conversation_id',
        'user_id',
        'attempt_group_id',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'input_estimated',
        'output_estimated',
        'model',
        'provider_type',
        'co_member_tags',
        'reused_input_tokens',
        'reused_input_estimated',
        'reused_input_adjusted',
        'agent_id',
        // Explicitly captured once at write time so it can never drift from
        // the model_prices lookup instant / cost_summaries period_date
        // bucket (data-model.md §2). $timestamps = false below means the
        // base Eloquent HasTimestamps trait never sets this automatically,
        // and the default $guarded = ['*'] silently discards any key passed
        // to create() that isn't listed here — so 'created_at' must be
        // fillable for MetricsRecorder::recordUsage()'s explicit capture to
        // actually take effect (rather than relying on the DB's own
        // useCurrent() default, which is not influenced by Carbon::setTestNow()
        // and would let the record's own timestamp drift from the price
        // lookup instant under a frozen clock).
        'created_at',
        'model_price_id',
        'reused_input_cost',
        'fresh_input_cost',
        'output_cost',
        'total_cost',
        'cost_unpriced',
        'cost_estimated',
    ];

    protected $casts = [
        'input_estimated' => 'boolean',
        'output_estimated' => 'boolean',
        'co_member_tags' => 'array',
        'reused_input_estimated' => 'boolean',
        'reused_input_adjusted' => 'boolean',
        'cost_unpriced' => 'boolean',
        'cost_estimated' => 'boolean',
        // The four cost amount columns are intentionally NOT cast — they are
        // read back as strings so no float ever re-enters the pipeline on
        // the read side either (research.md D1).
    ];

    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (!$model->id) {
                $model->id = (string) \Illuminate\Support\Str::uuid();
            }
        });

        static::creating(function ($model) {
            if ($model->run_id === null) {
                $model->run_id = Context::get('run_id');
            }
        });
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function getFreshInputTokensAttribute(): ?int
    {
        if ($this->reused_input_tokens === null) {
            return null;
        }

        return $this->input_tokens - $this->reused_input_tokens;
    }

    public function scopeForConversation($query, string $conversationId)
    {
        return $query->where('conversation_id', $conversationId);
    }

    public function scopeForUser($query, string $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForAgent($query, string $agentId)
    {
        return $query->where('agent_id', $agentId);
    }

    public function scopeUnpriced($query)
    {
        return $query->where('cost_unpriced', true);
    }

    public function scopeWithEstimateFlags($query)
    {
        return $query->where(function ($q) {
            $q->where('input_estimated', true)
              ->orWhere('output_estimated', true);
        });
    }

    public function scopeOrderByCreatedAtDesc($query)
    {
        return $query->orderByDesc('created_at');
    }
}
