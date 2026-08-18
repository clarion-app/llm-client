<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
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
     * The most recent last_seen_at among every cached tool row belonging
     * to one server -- "the pool this server's own tools were reported in
     * as of its last successful discover() run." Deliberately computed
     * from this table's own rows, never from
     * mcp_client_server_statuses.refresh_finished_at, since that column is
     * stamped on every discover() attempt including a failed one that
     * leaves every tool row untouched.
     */
    public static function maxLastSeenAtFor(string $serverId): ?Carbon
    {
        $max = static::where('server_id', $serverId)->max('last_seen_at');

        // max() is a raw aggregate query, not a hydrated model attribute --
        // it returns the driver's own string representation rather than
        // going through this model's 'last_seen_at' => 'datetime' cast, so
        // it is parsed explicitly here to honor the declared ?Carbon
        // return type.
        return $max === null ? null : Carbon::parse($max);
    }

    /**
     * Only rows sharing their own server's current maxLastSeenAtFor() --
     * a row whose last_seen_at falls short of that server's own maximum
     * belongs to a tool a later, successful refresh did not re-report,
     * i.e. a confirmed removal, and is excluded here rather than deleted
     * outright, so a since-removed tool's own invocation history stays
     * attributable. A single failed refresh attempt touches no tool row's
     * last_seen_at at all, so it moves no row's inclusion here, for any
     * number of consecutive failures.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('mcp_client_tools.last_seen_at', '>=', function ($sub) {
            $sub->selectRaw('MAX(t2.last_seen_at)')
                ->from('mcp_client_tools as t2')
                ->whereColumn('t2.server_id', 'mcp_client_tools.server_id');
        });
    }
}
