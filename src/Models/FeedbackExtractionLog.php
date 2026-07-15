<?php

namespace ClarionApp\LlmClient\Models;

use ClarionApp\EloquentMultiChainBridge\EloquentMultiChainBridge;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * FeedbackExtractionLog model.
 *
 * Audit trail for each extraction run, recording what signals were
 * considered and what outcome resulted.
 */
class FeedbackExtractionLog extends Model
{
    use HasFactory, EloquentMultiChainBridge;

    protected $table = 'feedback_extraction_log';

    protected $fillable = [
        'id',
        'user_id',
        'declarative_memory_id',
        'pattern_key',
        'signals_count',
        'signal_ids',
        'confidence_score',
        'outcome',
        'llm_call_id',
        'created_at',
    ];

    protected $casts = [
        'signals_count' => 'integer',
        'signal_ids' => 'array',
        'confidence_score' => 'integer',
        'created_at' => 'datetime',
    ];

    /**
     * Valid outcome values.
     */
    public const OUTCOMES = ['proposed', 'rejected', 'contradicted', 'retired'];

    public const OUTCOME_PROPOSED = 'proposed';
    public const OUTCOME_REJECTED = 'rejected';
    public const OUTCOME_CONTRADICTED = 'contradicted';
    public const OUTCOME_RETIRED = 'retired';

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
     * Check if the outcome is valid.
     */
    public static function isValidOutcome(string $outcome): bool
    {
        return in_array($outcome, self::OUTCOMES, true);
    }

    /**
     * Get extraction logs for a specific preference.
     */
    public static function getAuditTrail(string $declarativeMemoryId): static
    {
        return static::withoutGlobalScope('user')
            ->where('declarative_memory_id', $declarativeMemoryId)
            ->latest('created_at');
    }
}
