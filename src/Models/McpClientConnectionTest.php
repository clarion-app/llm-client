<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * McpClientConnectionTest -- ephemeral, credential-bearing scratch state
 * for one "test before saving" connection attempt (FR-003, FR-004,
 * FR-012). Deliberately NOT bridged (EloquentMultiChainBridge is for
 * persistent, user-owned configuration -- this table is short-lived
 * scratch state, mirroring McpClientServerStatus's own "derived,
 * frequently-changing data" carve-out, here additionally
 * credential-bearing and purged on a short cycle, D3). Never linked to
 * any mcp_client_servers row -- no foreign key, no relationship method --
 * so nothing here can leak into the server list by construction.
 *
 * Unlike McpClientServer, this model has no EloquentMultiChainBridge
 * trait to auto-generate its UUID primary key on create, so it needs its
 * own creating-time UUID generator, mirroring McpClientServerStatus's
 * existing (also non-bridged) precedent below rather than
 * McpClientServer's own bridged one.
 */
class McpClientConnectionTest extends Model
{
    use HasFactory;

    protected $table = 'mcp_client_connection_tests';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'transport',
        'url',
        'command',
        'args',
        'credential',
        'status',
        'failure_category',
        'message',
        'tool_count',
        'started_at',
        'finished_at',
    ];

    protected $hidden = ['credential'];

    protected $casts = [
        'args' => 'array',
        'credential' => 'encrypted',
        'tool_count' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /**
     * Generate a UUID before the model is first saved.
     */
    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }
        });
    }
}
