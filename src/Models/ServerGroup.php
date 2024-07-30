<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\Server;
use ClarionApp\EloquentMultiChainBridge\EloquentMultiChainBridge;

class ServerGroup extends Model
{
    use HasFactory, EloquentMultiChainBridge;

    protected $fillable = ['name', 'user_id', 'token', 'server_group_id'];

    public function servers()
    {
        return $this->hasMany(Server::class);
    }

    public function conversations()
    {
        return $this->hasMany(Conversation::class);
    }
}
