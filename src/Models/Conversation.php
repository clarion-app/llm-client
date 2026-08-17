<?php

namespace ClarionApp\LlmClient\Models;

use ClarionApp\LlmClient\Contracts\ProviderType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ClarionApp\EloquentMultiChainBridge\EloquentMultiChainBridge;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Database\Factories\ConversationFactory;

class Conversation extends Model
{
    use HasFactory, EloquentMultiChainBridge;

    protected $fillable = ['server_id', 'title', 'model', 'character', 'user_id', 'is_processing', 'channel', 'provider_override', 'ended_at', 'agent_id', 'agent_version_id', 'routing_reason', 'routing_disclosed_at', 'coding_project_id'];

    protected $casts = [
        'is_processing' => 'boolean',
        'provider_override' => ProviderType::class,
        'ended_at' => 'datetime',
        'routing_disclosed_at' => 'datetime',
    ];

    public function getChannelAttribute(): string
    {
        return $this->attributes['channel'] ?? 'web';
    }

    /**
     * A local, unconditional scope excluding system-owned conversations
     * (user_id = null, e.g. an eval-run's dedicated conversation) from a
     * user-facing query. Deliberately not auth()-gated — it says only "a
     * system-owned row is never a valid answer to a user-facing question,"
     * never which user is asking, so it stays correct for both an ordinary
     * user's own query and an authorized cross-user admin query alike.
     */
    public function scopeOwnedByRealUser($query)
    {
        return $query->whereNotNull('user_id');
    }

    protected static function newFactory()
    {
        return ConversationFactory::new();
    }

    public function messages()
    {
        return $this->hasMany(Message::class, 'conversation_id');
    }

    public function latest_message()
    {
        return $this->hasOne(Message::class, 'conversation_id')->latest();
    }

    public function server()
    {
        return $this->belongsTo(Server::class, 'server_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(\ClarionApp\LlmClient\Models\Agent::class, 'agent_id');
    }

    /**
     * The specific AgentVersion this conversation is bound to, if any — a
     * direct, indexed lookup by the fixed agent_version_id column recorded
     * at creation. This is the relation ConversationAgentDefinitionResolver
     * actually uses; it must never be substituted with agent()->currentVersion,
     * which would silently re-resolve to whatever version the agent has been
     * edited to since, defeating the whole point of the binding (FR-003).
     */
    public function agentVersion(): BelongsTo
    {
        return $this->belongsTo(\ClarionApp\LlmClient\Models\AgentVersion::class, 'agent_version_id');
    }

    /**
     * Get the effective provider type for this conversation.
     * Returns the provider_override if set, otherwise falls back to the server's provider_type.
     */
    public function getEffectiveProviderTypeAttribute(): ProviderType
    {
        if ($this->provider_override !== null) {
            return $this->provider_override;
        }

        $server = $this->server;

        if (!$server) {
            throw new \RuntimeException('No LLM server configured for this conversation');
        }

        // Use provider_type (snake_case) to get the casted enum value directly.
        // The camelCase providerType accessor has a bug where it receives the
        // already-casted enum and falls back to OpenAI for non-null values.
        return $server->provider_type;
    }
}
