<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\EloquentMultiChainBridge\EloquentMultiChainBridge;

class LanguageModel extends Model
{
    use HasFactory, EloquentMultiChainBridge;

    protected $fillable = ['name', 'server_id'];

    public function server()
    {
        return $this->belongsTo(Server::class);
    }

    public function conversations()
    {
        return $this->hasMany(Conversation::class);
    }
}
