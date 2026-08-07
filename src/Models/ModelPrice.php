<?php

namespace ClarionApp\LlmClient\Models;

use ClarionApp\EloquentMultiChainBridge\EloquentMultiChainBridge;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Operator-editable, effective-dated price for a (provider_type, model) pair
 * (data-model.md §1/§4.2). Uses EloquentMultiChainBridge like Server/RoleAssignment
 * — operator-authored, durable config, not high-volume derived data.
 *
 * A price is never updated in place: ModelPriceService::setPrice() is the only
 * write path, closing the prior open row and inserting a new one.
 */
class ModelPrice extends Model
{
    use HasFactory, EloquentMultiChainBridge, SoftDeletes;

    protected $table = 'model_prices';

    protected $fillable = [
        'provider_type',
        'model',
        'reused_input_rate',
        'fresh_input_rate',
        'output_rate',
        'effective_from',
        'effective_until',
    ];

    protected $casts = [
        'effective_from' => 'datetime',
        'effective_until' => 'datetime',
    ];

    /**
     * The effective-dated lookup used once, at write time, to resolve which
     * price applies to a usage record (research.md D2). Returns null when
     * either identity component is null — pricing is structurally impossible
     * without both (data-model.md §2).
     */
    public static function currentFor(?string $providerType, ?string $model, \DateTimeInterface $at): ?self
    {
        if ($providerType === null || $model === null) {
            return null;
        }

        return static::query()
            ->where('provider_type', $providerType)
            ->where('model', $model)
            ->where('effective_from', '<=', $at)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $at))
            ->orderByDesc('effective_from')
            ->first();
    }
}
