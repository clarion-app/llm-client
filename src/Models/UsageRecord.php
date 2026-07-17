<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Model;

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
    ];

    protected $casts = [
        'input_estimated' => 'boolean',
        'output_estimated' => 'boolean',
        'co_member_tags' => 'array',
    ];

    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (!$model->id) {
                $model->id = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function scopeForConversation($query, string $conversationId)
    {
        return $query->where('conversation_id', $conversationId);
    }

    public function scopeForUser($query, string $userId)
    {
        return $query->where('user_id', $userId);
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
