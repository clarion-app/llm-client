<?php

namespace ClarionApp\LlmClient\Models;

use ClarionApp\LlmClient\Contracts\MemoryScope;
use Illuminate\Database\Eloquent\Model;
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
        'last_accessed_at',
    ];

    protected $casts = [
        'scope' => MemoryScope::class,
        'last_accessed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function ($entry) {
            if (!$entry->id) {
                $entry->id = (string) Str::uuid();
            }
        });
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }
}
