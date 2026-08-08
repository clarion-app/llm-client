<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;
use ClarionApp\EloquentMultiChainBridge\EloquentMultiChainBridge;

class Message extends Model
{
    use HasFactory, EloquentMultichainBridge;

    protected $fillable = [
        'content',
        'role',
        'user',
        'responseTime',
        'conversation_id',
        'tool_data',

        // Mass-assignable so a caller building a transcript can place a
        // message at a specific instant. Nothing in src/ passes either one —
        // every production write takes the automatic timestamp — but a caller
        // that supplies one silently getting "now" instead is the kind of
        // difference that produces a transcript ordered other than the way it
        // was written, with nothing to indicate it.
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'tool_data' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if ($model->run_id === null) {
                $model->run_id = Context::get('run_id');
            }
        });
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }
}
