<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class McpSession extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'mcp_sessions';

    protected $fillable = [
        'id',
        'user_id',
        'protocol_version',
        'client_name',
        'client_version',
        'capabilities',
    ];

    protected $casts = [
        'capabilities' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(\ClarionApp\Backend\Models\User::class, 'user_id');
    }

    public function confirmationTokens()
    {
        return $this->hasMany(McpConfirmationToken::class, 'session_id');
    }
}
