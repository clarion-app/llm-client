<?php

namespace ClarionApp\LlmClient\Models;

use ClarionApp\LlmClient\ValueObjects\ToolFailureCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ToolInvocationRecord extends Model
{
    protected $table = 'tool_invocation_records';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'conversation_id',
        'user_id',
        'attempt_group_id',
        'tool_name',
        'outcome',
        'failure_category',
        'co_member_tags',
    ];

    protected $casts = [
        'failure_category' => ToolFailureCategory::class,
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

    public function scopeForToolName($query, string $toolName)
    {
        return $query->where('tool_name', $toolName);
    }

    public function scopeBetweenDates($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    public function scopeRecentFailures($query, int $hours = 24)
    {
        return $query->where('outcome', 'failure')
            ->where('created_at', '>=', now()->subHours($hours));
    }

    /**
     * Calculate failure rate for a given scope (returns float 0.0-1.0).
     */
    public static function failureRate(string $conversationId = null, string $userId = null, string $toolName = null): float
    {
        $query = static::query();
        if ($conversationId) $query->where('conversation_id', $conversationId);
        if ($userId) $query->where('user_id', $userId);
        if ($toolName) $query->where('tool_name', $toolName);

        $total = $query->count();
        if ($total === 0) return 0.0;

        $failures = (clone $query)->where('outcome', 'failure')->count();
        return $failures / $total;
    }

    /**
     * Group failure counts by failure_category.
     *
     * @return array<string, int>
     */
    public static function groupByFailureCategory(string $conversationId = null, string $userId = null): array
    {
        $query = static::query()->where('outcome', 'failure');
        if ($conversationId) $query->where('conversation_id', $conversationId);
        if ($userId) $query->where('user_id', $userId);

        return $query->select('failure_category', DB::raw('count(*) as cnt'))
            ->groupBy('failure_category')
            ->pluck('cnt', 'failure_category')
            ->toArray();
    }
}
