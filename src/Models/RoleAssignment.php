<?php

namespace ClarionApp\LlmClient\Models;

use ClarionApp\LlmClient\ValueObjects\ModelRole;
use ClarionApp\EloquentMultiChainBridge\EloquentMultiChainBridge;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RoleAssignment extends Model
{
    use HasFactory, EloquentMultiChainBridge, SoftDeletes;

    public const INSTALLATION_SCOPE_ID = '00000000-0000-0000-0000-000000000000';

    protected $table = 'llm_role_assignments';

    protected $fillable = ['role', 'user_id', 'server_id', 'model'];

    /**
     * Get the role as a ModelRole enum.
     * Defaults to Inference for legacy or unrecognised values.
     *
     * This is intentionally a plain accessor/mutator rather than an
     * enum cast in $casts. A cast would fatal on an unrecognised value;
     * the accessor falls back gracefully instead.
     */
    public function getRoleAttribute(?string $value): ModelRole
    {
        return ModelRole::tryFrom($value) ?? ModelRole::Inference;
    }

    /**
     * Normalize the role to its backing string value for storage,
     * accepting either a ModelRole enum or a raw string.
     */
    public function setRoleAttribute(ModelRole|string|null $value): void
    {
        $this->attributes['role'] = $value instanceof ModelRole
            ? $value->value
            : $value;
    }

    /**
     * Derive the scope from the user_id sentinel.
     */
    public function getScopeAttribute(): string
    {
        return $this->getAttribute('user_id') === self::INSTALLATION_SCOPE_ID
            ? 'installation'
            : 'user';
    }
}
