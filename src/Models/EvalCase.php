<?php

namespace ClarionApp\LlmClient\Models;

use ClarionApp\EloquentMultiChainBridge\EloquentMultiChainBridge;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The stable, editable-in-place half of a case (research.md D3) — the
 * identity a suite's case listing is keyed on, unaffected by how many
 * times its content has been edited. The content itself lives on
 * EvalCaseVersion, pointed at by current_version_id.
 *
 * EvalCaseService is the sole write path for this table and for
 * eval_case_versions.
 */
class EvalCase extends Model
{
    use HasFactory, EloquentMultiChainBridge, SoftDeletes;

    protected $table = 'eval_cases';

    protected $fillable = ['suite_id', 'current_version_id'];

    /** The version currently in effect for this case. */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(EvalCaseVersion::class, 'current_version_id');
    }

    /**
     * Every version this case has ever had, oldest first — the shape
     * `GET .../cases/{caseId}/versions` returns (contracts §3).
     */
    public function versions(): HasMany
    {
        return $this->hasMany(EvalCaseVersion::class, 'case_id')->orderBy('version_number');
    }
}
