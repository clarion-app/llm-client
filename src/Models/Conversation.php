<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use ClarionApp\EloquentMultiChainBridge\EloquentMultiChainBridge;
use ClarionApp\LlmClient\Models\Message;

class Conversation extends Model
{
    use HasFactory, EloquentMultiChainBridge;

    protected $fillable = ['server_group_id', 'title', 'model', 'character', 'user_id'];

    public function messages()
    {
        return $this->hasMany(Message::class, 'conversation_id');
    }

    public function latest_message()
    {
        return $this->hasOne(Message::class, 'conversation_id')->latest();
    }
}
