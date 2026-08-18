<?php

namespace ClarionApp\LlmClient\Models;

use ClarionApp\LlmClient\ValueObjects\McpTransportKind;
use ClarionApp\EloquentMultiChainBridge\EloquentMultiChainBridge;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A user- or installation-configured connection to a third-party MCP
 * server. Bridged (EloquentMultiChainBridge), mirroring Server's own
 * shape for persistent, user-owned configuration: an encrypted, hidden
 * credential column, and a plain accessor/mutator pair -- never a
 * $casts entry -- for its one enum-shaped attribute.
 */
class McpClientServer extends Model
{
    use HasFactory, EloquentMultiChainBridge, SoftDeletes;

    /**
     * Sentinel user_id marking a server as shared across every user at
     * this installation rather than owned by one person, the same
     * sentinel RoleAssignment already uses for its own installation-wide
     * scope.
     */
    public const INSTALLATION_SCOPE_ID = '00000000-0000-0000-0000-000000000000';

    protected $table = 'mcp_client_servers';

    protected $fillable = ['name', 'transport', 'url', 'command', 'args', 'credential', 'user_id'];
    protected $hidden = ['credential'];
    protected $casts = [
        'credential' => 'encrypted',
        'args' => 'array',
    ];

    /**
     * Derive the scope from the user_id sentinel, matching
     * RoleAssignment::getScopeAttribute()'s exact shape.
     */
    public function getScopeAttribute(): string
    {
        return $this->getAttribute('user_id') === self::INSTALLATION_SCOPE_ID
            ? 'project'
            : 'personal';
    }

    /**
     * Get the transport as a McpTransportKind enum. Defaults to
     * StreamableHttp for an unrecognized or legacy value.
     *
     * Intentionally a plain accessor/mutator rather than an enum cast in
     * $casts, for the same reason Server::getProviderTypeAttribute()'s
     * own docblock gives: defining both a cast and an accessor for the
     * same attribute routes Eloquent through the class-cast path and
     * fatals trying to instantiate the enum directly. The accessor
     * degrades gracefully instead.
     */
    public function getTransportAttribute(?string $value): McpTransportKind
    {
        return McpTransportKind::tryFrom((string) $value) ?? McpTransportKind::StreamableHttp;
    }

    /**
     * Normalize the transport to its backing string value for storage,
     * accepting either a McpTransportKind enum or a raw string.
     */
    public function setTransportAttribute(McpTransportKind|string|null $value): void
    {
        $this->attributes['transport'] = $value instanceof McpTransportKind
            ? $value->value
            : $value;
    }

    /**
     * Servers eligible for the given user: their own, plus every
     * installation-scoped server. The same sentinel-user_id eligibility
     * predicate RoleAssignment resolution already applies for role
     * scoping, applied here to server visibility.
     */
    public function scopeEligibleFor(Builder $query, string $userId): Builder
    {
        return $query->where('user_id', $userId)
            ->orWhere('user_id', self::INSTALLATION_SCOPE_ID);
    }
}
