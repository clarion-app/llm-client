<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Model;

class ContextManagementRecord extends Model
{
    protected $table = 'context_management_records';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'conversation_id',
        'user_id',
        'attempt_group_id',
        'mechanism',
        'history_budget',
        'context_capacity',
        'tokens_before',
        'tokens_after',
        'tokens_saved',
        'model',
        'provider_type',
        'error',
        'created_at',
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

    public function scopeOrderByCreatedAtDesc($query)
    {
        return $query->orderByDesc('created_at');
    }

    /**
     * Filter records with high utilization (tokens_before / context_capacity > threshold).
     *
     * @param float $threshold Utilization threshold (0.0-1.0), default 0.8 (80%).
     */
    public function scopeWithHighUtilization($query, float $threshold = 0.8)
    {
        return $query->where('context_capacity', '>', 0)
            ->whereRaw('tokens_before > context_capacity * ?', [$threshold]);
    }
}
