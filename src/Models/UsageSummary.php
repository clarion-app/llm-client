<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Model;

class UsageSummary extends Model
{
    protected $table = 'usage_summaries';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'entity_type',
        'entity_id',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'estimated_input_tokens',
        'estimated_output_tokens',
        'estimated_total_tokens',
        'request_count',
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
     * Get or create the usage summary for a conversation.
     */
    public static function getConversationTotals(string $conversationId): ?self
    {
        return static::where('entity_type', self::ENTITY_CONVERSATION)
            ->where('entity_id', $conversationId)
            ->first();
    }

    /**
     * Get or create the usage summary for a user.
     */
    public static function getUserTotals(string $userId): ?self
    {
        return static::where('entity_type', self::ENTITY_USER)
            ->where('entity_id', $userId)
            ->first();
    }
}
