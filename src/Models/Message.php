<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use MetaverseSystems\EloquentMultiChainBridge\EloquentMultiChainBridge;

class Message extends Model
{
    use HasFactory, EloquentMultichainBridge;

    protected $fillable = [
        'content',
        'role',
        'user',
        'responseTime',
        'conversation_id'
    ];

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }
}
