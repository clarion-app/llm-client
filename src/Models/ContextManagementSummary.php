<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Model;

class ContextManagementSummary extends Model
{
    protected $table = 'context_management_summaries';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'entity_type',
        'entity_id',
        'trim_activations',
        'smart_trim_activations',
        'condense_activations',
        'total_tokens_saved',
        'total_requests',
        'updated_at',
    ];

    // Only updated_at is tracked (no created_at column in migration)
    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (!$model->id) {
                $model->id = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    public const ENTITY_CONVERSATION = 'conversation';
    public const ENTITY_USER = 'user';

    public function scopeForConversation($query)
    {
        return $query->where('entity_type', self::ENTITY_CONVERSATION);
    }

    public function scopeForUser($query)
    {
        return $query->where('entity_type', self::ENTITY_USER);
    }

    /**
     * Get the context management summary for a conversation.
     */
    public static function getConversationTotals(string $conversationId): ?self
    {
        return static::where('entity_type', self::ENTITY_CONVERSATION)
            ->where('entity_id', $conversationId)
            ->first();
    }

    /**
     * Get the context management summary for a user.
     */
    public static function getUserTotals(string $userId): ?self
    {
        return static::where('entity_type', self::ENTITY_USER)
            ->where('entity_id', $userId)
            ->first();
    }

    /**
     * Scope: conversations with high trim activation counts.
     *
     * @param int $threshold Minimum total trim activations.
     */
    public function scopeHighTrimActivations($query, int $threshold = 10)
    {
        return $query->where('entity_type', self::ENTITY_CONVERSATION)
            ->where(function ($q) use ($threshold) {
                $q->where('trim_activations', '>', $threshold)
                    ->orWhere('smart_trim_activations', '>', $threshold);
            });
    }

    /**
     * Order by total tokens saved descending.
     */
    public function scopeOrderByTokensSaved($query)
    {
        return $query->orderByDesc('total_tokens_saved');
    }

    /**
     * Alias for scopeForConversation (matches quickstart.md examples).
     */
    public function scopeConversations($query)
    {
        return $query->where('entity_type', self::ENTITY_CONVERSATION);
    }
}
