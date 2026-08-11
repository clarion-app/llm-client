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

    // $timestamps = false above means Eloquent's default date-casting for
    // created_at (which only kicks in when timestamps are enabled) never
    // applies here — cast it explicitly so callers reading created_at get
    // a Carbon instance, not a raw DB string (the EvalCaseResult precedent).
    protected $casts = [
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (!$model->id) {
                // A time-ordered UUID rather than a plain random one: this
                // table's created_at column is only second-precision, so
                // two corrections to the same judgment within one second
                // are indistinguishable by timestamp alone. The id's own
                // byte layout carries the ordering "which correction came
                // last" depends on, portable across every supported
                // database.
                $model->id = (string) \Illuminate\Support\Str::orderedUuid();
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
