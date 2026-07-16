<?php

namespace ClarionApp\LlmClient\Models;

use ClarionApp\EloquentMultiChainBridge\EloquentMultiChainBridge;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * FeedbackOptOut model.
 *
 * Record of a preference pattern the user has explicitly declined to store.
 * Prevents re-proposal of the same pattern.
 */
class FeedbackOptOut extends Model
{
    use HasFactory, EloquentMultiChainBridge, SoftDeletes;

    protected $table = 'feedback_opt_outs';

    protected $fillable = [
        'id',
        'user_id',
        'pattern_key',
    ];

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
     * Check if a pattern key is opt-outed for a user.
     */
    public static function isOptedOut(string $userId, string $patternKey): bool
    {
        return static::withoutGlobalScope('user')
            ->where('user_id', $userId)
            ->where('pattern_key', $patternKey)
            ->exists();
    }

    /**
     * Create or retrieve an opt-out record (idempotent).
     */
    public static function optOut(string $userId, string $patternKey): static
    {
        return static::withoutGlobalScope('user')
            ->firstOrCreate(
                ['user_id' => $userId, 'pattern_key' => $patternKey],
                ['id' => \Illuminate\Support\Str::uuid()->toString()]
            );
    }
}
