<?php

namespace ClarionApp\LlmClient\Models;

use ClarionApp\EloquentMultiChainBridge\EloquentMultiChainBridge;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EpisodicMemory extends Model
{
    use HasFactory, EloquentMultiChainBridge;

    protected $table = 'episodic_memories';

    protected $fillable = [
        'id',
        'user_id',
        'conversation_id',
        'summary',
        'topics',
        'protected',
        'word_count',
        'summary_word_count',
        'embedding',
    ];

    protected $casts = [
        'topics' => 'array',
        'protected' => 'boolean',
        'embedding' => 'json',
    ];

    /**
     * Register the per-user global scope.
     */
    protected static function booted(): void
    {
        // Global scope: enforce per-user filtering at model level.
        // All queries automatically filter by authenticated user_id.
        static::addGlobalScope('user', function ($query) {
            if (function_exists('auth') && auth()->check()) {
                $query->where('user_id', auth()->id());
            }
        });
    }

    /**
     * Check if this entry is expired based on retention policy.
     * An entry is expired if it's older than the retention period and not protected.
     */
    public function isExpired(): bool
    {
        if ($this->protected) {
            return false;
        }

        $retentionDays = config('llm-client.episodic_memory.retention_days', 90);
        return $this->created_at->lt(now()->subDays($retentionDays));
    }

    /**
     * Get the user that owns this episodic memory.
     */
    public function user()
    {
        return $this->belongsTo(\ClarionApp\Backend\Models\User::class, 'user_id');
    }

    /**
     * Get the source conversation for this episodic memory.
     */
    public function conversation()
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }
}
