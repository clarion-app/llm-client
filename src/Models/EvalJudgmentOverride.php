<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An operator's correction to a Judgment — append-only, never a mutation
 * of the judgment it corrects or of any prior override of it.
 *
 * No EloquentMultiChainBridge, no SoftDeletes. Unlike EvalJudgment, this
 * table's own id is never pre-minted by a caller, so a creating listener
 * mints it here.
 */
class EvalJudgmentOverride extends Model
{
    protected $table = 'eval_judgment_overrides';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'id',
        'judgment_id',
        'user_id',
        'score',
        'justification',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (!$model->id) {
                $model->id = (string) \Illuminate\Support\Str::uuid();
            }

            if (!$model->created_at) {
                $model->created_at = now();
            }
        });
    }

    public function judgment(): BelongsTo
    {
        return $this->belongsTo(EvalJudgment::class, 'judgment_id');
    }
}
