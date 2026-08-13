<?php

namespace ClarionApp\LlmClient\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A recorded event marking that responsibility for a conversation passed
 * from one specific agent to another at a specific point, forming part of
 * the conversation's own history (093-agent-handoff, data-model.md §1).
 *
 * Append-only — no code path in this feature ever updates from_agent_id,
 * to_agent_id, to_agent_version_id, position, or created_at after
 * creation. disclosed_at is the sole field ever written a second time.
 */
class ConversationHandoff extends Model
{
    protected $table = 'conversation_handoffs';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'conversation_id', 'position', 'from_agent_id',
        'to_agent_id', 'to_agent_version_id', 'created_at', 'disclosed_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'disclosed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (!$model->id) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    /**
     * The conversation's current effective agent identity — the latest
     * handoff's target, or the conversation's own original binding (090)
     * if it has never been handed off. A direct DB read, never cached
     * (research.md D2/D7) — used by Message's own creating listener (§2).
     *
     * Not called by ConversationAgentDefinitionResolver::effectiveDefinitionFor()
     * (§3) — that method needs the fully-resolved AgentDefinition (parsed
     * instructions/permissions), not just the bare identity pair this helper
     * returns, and its no-handoff fallback branch calls forConversation()
     * directly to preserve forConversation()'s own relation-based resolution
     * (090) rather than re-deriving from this helper's plain agent_id/
     * agent_version_id fallback. The two methods independently query
     * conversation_handoffs for the identical "latest row" shape by design,
     * not by oversight.
     *
     * @return array{agent_id: ?string, agent_version_id: ?string}
     */
    public static function currentAgentIdentityFor(Conversation $conversation): array
    {
        $latest = static::where('conversation_id', $conversation->id)
            ->orderByDesc('position')
            ->first();

        if ($latest !== null) {
            return ['agent_id' => $latest->to_agent_id, 'agent_version_id' => $latest->to_agent_version_id];
        }

        return ['agent_id' => $conversation->agent_id, 'agent_version_id' => $conversation->agent_version_id];
    }
}
