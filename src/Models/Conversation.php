<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use ClarionApp\EloquentMultiChainBridge\EloquentMultiChainBridge;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Database\Factories\ConversationFactory;

class Conversation extends Model
{
    use HasFactory, EloquentMultiChainBridge;

    protected $fillable = ['server_id', 'title', 'model', 'character', 'user_id', 'is_processing', 'channel'];

    protected $casts = [
        'is_processing' => 'boolean',
    ];

    public function getChannelAttribute(): string
    {
        return $this->attributes['channel'] ?? 'web';
    }

    protected static function newFactory()
    {
        return ConversationFactory::new();
    }

    public function messages()
    {
        return $this->hasMany(Message::class, 'conversation_id');
    }

    public function latest_message()
    {
        return $this->hasOne(Message::class, 'conversation_id')->latest();
    }
}
