<?php

namespace ClarionApp\LlmClient\Models;

use ClarionApp\EloquentMultiChainBridge\EloquentMultiChainBridge;
use ClarionApp\LlmClient\Casts\PlainDecimalCast;
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
        // PlainDecimalCast (not a native numeric cast, which would form a
        // float): guarantees the exact plain-decimal-notation string these
        // decimal(14,8) columns were written as, at their own scale of 8,
        // even under SQLite's NUMERIC storage affinity for a small
        // configured rate — see the cast's own docblock.
        // MetricsRecorder::recordUsage() reads these three rates straight
        // into bcmul(), which — unlike Decimal::round() — has no tolerance
        // of its own for scientific notation.
        'reused_input_rate' => PlainDecimalCast::class.':8',
        'fresh_input_rate' => PlainDecimalCast::class.':8',
        'output_rate' => PlainDecimalCast::class.':8',
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
