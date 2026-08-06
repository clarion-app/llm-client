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
