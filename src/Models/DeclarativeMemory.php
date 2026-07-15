<?php

namespace ClarionApp\LlmClient\Models;

use ClarionApp\EloquentMultiChainBridge\EloquentMultiChainBridge;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * DeclarativeMemory model.
 *
 * Persistent, strictly user-scoped entry representing an explicit
 * fact, preference, or rule. Permanent until the user explicitly
 * edits or deletes it — no age, retention, eviction, or protection metadata.
 */
class DeclarativeMemory extends Model
{
    use HasFactory, EloquentMultiChainBridge;

    protected $table = 'declarative_memories';

    protected $fillable = [
        'id',
        'user_id',
        'type',
        'content',
        'source',
        'confidence_level',
        'embedding',
    ];

    protected $casts = [
        'confidence_level' => 'integer',
        'embedding' => 'json',
    ];

    /**
     * Valid type values for declarative memory entries.
     */
    public const TYPES = ['fact', 'preference', 'rule'];

    /**
     * Valid source (provenance) values.
     */
    public const SOURCES = ['user_stated', 'agent_learned'];

    /**
     * Source value for user-stated entries.
     */
    public const SOURCE_USER_STATED = 'user_stated';

    /**
     * Source value for agent-learned entries (learned patterns).
     *
     * Learned patterns carry a confidence_level (0-100); user-stated entries do not.
     */
    public const LEARNED_PATTERN = 'agent_learned';

    /**
     * Register the per-user global scope.
     *
     * Mirrors EpisodicMemory::booted() — all queries automatically
     * filter by the authenticated user's ID for strict per-user isolation (FR-011).
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
     * Validate the type value.
     */
    public static function isValidType(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    /**
     * Validate the source value.
     */
    public static function isValidSource(string $source): bool
    {
        return in_array($source, self::SOURCES, true);
    }

    /**
     * Get the user that owns this declarative memory.
     */
    public function user()
    {
        return $this->belongsTo(\ClarionApp\Backend\Models\User::class, 'user_id');
    }
}
