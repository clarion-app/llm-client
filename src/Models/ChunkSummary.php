<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Support\Str;

class ChunkSummary extends Model
{
    use HasUlids;

    protected $table = 'chunk_summaries';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'conversation_id',
        'chunk_index',
        'source_hash',
        'source_message_count',
        'summary',
        'summary_tokens',
        'condensation_model',
        'condensation_provider',
    ];

    protected $casts = [
        'chunk_index' => 'integer',
        'source_message_count' => 'integer',
        'summary' => 'json',
        'summary_tokens' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (!$model->id) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }
}
