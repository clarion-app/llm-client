<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\LlmClient\Models\ServerGroup;
use MetaverseSystems\EloquentMultiChainBridge\EloquentMultiChainBridge;

class Server extends Model
{
    use HasFactory, EloquentMultiChainBridge;

    protected $fillable = ['server_url', 'server_group_id'];

    public function server_group()
    {
        return $this->belongsTo(ServerGroup::class);
    }
}
