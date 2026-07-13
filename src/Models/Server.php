<?php

namespace ClarionApp\LlmClient\Models;

use ClarionApp\LlmClient\Contracts\ProviderType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use ClarionApp\LlmClient\Models\Conversation;
use ClarionApp\EloquentMultiChainBridge\EloquentMultiChainBridge;

class Server extends Model
{
    use HasFactory, EloquentMultiChainBridge, SoftDeletes;

    protected $fillable = ['name', 'server_url', 'token', 'provider_type'];
    protected $hidden = ['token'];
    protected $casts = [
        'token' => 'encrypted',
    ];
    protected $table = 'llm_servers';

    /**
     * Get the provider type as a ProviderType enum.
     * Defaults to OpenAI for legacy records with null provider_type.
     *
     * Note: this is intentionally a plain accessor/mutator rather than an
     * enum cast in $casts. Defining both a cast and an accessor for the same
     * attribute makes Eloquent route serialization through the class-cast path
     * and attempt to instantiate the enum (`new ProviderType`), which fatals.
     */
    public function getProviderTypeAttribute(?string $value): ProviderType
    {
        if ($value === null) {
            return ProviderType::OpenAI;
        }

        return ProviderType::tryFrom($value) ?? ProviderType::OpenAI;
    }

    /**
     * Normalize the provider type to its backing string value for storage,
     * accepting either a ProviderType enum or a raw string.
     */
    public function setProviderTypeAttribute(ProviderType|string|null $value): void
    {
        $this->attributes['provider_type'] = $value instanceof ProviderType
            ? $value->value
            : $value;
    }
}
