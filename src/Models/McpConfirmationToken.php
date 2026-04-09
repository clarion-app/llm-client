<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class McpConfirmationToken extends Model
{
    use HasUuids;

    protected $table = 'mcp_confirmation_tokens';

    protected $fillable = [
        'id',
        'session_id',
        'tool_name',
        'arguments_hash',
        'arguments_snapshot',
        'used_at',
        'expires_at',
    ];

    protected $casts = [
        'arguments_snapshot' => 'array',
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public function session()
    {
        return $this->belongsTo(McpSession::class, 'session_id');
    }

    public function isValid(string $sessionId, string $toolName, string $argumentsHash): bool
    {
        if ($this->used_at !== null) {
            return false;
        }

        if ($this->expires_at->isPast()) {
            return false;
        }

        if ($this->session_id !== $sessionId) {
            return false;
        }

        if ($this->tool_name !== $toolName) {
            return false;
        }

        if ($this->arguments_hash !== $argumentsHash) {
            return false;
        }

        return true;
    }

    public function consume(): void
    {
        $this->used_at = Carbon::now();
        $this->save();
    }
}
