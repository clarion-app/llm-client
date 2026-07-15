<?php

namespace ClarionApp\LlmClient\Models;

use ClarionApp\EloquentMultiChainBridge\EloquentMultiChainBridge;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * FeedbackSignal model.
 *
 * Transient user feedback action stored until processed by the
 * extraction pipeline. Purged after retention period.
 */
class FeedbackSignal extends Model
{
    use HasFactory, EloquentMultiChainBridge;

    protected $table = 'feedback_signals';

    protected $fillable = [
        'id',
        'user_id',
        'source_event_id',
        'conversation_id',
        'signal_type',
        'pattern_key',
        'raw_context',
        'created_at',
        'processed_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    /**
     * Valid signal types.
     */
    public const SIGNAL_TYPES = ['approval', 'rejection', 'correction'];

    public const SIGNAL_APPROVAL = 'approval';
    public const SIGNAL_REJECTION = 'rejection';
    public const SIGNAL_CORRECTION = 'correction';

    /**
     * Register the per-user global scope.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('user', function ($query) {
            if (function_exists('auth') && auth()->check()) {
                $query->where('user_id', auth()->id());
            }
        });
    }

    /**
     * Check if the signal is pending extraction.
     */
    public function isPending(): bool
    {
        return $this->processed_at === null;
    }

    /**
     * Check if the signal type is valid.
     */
    public static function isValidSignalType(string $type): bool
    {
        return in_array($type, self::SIGNAL_TYPES, true);
    }

    /**
     * Get pending signals for a user.
     */
    public static function getPendingForUser(string $userId, int $limit = 20): static
    {
        return static::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->whereNull('processed_at')
            ->limit($limit)
            ->oldest('created_at');
    }

    /**
     * Get signals grouped by pattern for threshold aggregation.
     */
    public static function getSignalsForPattern(string $userId, string $patternKey): static
    {
        return static::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->where('pattern_key', $patternKey)
            ->whereNull('processed_at');
    }
}
