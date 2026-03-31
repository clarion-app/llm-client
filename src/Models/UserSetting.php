<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use ClarionApp\EloquentMultiChainBridge\EloquentMultiChainBridge;

class UserSetting extends Model
{
    use HasFactory, SoftDeletes, EloquentMultiChainBridge;

    protected $table = 'llm_user_settings';

    protected $fillable = ['user_id', 'server_id', 'model'];

    public function user()
    {
        return $this->belongsTo(\ClarionApp\Backend\Models\User::class);
    }

    public function server()
    {
        return $this->belongsTo(Server::class);
    }
}
