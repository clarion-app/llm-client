<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * McpClientTool -- a cached snapshot of one tool a configured
 * McpClientServer currently offers. Not itself the source of truth --
 * refreshed periodically by a discovery service, the same rebuildable-
 * cache role the built-in operation search index already plays for the
 * built-in operation catalog.
 *
 * Does NOT use EloquentMultiChainBridge, matching ServerStatus's own
 * precedent for derived, frequently-changing data.
 */
class McpClientTool extends Model
{
    use HasFactory;

    protected $table = 'mcp_client_tools';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'server_id',
        'synthetic_operation_id',
        'name',
        'description',
        'input_schema',
        'annotations',
        'last_seen_at',
    ];

    protected $casts = [
        'input_schema' => 'array',
        'annotations' => 'array',
        'last_seen_at' => 'datetime',
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

    /**
     * The server this cached tool belongs to.
     * Cascade delete is enforced by the migration foreign key.
     */
    public function server()
    {
        return $this->belongsTo(McpClientServer::class, 'server_id');
    }

    /**
     * Look up one cached tool by its full, server-namespaced synthetic
     * operationId ("mcp:{server_id}:{name}") -- a plain unique-index
     * lookup, the primary query the search/execute call sites perform to
     * decide whether an operationId names an external tool at all.
     */
    public static function findBySyntheticId(string $id): ?self
    {
        return static::where('synthetic_operation_id', $id)->first();
    }

    /**
     * Only rows touched by their server's most recently completed
     * refresh -- a row whose last_seen_at predates that refresh belongs
     * to a tool the server no longer offers, and is excluded here rather
     * than deleted outright, so a since-removed tool's own invocation
     * history stays attributable.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereExists(function ($sub) {
            $sub->select(DB::raw(1))
                ->from('mcp_client_server_statuses')
                ->whereColumn('mcp_client_server_statuses.server_id', 'mcp_client_tools.server_id')
                ->whereColumn('mcp_client_tools.last_seen_at', '>=', 'mcp_client_server_statuses.refresh_finished_at');
        });
    }
}
