<?php

namespace ClarionApp\LlmClient\Models;

use ClarionApp\EloquentMultiChainBridge\EloquentMultiChainBridge;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A user-defined trigger: when it fires, its `defined_work` is run unattended
 * by the scheduler agent it is attached to.
 *
 * Two kinds share this one model. A `schedule` trigger reads
 * `schedule_expression`; a `condition` trigger reads the `condition_*` fields
 * and remembers the previous observation in `last_condition_state`, so only a
 * false -> true transition counts as an event. `kind` is immutable after
 * creation.
 *
 * `retry_limit` is always a concrete integer here — the default is applied
 * once, at creation, so no reader ever has to default it again.
 *
 * Mirrors `CodingProject`'s bridging shape exactly: persistent, user-owned
 * configuration, not ephemeral execution data.
 */
class SchedulerTrigger extends Model
{
    use HasFactory, EloquentMultiChainBridge, SoftDeletes;

    protected $fillable = [
        'user_id',
        'agent_id',
        'name',
        'kind',
        'schedule_expression',
        'condition_operation_id',
        'condition_path',
        'condition_comparator',
        'condition_value',
        'last_condition_state',
        'last_evaluated_at',
        'defined_work',
        'retry_limit',
        'is_active',
    ];

    protected $table = 'scheduler_triggers';

    protected $casts = [
        'last_condition_state' => 'boolean',
        'last_evaluated_at' => 'datetime',
        'retry_limit' => 'integer',
        'is_active' => 'boolean',
    ];

    public const KIND_SCHEDULE = 'schedule';
    public const KIND_CONDITION = 'condition';
}
