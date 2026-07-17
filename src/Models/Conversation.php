<?php

namespace ClarionApp\LlmClient\Models;

use ClarionApp\LlmClient\Contracts\ProviderType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use ClarionApp\EloquentMultiChainBridge\EloquentMultiChainBridge;
use ClarionApp\LlmClient\Models\Message;
use ClarionApp\LlmClient\Database\Factories\ConversationFactory;

class Conversation extends Model
{
    use HasFactory, EloquentMultiChainBridge;

    protected $fillable = ['server_id', 'title', 'model', 'character', 'user_id', 'is_processing', 'channel', 'provider_override', 'ended_at'];

    protected $casts = [
        'is_processing' => 'boolean',
        'provider_override' => ProviderType::class,
        'ended_at' => 'datetime',
    ];

    public function getChannelAttribute(): string
    {
        return $this->attributes['channel'] ?? 'web';
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
