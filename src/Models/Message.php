<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;
use ClarionApp\EloquentMultiChainBridge\EloquentMultiChainBridge;
use ClarionApp\LlmClient\Models\ConversationHandoff;

class Message extends Model
{
    use HasFactory, EloquentMultichainBridge;

    protected $fillable = [
        'content',
        'role',
        'user',
        'responseTime',
        'conversation_id',
        'tool_data',

        // Mass-assignable so a caller building a transcript can place a
        // message at a specific instant. Nothing in src/ passes either one —
        // every production write takes the automatic timestamp — but a caller
        // that supplies one silently getting "now" instead is the kind of
        // difference that produces a transcript ordered other than the way it
        // was written, with nothing to indicate it.
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'tool_data' => 'array',
    ];

    protected static function booted(): void
    {
        // EloquentMultiChainBridge's own `creating` listener (registered in
        // this class's boot(), which runs before booted()) stamps `id` with
        // a random Str::uuid() (v4) — fine for uniqueness, but it makes
        // `id`'s sort order unrelated to creation order. Several call sites
        // in this package (e.g. SubagentToolRestrictionRuntimeJourneyTest's
        // toolCallMessages()) order a conversation's messages by `id` to
        // recover the order they were written in, mirroring the same
        // ordered-UUID precedent already established by
        // EvalJudgmentOverride/EvalReferenceDesignation in this codebase.
        // Registered here (booted(), not boot()) so it runs after — and
        // overwrites — the trait's own random assignment.
        static::creating(function ($model) {
            $model->id = (string) \Illuminate\Support\Str::orderedUuid();
        });

        static::creating(function ($model) {
            if ($model->run_id === null) {
                $model->run_id = Context::get('run_id');
            }
        });

        static::creating(function ($model) {
            if ($model->agent_id === null && $model->conversation_id !== null) {
                $conversation = Conversation::find($model->conversation_id);
                if ($conversation !== null) {
                    $identity = ConversationHandoff::currentAgentIdentityFor($conversation);
                    $model->agent_id = $identity['agent_id'];
                    $model->agent_version_id = $identity['agent_version_id'];
                }
            }
        });
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }
}
