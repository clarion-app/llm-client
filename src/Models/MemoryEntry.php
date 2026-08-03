<?php

namespace ClarionApp\LlmClient\Models;

use ClarionApp\LlmClient\Casts\VectorEmbeddingCast;
use ClarionApp\LlmClient\Contracts\MemoryScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MemoryEntry extends Model
{
    protected $table = 'llm_memory_entries';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'scope',
        'agent_id',
        'user_id',
        'conversation_id',
        'turn_id',
        'key',
        'content',
        'embedding',
        'last_accessed_at',
    ];

    protected $casts = [
        'scope' => MemoryScope::class,
        'embedding' => VectorEmbeddingCast::class,
        'last_accessed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function ($entry) {
            if (!$entry->id) {
                $entry->id = (string) Str::uuid();
            }
        });

        // D4 fix: On MySQL/MariaDB, wrap embedding with VEC_FromText() for VECTOR columns.
        // The VectorEmbeddingCast formats the array as '[f1,f2,...]' and this event
        // wraps it with the SQL function so MariaDB stores it as a proper VECTOR value.
        // FR-030: SQLite path is unchanged (no VEC_FromText on non-MySQL drivers).
        static::saving(function ($entry) {
            if ($entry->isDirty('embedding')) {
                $attrs = $entry->getAttributes();
                $raw = $attrs['embedding'] ?? null;
                if ($raw !== null && DB::getDriverName() === 'mysql') {
                    $entry->setAttribute('embedding', DB::raw("VEC_FromText('{$raw}')"));
                }
            }
        });
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }
}
