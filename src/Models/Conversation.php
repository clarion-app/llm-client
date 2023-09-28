<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use MetaverseSystems\EloquentMultiChainBridge\EloquentMultiChainBridge;
use ClarionApp\LlmClient\Models\Message;

class Conversation extends Model
{
    use HasFactory, EloquentMultiChainBridge;

    protected $fillable = ['server_group_id', 'title', 'model', 'character', 'user'];

    public function messages()
    {
        $this->hasMany(Message::class);
    }
}
